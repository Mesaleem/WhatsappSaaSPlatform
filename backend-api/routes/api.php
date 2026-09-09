<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\WhatsAppController;
use App\Http\Controllers\Api\MetaConfigController;
use App\Http\Controllers\Api\MetaWebhookController;
use App\Http\Controllers\Api\PaymentAlertController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\MessageLogController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\PaymentGatewayController;
use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\Admin\GatewaySettingsController;
use App\Http\Controllers\Api\Admin\MailSettingsController;
use App\Http\Controllers\Api\ApiKeyController;
use App\Http\Controllers\Api\WebhookSubscriptionController;
use App\Http\Controllers\Api\V1\ExternalAlertController;
use App\Http\Controllers\Api\V1\TemplateMessageController;
use App\Http\Controllers\Api\ClientApiKeyController;
use App\Http\Controllers\Api\MessageTemplateController;
use App\Http\Controllers\Api\Internal\WhatsAppStatusController;
use App\Http\Controllers\Api\Internal\WhatsAppInboundController;
use App\Http\Controllers\Api\ChatbotRuleController;
use App\Http\Controllers\Api\ChatbotLogController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\InAppNotificationController;
use App\Http\Controllers\Api\NotificationTemplateController;
use App\Http\Controllers\Api\MailLogController;
use App\Http\Controllers\Api\NotificationBroadcastController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);

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
Route::get('/webhooks/meta', [MetaWebhookController::class, 'verify']);
Route::post('/webhooks/meta', [MetaWebhookController::class, 'handle']);

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
Route::middleware(['auth.apikey', 'throttle:external-api'])->prefix('v1')->group(function () {
    Route::post('/messages/send-payment-alert', [ExternalAlertController::class, 'sendPaymentAlert']);
    // Dynamic Templates & Variables System.
    Route::post('/messages/send-template', [TemplateMessageController::class, 'send']);
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
    Route::middleware(['tenant.isolation', 'permission:manage-subscriptions'])->prefix('billing')->group(function () {
        Route::get('/plans', [PaymentGatewayController::class, 'plans']);
        Route::post('/create-order', [PaymentGatewayController::class, 'createOrder']);
        Route::post('/verify-payment', [PaymentGatewayController::class, 'verifyPayment']);
        Route::get('/invoices', [BillingController::class, 'index']);
        Route::get('/invoices/{id}/pdf', [BillingController::class, 'pdf']);
        // Billing & Plans Module Overhaul — Super Admin Billing Overview.
        Route::get('/client-summary', [BillingController::class, 'clientSummary']);
    });

    // Dynamic Templates & Variables System — Profile/Account Settings'
    // "Client API Key" section. Gated by manage-developer-settings, the
    // same permission tier as the Developer Portal (ApiKeyController) —
    // regenerating an account's shared API secret is an administrative
    // action, not something the plain 'user' role should be able to do.
    Route::middleware(['tenant.isolation', 'permission:manage-developer-settings'])->prefix('account/api-key')->group(function () {
        Route::get('/', [ClientApiKeyController::class, 'show']);
        Route::post('/regenerate', [ClientApiKeyController::class, 'regenerate']);
    });

    Route::middleware(['tenant.isolation', 'subscription.guard'])->group(function () {
        Route::middleware('permission:manage-roles')->group(function () {
            Route::get('/roles', [RoleController::class, 'index']);
            Route::post('/roles', [RoleController::class, 'store']);
            Route::put('/roles/{id}', [RoleController::class, 'update']);
        });

        // Module 4: WhatsApp (QR engine) session management — bridges to
        // qr-engine-service. account_id is resolved from the authenticated
        // user server-side; never accepted from the client.
        Route::prefix('whatsapp')->group(function () {
            Route::get('/status', [WhatsAppController::class, 'status']);
            Route::post('/start-session', [WhatsAppController::class, 'startSession']);
            Route::post('/logout', [WhatsAppController::class, 'logout']);
        });

        // Module 5: Meta Cloud API credential vault. Admin-only (not
        // manage-accounts) — this is per-tenant configuration, distinct
        // from platform-level account provisioning.
        Route::middleware('role:admin')->prefix('whatsapp/meta-config')->group(function () {
            Route::get('/', [MetaConfigController::class, 'show']);
            Route::post('/', [MetaConfigController::class, 'store']);
            Route::post('/test-connection', [MetaConfigController::class, 'testConnection']);
        });

        // Module 9: Developer Portal — API key management + outbound webhook
        // subscriptions. One shared permission (manage-developer-settings)
        // since the frontend presents both as tabs of a single page.
        Route::middleware('permission:manage-developer-settings')->prefix('developer')->group(function () {
            Route::get('/api-keys', [ApiKeyController::class, 'index']);
            Route::post('/api-keys', [ApiKeyController::class, 'store']);
            Route::delete('/api-keys/{id}', [ApiKeyController::class, 'destroy']);

            Route::get('/webhooks', [WebhookSubscriptionController::class, 'index']);
            Route::post('/webhooks', [WebhookSubscriptionController::class, 'store']);
            Route::delete('/webhooks/{id}', [WebhookSubscriptionController::class, 'destroy']);
            Route::post('/webhooks/{id}/test', [WebhookSubscriptionController::class, 'test']);
            Route::get('/webhooks/{id}/deliveries', [WebhookSubscriptionController::class, 'deliveries']);
        });

        // Module 10: Chatbot Rule Builder + execution logs. A dedicated
        // permission (manage-chatbot) rather than reusing
        // manage-developer-settings — see RolePermissionSeeder's docblock.
        Route::middleware('permission:manage-chatbot')->prefix('chatbot')->group(function () {
            Route::get('/rules', [ChatbotRuleController::class, 'index']);
            Route::post('/rules', [ChatbotRuleController::class, 'store']);
            Route::put('/rules/{id}', [ChatbotRuleController::class, 'update']);
            Route::delete('/rules/{id}', [ChatbotRuleController::class, 'destroy']);
            Route::post('/rules/{id}/toggle', [ChatbotRuleController::class, 'toggle']);

            Route::get('/logs', [ChatbotLogController::class, 'index']);
        });

        // UI overhaul: Team Users page — tenant Admin managing sub-users
        // under their own account. manage-team already exists in
        // RolePermissionSeeder (Admin only) but was unused until now.
        Route::middleware('permission:manage-team')->prefix('team')->group(function () {
            Route::get('/users', [TeamController::class, 'index']);
            Route::post('/users', [TeamController::class, 'store']);
            Route::patch('/users/{id}/toggle', [TeamController::class, 'toggle']);
            Route::delete('/users/{id}', [TeamController::class, 'destroy']);
            Route::get('/roles', [TeamController::class, 'roles']);
        });

        // Module 6: Payment alert dispatch. send-messages is held by
        // every seeded role (Admin, User) — this is the same permission
        // already gating ordinary WhatsApp usage, not a new privilege tier.
        Route::middleware('permission:send-messages')->prefix('alerts')->group(function () {
            Route::post('/send', [PaymentAlertController::class, 'send']);
            Route::post('/bulk-upload', [PaymentAlertController::class, 'bulkUpload']);
            // Dynamic Templates & Variables System — Client Admin Dynamic
            // Form Engine: the approved-templates dropdown and its submit
            // action, both scoped to send-messages (the same permission
            // that already gates ordinary alert sending) rather than a
            // new one.
            Route::get('/message-templates', [MessageTemplateController::class, 'available']);
            Route::post('/send-template', [MessageTemplateController::class, 'send']);
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
        Route::middleware('permission:manage-notifications')->group(function () {
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
        // Absolute Super Admin Control — Dynamic Client Privilege Toggles.
        Route::patch('/accounts/{id}/permissions', [AccountController::class, 'updatePermissions']);

        // Absolute Super Admin Control — Password Override. Same
        // permission:manage-accounts gate as the rest of this group, PLUS
        // an explicit isSuperAdmin() check inside the controller (see
        // AdminUserController's docblock for why permission alone isn't
        // a strict enough guarantee here).
        Route::post('/users/{id}/change-password', [AdminUserController::class, 'changePassword']);
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
    // every tenant. Same permission tier as /admin/accounts (this is
    // platform account administration, not tenant data) — the actual
    // link/disconnect/reconnect actions reuse the existing tenant-scoped
    // /whatsapp/* routes below with ?account_id=, so only the listing
    // endpoint lives here. See WhatsAppController::adminIndex()'s
    // docblock for the disclosed "every tenant's device" interpretation.
    Route::middleware('permission:manage-accounts')->get('/admin/whatsapp/devices', [WhatsAppController::class, 'adminIndex']);

    // Super Admin WhatsApp Device Integration — the Super Admin's OWN
    // scannable WhatsApp test device (Account::platformDevice()), used by
    // MessageTemplateController::test() to fire test-sends for ANY
    // template. Same permission tier as the listing route above; see
    // WhatsAppController::selfDeviceStatus()'s docblock for why these
    // don't reuse the generic per-tenant /whatsapp/* routes below.
    Route::middleware('permission:manage-accounts')->prefix('admin/whatsapp/self-device')->group(function () {
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
});
