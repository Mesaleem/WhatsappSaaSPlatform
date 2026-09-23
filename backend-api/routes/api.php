<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\RouteMasterController;
use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\WhatsAppController;
use App\Http\Controllers\Api\MetaConfigController;
use App\Http\Controllers\Api\MetaWebhookController;
use App\Http\Controllers\Api\PaymentAlertController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\MessageLogController;
use App\Http\Controllers\Api\MessageDispatchLogController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\PaymentGatewayController;
use App\Http\Controllers\Api\PlanManagementController;
use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\Admin\GatewaySettingsController;
use App\Http\Controllers\Api\Admin\MailSettingsController;
use App\Http\Controllers\Api\ApiKeyController;
use App\Http\Controllers\Api\WebhookSubscriptionController;
use App\Http\Controllers\Api\V1\ExternalAlertController;
use App\Http\Controllers\Api\V1\TemplateMessageController;
use App\Http\Controllers\Api\V1\GroupController as V1GroupController;
use App\Http\Controllers\Api\V1\UnifiedMessageController;
use App\Http\Controllers\Api\ClientApiKeyController;
use App\Http\Controllers\Api\MessageTemplateController;
use App\Http\Controllers\Api\Internal\WhatsAppStatusController;
use App\Http\Controllers\Api\Internal\WhatsAppInboundController;
use App\Http\Controllers\Api\ChatbotRuleController;
use App\Http\Controllers\Api\WhatsAppFlowController;
use App\Http\Controllers\Api\ChatbotLogController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\QuotaRequestController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\InAppNotificationController;
use App\Http\Controllers\Api\NotificationTemplateController;
use App\Http\Controllers\Api\MailLogController;
use App\Http\Controllers\Api\NotificationBroadcastController;
use App\Http\Controllers\Api\SocialAuthController;
use App\Http\Controllers\Api\AdCampaignController;
use App\Http\Controllers\Api\OrganicPostController;
use App\Http\Controllers\Api\CommentAutomationRuleController;
use App\Http\Controllers\Api\SocialInboxController;
use App\Http\Controllers\Api\SocialWebhookController;
use App\Http\Controllers\Api\Admin\SocialGatewayController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\CrmLeadController;
use App\Http\Controllers\Api\CrmContactController;
use App\Http\Controllers\Api\CrmAssigneeController;
use App\Http\Controllers\Api\CrmPipelineController;
use App\Http\Controllers\Api\CrmTagController;
use App\Http\Controllers\Api\CrmLeadBulkController;
use App\Http\Controllers\Api\CrmAnalyticsController;
use App\Http\Controllers\Api\V1\CrmLeadController as V1CrmLeadController;
use App\Http\Controllers\Api\AICopywriterController;
use App\Http\Controllers\Api\SocialMediaController;
use App\Http\Controllers\Api\SocialReportController;
use App\Http\Controllers\Api\ContactGroupController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);

// Social/Ads Launcher Overhaul — Step 2. Publicly fetchable ad-creative
// media (images/videos a tenant uploaded) — see SocialMediaController::
// show()'s docblock for why this is deliberately NOT behind auth:sanctum.
Route::get('/media/social/{path}', [SocialMediaController::class, 'show'])
    ->where('path', '.*')
    ->name('social.media.show');

// Service-to-service webhook from qr-engine-service. Deliberately NOT under
// auth:sanctum — there is no user, only a shared secret (VerifyInternalSecret).
Route::middleware('internal.secret')->post('/internal/whatsapp-status', [WhatsAppStatusController::class, 'update']);

// Module 10: Baileys-side inbound-message receiver — see
// WhatsAppInboundController's docblock for the disclosed qr-engine-service
// gap (no listener yet calls this). Same internal.secret gate as the
// status endpoint above; still no Laravel user/session.
Route::middleware('internal.secret')->post('/internal/whatsapp-inbound', [WhatsAppInboundController::class, 'handle']);

// Module 5: Meta Cloud API webhook receiver. Public — Meta calls these
// directly with no Laravel session and no knowledge of our internal
// shared secret. GET is protected by the per-tenant hub_verify_token
// handshake; POST processing gaps (signature verification, persistence)
// are disclosed in MetaWebhookController and the Module 5 report.
// Production Polish — see AppServiceProvider's 'meta-webhook' limiter
// docblock for the verified root cause (this app's 'api' middleware
// group has NO throttle at all without this).
Route::middleware('throttle:meta-webhook')->group(function () {
    Route::get('/webhooks/meta', [MetaWebhookController::class, 'verify']);
    Route::post('/webhooks/meta', [MetaWebhookController::class, 'handle']);
});

// Social Media Marketing & Meta Ads Automation Expansion (Phase 1).
// /social/callback/{provider} is the OAuth redirect_uri the PROVIDER
// (e.g. Meta) sends the popup window back to — public, no Laravel
// session, tenant identity travels in the encrypted `state` query param
// instead (see SocialAuthController::callback()'s docblock). Distinct
// from /social/webhook/{provider} below (Lead Ads / Page event
// subscriptions) even though the literal spec named only one path for
// both concerns — see SocialWebhookController's docblock.
// Production Polish — same 'meta-webhook' throttle as /webhooks/meta
// above; these three are equally public/unauthenticated.
Route::middleware('throttle:meta-webhook')->group(function () {
    Route::get('/social/callback/{provider}', [SocialAuthController::class, 'callback']);
    Route::get('/social/webhook/{provider}', [SocialWebhookController::class, 'verify']);
    Route::post('/social/webhook/{provider}', [SocialWebhookController::class, 'handle']);
});

// Module 8: Razorpay/Stripe webhook receivers. Public — the gateways call
// these directly with no Laravel session and no bearer token; authenticity
// comes ONLY from the HMAC signature header, verified against the RAW
// request body inside PaymentWebhookController before anything is trusted.
// This is the source-of-truth path for production money movement (see
// PaymentWebhookController's docblock and the Module 8 report).
Route::post('/webhooks/razorpay', [PaymentWebhookController::class, 'razorpay']);
Route::post('/webhooks/stripe', [PaymentWebhookController::class, 'stripe']);

// Module 9: public external Developer API. Authenticated by
// AuthenticateApiKey (Bearer <api_key>, SHA-256 hash lookup against
// api_keys.key_hash) — NOT auth:sanctum, since the caller is an external
// system with no Laravel user/session. Deliberately sits OUTSIDE the
// auth:sanctum group below for that reason. Rate-limited per-account via
// the 'external-api' limiter (AppServiceProvider::boot()), which reads
// the account's api_rate_limit_per_minute setting — auth.apikey MUST run
// first so api_account_id is resolved before the limiter callback fires.
// Phase 3 Task 5 -- 'idempotency' is appended LAST (innermost) so it runs
// after auth.apikey has resolved api_account_id (a key is scoped per
// account) and after throttle:external-api (rate limits unchanged). It is
// a no-op for any request that sends no Idempotency-Key header, so every
// existing client of these three routes is unaffected.
Route::middleware(['log.apirequest', 'auth.apikey', 'throttle:external-api', 'idempotency'])->prefix('v1')->group(function () {
    Route::post('/messages/send-payment-alert', [ExternalAlertController::class, 'sendPaymentAlert']);
    // Dynamic Templates & Variables System.
    Route::post('/messages/send-template', [TemplateMessageController::class, 'send']);
    // Group Messaging Phase 4 — Developer Send Message API. Same
    // auth.apikey/throttle:external-api gate as the two routes above;
    // recipient_type-based routing (individual vs group) happens
    // inside the controller action itself, not at the route level.
    Route::post('/send-message', [TemplateMessageController::class, 'sendMessage']);

    /*
     * Phase 6 CRM Hardening Round 2, Issue 1 — external CRM lead intake.
     *
     * Inherits this group's existing contract unchanged: log.apirequest
     * (every call logged exactly once), auth.apikey (the key resolves the
     * tenant), throttle:external-api (per-account rate limit) and
     * idempotency (Idempotency-Key honoured the same way as on the send
     * endpoints). capability.apikey:crm is layered on top — the same
     * AccessControlService::canTenant() check the tenant API uses, reading
     * api_account_id because this group deliberately has no
     * tenant.isolation. See Api\V1\CrmLeadController for the full
     * request/response documentation.
     *
     * Deliberately create-only: the message-sending endpoints above are
     * untouched and still create no CRM leads.
     *
     * Phase 6 CRM Task 11 — the gates now match /api/crm/* exactly:
     * subscription.apikey (writes need an active subscription, reads do
     * not), module.apikey:lead_crm (the Super Admin's toggle) and
     * capability.apikey:crm (the plan). Four single-lead operations join
     * the intake: read, status, assignee, tag attach/detach. No list,
     * delete, contact, pipeline, tag-CRUD or bulk endpoint on /v1. Every
     * {id}/{tag} is numeric (anything else is 404).
     */
    Route::middleware(['subscription.apikey', 'module.apikey:lead_crm', 'capability.apikey:crm'])
        ->prefix('crm/leads')
        ->group(function () {
            Route::post('/', [V1CrmLeadController::class, 'store']);
            Route::get('/{id}', [V1CrmLeadController::class, 'show'])->whereNumber('id');
            Route::patch('/{id}/status', [V1CrmLeadController::class, 'status'])->whereNumber('id');
            Route::patch('/{id}/assignee', [V1CrmLeadController::class, 'assignee'])->whereNumber('id');
            Route::post('/{id}/tags/{tag}', [V1CrmLeadController::class, 'attachTag'])->whereNumber(['id', 'tag']);
            Route::delete('/{id}/tags/{tag}', [V1CrmLeadController::class, 'detachTag'])->whereNumber(['id', 'tag']);
        });
});

// Developer API Platform for WhatsApp Group Creation & Unified
// Messaging -- a SEPARATE dual-factor-authenticated (X-API-KEY +
// X-API-SECRET) tier, deliberately NOT added to the auth.apikey group
// above: every pre-existing route in that group keeps its original
// single-factor contract untouched (see ApiAuthMiddleware's own
// docblock for the full rationale). Shares the same 'external-api'
// rate limiter — ApiAuthMiddleware resolves api_account_id the same way
// AuthenticateApiKey does, before the limiter callback fires.
// Phase 3 Task 5 -- same optional 'idempotency' guard as the group above,
// innermost for the same reasons (ApiAuthMiddleware resolves
// api_account_id before it runs).
Route::middleware(['log.apirequest', 'auth.apisecret', 'throttle:external-api', 'idempotency'])->prefix('v1/whatsapp')->group(function () {
    Route::post('/groups/create', [V1GroupController::class, 'create']);
    Route::post('/messages/send', [UnifiedMessageController::class, 'send']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::patch('/auth/profile', [AuthController::class, 'updateProfile']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Role-Based Login Audit Logging Architecture / Advanced Broadcast
    // Engine — a user's own in-app notification inbox. Deliberately
    // outside tenant.isolation/subscription.guard (see
    // InAppNotificationController's docblock): a user whose subscription
    // just lapsed must still be able to see the broadcast that told them so.
    Route::prefix('notifications/inbox')->group(function () {
        Route::get('/', [InAppNotificationController::class, 'index']);
        Route::get('/unread-count', [InAppNotificationController::class, 'unreadCount']);
        Route::post('/{id}/read', [InAppNotificationController::class, 'markRead']);
        Route::post('/read-all', [InAppNotificationController::class, 'markAllRead']);
    });

    // Module 8: self-service billing & checkout. Deliberately under
    // tenant.isolation ONLY — NOT subscription.guard. subscription.guard
    // 403s any tenant whose subscription is expired/inactive, which is
    // EXACTLY the tenant who most needs to reach checkout to renew; nesting
    // these routes inside that guard would permanently lock out anyone
    // whose plan lapses. Invoice history must also stay visible after
    // expiry. permission:manage-subscriptions is already held by Admin
    // (RolePermissionSeeder) and is the correct gate on its own.
    Route::middleware(['tenant.isolation', 'permission:manage-subscriptions', 'module.guard:billing'])->prefix('billing')->group(function () {
        Route::get('/plans', [PaymentGatewayController::class, 'plans']);
        Route::post('/create-order', [PaymentGatewayController::class, 'createOrder']);
        Route::post('/verify-payment', [PaymentGatewayController::class, 'verifyPayment']);
        Route::get('/invoices', [BillingController::class, 'index']);
        Route::get('/invoices/{id}/pdf', [BillingController::class, 'pdf']);
        // Billing & Plans Module Overhaul — Super Admin Billing Overview.
        Route::get('/client-summary', [BillingController::class, 'clientSummary']);
        // Agent Commission Foundation — same tenant.isolation +
        // permission:manage-subscriptions + module.guard:billing gate as
        // client-summary above; BillingController::commissions() applies
        // the actual Super-Admin-vs-Agent scoping.
        Route::get('/commissions', [BillingController::class, 'commissions']);

        // Agent Commission Payout Ledger — same tenant.isolation +
        // permission:manage-subscriptions + module.guard:billing gate;
        // BillingController::payouts()/storePayout()/updatePayoutStatus()
        // apply the actual Super-Admin-vs-Agent scoping and the explicit
        // Super-Admin-only guard on the two mutating actions.
        Route::get('/payouts', [BillingController::class, 'payouts']);
        Route::post('/payouts', [BillingController::class, 'storePayout']);
        Route::patch('/payouts/{id}/status', [BillingController::class, 'updatePayoutStatus']);
    });

    // Quota Exhaustion Request Workflow — Client Admin submits a top-up
    // request. Deliberately placed in the SAME group as /billing/* above
    // (tenant.isolation ONLY, NOT subscription.guard) for the identical
    // reason: this is most needed exactly when the subscription has just
    // flipped to 'exhausted', and subscription.guard would 403 the very
    // request meant to fix that. See QuotaRequestController::store()'s
    // docblock.
    Route::middleware(['tenant.isolation', 'permission:manage-subscriptions'])
        ->post('/quota-requests/store', [QuotaRequestController::class, 'store']);

    // Dynamic Templates & Variables System — Profile/Account Settings'
    // "Client API Key" section. Gated by manage-developer-settings, the
    // same permission tier as the Developer Portal (ApiKeyController) —
    // regenerating an account's shared API secret is an administrative
    // action, not something the plain 'user' role should be able to do.
    Route::middleware(['tenant.isolation', 'permission:manage-developer-settings'])->prefix('account/api-key')->group(function () {
        Route::get('/', [ClientApiKeyController::class, 'show']);
        Route::post('/regenerate', [ClientApiKeyController::class, 'regenerate']);
    });

    // ==========================================================================
    // ARCHITECTURE ENFORCER — Mandatory Module Gating Rule (added this session,
    // applies going forward; see the disclosed note below about existing routes).
    //
    // Every NEW route/sub-route added inside this tenant-authenticated group
    // MUST be reachable only when the tenant's own `allowed_modules` toggle
    // permits it — never as a permission-only-gated, globally-exposed
    // feature. Concretely, when adding a route here:
    //   1. Wrap it (or its group) in `module.guard:<slug>` — see
    //      `module.guard:chatbot` / `module.guard:meta_ads` /
    //      `module.guard:social_accounts` below for the pattern — using a
    //      real slug from `Account::MODULES` (never invent one; check that
    //      constant first).
    //   2. Give the matching frontend nav item the SAME slug via
    //      `requiresModule` (AppLayout.tsx) and the SAME slug via `module`
    //      on its `<ProtectedRoute>` (App.tsx) — sidebar visibility, the
    //      route guard, and the API itself must all agree, or a module
    //      "disabled" for a tenant is disabled in name only. This is the
    //      exact class of bug fixed twice this session (Social Suite nav
    //      items missing `requiresModule`; Dashboard widgets checking only
    //      `module_assignment`/permission, never `hasModule()`).
    //
    // [Disclosed, not silently fixed here]: this rule is NOT retroactively
    // applied to every existing route below. Several already ship without
    // `module.guard` — e.g. `permission:manage-subscriptions` (billing,
    // ~line 146), `permission:manage-team` (team, ~line 400),
    // `permission:view-analytics` (analytics, ~line 432) — by an earlier,
    // separate design decision that relies on the FRONTEND's own
    // `requiresModule`/`module` gates as the only enforcement layer, not a
    // backend one. That is a real, pre-existing inconsistency with the rule
    // above, not something this change silently papered over — retrofitting
    // `module.guard` onto every one of those routes is a larger, separately
    // scoped change (broader blast radius, real regression risk) than this
    // session's request covers. Flag it explicitly if you want that done.
    // ==========================================================================
    Route::middleware(['tenant.isolation', 'subscription.guard'])->group(function () {
        Route::middleware('permission:manage-roles')->group(function () {
            Route::get('/roles', [RoleController::class, 'index']);

            // P0 Security Fix (2026-09-16) — [Bugfix, disclosed, root
            // cause]: Spatie roles are GLOBAL (config/permission.php has
            // no 'teams' scoping), but 'manage-roles' is a normal
            // permission that RolePermissionSeeder also grants to the
            // 'admin' role (Client Admin's own account-management
            // permission set) — not just 'super_admin'. Since
            // RoleController::store()/update() call $role->syncPermissions()
            // directly on Role rows shared platform-wide, any Client
            // Admin holding 'admin' could previously rename or
            // re-permission GLOBAL roles like 'admin'/'user' themselves
            // (every tenant's Admin/User roles at once), a cross-tenant
            // privilege-escalation path — permission:manage-roles alone
            // was never a sufficient gate for a global mutation, only for
            // a per-tenant one. `role:super_admin` is this codebase's own
            // existing, established mechanism for exactly this class of
            // platform-global-only endpoint (already used identically for
            // GET /admin/whatsapp/devices and the /admin/whatsapp/self-device/*
            // group below) — reused here rather than inventing a new
            // authorization path. Layered ON TOP of the existing
            // permission:manage-roles gate above (not replacing it) so
            // both must pass; Super Admin already holds 'manage-roles'
            // (RolePermissionSeeder's PERMISSIONS constant) AND is exempt
            // from every permission/role check anyway via
            // AuthServiceProvider's Gate::before() bypass, so this is a
            // pure narrowing for every other role with zero behavior
            // change for Super Admin. GET /roles (read-only, listing
            // roles+permissions) is deliberately left on
            // permission:manage-roles alone — the audit finding and this
            // fix's scope are about MUTATING global role definitions, not
            // about who can view them.
            Route::middleware('role:super_admin')->group(function () {
                Route::post('/roles', [RoleController::class, 'store']);
                Route::put('/roles/{id}', [RoleController::class, 'update']);
            });
        });

        // Module 4: WhatsApp (QR engine) session management — bridges to
        // qr-engine-service. account_id is resolved from the authenticated
        // user server-side; never accepted from the client.
        Route::middleware('module.guard:whatsapp_setup')->prefix('whatsapp')->group(function () {
            Route::get('/status', [WhatsAppController::class, 'status']);
            Route::post('/start-session', [WhatsAppController::class, 'startSession']);
            Route::post('/logout', [WhatsAppController::class, 'logout']);
        });

        // Module 5: Meta Cloud API credential vault. Admin-only (not
        // manage-accounts) — this is per-tenant configuration, distinct
        // from platform-level account provisioning.
        Route::middleware(['role:admin', 'module.guard:whatsapp_setup'])->prefix('whatsapp/meta-config')->group(function () {
            Route::get('/', [MetaConfigController::class, 'show']);
            Route::post('/', [MetaConfigController::class, 'store']);
            Route::post('/test-connection', [MetaConfigController::class, 'testConnection']);
        });

        // Module 9: Developer Portal — API key management + outbound webhook
        // subscriptions. One shared permission (manage-developer-settings)
        // since the frontend presents both as tabs of a single page.
        Route::middleware(['permission:manage-developer-settings', 'module.guard:developer_api'])->prefix('developer')->group(function () {
            Route::get('/api-keys', [ApiKeyController::class, 'index']);
            Route::post('/api-keys', [ApiKeyController::class, 'store']);
            Route::delete('/api-keys/{id}', [ApiKeyController::class, 'destroy']);
            // Developer API Platform for WhatsApp Group Creation & Unified
            // Messaging -- backfills/rotates an existing key's dual-factor
            // secret without touching its key_hash (see
            // ApiKeyController::regenerateSecret()'s own docblock).
            Route::post('/api-keys/{id}/regenerate-secret', [ApiKeyController::class, 'regenerateSecret']);

            Route::get('/webhooks', [WebhookSubscriptionController::class, 'index']);
            Route::post('/webhooks', [WebhookSubscriptionController::class, 'store']);
            Route::delete('/webhooks/{id}', [WebhookSubscriptionController::class, 'destroy']);
            Route::post('/webhooks/{id}/test', [WebhookSubscriptionController::class, 'test']);
            Route::get('/webhooks/{id}/deliveries', [WebhookSubscriptionController::class, 'deliveries']);
        });

        // Module 10: Chatbot Rule Builder + execution logs. A dedicated
        // permission (manage-chatbot) rather than reusing
        // manage-developer-settings — see RolePermissionSeeder's docblock.
        // Client Admin Granular Permission Matrix — WhatsApp Module
        // (View/Create/Edit/Delete map 1:1 onto this controller's CRUD;
        // 'Send Messages' is gated separately below under /alerts, reusing
        // the existing send-messages permission — see
        // TeamController::MANAGED_PERMISSIONS' docblock). Each route below
        // accepts EITHER the pre-existing coarse 'manage-chatbot'
        // permission (so 'admin' — and any other role/user who already
        // held it — is completely unaffected by this change) OR the new
        // matching granular permission (so a user granted ONLY
        // 'whatsapp.view' via the new per-user matrix can view rules
        // without also being able to create/edit/delete them).
        Route::prefix('chatbot')->group(function () {
            Route::middleware('permission:manage-chatbot|whatsapp.view')->group(function () {
                Route::get('/rules', [ChatbotRuleController::class, 'index']);
                Route::get('/logs', [ChatbotLogController::class, 'index']);
            });
            Route::middleware('permission:manage-chatbot|whatsapp.create')->post('/rules', [ChatbotRuleController::class, 'store']);
            Route::middleware('permission:manage-chatbot|whatsapp.edit')->group(function () {
                Route::put('/rules/{id}', [ChatbotRuleController::class, 'update']);
                Route::post('/rules/{id}/toggle', [ChatbotRuleController::class, 'toggle']);
            });
            Route::middleware('permission:manage-chatbot|whatsapp.delete')->delete('/rules/{id}', [ChatbotRuleController::class, 'destroy']);
        });

        // Module 5 — No-Code WhatsApp Journey Builder. Reuses the SAME
        // manage-chatbot permission tier (+ the existing granular
        // whatsapp.view/create/edit/delete permissions already seeded
        // above) rather than introducing a new permission slug — a
        // Journey is, functionally, an advanced/multi-turn evolution of
        // the chatbot_rules feature this whole route group already
        // gates, and every role that can manage chatbot rules today
        // manages journeys too without any seeder change (avoiding
        // ANOTHER pending "re-run the seeder" manual step on top of the
        // two already-pending migrations — see this session's summary
        // report). Gated on the SAME 'chatbot' module slug for the same
        // reason.
        //
        // Phase 7 Task 1.5 — + capability.guard:journey_automation on EVERY
        // journey route (list/show/sessions, create, update/toggle/test,
        // cancel, delete): what the tenant's plan sold, the same
        // CAPABILITY_NOT_ENTITLED 403 as the CRM routes. The module guard,
        // the per-route permissions and the per-node entitlement check on
        // save (JourneyNodeAuthorizer) all still apply. Super Admin is
        // unchanged (the guard bypasses, as for every capability gate).
        Route::middleware(['module.guard:chatbot', 'capability.guard:journey_automation'])->prefix('whatsapp/flows')->group(function () {
            Route::middleware('permission:manage-chatbot|whatsapp.view')->group(function () {
                Route::get('/', [WhatsAppFlowController::class, 'index']);
                Route::get('/{id}', [WhatsAppFlowController::class, 'show']);
                Route::get('/{id}/sessions', [WhatsAppFlowController::class, 'sessions']);
                // Phase 7 Task 2 — immutable journey versions.
                Route::get('/{id}/versions', [WhatsAppFlowController::class, 'versions'])->whereNumber('id');
                Route::get('/{id}/versions/{versionId}', [WhatsAppFlowController::class, 'showVersion'])->whereNumber(['id', 'versionId']);
            });
            Route::middleware('permission:manage-chatbot|whatsapp.create')->post('/', [WhatsAppFlowController::class, 'store']);
            Route::middleware('permission:manage-chatbot|whatsapp.edit')->group(function () {
                Route::put('/{id}', [WhatsAppFlowController::class, 'update']);
                Route::post('/{id}/toggle', [WhatsAppFlowController::class, 'toggle']);
                Route::post('/{id}/test', [WhatsAppFlowController::class, 'test']);
                // Phase 7 Task 1 — stop one open (awaiting-reply or delayed) run.
                Route::post('/{id}/sessions/{sessionId}/cancel', [WhatsAppFlowController::class, 'cancelSession']);
                Route::post('/{id}/versions/{versionId}/publish', [WhatsAppFlowController::class, 'publishVersion'])->whereNumber(['id', 'versionId']);
            });
            Route::middleware('permission:manage-chatbot|whatsapp.delete')->delete('/{id}', [WhatsAppFlowController::class, 'destroy']);
        });

        // Social Media Marketing & Meta Ads Automation Expansion (Phase 1).
        // Gated the same tier as chatbot/team above: an active-subscription
        // admin-console feature. redirect()/index()/bind()/destroy() all
        // resolve the tenant via ResolvesTenantAccount, same as every other
        // route in this group.
        Route::middleware('permission:manage-social-accounts')->prefix('social')->group(function () {
            Route::get('/accounts', [SocialAuthController::class, 'index']);
            Route::delete('/accounts/{id}', [SocialAuthController::class, 'destroy']);
            Route::post('/accounts/bind', [SocialAuthController::class, 'bind']);
            Route::get('/oauth/{provider}/redirect', [SocialAuthController::class, 'redirect']);
        });

        // Social Media Marketing & Meta Ads Automation Expansion (Phase 3).
        // Meta Ads Launcher & Auto-Budget Guard — separate permission tier
        // (launch-meta-ads) from manage-social-accounts above: connecting a
        // Page/Ad Account is asset administration, launching a real,
        // budget-spending campaign is a distinct, higher-stakes action a
        // Super Admin may want to grant separately. Seeded onto
        // social_marketer already in Phase 1's RolePermissionSeeder.
        // Client Management, User Creation, Multi-Role Permissions &
        // Feature Module Checklists refactor — explicit requirement:
        // block every /api/social/ads/* route for a client with the
        // 'meta_ads' module disabled. 'module.guard:meta_ads' runs
        // after permission:launch-meta-ads, inside the same
        // tenant.isolation/subscription.guard group both already sit
        // in. Disclosed, deliberately NOT extended to /social/ai/* (the
        // AI Copywriter embedded in this page's Launch Wizard) — the
        // spec named only /api/social/ads/*; see the audit report.
        // Client Admin Granular Permission Matrix — Social Ads Module
        // (View/Launch Ads/Edit Budget map 1:1 onto index/launch/
        // updateCplThreshold below; 'social_ads.delete_rules' has no
        // corresponding action here — AdCampaignController has no
        // delete-campaign endpoint — disclosed in
        // TeamController::MANAGED_PERMISSIONS' docblock and this
        // refactor's audit report; NOT invented here). pause/resume are
        // deliberately NOT covered by the new granular permissions (not
        // named in the spec's 4-item checklist) — they stay gated on
        // 'launch-meta-ads' alone, unchanged. module.guard:meta_ads stays
        // a group-level gate, applying to every action exactly as before.
        Route::middleware('module.guard:meta_ads')->prefix('social/ads')->group(function () {
            Route::middleware('permission:launch-meta-ads|social_ads.view')->get('/', [AdCampaignController::class, 'index']);
            Route::middleware('permission:launch-meta-ads|social_ads.launch')->post('/launch', [AdCampaignController::class, 'launch']);
            Route::middleware('permission:launch-meta-ads')->post('/{id}/pause', [AdCampaignController::class, 'pause']);
            Route::middleware('permission:launch-meta-ads')->post('/{id}/resume', [AdCampaignController::class, 'resume']);
            Route::middleware('permission:launch-meta-ads|social_ads.edit_budget')->patch('/{id}/cpl-threshold', [AdCampaignController::class, 'updateCplThreshold']);
        });

        // Social Media Marketing & Meta Ads Automation Expansion (Phase 4).
        // Ad Comment Auto-Responder — rule CRUD (CommentRulesPage).
        Route::middleware(['permission:manage-comment-automation', 'module.guard:comment_automation'])->prefix('social/comment-rules')->group(function () {
            Route::get('/', [CommentAutomationRuleController::class, 'index']);
            Route::post('/', [CommentAutomationRuleController::class, 'store']);
            Route::put('/{id}', [CommentAutomationRuleController::class, 'update']);
            Route::delete('/{id}', [CommentAutomationRuleController::class, 'destroy']);
        });

        // Social Media Marketing & Meta Ads Automation Expansion (Phase 4).
        // Unified Social Inbox — gated on manage-social-leads (same tier
        // as the Instant Lead Bridge it surfaces alongside FB/IG DMs;
        // see SocialInboxController's docblock).
        Route::middleware(['permission:manage-social-leads', 'module.guard:social_inbox'])->prefix('social/inbox')->group(function () {
            Route::get('/threads', [SocialInboxController::class, 'threads']);
            Route::get('/threads/{id}/messages', [SocialInboxController::class, 'messages']);
            Route::post('/send', [SocialInboxController::class, 'send']);
        });

        // Social Media Marketing & Meta Ads Automation Expansion —
        // Final Phase. Instant Lead CRM — read-only list/detail over the
        // SAME Lead rows the Unified Social Inbox already surfaces as
        // synthetic 'lead:' threads (Phase 2's leads table). NOT part of
        // the literal Phase 1-4 spec text, but explicitly required by
        // this final phase's route/nav audit item 3 ("Instant Lead CRM
        // (/social/leads)") — no backend or frontend for it existed
        // before this. Same permission tier as the Inbox above
        // (manage-social-leads), since both surface the same underlying
        // data.
        Route::middleware(['permission:manage-social-leads', 'module.guard:lead_crm'])->prefix('social/leads')->group(function () {
            Route::get('/', [LeadController::class, 'index']);
            Route::get('/{id}', [LeadController::class, 'show']);
        });

        /*
         * Phase 6 — CRM, Task 2. The CRM lead API (crm_leads/contacts,
         * created in Task 1). Entirely separate from /social/leads
         * directly above, which stays a read-only view over the existing
         * Meta/journey `leads` capture table — same word, different
         * domain, untouched by this phase.
         *
         * THREE GATES, each a different question, none redundant:
         *  - permission:manage-crm — RBAC: may this USER touch CRM data?
         *    Phase 6 Hardening (Issue 1) SPLIT THIS OUT of
         *    manage-social-leads, which these routes used in Task 2. A
         *    tenant's full CRM contact book is broader and more
         *    sensitive than a Meta Lead Ads capture list, and a Super
         *    Admin must be able to grant one without the other. The
         *    split changes the permission model only, not anyone's
         *    effective access: manage-crm is seeded onto exactly the
         *    roles that already held manage-social-leads (super_admin,
         *    admin, social_marketer) — see the
         *    add_manage_crm_permission migration.
         *    /api/social/leads directly above deliberately KEEPS
         *    manage-social-leads and is untouched by that split.
         *  - module.guard:lead_crm — the Super Admin's per-tenant toggle,
         *    mandatory for every new route in this group per the
         *    ARCHITECTURE ENFORCER rule above. 'lead_crm' is an existing
         *    Account::MODULES slug (no slug was invented) and is already
         *    what gates /social/leads.
         *  - capability.guard:crm — what the tenant's PLAN actually sold
         *    them (account_entitlements, via AccessControlService). A
         *    Super Admin can have the module on while the plan never
         *    included CRM; that must still be refused, and frontend route
         *    hiding is not a gate.
         * Order matches the chain the hardening brief specifies:
         * module -> permission -> capability, inside the group's own
         * tenant.isolation + subscription.guard.
         *
         * No /v1 prefix: /api/v1/* in this codebase is exclusively the
         * external, API-key-authenticated Developer API. These are
         * session-authenticated tenant-UI routes and belong here, the
         * same correction already recorded for /api/groups above.
         */
        /*
         * crm.target (post-Phase-6 fix) runs first: a Super Admin with no
         * client selected acts on their own platform CRM account, and a
         * Super Admin's target account must itself hold lead_crm + crm.
         * No-op for every other caller. See EnsureCrmTargetAccount.
         */
        Route::middleware(['crm.target', 'module.guard:lead_crm', 'permission:manage-crm', 'capability.guard:crm'])
            ->prefix('crm')
            ->group(function () {
                /*
                 * Task 9 — whereNumber() on every {id}: previously a
                 * non-numeric id (e.g. DELETE /crm/leads/bulk, which now
                 * sits beside a real /bulk/* prefix) reached an int-typed
                 * controller parameter and 500ed. It is now a plain 404.
                 */
                Route::prefix('leads')->group(function () {
                    Route::get('/', [CrmLeadController::class, 'index']);
                    Route::post('/', [CrmLeadController::class, 'store']);
                    Route::get('/{id}', [CrmLeadController::class, 'show'])->whereNumber('id');
                    Route::match(['put', 'patch'], '/{id}', [CrmLeadController::class, 'update'])->whereNumber('id');
                    // Phase 6 Hardening (Issue 9). Never touches the
                    // Contact — see the controller action's docblock.
                    Route::delete('/{id}', [CrmLeadController::class, 'destroy'])->whereNumber('id');
                    /*
                     * Task 3 — explicit Contact reassignment. Its own
                     * action endpoint rather than a field on the update
                     * route above, so a general edit can never re-point a
                     * lead at another person by accident. Same three
                     * gates as every route in this group.
                     */
                    Route::patch('/{id}/contact', [CrmLeadController::class, 'reassignContact'])->whereNumber('id');
                    /*
                     * Task 4 — the explicit ownership action. Assign,
                     * reassign and unassign all land here; the general
                     * update route above keeps accepting
                     * assigned_user_id for existing clients, and both
                     * funnel through CrmLeadService::changeAssignee().
                     */
                    Route::patch('/{id}/assignee', [CrmLeadController::class, 'assignee'])->whereNumber('id');
                    /*
                     * Task 5 — the explicit lifecycle action. The
                     * general update route above keeps accepting
                     * `status` for existing clients, and both funnel
                     * through CrmLeadService's single status step.
                     */
                    Route::patch('/{id}/status', [CrmLeadController::class, 'status'])->whereNumber('id');

                    /*
                     * Phase 6 CRM Task 9 — bulk operations over up to
                     * CrmBulkLeadSelection::MAX_LEADS (100) leads: the four
                     * operations that exist individually, all or nothing,
                     * one transaction each. Same gates as this group; no
                     * bulk-specific permission. Not on /api/v1. See
                     * CrmLeadBulkController.
                     */
                    Route::prefix('bulk')->group(function () {
                        Route::post('assignee', [CrmLeadBulkController::class, 'assignee']);
                        Route::post('status', [CrmLeadBulkController::class, 'status']);
                        Route::post('tags/attach', [CrmLeadBulkController::class, 'attachTag']);
                        Route::post('tags/detach', [CrmLeadBulkController::class, 'detachTag']);
                    });

                    /*
                     * Phase 6 CRM Task 7 — tag assignment, one tag per
                     * call. Idempotent both ways (200 no-op when already
                     * attached / not attached). Never changes status.
                     * whereNumber: a non-numeric id is a plain 404.
                     */
                    Route::post('/{id}/tags/{tag}', [CrmLeadController::class, 'attachTag'])
                        ->whereNumber(['id', 'tag']);
                    Route::delete('/{id}/tags/{tag}', [CrmLeadController::class, 'detachTag'])
                        ->whereNumber(['id', 'tag']);
                });

                /*
                 * Phase 6 Hardening (Issue 2) — the Contacts API. Same
                 * three gates as leads above, by construction: it is the
                 * same route group, so a future endpoint cannot be added
                 * here with a weaker set by accident.
                 */
                /*
                 * Task 4 — the eligible-assignee selector. Same three
                 * gates as every other CRM route, and deliberately NOT
                 * /api/team/users, which is gated on manage-team and
                 * returns inactive, non-CRM users plus their contact
                 * details. See CrmAssigneeController.
                 */
                Route::get('assignees', [CrmAssigneeController::class, 'index']);

                /*
                 * Task 6 — the Kanban/pipeline view. READ-ONLY: moving a
                 * lead between columns is a lifecycle change and goes
                 * through PATCH /crm/leads/{id}/status, which owns the
                 * transition matrix, the outcome bookkeeping and the
                 * audit trail. Same three gates as every route in this
                 * group; deliberately NOT added to /api/v1.
                 */
                Route::get('pipeline', [CrmPipelineController::class, 'index']);

                /*
                 * Task 12 — CRM analytics. READ-ONLY aggregates over
                 * crm_leads for the resolved account; inherits every gate
                 * of this group (no analytics-specific permission or
                 * capability — CRM entitlement covers it). Not on /api/v1.
                 * See CrmAnalyticsController / CrmAnalyticsService.
                 */
                Route::get('analytics', [CrmAnalyticsController::class, 'index']);

                /*
                 * Phase 6 CRM Task 7 — tenant-scoped lead tags. Inherits
                 * this group's module.guard:lead_crm + permission:manage-crm
                 * + capability.guard:crm and the outer tenant.isolation +
                 * subscription.guard; no tag-specific permission or
                 * capability exists. See CrmTagController.
                 */
                Route::prefix('tags')->group(function () {
                    Route::get('/', [CrmTagController::class, 'index']);
                    Route::post('/', [CrmTagController::class, 'store']);
                    Route::get('/{id}', [CrmTagController::class, 'show'])->whereNumber('id');
                    Route::match(['put', 'patch'], '/{id}', [CrmTagController::class, 'update'])->whereNumber('id');
                    Route::delete('/{id}', [CrmTagController::class, 'destroy'])->whereNumber('id');
                });

                Route::prefix('contacts')->group(function () {
                    Route::get('/', [CrmContactController::class, 'index']);
                    Route::post('/', [CrmContactController::class, 'store']);
                    Route::get('/{id}', [CrmContactController::class, 'show'])->whereNumber('id');
                    Route::match(['put', 'patch'], '/{id}', [CrmContactController::class, 'update'])->whereNumber('id');
                    // Phase 6 Hardening (Issue 10). Refused with 409
                    // while leads or group memberships depend on it.
                    Route::delete('/{id}', [CrmContactController::class, 'destroy'])->whereNumber('id');
                    /*
                     * Round 2, Limitation 6 — Contact merge. POST with the
                     * action in the path, matching this codebase's existing
                     * action-endpoint style (/groups/{id}/recreate,
                     * /whatsapp/flows/{id}/toggle, /api-key/regenerate)
                     * rather than introducing a PATCH-with-verb convention.
                     * Registered AFTER /{id} so the literal segment can
                     * never be shadowed.
                     */
                    Route::post('/{source}/merge/{target}', [CrmContactController::class, 'merge'])->whereNumber(['source', 'target']);
                    // Task 3 — the Contact -> Leads half of the
                    // relationship. Returns CRM leads only, never raw
                    // capture rows.
                    Route::get('/{id}/leads', [CrmContactController::class, 'leads'])->whereNumber('id');
                });
            });

        // Social Media Marketing & Meta Ads Automation Expansion — Final
        // Phase. AI Ad Copywriter — gated the SAME tier as
        // launch-meta-ads (it is embedded in, and only useful from, the
        // Ad Creation Wizard on MetaAdsPage.tsx).
        Route::middleware('permission:launch-meta-ads')->prefix('social/ai')->group(function () {
            Route::post('/generate', [AICopywriterController::class, 'generate']);
        });

        // Social/Ads Launcher Overhaul — Step 2. Multipart media upload
        // for the Ad Creation Wizard's creative step — same permission
        // tier as the AI Copywriter and the launcher itself, since all
        // three are steps of the one wizard. See SocialMediaController.
        Route::middleware('permission:launch-meta-ads')->prefix('social/media')->group(function () {
            Route::post('/upload', [SocialMediaController::class, 'upload']);
        });

        // Social/Ads Launcher Overhaul — Step 3 (Organic Multi-Channel
        // Publishing Engine). Gated on manage-social-accounts + the
        // 'social_accounts' module (NOT launch-meta-ads/'meta_ads' — an
        // organic, budget-free post is asset administration/content
        // publishing, the same tier as connecting the asset itself in
        // Phase 1 above, not a paid-campaign action). Both the
        // permission and module slug already exist and are already
        // seeded/co-assigned (RolePermissionSeeder) — no seeder changes
        // needed. See OrganicPostController/OrganicPublishService.
        Route::middleware(['module.guard:social_accounts', 'permission:manage-social-accounts'])->prefix('social/organic-posts')->group(function () {
            Route::get('/', [OrganicPostController::class, 'index']);
            Route::post('/', [OrganicPostController::class, 'store']);
        });

        // Social Media Marketing & Meta Ads Automation Expansion — Final
        // Phase. White-Label Automated PDF Reporting — read-only
        // reporting surface, gated on view-social-analytics (the existing
        // read-only social reporting permission tier; NOT a new
        // permission, since a monthly report is analytics, not a new
        // write-capability like manage-comment-automation was).
        // /summary (JSON, for SocialReportsPage's recharts) is a
        // disclosed addition beyond the literal spec's single /generate
        // endpoint — the spec explicitly asks the SAME page to also show
        // "monthly metric graphs", which needs data in a chartable shape,
        // not PDF bytes.
        Route::middleware(['permission:view-social-analytics', 'module.guard:reports'])->prefix('social/reports')->group(function () {
            Route::get('/summary', [SocialReportController::class, 'summary']);
            Route::get('/generate', [SocialReportController::class, 'generate']);
        });

        // UI overhaul: Team Users page — tenant Admin managing sub-users
        // under their own account. manage-team already exists in
        // RolePermissionSeeder (Admin only) but was unused until now.
        Route::middleware(['permission:manage-team', 'module.guard:team_management'])->prefix('team')->group(function () {
            Route::get('/users', [TeamController::class, 'index']);
            Route::post('/users', [TeamController::class, 'store']);
            Route::patch('/users/{id}', [TeamController::class, 'update']);
            Route::patch('/users/{id}/toggle', [TeamController::class, 'toggle']);
            Route::delete('/users/{id}', [TeamController::class, 'destroy']);
            Route::get('/roles', [TeamController::class, 'roles']);
            // Client Admin Granular Permission Matrix (/team/permissions
            // on the frontend) — same manage-team gate as the rest of
            // this group, since granting a teammate finer WhatsApp/Social
            // Ads access is itself a team-management action.
            Route::get('/users/{id}/permissions', [TeamController::class, 'permissions']);
            Route::patch('/users/{id}/permissions', [TeamController::class, 'updatePermissions']);
        });

        // Module 6: Payment alert dispatch. send-messages is held by
        // every seeded role (Admin, User) — this is the same permission
        // already gating ordinary WhatsApp usage, not a new privilege tier.
        Route::middleware(['permission:send-messages', 'module.guard:send_alert'])->prefix('alerts')->group(function () {
            Route::post('/send', [PaymentAlertController::class, 'send']);
            Route::post('/bulk-upload', [PaymentAlertController::class, 'bulkUpload']);
            // Dynamic Templates & Variables System — Client Admin Dynamic
            // Form Engine: the approved-templates dropdown and its submit
            // action, both scoped to send-messages (the same permission
            // that already gates ordinary alert sending) rather than a
            // new one.
            Route::get('/message-templates', [MessageTemplateController::class, 'available']);
            Route::post('/send-template', [MessageTemplateController::class, 'send']);
            // Anti-Spam Bulk Dispatch -- one call queues N recipients
            // (recipient_phones: string[]) as individually-delayed,
            // rate-limited jobs instead of sending them all inline. See
            // MessageTemplateController::sendBulk()'s own docblock.
            Route::post('/send-template-bulk', [MessageTemplateController::class, 'sendBulk']);
            // Strict Bulk Messaging Limit & Tier-Based Cooldown -- lets the
            // Send Alert page show its cooldown countdown banner up front,
            // not only after a blocked send attempt. See
            // MessageTemplateController::bulkCooldownStatus()'s own docblock.
            Route::get('/bulk-cooldown-status', [MessageTemplateController::class, 'bulkCooldownStatus']);
            // Tiered Template Approval Workflow for 3-Tier Hierarchy --
            // the entry point rules 1/2 assume exists: a plain Client
            // Admin/User submitting a template REQUEST for their own
            // account (never Super Admin or Agent -- those author
            // directly via the pre-existing /api/message-templates
            // store() below, gated by manage-templates). Same
            // send-messages tier as every other route in this /alerts
            // group -- content-authoring-adjacent, not a new privilege.
            // Status is decided server-side by TemplateService
            // ::resolveCreationStatus() purely from the caller's OWN
            // account.agent_id -- never client-supplied.
            Route::post('/message-templates/request', [MessageTemplateController::class, 'submitRequest']);
            // GET /api/alerts/message-templates/mine -- every template on
            // the caller's own account regardless of status, so a plain
            // Client Admin/User (who can never reach the permission:
            // manage-templates index() below) can see what they've
            // requested and its current review status. Registered BEFORE
            // GET /message-templates/{id}-style routes would ever be
            // added here to avoid an ambiguous /mine vs /{id} match (this
            // group has no such {id} route today, but this ordering
            // guards against one being added later without noticing).
            Route::get('/message-templates/mine', [MessageTemplateController::class, 'myTemplates']);
        });

        // Group Messaging Phase 2: Contact Group Management APIs. Same
        // send-messages permission tier as /alerts above (managing a
        // recipient list is part of the same "can send WhatsApp
        // messages" privilege, not a new one) PLUS module.guard:
        // contact_groups — a paid addon, so even a user who holds
        // send-messages is blocked unless their tenant's allowed_modules
        // includes it (see EnsureModuleEnabledMiddleware's
        // CUSTOM_RESPONSES map for the GROUP_MODULE_DISABLED shape this
        // returns instead of the generic MODULE_DISABLED one). Follows
        // the Architecture Enforcer rule above — module-gated at the
        // API itself, not just the frontend.
        //
        // [Disclosed correction]: the request that created these routes
        // specified /api/v1/groups paths. /v1 in this codebase is
        // exclusively the external, auth.apikey-gated Developer API
        // surface (see the 'auth.apikey' route group above) — nesting
        // session-authenticated tenant-UI routes there would make them
        // unreachable from the web app (which authenticates with a
        // Sanctum bearer token, not an API key). These live under the
        // same tenant.isolation/subscription.guard group as every other
        // internal page instead, at /api/groups* (no /v1).
        Route::middleware(['permission:send-messages', 'module.guard:contact_groups'])->prefix('groups')->group(function () {
            Route::get('/', [ContactGroupController::class, 'index']);
            Route::post('/create', [ContactGroupController::class, 'store']);
            // [New, "select an existing group"]: lists the tenant's REAL
            // WhatsApp groups (via Baileys' groupFetchAllParticipating(),
            // through NativeWhatsAppGroupService) that are not yet
            // imported as a ContactGroup, so the frontend can offer
            // "pick an existing group" alongside "+ Create Group".
            // Registered as a literal path, not '/{id}', so it can never
            // collide with the DELETE/{id} route below.
            Route::get('/available-native', [ContactGroupController::class, 'availableNativeGroups']);
            // [New, "select an existing group"]: adopts one of the
            // groups listed above as a ContactGroup row, importing its
            // real current member list. See
            // NativeGroupCreationService::importExisting()'s docblock
            // for why this never dispatches CreateNativeWhatsAppGroupJob
            // the way /create's native_wa_group path does.
            Route::post('/import-native', [ContactGroupController::class, 'importNative']);
            Route::post('/add-contacts', [ContactGroupController::class, 'addContacts']);
            Route::delete('/{id}', [ContactGroupController::class, 'destroy']);
            // [New, disclosed]: previously there was NO session-authenticated
            // way to send a message to a group at all — GroupMessageDispatcher
            // was only reachable from the external, API-key-gated
            // /api/v1/send-message endpoint. This is the internal, Sanctum
            // counterpart the Send Alert screen now calls. Same
            // permission:send-messages + module.guard:contact_groups gate as
            // every other route in this group — no new permission tier.
            Route::post('/{id}/send-template', [ContactGroupController::class, 'sendTemplate']);
            // [New, disclosed]: one-click response to a native_wa_group
            // stuck at sync_status='failed' (initial creation failure, or
            // a later send failure now also flips this — see
            // ProcessGroupDispatchJob). See recreate()'s own docblock for
            // the disclosed duplicate-group risk this carries.
            Route::post('/{id}/recreate', [ContactGroupController::class, 'recreate']);
        });

        // Module 7: analytics KPIs/charts — same sensitivity tier as
        // ordinary WhatsApp usage (aggregate numbers only, no customer PII).
        Route::middleware('permission:view-analytics')->prefix('analytics')->group(function () {
            Route::get('/summary', [AnalyticsController::class, 'summary']);
            Route::get('/charts', [AnalyticsController::class, 'charts']);
            // Super Admin only (enforced in the controller via is_super_admin,
            // not a separate permission — every seeded role already holds
            // view-analytics). Platform-wide KPIs for the Super Admin Dashboard.
            Route::get('/global-summary', [AnalyticsController::class, 'globalSummary']);
        });

        // Module 7: message logs + exports — gated separately from
        // view-analytics because these expose row-level customer PII
        // (phone numbers, names) rather than aggregates. Not granted to
        // the 'user' role by default — see RolePermissionSeeder.
        Route::middleware('permission:view-logs')->group(function () {
            Route::get('/alerts/logs', [MessageLogController::class, 'index']);
            Route::get('/alerts/logs/{id}', [MessageLogController::class, 'show']);

            Route::prefix('exports')->group(function () {
                Route::get('/csv', [ExportController::class, 'csv']);
                Route::get('/pdf', [ExportController::class, 'pdf']);
            });

            // [New feature, disclosed]: Message Logs / Audit Trail — the
            // new unified view across every dispatch pathway
            // (message_dispatch_logs), deliberately a separate endpoint
            // from /alerts/logs above rather than a rename/replacement of
            // it — see MessageDispatchLogController's own docblock for why.
            // Additionally wrapped in module.guard:analytics (unlike its
            // /alerts/logs sibling above) — this route is new, so it
            // follows the Architecture Enforcer rule documented above:
            // module-gated at the API itself, not just the frontend. No
            // 'whatsapp' module slug exists (Account::MODULES) — 'analytics'
            // matches this route's own frontend module gate (App.tsx /
            // AppLayout.tsx) exactly.
            Route::middleware('module.guard:message_logs')->get('/message-logs', [MessageDispatchLogController::class, 'index']);
        });

        // Role-Based Login Audit Logging Architecture — held by ALL three
        // default roles (RolePermissionSeeder), each scoped differently
        // by AuditLogController::scopedQuery(). Sits inside
        // subscription.guard like view-logs above, not alongside the
        // notification inbox — reading platform audit history is an
        // active-subscription admin-console feature, not a
        // "tell me my account lapsed" notice.
        Route::middleware('permission:view-audit-logs')->prefix('audit-logs')->group(function () {
            Route::get('/', [AuditLogController::class, 'index']);
            Route::get('/csv', [AuditLogController::class, 'csv']);
            Route::get('/pdf', [AuditLogController::class, 'pdf']);
        });

        // Advanced Broadcast Engine + Mail Template Manager. Composing and
        // sending broadcasts is treated the same as manage-chatbot/
        // manage-team above: an active-subscription admin-console feature.
        Route::middleware(['permission:manage-notifications', 'module.guard:notifications'])->group(function () {
            Route::prefix('notification-templates')->group(function () {
                Route::get('/', [NotificationTemplateController::class, 'index']);
                Route::get('/{id}', [NotificationTemplateController::class, 'show']);
                Route::post('/', [NotificationTemplateController::class, 'store']);
                Route::put('/{id}', [NotificationTemplateController::class, 'update']);
                Route::delete('/{id}', [NotificationTemplateController::class, 'destroy']);
            });

            Route::prefix('notification-broadcasts')->group(function () {
                Route::get('/', [NotificationBroadcastController::class, 'index']);
                Route::post('/', [NotificationBroadcastController::class, 'store']);
            });

            // Mail Log — Track Record of Mail Sends. Same permission as
            // the broadcast engine it records, since it's read-only
            // evidence of what that engine did.
            Route::get('/mail-logs', [MailLogController::class, 'index']);
        });
    });

    // Platform admin: account provisioning & billing. Deliberately NOT
    // behind tenant.isolation/subscription.guard — these operate across
    // every tenant and only Super Admin holds manage-accounts (seeded in
    // Module 2), so permission:manage-accounts alone is the correct gate.
    Route::middleware('permission:manage-accounts')->prefix('admin')->group(function () {
        Route::get('/accounts', [AccountController::class, 'index']);
        Route::post('/accounts', [AccountController::class, 'store']);
        // Super Admin Dashboard Enhancement — "Expiring in 7 Days" metric
        // card + modal. MUST be registered before the '/accounts/{id}'
        // route immediately below it — otherwise Laravel would match
        // "expiring-soon" as the {id} segment first and this route would
        // never be reached.
        Route::get('/accounts/expiring-soon', [AccountController::class, 'expiringSoon']);
        Route::get('/accounts/{id}', [AccountController::class, 'show']);
        Route::put('/accounts/{id}', [AccountController::class, 'update']);
        Route::put('/accounts/{id}/subscription', [AccountController::class, 'updateSubscription']);
        // 3-Tier Hierarchy & Agent-Client Scope Engine (Phase 4) —
        // Agent Quota Pool & Allocation. Same permission:manage-accounts
        // gate as every other route in this group; the actual
        // Agent-vs-pool restriction lives in AccountController::
        // updateQuota()/QuotaService, not in route middleware.
        Route::put('/accounts/{id}/quota', [AccountController::class, 'updateQuota']);
        // Absolute Super Admin Control — Dynamic Client Privilege Toggles.
        Route::patch('/accounts/{id}/permissions', [AccountController::class, 'updatePermissions']);

        // Phase 1 Foundation, Task 7 — Capability entitlement grant/revoke,
        // write-time checked against ProviderCapabilityService::supports()
        // so an incompatible (provider, capability) pair can never be
        // granted. Super-Admin-only (see AccountController::
        // grantEntitlement()'s docblock for why an Agent is not yet
        // allowed here).
        // Phase 5 Task 8 — the READ half of the grant/revoke pair below,
        // for the Super Admin entitlement panel. Agent-scoped by the same
        // assertCallerCanAccessAccount() guard every other {id} action
        // in AccountController uses.
        Route::get('/accounts/{id}/entitlements', [AccountController::class, 'listEntitlements']);
        Route::post('/accounts/{id}/entitlements', [AccountController::class, 'grantEntitlement']);
        Route::delete('/accounts/{id}/entitlements/{capability}', [AccountController::class, 'revokeEntitlement']);

        /*
         * Phase 5 Task 10 — Super-Admin plan management (create/modify,
         * including the capability bundle). Named 'plans-management' so
         * it cannot be confused with the pre-existing, customer-facing
         * read-only '/plans' checkout listing, which is untouched.
         *
         * Super-Admin-only inside the controller as well as here: a plan
         * is a GLOBAL object, so unlike the account entitlement routes
         * above there is deliberately no Agent path.
         */
        Route::get('/plans-management', [PlanManagementController::class, 'index']);
        Route::post('/plans-management', [PlanManagementController::class, 'store']);
        Route::put('/plans-management/{slug}', [PlanManagementController::class, 'update']);

        // Phase 1 Foundation, Task 12 — Agent Selling Entitlements.
        // Super-Admin-only grant/revoke of which Capabilities an Agent
        // may resell to its own sub-clients; GET is readable by Super
        // Admin (any Agent) or the Agent itself (its own only) — see
        // AccountController::assertCallerCanViewSellingEntitlements().
        Route::post('/accounts/{id}/selling-entitlements', [AccountController::class, 'grantSellingEntitlement']);
        Route::delete('/accounts/{id}/selling-entitlements/{capability}', [AccountController::class, 'revokeSellingEntitlement']);
        Route::get('/accounts/{id}/selling-entitlements', [AccountController::class, 'listSellingEntitlements']);

        // Agent Commission Foundation — Super-Admin-only configuration
        // of one Agent's DB-driven commission rule. Same
        // permission:manage-accounts gate as the rest of this group, PLUS
        // an explicit isSuperAdmin() check inside the controller (see
        // AccountController::setCommissionRule()).
        Route::put('/accounts/{id}/commission-rule', [AccountController::class, 'setCommissionRule']);

        // Absolute Super Admin Control — Password Override. Same
        // permission:manage-accounts gate as the rest of this group, PLUS
        // an explicit isSuperAdmin() check inside the controller (see
        // AdminUserController's docblock for why permission alone isn't
        // a strict enough guarantee here).
        Route::post('/users/{id}/change-password', [AdminUserController::class, 'changePassword']);

        // BUILD: Fully Dynamic Categorized Route Master & Nested
        // Permission Matrix UI. /permissions-tree is read by BOTH Super
        // Admin and Agent callers (both hold manage-accounts, the gate
        // on this whole group) to render CreateAccountModal.tsx's
        // dynamic "Module & Feature Access" checklist —
        // RouteMasterController::tree() does the Agent-vs-Super-Admin
        // filtering internally. /route-categories and /system-routes
        // (RouteMasterPage.tsx's CRUD surface) are additionally
        // self-guarded to Super-Admin-only inside the controller — see
        // its docblock for why that isn't a second route-level
        // permission tier. Deliberately NOT placed under /api/v1/ as
        // originally specified — see RouteMasterController's docblock
        // for the disclosed naming correction.
        Route::get('/permissions-tree', [RouteMasterController::class, 'tree']);

        Route::get('/route-categories', [RouteMasterController::class, 'categories']);
        Route::post('/route-categories', [RouteMasterController::class, 'storeCategory']);
        Route::put('/route-categories/{id}', [RouteMasterController::class, 'updateCategory']);
        Route::delete('/route-categories/{id}', [RouteMasterController::class, 'destroyCategory']);

        Route::get('/system-routes', [RouteMasterController::class, 'routes']);
        Route::post('/system-routes', [RouteMasterController::class, 'storeRoute']);
        Route::put('/system-routes/{id}', [RouteMasterController::class, 'updateRoute']);
        Route::delete('/system-routes/{id}', [RouteMasterController::class, 'destroyRoute']);
    });

    // IMPLEMENT: Dynamic Route Master with Super-Admin Bypass & Global
    // Audit Tracking — requirement 4's "Super-Admin Audit Trail UI".
    // Deliberately its OWN permission tier (view-activity-logs), not
    // manage-accounts — an Agent holds manage-accounts too, but this
    // platform-wide CRUD activity trail is Super-Admin-only per the
    // literal spec, unlike /admin/accounts above.
    Route::middleware('permission:view-activity-logs')->prefix('admin')->group(function () {
        Route::get('/activity-logs', [ActivityLogController::class, 'index']);
        Route::get('/activity-logs/modules', [ActivityLogController::class, 'modules']);
    });

    // Dynamic Templates & Variables System — Super Admin Template
    // Designer & Approval Panel. Platform-wide content management, not
    // tenant-scoped data, so this sits outside tenant.isolation exactly
    // like the /admin/accounts group above — permission:manage-templates
    // (Super-Admin-only, RolePermissionSeeder) is the whole gate.
    Route::middleware('permission:manage-templates')->prefix('message-templates')->group(function () {
        Route::get('/', [MessageTemplateController::class, 'index']);
        Route::post('/', [MessageTemplateController::class, 'store']);
        Route::put('/{id}', [MessageTemplateController::class, 'update']);
        Route::patch('/{id}/approve', [MessageTemplateController::class, 'approve']);
        Route::patch('/{id}/reject', [MessageTemplateController::class, 'reject']);
        Route::delete('/{id}', [MessageTemplateController::class, 'destroy']);
    });

    // Strict 1-Template-Per-Client & Testing Gate — spec names this exact
    // path (/api/admin/templates/{id}/test), distinct from the
    // /message-templates prefix every other template route uses above;
    // kept as its own literal route rather than folding it in, so the
    // documented endpoint path matches exactly what integrators/QA were
    // told to expect. Same permission:manage-templates gate as every
    // other template-management action.
    Route::middleware('permission:manage-templates')->post('/admin/templates/{id}/test', [MessageTemplateController::class, 'test']);

    // Super Admin WhatsApp Device Integration — device overview across
    // every tenant. Deliberately `role:super_admin`, NOT
    // `permission:manage-accounts` (what this route used until the
    // WhatsApp production-readiness audit caught it) — `adminIndex()`
    // returns EVERY tenant's device status/company name completely
    // unscoped (no agent_id filter at all, unlike AccountController's
    // callerAgentScopeId() pattern), and manage-accounts is ALSO held by
    // Agent (RolePermissionSeeder, added in the later 3-Tier Hierarchy
    // phase for a different reason — managing its own Sub-Client
    // accounts). Sharing manage-accounts here let any Agent see every
    // OTHER Agent's/direct client's WhatsApp connection status too — a
    // real cross-tenant information-disclosure gap this route's own
    // original docblock never anticipated. The actual link/disconnect/
    // reconnect actions reuse the existing tenant-scoped /whatsapp/*
    // routes below with ?account_id=, so only the listing endpoint lives
    // here. See WhatsAppController::adminIndex()'s docblock for the
    // disclosed "every tenant's device" interpretation.
    Route::middleware('role:super_admin')->get('/admin/whatsapp/devices', [WhatsAppController::class, 'adminIndex']);

    // Super Admin WhatsApp Device Integration — the Super Admin's OWN
    // scannable WhatsApp test device (Account::platformDevice()), used by
    // MessageTemplateController::test() to fire test-sends for ANY
    // template. Deliberately `role:super_admin`, same fix and same
    // reasoning as the listing route above — and doubly so here: letting
    // Agent reach this at all would mean an Agent could start-session or
    // LOG OUT the one shared device every tenant's template approval
    // depends on, exactly the "cross-tenant abuse surface" that
    // MessageTemplateController::test()'s own docblock already explicitly
    // excludes Agent from via a DIFFERENT path (the test-fire endpoint) —
    // this self-device group was the gap that same exclusion missed. See
    // WhatsAppController::selfDeviceStatus()'s docblock for why these
    // don't reuse the generic per-tenant /whatsapp/* routes below.
    Route::middleware('role:super_admin')->prefix('admin/whatsapp/self-device')->group(function () {
        Route::get('/', [WhatsAppController::class, 'selfDeviceStatus']);
        Route::post('/start-session', [WhatsAppController::class, 'selfDeviceStartSession']);
        Route::post('/logout', [WhatsAppController::class, 'selfDeviceLogout']);
    });

    // Module 8: platform's own Razorpay/Stripe merchant credential vault
    // (used to COLLECT payments FROM tenants) — a distinct, dedicated
    // permission (manage-billing-settings) from manage-accounts, so this
    // can later be delegated to a finance role without also granting full
    // tenant/account management. Not tenant-scoped, so it sits alongside
    // /admin/accounts rather than inside tenant.isolation.
    Route::middleware('permission:manage-billing-settings')->prefix('admin/billing')->group(function () {
        Route::get('/gateway-settings', [GatewaySettingsController::class, 'index']);
        Route::post('/gateway-settings/{gateway}', [GatewaySettingsController::class, 'update']);

        // Dynamic System & Mail Configuration — Super Admin SMTP setup,
        // same 'Admin Gateway Settings' screen/permission as the payment
        // gateway credentials above.
        Route::get('/mail-settings', [MailSettingsController::class, 'show']);
        Route::put('/mail-settings', [MailSettingsController::class, 'update']);
        Route::post('/mail-settings/test', [MailSettingsController::class, 'sendTest']);
    });

    // Quota Exhaustion Request Workflow — Super Admin AND Agent
    // review/approval (Agent-Routed Quota Top-Up Requests). Gated on
    // manage-accounts, not manage-billing-settings, on purpose: an Agent
    // already holds manage-accounts (same tier as /admin/accounts) but
    // must never reach true platform settings (gateway/mail credentials
    // above), which stay manage-billing-settings-only. Super Admin holds
    // both permissions via PERMISSIONS, so this is a pure widening, not a
    // narrowing, of who can reach this group. QuotaRequestController
    // itself scopes an Agent caller to only its OWN Sub-Clients'
    // requests (index()) and refuses to approve any other tenant's
    // (approve()) — a directly-onboarded Admin (Account::agent_id ===
    // null) is only ever reachable by Super Admin.
    Route::middleware('permission:manage-accounts')->prefix('admin/quota-requests')->group(function () {
        Route::get('/', [QuotaRequestController::class, 'index']);
        Route::post('/{id}/approve', [QuotaRequestController::class, 'approve']);
    });

    // Social Media Marketing & Meta Ads Automation Expansion (Phase 1).
    // Platform-level Meta/LinkedIn/Google OAuth App credential vault —
    // same tier and shape as /admin/billing/gateway-settings above, its
    // own dedicated permission (manage-social-settings) rather than
    // manage-billing-settings so it can be delegated separately.
    Route::middleware('permission:manage-social-settings')->prefix('admin/social')->group(function () {
        Route::get('/provider-configs', [SocialGatewayController::class, 'index']);
        Route::post('/provider-configs/{provider}', [SocialGatewayController::class, 'update']);
    });
});
