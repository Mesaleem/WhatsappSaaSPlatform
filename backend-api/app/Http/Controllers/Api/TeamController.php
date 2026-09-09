<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * UI overhaul (Team Users page) — tenant Admin manages the sub-users that
 * belong to their own account_id. Mirrors AccountController::store()'s
 * User::create()+assignRole() pattern for the initial Admin, and
 * RoleController's existing GET /api/roles for the role picker (already
 * available to any Admin — Admin holds manage-roles per RolePermissionSeeder).
 *
 * KNOWN GAP (disclosed): there is no mail/notification infrastructure
 * anywhere in this codebase (confirmed by inspection — no Mailable, no
 * queued notification classes), so "invite" here means immediate account
 * creation with an admin-chosen password, not an emailed invite link. A
 * future module can add that without changing this endpoint's contract.
 */
class TeamController extends Controller
{
    use ResolvesTenantAccount;

    /**
     * GET /api/team/users — every user on the caller's account, including
     * the account owner. Super Admin with no ?account_id= selected gets a
     * GLOBAL roster instead of a 422: every user on every tenant, each row
     * labelled with its account, so the Team Users page can render a
     * cross-tenant table rather than a red error banner.
     */
    public function index(Request $request): JsonResponse
    {
        $account = $this->resolveAccount($request);

        if (! $account) {
            $users = User::whereNotNull('account_id')
                ->with(['roles:id,name', 'account:id,company_name'])
                ->orderBy('account_id')
                ->orderBy('name')
                ->get(['id', 'account_id', 'name', 'email', 'is_active', 'created_at']);

            return response()->json(['data' => $users, 'scope' => 'global']);
        }

        $users = User::where('account_id', $account->id)
            ->with('roles:id,name')
            ->orderBy('name')
            ->get(['id', 'account_id', 'name', 'email', 'is_active', 'created_at']);

        return response()->json(['data' => $users, 'scope' => 'account']);
    }

    /**
     * POST /api/team/users — creates a new sub-user under the caller's own
     * account_id (never accepted from the client) and assigns one role.
     * Assigning 'super_admin' is blocked — that role carries no account_id
     * and is reserved for platform staff provisioned outside this endpoint.
     */
    public function store(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client/tenant account to add a team member to (pass ?account_id=).');

        // Absolute Super Admin Control — Strict User Creation Check. Only
        // gates a tenant Admin; Super Admin is never blocked by a toggle
        // they themselves control, even while managing this tenant's team
        // via ?account_id=.
        if (! $request->attributes->get('is_super_admin') && ! $account->hasModuleEnabled('team_management')) {
            return response()->json([
                'message' => 'User management has been disabled for your account by the Super Admin.',
            ], 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'role_name' => ['required', 'string', Rule::exists('roles', 'name')->where(fn ($q) => $q->where('name', '!=', 'super_admin'))],
        ]);

        $user = User::create([
            'account_id' => $account->id,
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'is_active' => true,
        ]);
        $user->assignRole($data['role_name']);

        return response()->json([
            'message' => 'Team member added.',
            'data' => $user->load('roles:id,name'),
        ], 201);
    }

    /**
     * PATCH /api/team/users/{id}/toggle — activate/deactivate a sub-user.
     * Blocked on the account owner (Account::owner — the oldest Admin) so
     * an Admin can't lock themselves or the account's original owner out;
     * revoking access for a specific admin is a role change, not a status
     * flip, and out of this endpoint's scope.
     */
    public function toggle(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client/tenant account to manage its team (pass ?account_id=).');

        $user = User::where('account_id', $account->id)->find($id);
        abort_if(! $user, 404, 'Team member not found.');
        abort_if($account->owner?->id === $user->id, 422, "The account owner's access cannot be revoked here.");

        $user->forceFill(['is_active' => ! $user->is_active])->save();

        return response()->json(['message' => 'Team member status updated.', 'data' => $user->fresh()->load('roles:id,name')]);
    }

    /**
     * DELETE /api/team/users/{id} — removes a sub-user. Same account-owner
     * protection as toggle(); also blocks a user from deleting their own
     * account (they should use logout, not this endpoint, to end their
     * own session).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client/tenant account to manage its team (pass ?account_id=).');

        $user = User::where('account_id', $account->id)->find($id);
        abort_if(! $user, 404, 'Team member not found.');
        abort_if($account->owner?->id === $user->id, 422, 'The account owner cannot be removed.');
        abort_if($request->user()->id === $user->id, 422, 'You cannot remove your own account.');

        $user->delete();

        return response()->json(['message' => 'Team member removed.']);
    }

    /** GET /api/team/roles — assignable roles for the invite form's picker (excludes Super Admin). */
    public function roles(): JsonResponse
    {
        $roles = Role::where('name', '!=', 'super_admin')->orderBy('name')->get(['id', 'name']);

        return response()->json(['data' => $roles]);
    }
}
