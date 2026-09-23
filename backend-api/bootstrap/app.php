<?php

use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\ApiAuthMiddleware;
use App\Http\Middleware\EnsureApiKeyCapability;
use App\Http\Middleware\EnsureApiKeyModule;
use App\Http\Middleware\EnsureApiKeySubscription;
use App\Http\Middleware\EnsureCapabilityMiddleware;
use App\Http\Middleware\EnsureCrmTargetAccount;
use App\Http\Middleware\EnsureIdempotentApiRequest;
use App\Http\Middleware\EnsureModuleEnabledMiddleware;
use App\Http\Middleware\LogApiRequestMiddleware;
use App\Http\Middleware\SubscriptionGuardMiddleware;
use App\Http\Middleware\TenantIsolationMiddleware;
use App\Http\Middleware\VerifyInternalSecret;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'tenant.isolation' => TenantIsolationMiddleware::class,
            'subscription.guard' => SubscriptionGuardMiddleware::class,
            'internal.secret' => VerifyInternalSecret::class,
            // Client Management, User Creation, Multi-Role Permissions &
            // Feature Module Checklists refactor — server-side
            // Account::MODULES enforcement (see that middleware's docblock).
            'module.guard' => EnsureModuleEnabledMiddleware::class,
            // Phase 6 CRM Task 2 — route-level enforcement for the
            // CAPABILITY dimension (account_entitlements), delegating to
            // the existing AccessControlService::canTenant(). Sibling of
            // module.guard above, not a replacement for it: module.guard
            // is the Super Admin's per-tenant toggle, this is what the
            // tenant's PLAN sold them. See its own docblock.
            'capability.guard' => EnsureCapabilityMiddleware::class,
            // Phase 6 CRM Hardening Round 2 — the same capability check
            // for the external Developer API, reading the account from
            // the presented API key instead of TenantIsolationMiddleware
            // (which /api/v1/* deliberately does not run). See its own
            // docblock for why capability.guard cannot simply be reused.
            'capability.apikey' => EnsureApiKeyCapability::class,
            // Phase 6 CRM Task 11 — module.guard and subscription.guard for
            // the Developer API, reading the API key's account (neither UI
            // guard can run on /v1: one falls through without
            // TenantIsolationMiddleware's account_id, the other needs a
            // user). Same predicates, same 403 envelopes. See their docblocks.
            'module.apikey' => EnsureApiKeyModule::class,
            'subscription.apikey' => EnsureApiKeySubscription::class,
            // CRM — Super Admin target account: own platform CRM account when
            // no client is selected, and the target's own lead_crm + crm
            // entitlement enforced for Super Admin (CRM routes only).
            'crm.target' => EnsureCrmTargetAccount::class,
            // Module 9 — external Developer API (Api\V1\*) Bearer API-key auth.
            'auth.apikey' => AuthenticateApiKey::class,
            // Phase 3 Task 4 -- Public API observability/auditability.
            // Registered as the OUTERMOST middleware on both external
            // Developer API route groups (see routes/api.php), ahead of
            // auth.apikey/auth.apisecret, so every request is logged
            // exactly once regardless of outcome (see its own docblock).
            'log.apirequest' => LogApiRequestMiddleware::class,
            // Developer API Platform for WhatsApp Group Creation & Unified
            // Messaging -- dual-factor (X-API-KEY + X-API-SECRET) auth, scoped
            // only to the new /api/v1/whatsapp/* routes (see routes/api.php).
            'auth.apisecret' => ApiAuthMiddleware::class,
            // Phase 3 Task 5 -- OPTIONAL Idempotency-Key protection for
            // the external Developer API's send operations. Registered
            // as the INNERMOST middleware on both /v1 groups (see
            // routes/api.php), after auth.apikey/auth.apisecret so the
            // key is scoped to the authenticated account, and after
            // throttle:external-api so rate limiting is unchanged. A
            // request without the header is passed straight through
            // (see its own docblock).
            'idempotency' => EnsureIdempotentApiRequest::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        /*
         * Phase 6 CRM Task 11 — [Bugfix, disclosed]. Laravel sorts route
         * middleware by its priority list, which contains ThrottleRequests
         * but not the Developer API's own middleware; ThrottleRequests was
         * therefore hoisted to the FRONT of every /api/v1 route, ahead of
         * log.apirequest and auth.apikey/auth.apisecret (verified with
         * Router::gatherRouteMiddleware()). The 'external-api' limiter then
         * never saw api_account_id and always fell back to 20/min per IP,
         * so each account's api_rate_limit_per_minute was never applied,
         * and a 429 was never written to api_request_logs. Declaring the
         * three before ThrottleRequests restores the order routes/api.php
         * documents: log -> auth -> throttle -> idempotency.
         */
        $middleware->prependToPriorityList(ThrottleRequests::class, LogApiRequestMiddleware::class);
        $middleware->prependToPriorityList(ThrottleRequests::class, AuthenticateApiKey::class);
        $middleware->prependToPriorityList(ThrottleRequests::class, ApiAuthMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // All-Module Form & API Validation Audit — Laravel's default
        // shouldReturnJson() falls back to $request->expectsJson(), which
        // is driven ONLY by the Accept header (Illuminate's
        // InteractsWithContentTypes::wantsJson()); it does NOT look at
        // Content-Type. A typical external API caller (curl, Postman, a
        // partner's HTTP client) that POSTs `Content-Type: application/json`
        // but sends no `Accept` header therefore got expectsJson() === false
        // — verified by reading vendor/laravel/framework's Handler +
        // InteractsWithContentTypes source directly, not assumed. The
        // practical failure mode this caused, confirmed for this app: a
        // ValidationException on such a request fell through to
        // Handler::invalid(), which tries to redirect back() with flashed
        // session errors — meaningless (and likely itself an error) for a
        // stateless external API client with no session and no "previous
        // page"; an uncaught exception rendered Symfony's HTML error page
        // instead of JSON, i.e. exactly the "generic 500 instead of a
        // clean JSON error" failure this audit was asked to eliminate.
        //
        // Fix: force JSON error rendering for every request under /api/*
        // (external Developer API + the SPA's own calls) regardless of
        // Accept header, while leaving web.php's normal HTML error pages
        // (if any are ever added) untouched.
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
