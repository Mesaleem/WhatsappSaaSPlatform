<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Services\Access\EntitlementAuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 6 — CRM Task 11. `module.guard` for the external Developer API.
 *
 * EnsureModuleEnabledMiddleware reads the `account_id` attribute set by
 * TenantIsolationMiddleware, which /api/v1/* deliberately does not run —
 * and it FALLS THROUGH when that attribute is absent, so attaching it to a
 * /v1 route would silently enforce nothing. This is the same check
 * (Account::hasModuleEnabled(), i.e. the Super Admin's per-tenant toggle
 * capped by the Agent hierarchy) reading the account from the presented
 * API key instead, and it FAILS CLOSED: no resolved key account is a 401.
 *
 * Same sibling relationship EnsureApiKeyCapability has to
 * EnsureCapabilityMiddleware. Same 403 message and error_code as the
 * tenant-UI guard. No Super Admin bypass: an API key always belongs to one
 * tenant account.
 *
 * Must run after auth.apikey. Usage: ->middleware('module.apikey:lead_crm')
 */
class EnsureApiKeyModule
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $accountId = $request->attributes->get('api_account_id');
        $account = $accountId ? Account::findCached((int) $accountId) : null;

        if (! $account) {
            return response()->json(['status' => false, 'message' => 'Unauthenticated.'], 401);
        }

        if (! $account->hasModuleEnabled($module)) {
            // P5-8 — audited like the UI module guard; the key's own account.
            app(EntitlementAuditLogger::class)->record($account, false, [
                'action' => 'route.access', 'resource_type' => 'route', 'source' => 'api_key',
                'category' => 'module_disabled', 'module' => $module,
                'error_code' => 'MODULE_DISABLED', 'http_status' => 403,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'This feature has been disabled for your account by the Super Admin.',
                'error_code' => 'MODULE_DISABLED',
            ], 403);
        }

        return $next($request);
    }
}
