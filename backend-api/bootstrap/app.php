<?php

use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\ApiAuthMiddleware;
use App\Http\Middleware\EnsureModuleEnabledMiddleware;
use App\Http\Middleware\SubscriptionGuardMiddleware;
use App\Http\Middleware\TenantIsolationMiddleware;
use App\Http\Middleware\VerifyInternalSecret;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
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
            // Module 9 — external Developer API (Api\V1\*) Bearer API-key auth.
            'auth.apikey' => AuthenticateApiKey::class,
            // Developer API Platform for WhatsApp Group Creation & Unified
            // Messaging -- dual-factor (X-API-KEY + X-API-SECRET) auth, scoped
            // only to the new /api/v1/whatsapp/* routes (see routes/api.php).
            'auth.apisecret' => ApiAuthMiddleware::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
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
