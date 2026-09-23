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
        // Social Media Marketing & Meta Ads Automation Expansion (Phase 1).
        // manage-social-settings is Super-Admin-only, separate from the four
        // social_marketer permissions below, on purpose: it gates the
        // PLATFORM's Meta/LinkedIn/Google OAuth App credential vault
        // (SocialGatewayController — same tier as manage-billing-settings),
        // never a tenant's own connected social accounts.
        'manage-social-settings',
        'manage-social-accounts',
        'launch-meta-ads',
        'manage-social-leads',
        // Phase 6 CRM Hardening (Issue 1) — the CRM's own permission,
        // split out of manage-social-leads. Deliberately separate: a
        // Meta Lead Ads capture list and a tenant's full CRM contact
        // book are different data at different sensitivity, and a Super
        // Admin must be able to grant one without the other. The split
        // is permission-model-only — see the accompanying
        // add_manage_crm_permission migration for why the same three
        // roles receive it, so nobody's effective access changes.
        // /api/social/leads deliberately KEEPS manage-social-leads.
        'manage-crm',
        'view-social-analytics',
        // Social Media Marketing & Meta Ads Automation Expansion (Phase 4).
        // Separate from manage-social-leads on purpose, mirroring the
        // launch-meta-ads / manage-social-accounts split from Phase 3:
        // configuring WHICH keywords auto-reply (CommentRulesPage) is a
        // distinct, higher-stakes action (it posts publicly on the
        // tenant's Page/Instagram automatically) from day-to-day lead/
        // inbox management, so a Super Admin can grant them separately.
        'manage-comment-automation',
        // Client Admin Granular Permission Matrix — see
        // TeamController::MANAGED_PERMISSIONS' docblock for the full
        // design rationale (why these are granted as DIRECT per-user
        // Spatie permissions via /team/users/{id}/permissions rather than
        // edited onto a Role — Role rows are global across every tenant
        // since config/permission.php has 'teams' => false). Not added to
        // any DEFAULT_ROLES below: 'admin' and 'social_marketer' already
        // reach the same underlying actions through their existing
        // coarser manage-chatbot/launch-meta-ads permissions (both OR'd
        // with these in routes/api.php), so no default role needs these
        // granted a second time. They exist here purely so
        // Rule::exists('permissions','name') accepts them from
        // TeamController::updatePermissions() and RoleController::store().
        'whatsapp.view',
        'whatsapp.create',
        'whatsapp.edit',
        'whatsapp.delete',
        'social_ads.view',
        'social_ads.launch',
        'social_ads.edit_budget',
        // No corresponding backend action exists for this one (disclosed
        // in TeamController::MANAGED_PERMISSIONS' docblock and this
        // refactor's audit report) — kept in the catalog so the matrix
        // checkbox is still storable, matching the spec's literal
        // checklist.
        'social_ads.delete_rules',
        // IMPLEMENT: Dynamic Route Master with Super-Admin Bypass &
        // Global Audit Tracking — requirement 4's "Super-Admin Audit
        // Trail UI". Deliberately its own permission, separate from the
        // existing 'view-audit-logs' (login-attempt history, visible to
        // admin/user/agent too) — granted ONLY to super_admin here (via
        // 'super_admin' => self::PERMISSIONS below); no other role's
        // array in DEFAULT_ROLES lists it, matching this feature's
        // literal "Super-Admin Audit Trail UI" scope.
        'view-activity-logs',
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
        // Client Management, Team Users, Dynamic RBAC Sidebar & Global
        // Table Filters refactor — Admin (spec's "Client Admin") must see
        // the full Social Media Marketing operational suite in the
        // sidebar (AppLayout.tsx's nav items are permission-gated, so the
        // nav can't show them without the permission existing here too).
        // Admin did not hold any of these five before this change —
        // verified by reading this exact array prior to this edit.
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
            'manage-social-accounts',
            'launch-meta-ads',
            'manage-social-leads',
            // Phase 6 CRM Hardening (Issue 1) — Admin already reached
            // the CRM through manage-social-leads; this keeps that
            // unchanged under the new permission.
            'manage-crm',
            'view-social-analytics',
            'manage-comment-automation',
        ],
        // Standard staff member.
        'user' => [
            'send-messages',
            'view-analytics',
            'view-audit-logs',
        ],

        // 3-Tier Hierarchy & Agent-Client Scope Engine (Phase 2) — Agent
        // (Reseller). Deliberately just this ONE permission, not a
        // parallel copy of 'admin's list: an Agent's primary user is
        // ADDITIONALLY assigned 'admin' at account-creation time (see
        // AccountController::store()), which already covers that same
        // user's own normal account operations (billing, team, WhatsApp
        // setup, ...). manage-accounts is the only thing 'admin' does NOT
        // already grant and the only thing this role exists to add — it
        // is what lets that user pass routes/api.php's
        // permission:manage-accounts gate on /api/admin/accounts at all.
        // Getting past that gate does NOT by itself mean unrestricted
        // access to every account: AccountController's own
        // account_type-driven scoping (TenantIsolationMiddleware +
        // AccountController::callerAgentScopeId(), both Phase 1) confines
        // an Agent to its own agent_id tree regardless of holding this
        // permission platform-wide by role name.
        'agent' => [
            'manage-accounts',
            // Tiered Template Approval Workflow for 3-Tier Hierarchy —
            // lets an Agent reach the SAME /api/message-templates* routes
            // Super Admin uses (index/store/update/approve/reject), which
            // sit outside tenant.isolation exactly like /api/admin/accounts
            // above. MessageTemplateController enforces the actual
            // Agent-vs-Agent ownership scoping itself (mirroring
            // AccountController::callerAgentScopeId()), and explicitly
            // excludes an Agent from destroy() and the Super-Admin-only
            // /admin/templates/{id}/test endpoint (which fires through
            // Account::platformDevice(), the SUPER ADMIN's own WhatsApp
            // connection — an Agent must never be able to spend sends on
            // that device). See TemplateService's docblock for the full
            // approval-routing design.
            'manage-templates',
        ],
        // Social Media Marketing & Meta Ads Automation Expansion (Phase 1).
        // Deliberately restricted to ONLY these four permissions — this
        // role has no access to WhatsApp, Billing, or Developer settings
        // (see AppLayout.tsx's nav gating and routes/api.php's route
        // groups, neither of which grants a social_marketer route on any
        // of those permissions). NOT granted view-audit-logs, unlike the
        // three default roles above — a disclosed deviation from this
        // seeder's own "every role can see login history" precedent,
        // since the spec explicitly calls this role "restricted access".
        // Revisit if Super Admin wants social_marketer to see their own
        // login history too.
        'social_marketer' => [
            'manage-social-accounts',
            'launch-meta-ads',
            'manage-social-leads',
            // Phase 6 CRM Hardening (Issue 1) — same reasoning as
            // 'admin' above: this role already reached the CRM through
            // manage-social-leads, so granting the split-out permission
            // to exactly the roles that already had access keeps
            // effective authorization identical.
            'manage-crm',
            'view-social-analytics',
            // Phase 4 — see PERMISSIONS' comment for why this is separate
            // from manage-social-leads.
            'manage-comment-automation',
            // Client Management, Team Users, Dynamic RBAC Sidebar & Global
            // Table Filters refactor — the spec explicitly lists "Team
            // Users" as a page this role must see. Deliberately still NOT
            // given manage-roles/manage-subscriptions/etc. — only enough
            // to see and manage the Team Users page itself, consistent
            // with this role's existing "restricted access" scope (see
            // this array's own comment above re: view-audit-logs).
            'manage-team',
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
