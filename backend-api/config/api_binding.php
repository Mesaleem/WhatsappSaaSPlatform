<?php

/*
 * Public API authorized-server binding (see App\Services\ApiAccess\ApiKeyBindingService).
 *
 * allow_legacy_unbound  TEMPORARY EMERGENCY OVERRIDE - NOT a security posture. Default false (env
 *                       API_ALLOW_LEGACY_UNBOUND): a key with NO binding row (every key created before this feature)
 *                       is refused on /api/v1/* with 403 API_SERVER_BINDING_REQUIRED until its owner enrolls a server
 *                       from the dashboard (Developer > API Access > Register authorized server) or a Super Admin
 *                       rebinds it. A legacy key alone never claims a server. Set the env var to true ONLY as a
 *                       short, explicit migration window (every such request is recorded as a security event) and
 *                       remove it again; while true, any holder of an unbound key can use it from any server.
 * default_ip_policy     Policy offered for a newly created key. NONE is never a client choice (Super Admin only).
 * installation_header   Request header carrying the installation credential issued when the binding was created.
 *
 * The client IP is $request->ip(): it honours the application's trusted-proxy configuration and ignores a spoofed
 * X-Forwarded-For unless the connecting proxy is trusted. Behind a load balancer, configure trusted proxies for it
 * (bootstrap/app.php `trustProxies`) or every caller will appear to come from the balancer's address.
 */
return [
    'allow_legacy_unbound' => (bool) env('API_ALLOW_LEGACY_UNBOUND', false),
    'default_ip_policy' => 'SINGLE_IP',
    'installation_header' => 'X-Client-Installation',
    'warning' => 'This API key is licensed to your purchased account and authorized server. Sharing this API key or using it from an unauthorized server is not permitted. Changing the authorized server requires approval.',
    'event_dedupe_seconds' => 600,
    'success_touch_seconds' => 60,
];
