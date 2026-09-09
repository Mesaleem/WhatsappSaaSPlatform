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
                'role' => $user?->roles?->first()?->name,
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
        $role = $user->roles->first();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => $user->is_active,
            'account_id' => $user->account_id,
            'account' => $user->account,
            'role' => $role,
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'created_at' => $user->created_at,
        ];
    }
}
