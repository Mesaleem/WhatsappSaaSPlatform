<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * ABSOLUTE SUPER ADMIN BYPASS & POLICY OVERRIDE.
 *
 * Gate::before() runs before EVERY ability Laravel's Gate resolves — and
 * that includes Spatie's own `permission:<name>` route middleware, which
 * (verified against vendor/spatie/laravel-permission/src/Middleware/
 * PermissionMiddleware.php, not assumed) calls $user->canAny($permissions).
 * canAny() is Laravel's own Illuminate\Foundation\Auth\Access\Authorizable
 * trait method — it goes through Gate, so this single callback transparently
 * short-circuits every `permission:*` route in routes/api.php for a Super
 * Admin, with zero per-route changes and no dependency on the seeded
 * role/permission pivot rows being perfectly in sync.
 *
 * This deliberately does NOT touch TenantIsolationMiddleware or
 * SubscriptionGuardMiddleware — both already pass Super Admin through
 * unconditionally on their own merits (see their own docblocks); Gate is
 * strictly an authorization concern, not tenant-scoping or billing-state.
 *
 * Uses the existing User::isSuperAdmin() (hasRole('super_admin')) rather
 * than a $user->role property — this codebase has no such column; roles
 * are Spatie pivot-table assignments.
 */
class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::before(function (User $user, string $ability) {
            return $user->isSuperAdmin() ? true : null;
        });
    }
}
