<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ContactGroup;
use App\Models\EmailVerificationCode;
use App\Models\LoginAuditLog;
use App\Models\Subscription;
use App\Models\User;
use App\Rules\DeliverableEmail;
use App\Services\Access\AccessControlService;
use App\Services\Auth\AccountMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

use function Illuminate\Support\defer;

class AuthController extends Controller
{
    public function __construct(private readonly AccessControlService $accessControl)
    {
    }

    /**
     * Validate credentials, verify the user/account is active, issue a
     * Sanctum token scoped to the user's permissions, and return the
     * authenticated user context.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            $this->logAttempt($request, null, 'failed', $credentials['email']);

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            $this->logAttempt($request, $user, 'failed');

            return response()->json([
                'message' => 'Your account has been suspended. Contact your administrator.',
                'error_code' => 'ACCOUNT_SUSPENDED',
            ], 403);
        }

        if (! $user->isSuperAdmin()) {
            $account = $user->account;

            if (! $account) {
                $this->logAttempt($request, $user, 'failed');

                return response()->json([
                    'message' => 'Your user is not linked to any account.',
                    'error_code' => 'ACCOUNT_SUSPENDED',
                ], 403);
            }

            // Client Management & Account Deactivation Engine.
            // ROOT-CAUSE FINDING: before this, hasActiveSubscription() (see
            // Account::hasActiveSubscription()) already returned false for
            // BOTH an administratively suspended account (status !==
            // 'active') AND a genuinely lapsed subscription (status
            // 'active' but currentSubscription not isActive()) — both fell
            // through to the single "subscription is not active" branch
            // below, so a Super-Admin-deactivated client saw a MISLEADING
            // billing message instead of the deactivation notice the spec
            // requires. This check runs FIRST and specifically, using
            // Account::isAdministrativelyActive() (status === 'active')
            // alone — independent of subscription state — so a deactivated
            // client's users are blocked with the correct message
            // regardless of what their subscription looks like.
            if (! $account->isAdministrativelyActive()) {
                $this->logAttempt($request, $user, 'failed');

                return response()->json([
                    'message' => 'Your organization account has been suspended. Contact Super Admin.',
                    'error_code' => 'CLIENT_ACCOUNT_SUSPENDED',
                ], 403);
            }

            if (! $account->hasActiveSubscription()) {
                $this->logAttempt($request, $user, 'failed');

                return response()->json([
                    'message' => "Your account's subscription is not active.",
                    'error_code' => 'SUBSCRIPTION_EXPIRED',
                ], 403);
            }
        }

        // Device limit (security.account.max_devices, default 2). Logins up to the
        // limit just work. One more is refused with 409 until the user chooses
        // "log out from all devices" (force=true): every existing session is then
        // deleted (those devices land on the login page on their next request)
        // and the user is emailed, since a login beyond the limit can mean
        // someone else has the password.
        $signedOutOthers = false;
        $maxDevices = (int) config('security.account.max_devices');
        if ($maxDevices > 0) {
            $sessions = $this->liveSessions($user);
            $count = $sessions->count();

            if ($count >= $maxDevices) {
                if (! $request->boolean('force')) {
                    return response()->json([
                        'message' => "This account is already logged in on {$count} devices (the limit is {$maxDevices}). Log out from all devices to log in here.",
                        'error_code' => 'ACTIVE_SESSION',
                        'active_sessions' => $count,
                        'max_devices' => $maxDevices,
                    ], 409);
                }

                $sessions->delete();
                $signedOutOthers = true;
            }
        }

        $abilities = $user->isSuperAdmin()
            ? ['*']
            : $user->getAllPermissions()->pluck('name')->all();

        $token = $user->createToken('api-token', $abilities)->plainTextToken;

        if ($signedOutOthers) {
            $this->sendLoginAlert($request, $user);
        }

        // Phase 12 Task 2 — a successful login forgets this e-mail + IP's failed attempts (see LoginThrottle).
        \App\Support\Security\LoginThrottle::clear($request);

        $this->logAttempt($request, $user, 'success');

        return response()->json([
            'user' => $this->formatUser($user),
            'token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    /** "Your password was changed" email (profile change or reset), sent after the response. */
    public static function sendPasswordChangedEmail(User $user, ?string $ip): void
    {
        if (! $user->email) {
            return;
        }
        $when = now()->timezone('Asia/Kolkata')->format('d M Y, h:i A').' IST';

        defer(function () use ($user, $ip, $when) {
            $mailer = app(AccountMailer::class);
            $mailer->send(
                $user->email,
                "Your {$mailer->brand()} password was changed",
                'Your password was changed',
                '<p>Hi '.e($user->name).',</p>'
                .'<p>The password for your account was changed on <b>'.e($when).'</b>'.($ip ? ' from IP <b>'.e($ip).'</b>' : '').'.</p>'
                .'<p>All other devices have been logged out.</p>'
                .'<p style="color:#b91c1c"><b>Not you?</b> Reset your password right away and contact us.</p>',
                ['label' => 'Reset my password', 'url' => $mailer->frontendUrl('/forgot-password')],
            );
        });
    }

    /** The user's app login tokens that are still valid (Sanctum expiration honoured). */
    private function liveSessions(User $user)
    {
        $minutes = config('sanctum.expiration');

        return $user->tokens()
            ->where('name', 'api-token')
            ->when($minutes, fn ($q) => $q->where('created_at', '>', now()->subMinutes((int) $minutes)))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * Security email when a login beyond the device limit signed every other
     * device out. Sent after the response so a slow mail server never delays
     * logging in. Best-effort.
     */
    private function sendLoginAlert(Request $request, User $user): void
    {
        if (! config('security.account.login_alerts') || ! $user->email) {
            return;
        }

        $ip = (string) $request->ip();
        $device = AccountMailer::describeDevice($request->userAgent());
        $when = now()->timezone('Asia/Kolkata')->format('d M Y, h:i A').' IST';

        defer(function () use ($user, $ip, $device, $when) {
            $mailer = app(AccountMailer::class);
            $brand = $mailer->brand();
            $mailer->send(
                $user->email,
                "Security alert: all devices were logged out of your {$brand} account",
                'All your devices were logged out',
                '<p>Hi '.e($user->name).',</p>'
                .'<p>Your account was logged in on one more device than allowed, so <b>every other device was logged out</b>. The new login:</p>'
                .'<table style="border-collapse:collapse;font-size:14px;margin:8px 0 4px">'
                .'<tr><td style="padding:4px 16px 4px 0;color:#64748b">When</td><td><b>'.e($when).'</b></td></tr>'
                .'<tr><td style="padding:4px 16px 4px 0;color:#64748b">Device</td><td><b>'.e($device).'</b></td></tr>'
                .'<tr><td style="padding:4px 16px 4px 0;color:#64748b">IP address</td><td><b>'.e($ip).'</b></td></tr>'
                .'</table>'
                .'<p>If this was you, you can ignore this email.</p>'
                .'<p style="color:#b91c1c"><b>Not you?</b> Change your password now. Every device will be logged out.</p>',
                ['label' => 'Change my password', 'url' => $mailer->frontendUrl('/forgot-password')],
            );
        });
    }

    /**
     * POST /api/auth/register/send-code — step 1 of website signup: check the
     * email is real (format, not disposable, domain receives mail, not taken)
     * and email it a 6-digit code. The account is only created with that code.
     */
    public function sendSignupCode(Request $request, AccountMailer $mailer): JsonResponse
    {
        if (! PublicSiteController::brand('signup_enabled', true)) {
            return response()->json(['message' => 'Sign-up is currently closed.', 'error_code' => 'SIGNUP_DISABLED'], 403);
        }

        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255', new DeliverableEmail(), Rule::unique('users', 'email')],
        ], ['email.unique' => 'An account with this email already exists. Please log in instead.']);

        $email = mb_strtolower(trim($data['email']));
        $code = (string) random_int(100000, 999999);
        $minutes = (int) config('security.signup.code_minutes');

        EmailVerificationCode::updateOrCreate(
            ['email' => $email],
            ['code_hash' => Hash::make($code), 'attempts' => 0, 'expires_at' => now()->addMinutes($minutes)],
        );

        $sent = $mailer->send(
            $email,
            "{$code} is your {$mailer->brand()} verification code",
            'Verify your email',
            '<p>Use this code to finish creating your account:</p>'
            .'<p style="font-size:32px;font-weight:bold;letter-spacing:8px;color:#047857;margin:18px 0">'.$code.'</p>'
            .'<p style="color:#64748b">The code expires in '.$minutes.' minutes. If you did not try to sign up, ignore this email.</p>',
        );

        if (! $sent) {
            EmailVerificationCode::where('email', $email)->delete();

            return response()->json([
                'message' => "We couldn't send the verification email right now. Please try again later or contact us.",
                'error_code' => 'EMAIL_NOT_SENT',
            ], 503);
        }

        return response()->json(['message' => 'We have emailed you a 6-digit code.', 'expires_in_minutes' => $minutes]);
    }

    /** Check the signup code for an email; counts wrong tries. Returns an error message, or null when valid. */
    private function checkSignupCode(string $email, string $code): ?string
    {
        $row = EmailVerificationCode::where('email', mb_strtolower(trim($email)))->first();

        if (! $row || $row->expires_at->isPast()) {
            return 'This code has expired. Please request a new one.';
        }
        if ($row->attempts >= (int) config('security.signup.code_max_attempts')) {
            return 'Too many wrong codes. Please request a new one.';
        }
        if (! Hash::check($code, $row->code_hash)) {
            $row->increment('attempts');

            return 'The code is not correct.';
        }

        return null;
    }

    /**
     * POST /api/auth/register — self-service signup from the public website.
     * Creates the same records as a Super Admin's "Create Client" (account,
     * its admin user, the default contact group, a subscription) and logs the
     * new admin in. The subscription is a free trial whose length and quota
     * the Super Admin sets in Website Content (brand.trial_days /
     * brand.trial_messages); the customer buys a plan from Billing.
     */
    public function register(Request $request): JsonResponse
    {
        if (! PublicSiteController::brand('signup_enabled', true)) {
            return response()->json([
                'message' => 'Sign-up is currently closed. Please contact us to open an account.',
                'error_code' => 'SIGNUP_DISABLED',
            ], 403);
        }

        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', new DeliverableEmail(), Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'accept_terms' => ['accepted'],
            'email_code' => ['required', 'digits:6'],
        ]);

        // The emailed code proves the address is real and belongs to this person.
        if ($error = $this->checkSignupCode($data['email'], $data['email_code'])) {
            return response()->json(['message' => $error, 'errors' => ['email_code' => [$error]]], 422);
        }

        $trialDays = max(1, (int) PublicSiteController::brand('trial_days', 7));
        $trialMessages = max(0, (int) PublicSiteController::brand('trial_messages', 50));

        $user = DB::transaction(function () use ($data, $trialDays, $trialMessages) {
            $account = Account::create([
                'company_name' => $data['company_name'],
                'primary_phone' => $data['phone'] ?? null,
                'status' => 'active',
                'allowed_modules' => null,
                'max_users_limit' => null,
                'module_assignment' => 'both',
                'account_type' => 'client',
                'agent_id' => null,
            ]);

            $user = User::create([
                'account_id' => $account->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone_number' => $data['phone'] ?? null,
                'password' => $data['password'],
                'is_active' => true,
            ]);
            $user->assignRole('admin');

            ContactGroup::create([
                'account_id' => $account->id,
                'name' => 'All Contacts',
                'is_default' => true,
            ]);

            Subscription::create([
                'account_id' => $account->id,
                'engine_type' => 'qr',
                'billing_model' => 'flat_quota',
                'rate_per_message' => null,
                'total_allocated_messages' => $trialMessages,
                'used_messages' => 0,
                'price_paid' => 0,
                'payment_mode' => 'cash',
                'starts_at' => now(),
                'expires_at' => now()->addDays($trialDays),
                'status' => 'active',
            ]);

            return $user;
        });

        EmailVerificationCode::where('email', mb_strtolower(trim($data['email'])))->delete();
        $user->refresh();
        $token = $user->createToken('api-token', $user->getAllPermissions()->pluck('name')->all())->plainTextToken;
        $this->logAttempt($request, $user, 'success');

        return response()->json([
            'user' => $this->formatUser($user),
            'token' => $token,
            'token_type' => 'Bearer',
        ], 201);
    }

    /**
     * Write one login_audit_logs row for this attempt. Best-effort ONLY —
     * wrapped in try/catch so a logging failure (e.g. a DB hiccup) can
     * never break real authentication, mirroring how WebhookSender/exports
     * treat their own side-effects as non-load-bearing. `role`/`account_id`
     * are snapshotted from the user's CURRENT state at the moment of this
     * login (see the migration's docblock for why that snapshot, not a
     * live join, is intentional).
     */
    private function logAttempt(Request $request, ?User $user, string $status, ?string $attemptedEmail = null): void
    {
        try {
            LoginAuditLog::create([
                'user_id' => $user?->id,
                'account_id' => $user && ! $user->isSuperAdmin() ? $user->account_id : null,
                // Dynamic Multi-Role Sidebar Aggregation refactor — a user can
                // now hold more than one role; this audit-log snapshot
                // joins every currently-assigned role name rather than
                // silently keeping only whichever role Eloquent's
                // unordered pivot query happened to return first.
                'role' => $user?->roles?->pluck('name')->implode(', ') ?: null,
                'email' => $user?->email ?? $attemptedEmail,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'status' => $status,
                'logged_in_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Login audit log write failed: '.$e->getMessage());
        }
    }

    /**
     * Return the currently authenticated user's context.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing('account.currentSubscription');
        $user->account?->currentSubscription?->refreshStatus();

        return response()->json([
            'user' => $this->formatUser($user),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'subscription_status' => $user->isSuperAdmin()
                ? null
                : $user->account?->currentSubscription?->status,
        ]);
    }

    /**
     * PATCH /api/auth/profile — self-service name/email/password update
     * for the currently authenticated user (any role, including Super
     * Admin). Sits alongside /auth/me and /auth/logout in the plain
     * auth:sanctum group rather than behind subscription.guard — a
     * tenant whose subscription lapsed must still be able to fix their
     * own login credentials, matching Read-Only Subscription Expired
     * Mode's intent (see SubscriptionGuardMiddleware's docblock) even
     * though this route isn't literally inside that middleware group.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // The email is the login ID: it cannot be changed from the profile.
        if ($request->has('email') && (string) $request->input('email') !== (string) $user->email) {
            return response()->json([
                'message' => 'Your email is your login ID, so it cannot be changed. Contact support if you need a different login.',
                'error_code' => 'email_locked',
            ], 422);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:25'],
            'current_password' => ['required_with:password', 'nullable', 'string'],
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'confirmed'],
        ]);

        // A password change needs the current one, checked on the server.
        if (! empty($data['password']) && ! Hash::check((string) ($data['current_password'] ?? ''), (string) $user->password)) {
            return response()->json([
                'message' => 'The current password is not correct.',
                'errors' => ['current_password' => ['The current password is not correct.']],
            ], 422);
        }

        $user->name = $data['name'];
        $user->phone_number = $data['phone_number'] ?? null;

        $passwordChanged = ! empty($data['password']);
        if ($passwordChanged) {
            $user->password = $data['password'];
        }

        $user->save();

        if ($passwordChanged) {
            // Every other device is signed out; this one stays logged in.
            // (A cookie/session login has a TransientToken with no id: then every token goes.)
            $current = $request->user()->currentAccessToken();
            $currentId = $current instanceof \Laravel\Sanctum\PersonalAccessToken ? $current->id : null;
            $user->tokens()->when($currentId, fn ($q, $id) => $q->where('id', '!=', $id))->delete();
            self::sendPasswordChangedEmail($user, $request->ip());
        }

        return response()->json([
            'message' => 'Profile updated.',
            'user' => $this->formatUser($user->fresh()),
        ]);
    }

    /**
     * Revoke the token used to authenticate the current request.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    private function formatUser(User $user): array
    {
        $user->loadMissing(['account.currentSubscription', 'roles.permissions']);

        // Dynamic Multi-Role Sidebar Aggregation refactor.
        // ROOT-CAUSE FINDING: this previously collapsed to a single
        // 'role' => $user->roles->first() — an ARBITRARY role once a
        // user holds more than one (Eloquent's belongsToMany pivot query
        // carries no defined order), silently discarding every other
        // assigned role's identity. `permissions` below was already
        // correct for multi-role users (getAllPermissions() already
        // unions permissions across every assigned role — verified by
        // reading Spatie's HasRoles trait before this edit), so nav
        // items gated purely on `permission` already worked; only
        // role-NAME-based checks (isSuperAdmin(), hasRole()) were at
        // risk. Exposing the full `roles` array (already eager-loaded
        // above) lets the frontend check role membership correctly
        // instead of trusting one arbitrarily-picked role.
        // 3-Tier Hierarchy & Agent-Client Scope Engine (Phase 2) — API
        // Response Audit: effective_modules alongside allowed_modules on
        // this same 'account' payload (see Account::effectiveModules()),
        // so the frontend's hasModule() can honor the live Agent-Client
        // hierarchy cap without re-implementing the intersection itself.
        $user->account?->setAttribute('effective_modules', $user->account->effectiveModules());

        // The API key is part of the plan (external_api capability), not a separate module. The key stays
        // with the client after the plan ends; sending from it is refused until the plan is renewed.
        if ($user->account !== null) {
            $user->account->setAttribute('api_access', app(\App\Services\Access\AccessControlService::class)->hasApiKeyAccess($user->account));
            $user->account->setAttribute('subscription_active', $user->account->hasActiveSubscription());
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone_number' => $user->phone_number,
            'is_active' => $user->is_active,
            'account_id' => $user->account_id,
            'account' => $user->account,
            // CRM — the Super Admin's own CRM account (PlatformCrmAccount),
            // the CRM target when no client is selected. Null for everyone
            // else, and for a Super Admin when it has not been created.
            'platform_crm_account' => $user->isSuperAdmin()
                ? app(\App\Services\Crm\PlatformCrmAccount::class)->find()?->only(['id', 'company_name'])
                : null,
            'roles' => $user->roles,
            'permissions' => $user->getAllPermissions()->pluck('name'),
            // Phase 1 Foundation, Task 9 — { capability_slug => bool },
            // driven by AccessControlService::capabilityMap() (Super
            // Admin bypass + AccountEntitlement, single query), additive
            // alongside effective_modules/allowed_modules above; neither
            // is replaced in Phase 1.
            'capabilities' => $this->accessControl->capabilityMap($user),
            // Phase 11 Task 1 — "industry.module" keys this user may use right now (navigation gating).
            // Empty for a Super Admin (no own account) and for any account with no usable industry module.
            'industry_modules' => $user->account && $user->account->hasModuleEnabled(config('industries.module'))
                ? app(\App\Services\Industry\IndustryModuleResolver::class)->usableKeys($user->account, $user)
                : [],
            'created_at' => $user->created_at,
        ];
    }
}
