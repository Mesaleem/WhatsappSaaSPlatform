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
     * Client Admin Granular Permission Matrix — the exact 9 checkboxes the
     * frontend's /team/permissions screen renders, grouped WhatsApp
     * Module (5: view/create/edit/delete map to ChatbotRuleController's
     * CRUD — the only WhatsApp-side rule/content CRUD surface in this
     * app; 'Send Messages' reuses the EXISTING `send-messages` permission
     * already enforced on /alerts/* rather than a new, redundant slug)
     * then Social Ads Module (4: view/launch/edit_budget map 1:1 to
     * AdCampaignController's index/launch/updateCplThreshold;
     * 'social_ads.delete_rules' has NO corresponding backend action —
     * AdCampaignController has no delete-campaign endpoint — so that one
     * checkbox is stored but currently has zero server-side effect,
     * disclosed in this refactor's audit report, same as this codebase's
     * existing precedent for the 'templates' module toggle).
     *
     * ROOT-CAUSE DESIGN NOTE (see audit report): this matrix operates on
     * DIRECT per-user permission grants (Spatie's model_has_permissions,
     * scoped to one user id), never on a Role's permission set the way
     * RoleController::update() does. config/permission.php has
     * 'teams' => false, so Role rows are GLOBAL across every tenant —
     * editing role 'admin' here would silently change permissions for
     * EVERY OTHER tenant's admin users too. Direct user grants are the
     * only tenant-safe way to implement a per-team-member matrix.
     *
     * @var list<string>
     */
    private const MANAGED_PERMISSIONS = [
        'whatsapp.view',
        'whatsapp.create',
        'whatsapp.edit',
        'whatsapp.delete',
        'send-messages',
        'social_ads.view',
        'social_ads.launch',
        'social_ads.edit_budget',
        'social_ads.delete_rules',
    ];

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
                ->get(['id', 'account_id', 'name', 'email', 'phone_number', 'is_active', 'created_at']);

            return response()->json(['data' => $users, 'scope' => 'global']);
        }

        $users = User::where('account_id', $account->id)
            ->with('roles:id,name')
            ->orderBy('name')
            ->get(['id', 'account_id', 'name', 'email', 'phone_number', 'is_active', 'created_at']);

        return response()->json(['data' => $users, 'scope' => 'account']);
    }

    /**
     * POST /api/team/users — creates a new sub-user under the caller's own
     * account_id (never accepted from the client) and assigns one or more
     * roles (Multi-Role Assignment — Spatie's HasRoles::assignRole()
     * already accepts an array natively; this was previously validated
     * and assigned as a single 'role_name' string, confirmed by reading
     * this method before this edit). Assigning 'super_admin' is blocked —
     * that role carries no account_id and is reserved for platform staff
     * provisioned outside this endpoint.
     */
    public function store(Request $request): JsonResponse
    {
        // Super Admin / Client Admin RBAC boundary refactor — "Super Admin
        // ONLY provisions the Client Account and its SINGLE Primary
        // Client Admin during account setup" (that happens via
        // AccountController::store(), a completely separate endpoint).
        // Super Admin MUST NOT create regular team users here, even for a
        // tenant they've selected via ?account_id=.
        if ($request->attributes->get('is_super_admin')) {
            return response()->json([
                'message' => 'Super Admin cannot create team users directly. Ask the Client Admin to add team members to their own account.',
            ], 403);
        }

        $account = $this->requireAccount($request, 'Select a client/tenant account to add a team member to (pass ?account_id=).');

        if (! $account->hasModuleEnabled('team_management')) {
            return response()->json([
                'message' => 'User management has been disabled for your account by the Super Admin.',
            ], 403);
        }

        // Super Admin Client Provisioning refactor — User Limits. Checked
        // for EVERY caller, Super Admin included (unlike the module-toggle
        // check above, a seat cap is a provisioning limit Super Admin set
        // deliberately for this client, not a self-controlled toggle they
        // should be able to bypass while managing that tenant's team).
        if ($account->hasReachedUserLimit()) {
            return response()->json([
                'message' => 'User limit reached. Contact Super Admin to upgrade.',
            ], 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')],
            'phone_number' => ['nullable', 'string', 'max:32'],
            'password' => ['required', 'string', 'min:8'],
            'role_names' => ['required', 'array', 'min:1'],
            // Admin Role Assignment Restriction — 'admin' excluded
            // alongside 'super_admin': this endpoint is only reachable by
            // a non-Super-Admin caller (see the guard above), and Primary
            // Admin creation is reserved exclusively for
            // AccountController::store() during Super-Admin client setup.
            'role_names.*' => ['string', 'distinct', Rule::exists('roles', 'name')->where(fn ($q) => $q->whereNotIn('name', ['super_admin', 'admin']))],
        ]);

        $user = User::create([
            'account_id' => $account->id,
            'name' => $data['name'],
            'email' => $data['email'],
            'phone_number' => $data['phone_number'] ?? null,
            'password' => $data['password'],
            'is_active' => true,
        ]);
        $user->assignRole($data['role_names']);

        return response()->json([
            'message' => 'Team member added.',
            'data' => $user->load('roles:id,name'),
        ], 201);
    }

    /**
     * PATCH /api/team/users/{id} — edits a sub-user's name/email/role.
     * Client Management & Account Deactivation Engine — TeamUsersPage's
     * "Edit User" action. Deliberately excludes password (ResetPasswordModal
     * / AdminUserController::changePassword already own that, Super-Admin-
     * only) and excludes is_active (toggle() above already owns that).
     * Same account-owner protection as toggle()/destroy(): the account
     * owner's role can't be changed here, since that could silently strip
     * their Admin access with no ownership-transfer flow to replace it.
     * Multi-Role Assignment — see store()'s docblock; syncRoles() already
     * accepts an array natively.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        // Super Admin Edit & Deactivation Capabilities refactor — Super
        // Admin MAY now edit any tenant's team member directly (name,
        // email, phone, role) via ?account_id=, exactly like every other
        // cross-tenant action already on this controller's read
        // endpoints. Only the Granular Permission Matrix
        // (permissions()/updatePermissions() below) stays exclusively
        // Client Admin's — see this refactor's audit report.
        $account = $this->requireAccount($request, 'Select a client/tenant account to manage its team (pass ?account_id=).');

        $user = User::where('account_id', $account->id)->find($id);
        abort_if(! $user, 404, 'Team member not found.');
        abort_if($account->owner?->id === $user->id, 422, "The account owner's details cannot be edited here.");

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'phone_number' => ['nullable', 'string', 'max:32'],
            'role_names' => ['required', 'array', 'min:1'],
            // Admin Role Assignment Restriction — same exclusion as
            // store(); prevents promoting an existing team member to
            // 'admin' from the Edit User form, which would otherwise
            // bypass the invite-time restriction above.
            'role_names.*' => ['string', 'distinct', Rule::exists('roles', 'name')->where(fn ($q) => $q->whereNotIn('name', ['super_admin', 'admin']))],
        ]);

        $user->forceFill([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone_number' => $data['phone_number'] ?? null,
        ])->save();
        $user->syncRoles($data['role_names']);

        return response()->json(['message' => 'Team member updated.', 'data' => $user->fresh()->load('roles:id,name')]);
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
        // Super Admin Edit & Deactivation Capabilities refactor — Super
        // Admin MAY now deactivate/activate any tenant's team member
        // directly via ?account_id=. Only the Granular Permission Matrix
        // stays exclusively Client Admin's — see this refactor's audit
        // report.
        $account = $this->requireAccount($request, 'Select a client/tenant account to manage its team (pass ?account_id=).');

        $user = User::where('account_id', $account->id)->find($id);
        abort_if(! $user, 404, 'Team member not found.');
        abort_if($account->owner?->id === $user->id, 422, "The account owner's access cannot be revoked here.");
        // Admin Self-Preservation — a caller can never deactivate their
        // own row, preventing an accidental self-lockout (mirrors the
        // equivalent guard in destroy() below). Super Admin's own user
        // row is never resolvable as $user here (Super Admin has no
        // account_id, so it can never match `where('account_id', ...)`),
        // so this guard only ever fires for a Client Admin acting on
        // their own row.
        abort_if($request->user()->id === $user->id, 403, 'You cannot deactivate or remove your own account.');

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
        // Super Admin / Client Admin RBAC boundary refactor — Super Admin
        // MUST NOT manage individual team members; that's exclusively
        // Client Admin's (and 'social_marketer''s, per its own pre-existing
        // manage-team grant) job now. Read-only oversight (GET /team/users)
        // is unaffected.
        if ($request->attributes->get('is_super_admin')) {
            return response()->json([
                'message' => 'Super Admin cannot manage individual team members directly. Ask the Client Admin to manage their own team.',
            ], 403);
        }

        $account = $this->requireAccount($request, 'Select a client/tenant account to manage its team (pass ?account_id=).');

        $user = User::where('account_id', $account->id)->find($id);
        abort_if(! $user, 404, 'Team member not found.');
        abort_if($account->owner?->id === $user->id, 422, 'The account owner cannot be removed.');
        // Admin Self-Preservation — 403 (was 422) and unified wording with
        // toggle()'s new self-guard; a self-lockout attempt is an
        // authorization failure, not a validation error.
        abort_if($request->user()->id === $user->id, 403, 'You cannot deactivate or remove your own account.');

        $user->delete();

        return response()->json(['message' => 'Team member removed.']);
    }

    /** GET /api/team/roles — assignable roles for the invite form's picker (excludes Super Admin). */
    public function roles(): JsonResponse
    {
        // Admin Role Assignment Restriction — 'admin' excluded alongside
        // 'super_admin' so the Invite/Edit form's role picker never offers
        // it; Primary Admin creation is reserved for Super Admin during
        // client setup (AccountController::store()), never this endpoint.
        $roles = Role::whereNotIn('name', ['super_admin', 'admin'])->orderBy('name')->get(['id', 'name']);

        return response()->json(['data' => $roles]);
    }

    /**
     * GET /api/team/users/{id}/permissions — Client Admin Granular
     * Permission Matrix. Returns two lists, both restricted to
     * MANAGED_PERMISSIONS: `direct` (grants this matrix itself controls —
     * what the checkboxes should reflect as checked+editable) and
     * `effective` (every managed permission the user actually has right
     * now, direct OR via a role — e.g. an 'admin' already holds all 9
     * via role, so 'admin' users show fully checked but not editable
     * here). The frontend disables a checkbox that is `effective` but
     * not `direct` — it's inherited from the user's role and can't be
     * revoked from this per-user screen without corrupting every OTHER
     * tenant's users who share that same global role.
     */
    public function permissions(Request $request, int $id): JsonResponse
    {
        // Super Admin / Client Admin RBAC boundary refactor — "Move the
        // Granular Permission Matrix exclusively to the Client Admin
        // workspace... restricted to `admin` role." A single hasRole('admin')
        // check does double duty: it blocks Super Admin (role name
        // 'super_admin', never 'admin') AND 'social_marketer' (which still
        // holds manage-team for the plain Team Users CRUD above, but is
        // NOT the 'admin'/Client Admin role this specific screen is
        // scoped to).
        if (! $request->user()->hasRole('admin')) {
            return response()->json([
                'message' => 'Only the Client Admin can manage the granular team permission matrix.',
            ], 403);
        }

        $account = $this->requireAccount($request, 'Select a client/tenant account to view its team permissions.');

        $user = User::where('account_id', $account->id)->find($id);
        abort_if(! $user, 404, 'Team member not found.');

        $direct = $user->permissions()->whereIn('name', self::MANAGED_PERMISSIONS)->pluck('name')->values();
        $effective = $user->getAllPermissions()->pluck('name')->intersect(self::MANAGED_PERMISSIONS)->values();

        return response()->json(['data' => ['user_id' => $user->id, 'direct' => $direct, 'effective' => $effective]]);
    }

    /**
     * PATCH /api/team/users/{id}/permissions — replaces this user's DIRECT
     * grants within MANAGED_PERMISSIONS with exactly the submitted set
     * (revoke-all-managed then grant-the-submitted-subset), leaving any
     * role-derived permission and any OTHER direct permission the user
     * might hold completely untouched.
     */
    public function updatePermissions(Request $request, int $id): JsonResponse
    {
        // Super Admin / Client Admin RBAC boundary refactor — "Move the
        // Granular Permission Matrix exclusively to the Client Admin
        // workspace... restricted to `admin` role." A single hasRole('admin')
        // check does double duty: it blocks Super Admin (role name
        // 'super_admin', never 'admin') AND 'social_marketer' (which still
        // holds manage-team for the plain Team Users CRUD above, but is
        // NOT the 'admin'/Client Admin role this specific screen is
        // scoped to).
        if (! $request->user()->hasRole('admin')) {
            return response()->json([
                'message' => 'Only the Client Admin can manage the granular team permission matrix.',
            ], 403);
        }

        $account = $this->requireAccount($request, 'Select a client/tenant account to manage its team permissions.');

        $user = User::where('account_id', $account->id)->find($id);
        abort_if(! $user, 404, 'Team member not found.');

        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(self::MANAGED_PERMISSIONS)],
        ]);

        $user->revokePermissionTo(self::MANAGED_PERMISSIONS);
        if (! empty($data['permissions'])) {
            $user->givePermissionTo($data['permissions']);
        }

        $direct = $user->permissions()->whereIn('name', self::MANAGED_PERMISSIONS)->pluck('name')->values();
        $effective = $user->getAllPermissions()->pluck('name')->intersect(self::MANAGED_PERMISSIONS)->values();

        return response()->json([
            'message' => 'Permissions updated.',
            'data' => ['user_id' => $user->id, 'direct' => $direct, 'effective' => $effective],
        ]);
    }
}
