<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoginAuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
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

        $abilities = $user->isSuperAdmin()
            ? ['*']
            : $user->getAllPermissions()->pluck('name')->all();

        $token = $user->createToken('api-token', $abilities)->plainTextToken;

        $this->logAttempt($request, $user, 'success');

        return response()->json([
            'user' => $this->formatUser($user),
            'token' => $token,
            'token_type' => 'Bearer',
        ]);
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

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
        ]);

        $user->name = $data['name'];
        $user->email = $data['email'];

        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }

        $user->save();

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

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone_number' => $user->phone_number,
            'is_active' => $user->is_active,
            'account_id' => $user->account_id,
            'account' => $user->account,
            'roles' => $user->roles,
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'created_at' => $user->created_at,
        ];
    }
}
