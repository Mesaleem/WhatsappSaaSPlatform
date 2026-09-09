<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    /**
     * Base platform permission catalog. This is the full universe of
     * permissions a role can be assigned; it is NOT the list of roles —
     * Super Admin can create additional dynamic roles at runtime via
     * RoleController and assign any subset of these permissions to them.
     */
    private const PERMISSIONS = [
        'manage-accounts',
        'manage-subscriptions',
        'send-messages',
        'view-analytics',
        // Module 7: separate from view-analytics (aggregate KPIs/charts)
        // because message logs and exports expose row-level customer PII
        // (phone numbers, names) — a strictly higher sensitivity tier.
        'view-logs',
        // Module 8: platform-level Razorpay/Stripe credential vault —
        // separate from manage-accounts on purpose, so billing-gateway
        // access can be delegated to a finance role later without also
        // granting full tenant/account management.
        'manage-billing-settings',
        // Module 9 — Developer Portal: API key generation/revocation and
        // outbound webhook subscription management. One permission covers
        // both, since the frontend spec presents them as two tabs of a
        // single Developer Portal page, not separate features.
        'manage-developer-settings',
        // Module 10 — Chatbot Rule Builder and its execution logs. Kept
        // as its own permission (rather than folded into
        // manage-developer-settings) since a chatbot editor is a
        // messaging/content-authoring role, not an API-credentials role.
        'manage-chatbot',
        'manage-team',
        'manage-roles',
        // Dynamic Templates & Variables System — Super Admin Template
        // Designer & Approval Panel. Super-Admin-only (not listed in
        // 'admin' or 'user' below): a Client Admin USES approved
        // templates (gated by the send-messages permission they already
        // hold) but never authors/approves them.
        'manage-templates',
        // Role-Based Login Audit Logging Architecture. Granted to ALL
        // three default roles (folded into PERMISSIONS so super_admin
        // picks it up automatically, and listed explicitly for admin/user
        // below) since every role can see login history, only scoped
        // differently: super_admin sees every tenant, admin sees their
        // own account's users, user sees only their own login rows (see
        // AuditLogController::scopedQuery()).
        'view-audit-logs',
        // Advanced Broadcast Engine + Mail Template Manager. Admin-only
        // (besides super_admin, who already holds it via PERMISSIONS) —
        // an Admin may broadcast to their own account's users; a plain
        // 'user' role never composes or sends notifications.
        'manage-notifications',
    ];

    /**
     * Default seeded roles. Only the three defaults below are created here;
     * everything else (Sales, Support, Manager, ...) is created dynamically
     * by a Super Admin through the Role Management endpoints.
     *
     * @var array<string, list<string>>
     */
    private const DEFAULT_ROLES = [
        // Global system access across every tenant account.
        'super_admin' => self::PERMISSIONS,
        // Account owner: manages their own account's subscription, team and roles.
        'admin' => [
            'manage-subscriptions',
            'send-messages',
            'view-analytics',
            'view-logs',
            'manage-developer-settings',
            'manage-chatbot',
            'manage-team',
            'manage-roles',
            'view-audit-logs',
            'manage-notifications',
        ],
        // Standard staff member.
        'user' => [
            'send-messages',
            'view-analytics',
            'view-audit-logs',
        ],
    ];

    /**
     * Historical role names, kept exactly so an already-seeded database is
     * migrated in place rather than left with orphaned old-named rows
     * (firstOrCreate() alone would create NEW 'super_admin'/'admin'/'user'
     * rows and silently strand every existing user's role assignment on
     * the old 'Super Admin'/'Admin'/'User' rows). Safe to re-run: once a
     * row is renamed, its old name no longer matches and this is a no-op.
     *
     * @var array<string, string>
     */
    private const LEGACY_ROLE_RENAMES = [
        'Super Admin' => 'super_admin',
        'Admin' => 'admin',
        'User' => 'user',
    ];

    public function run(): void
    {
        foreach (self::LEGACY_ROLE_RENAMES as $oldName => $newName) {
            Role::where('name', $oldName)->update(['name' => $newName]);
        }

        $permissions = collect(self::PERMISSIONS)->mapWithKeys(
            fn (string $name) => [$name => Permission::firstOrCreate(['name' => $name])]
        );

        foreach (self::DEFAULT_ROLES as $roleName => $permissionNames) {
            $role = Role::firstOrCreate(['name' => $roleName]);
            $role->syncPermissions(
                collect($permissionNames)->map(fn (string $name) => $permissions[$name])->all()
            );
        }
    }
}
