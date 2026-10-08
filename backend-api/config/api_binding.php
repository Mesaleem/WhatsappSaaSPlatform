<?php

/*
 * Public API authorized-server binding (see App\Services\ApiAccess\ApiKeyBindingService).
 *
 * allow_legacy_unbound  REMOVED / INERT as of Phase 4 Task 7's fix — ApiKeyBindingService::gate() no longer reads
 *                       this key at all. There must be no configuration/admin override that authenticates a key
 *                       with no live binding merely because a flag is set; a key with no binding row is
 *                       unconditionally refused on /api/v1/* with 403 API_SERVER_BINDING_REQUIRED unless it is
 *                       legacy-IP-dependent (Account.authorized_server_ip set) AND within its own per-key Task 5
 *                       grace deadline (api_keys.legacy_binding_grace_expires_at, see InstallationAllowanceResolver's
 *                       sibling ApiKeyBindingService::effectiveLegacyDeadline()). The config entry and its env var
 *                       are left in place only so tests\Concerns\AllowsUnboundApiKeys (and the ~20 test files that
 *                       apply it) do not hard-error setting a now-meaningless config key — see that trait's own
 *                       docblock for the disclosed, unresolved fallout.
 * default_ip_policy     Policy offered for a newly created key. NONE is never a client choice (Super Admin only).
 * installation_header   Request header carrying the installation credential issued when the binding was created.
 *
 * legacy_binding_grace_days   Phase 4 Task 5 — grace period (days) given to a legacy-IP-dependent key's
 *                       backfill-created, credential-less binding, counted from the actual backfill run time.
 *                       NO BUSINESS DECISION HAS BEEN CONFIRMED FOR THIS VALUE: 30 is a placeholder default,
 *                       chosen only so the backfill has one explicit, documented duration rather than inventing
 *                       different windows per run or per plan. Override via API_LEGACY_BINDING_GRACE_DAYS. A
 *                       later task (grace-expiry consequence / admin extension) may revisit this value, not the
 *                       fact that it's a single global duration.
 *
 * The client IP is $request->ip(): it honours the application's trusted-proxy configuration and ignores a spoofed
 * X-Forwarded-For unless the connecting proxy is trusted. Behind a load balancer, configure trusted proxies for it
 * (bootstrap/app.php `trustProxies`) or every caller will appear to come from the balancer's address.
 */
return [
    'allow_legacy_unbound' => (bool) env('API_ALLOW_LEGACY_UNBOUND', false),
    'default_ip_policy' => 'SINGLE_IP',
    'installation_header' => 'X-Client-Installation',
    'legacy_binding_grace_days' => (int) env('API_LEGACY_BINDING_GRACE_DAYS', 30),
    'warning' => 'This API key is licensed to your purchased account and authorized server. Sharing this API key or using it from an unauthorized server is not permitted. Changing the authorized server requires approval.',
    'event_dedupe_seconds' => 600,
    'success_touch_seconds' => 60,
];
