<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Absolute Super Admin Control — Password Override.
 *
 * POST /api/admin/users/{id}/change-password lets a Super Admin overwrite
 * any Admin's or User's password directly, without knowing the current one.
 * Gated by BOTH the route's permission:manage-accounts middleware AND an
 * explicit in-controller isSuperAdmin() check (defense in depth): unlike
 * every other permission in RolePermissionSeeder::PERMISSIONS,
 * manage-accounts could in principle be granted to a Super-Admin-created
 * custom role via RoleController, and this endpoint's blast radius
 * (overwriting ANY user's credentials) is too high to rely on permission
 * naming alone.
 */
class AdminUserController extends Controller
{
    public function changePassword(Request $request, int $id): JsonResponse
    {
        abort_unless(
            $request->user()?->isSuperAdmin(),
            403,
            'Only the Super Admin can override a user\'s password.'
        );

        $target = User::findOrFail($id);

        // Excludes the caller's own row too, when the caller is Super
        // Admin — there is no second Super Admin this could legitimately
        // target, and a Super Admin changing their own password should go
        // through a normal "change my password" flow, not this override.
        abort_if(
            $target->isSuperAdmin(),
            422,
            'The Super Admin password cannot be overridden here.'
        );

        $data = $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ]);

        // User::$casts hashes 'password' automatically (see User::casts()),
        // matching the same forceFill()+save() pattern used nowhere else
        // for passwords in this codebase — every other write is via
        // User::create(), which relies on the same cast.
        $target->forceFill(['password' => $data['password']])->save();

        // Revoke every existing session so the new password takes effect
        // immediately and no stale token survives the override.
        $target->tokens()->delete();

        return response()->json([
            'message' => "Password updated for {$target->name}. All of their active sessions have been signed out.",
        ]);
    }
}
