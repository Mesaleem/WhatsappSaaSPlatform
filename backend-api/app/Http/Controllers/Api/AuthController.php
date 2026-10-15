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
use App\Services\Mail\SystemMailer;
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

            // QA fixes (2026-10-14): an expired plan, or a used-up message quota, no
            // longer blocks login. The client must reach Billing to renew; the
            // read-only mode (subscription.guard + the frontend banner) keeps
            // every change disabled until then. Only a suspended account is refused.
            // (An agent's own account normally has no subscription at all.)
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

        $mailer = app(SystemMailer::class);
        $mailer->sendLater('auth.password_changed', $user->email, [
            'name' => $user->name,
            'changed_at' => $when,
            'ip_address' => $ip ?: 'unknown',
            'reset_url' => $mailer->url('/forgot-password'),
        ], [], $user->account_id, $user->name);
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

        $mailer = app(SystemMailer::class);
        $mailer->sendLater('auth.login_alert', $user->email, [
            'name' => $user->name,
            'login_time' => $when,
            'device' => $device,
            'ip_address' => $ip,
            'reset_url' => $mailer->url('/forgot-password'),
        ], [], $user->account_id, $user->name);
    }

    /**
     * POST /api/auth/register/send-code — step 1 of website signup: check the
     * email is real (format, not disposable, domain receives mail, not taken)
     * and email it a 6-digit code. The account is only created with that code.
     */
    public function sendSignupCode(Request $request, SystemMailer $mailer): JsonResponse
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

        $sent = $mailer->send('auth.signup_otp', $email, ['code' => $code, 'minutes' => $minutes]);

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

            $subscription = Subscription::create([
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

            $this->startTrialPlan($account, $subscription);

            return $user;
        });

        EmailVerificationCode::where('email', mb_strtolower(trim($data['email'])))->delete();
        $user->refresh();
        $this->sendWelcome($user, $data['company_name'], $data['phone'] ?? null, $trialDays, $trialMessages);
        $token = $user->createToken('api-token', $user->getAllPermissions()->pluck('name')->all())->plainTextToken;
        $this->logAttempt($request, $user, 'success');

        return response()->json([
            'user' => $this->formatUser($user),
            'token' => $token,
            'token_type' => 'Bearer',
        ], 201);
    }

    /**
     * QA fixes (2026-10-14, owner): a website trial is the hidden "Free Trial"
     * plan — Starter's features and limits. A ₹0 paid invoice records it, so
     * the trial is handled by the same plan machinery as every paid plan: its
     * features (entitlements, via the reconciler), its menu (modules_from_plan)
     * and its limits. Buying any plan later is an upgrade from it. The trial's
     * length and messages still come from the website settings above.
     */
    private function startTrialPlan(Account $account, Subscription $subscription): void
    {
        $plan = \App\Models\Plan::query()->where('slug', \App\Models\Plan::TRIAL_SLUG)->where('is_active', true)->first();
        if ($plan === null) {
            return; // No trial plan configured: the account keeps the old open trial.
        }

        $invoice = (new \App\Models\Invoice())->forceFill([
            'account_id' => $account->id,
            'invoice_number' => sprintf('TRIAL-%d-%s', $account->id, now()->format('Ymd')),
            'plan_key' => $plan->slug,
            'plan_label' => $plan->label,
            'amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'currency' => 'INR',
            'payment_gateway' => 'manual',
            'status' => 'paid',
            'gateway_payment_id' => 'trial',
            'paid_at' => now(),
        ]);
        $invoice->capturePlanTerms($plan)->save();

        foreach (array_keys(\App\Models\Plan::LIMITS) as $column) {
            $subscription->{$column} = $plan->{$column};
        }
        $subscription->save();

        $account->forceFill(['modules_from_plan' => true, 'allowed_modules' => null])->save();
        app(\App\Services\Access\PlanEntitlementReconciliationService::class)->reconcile($account);
    }

    /**
     * Onboarding after a website signup: a welcome email + in-app
     * notification for the new customer, and an email + in-app notification
     * for every Super Admin. Best effort, sent after the response.
     */
    private function sendWelcome(User $user, string $company, ?string $phone, int $trialDays, int $trialMessages): void
    {
        $mailer = app(SystemMailer::class);

        $mailer->sendLater('onboarding.welcome', $user->email, [
            'name' => $user->name,
            'company_name' => $company,
            'email' => $user->email,
            'trial_days' => $trialDays,
            'trial_messages' => $trialMessages,
            'login_url' => $mailer->url('/'),
        ], [], $user->account_id, $user->name);
        $mailer->notifyUsers([$user], 'Welcome to '.app(AccountMailer::class)->brand().'!', "Your free trial is active: {$trialMessages} messages for {$trialDays} days. Start by connecting your WhatsApp number.", 'onboarding', '/settings/whatsapp');

        $admins = $mailer->superAdmins();
        $mailer->sendToUsers('onboarding.admin_new_signup', $admins, [
            'name' => $user->name,
            'company_name' => $company,
            'email' => $user->email,
            'phone' => $phone ?: '-',
            'trial_days' => $trialDays,
            'signup_time' => now()->timezone('Asia/Kolkata')->format('d M Y, h:i A').' IST',
            'admin_url' => $mailer->url('/admin/accounts'),
        ]);
        $mailer->notifyUsers($admins, 'New signup: '.$company, $user->name.' ('.$user->email.') signed up from the website and started a free trial.', 'onboarding', '/admin/accounts');
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
    /**
     * POST /api/auth/tours — product tours (2026-10-15).
     *   {tour: "welcome"}                 mark one tour finished/skipped
     *   {action: "reset"}                 see every tour again
     *   {action: "reset", tour: "..."}    see one tour again
     * Returns the user's finished tours.
     */
    public function tours(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['sometimes', 'string', Rule::in(['complete', 'reset'])],
            'tour' => ['required_unless:action,reset', 'nullable', 'string', 'max:64', 'regex:/^[a-z0-9_:\/\-]+$/'],
        ]);

        $user = $request->user();
        $done = array_values($user->completed_tours ?? []);
        $tour = $data['tour'] ?? null;

        if (($data['action'] ?? 'complete') === 'reset') {
            $done = $tour === null ? [] : array_values(array_diff($done, [$tour]));
        } elseif (! in_array($tour, $done, true)) {
            // Bounded: the oldest entries go first if someone keeps adding unknown keys.
            $done = array_slice([...$done, $tour], -200);
        }

        // A direct write: finishing a tour is not an account change worth an activity-log row.
        $user->newQuery()->whereKey($user->id)->update(['completed_tours' => json_encode($done)]);
        $user->forceFill(['completed_tours' => $done])->syncOriginal();

        return response()->json(['completed_tours' => $done]);
    }

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
        $effectiveModules = $user->account?->effectiveModules() ?? [];
        $user->account?->setAttribute('effective_modules', $effectiveModules);

        // The API key is part of the plan (external_api capability), not a separate module. The key stays
        // with the client after the plan ends; sending from it is refused until the plan is renewed.
        if ($user->account !== null) {
            $user->account->setAttribute('api_access', app(\App\Services\Access\AccessControlService::class)->hasApiKeyAccess($user->account));
            $user->account->setAttribute('subscription_active', $user->account->hasActiveSubscription());
            // Account override, else the plan's team-user limit (null = unlimited).
            $user->account->setAttribute('user_limit', $user->account->userLimit());
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone_number' => $user->phone_number,
            'is_active' => $user->is_active,
            // Product tours already finished or skipped (empty for a new user → the welcome tour).
            'completed_tours' => array_values($user->completed_tours ?? []),
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
            'industry_modules' => $user->account && in_array(config('industries.module'), $effectiveModules, true)
                ? app(\App\Services\Industry\IndustryModuleResolver::class)->usableKeys($user->account, $user)
                : [],
            'created_at' => $user->created_at,
        ];
    }
}
