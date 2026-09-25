<?php

namespace App\Services\Access;

use App\Models\Account;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * P5-8 — Entitlement audit logging.
 *
 * One append-only record per authorization DECISION (allowed / denied) at
 * the platform's entitlement boundaries, written into the EXISTING audit
 * trail — `activity_logs`, the table LogsActivity writes and the Super
 * Admin's Activity Logs screen (ActivityLogController, `view-activity-logs`)
 * reads. No second audit system, no migration:
 *
 *   activity_logs.module_name   'Entitlement Authorization' (MODULE)
 *   activity_logs.action_type   'allowed' | 'denied'
 *   activity_logs.user_id       the authenticated actor, or NULL (webhook,
 *                               scheduler, queue, API key)
 *   activity_logs.account_id    the tenant the decision is ABOUT — always
 *                               resolved server-side (see "Tenant safety")
 *   activity_logs.agent_id      that tenant's Agent (same rule as LogsActivity)
 *   activity_logs.route_path / ip_address   the HTTP request, when there is one
 *   activity_logs.new_values    the decision payload (FIELDS below)
 *   activity_logs.created_at    timestamp
 *
 * TENANT SAFETY. $account is always an Account the caller resolved from
 * trusted state — TenantIsolationMiddleware's request attribute, the API
 * key's own account, or a journey session row — never from request input.
 * A denied attempt against ANOTHER account (cross-tenant resource, a
 * forged/unauthorized ?account_id=) is recorded on the ACTOR's own account;
 * the other account only appears as `target_account_id` inside the payload.
 * So no request can make a row land in someone else's tenant.
 *
 * SECRETS. The payload is built from a closed allow-list of keys (FIELDS)
 * holding scalars and short lists of slugs; anything else a caller passes
 * is dropped, strings are cut to 191 characters. No request body, header,
 * query string, graph, node configuration, message text, token or key is
 * ever read by this class.
 *
 * FAILURE SAFETY. record() never throws and never changes the decision: a
 * failed write is reported with Log::warning (no payload, so no loop and no
 * leak) and the caller carries on exactly as it would have.
 */
class EntitlementAuditLogger
{
    public const MODULE = 'Entitlement Authorization';

    public const ALLOWED = 'allowed';

    public const DENIED = 'denied';

    /**
     * Machine-readable decision categories. Allowed decisions use
     * `entitled`, `no_requirement` or `super_admin_bypass`; every other value
     * is a denial and names the ACTUAL reason — kept distinct on purpose
     * (a validation problem is never recorded as a missing entitlement).
     */
    public const CATEGORIES = [
        // allowed
        'entitled',                  // every required capability/module/provider check passed
        'no_requirement',            // the operation checks nothing (control-flow node)
        'super_admin_bypass',        // existing platform rule: a Super Admin is not gated by tenant entitlements
        // denied — entitlement
        'capability_not_entitled',   // the account does not hold the capability
        'module_disabled',           // the module is switched off for the account
        'provider_not_supported',    // the account's WhatsApp provider cannot do it
        'account_suspended',         // the account is not administratively active
        'no_active_subscription',    // no current/active subscription
        // denied — authorization (not entitlement)
        'missing_permission',        // the actor's role lacks the route permission
        'cross_tenant',              // the resource belongs to another account
        'unauthorized_target_account', // a requested ?account_id= the actor may not act on
        // denied — publishability (not entitlement)
        'unsupported_node',          // the runtime cannot execute a node in the graph
        'invalid_configuration',     // the graph/node configuration is invalid
    ];

    /** Allow-listed payload keys (new_values). Nothing else is ever stored. */
    private const FIELDS = [
        'decision', 'action', 'category', 'reason', 'source',
        'resource_type', 'resource_id', 'node_type', 'node_id',
        'module', 'capability', 'capabilities', 'provider', 'providers', 'permission',
        'actor_account_id', 'target_account_id', 'account_id',
        'session_id', 'flow_version_id', 'inbound_event_id', 'error_code', 'http_status',
    ];

    /**
     * @param  array<string, mixed>  $fields  decision payload; see FIELDS
     */
    public function record(?Account $account, bool $allowed, array $fields): void
    {
        try {
            $payload = ['decision' => $allowed ? self::ALLOWED : self::DENIED] + $this->sanitize($fields);
            $payload['account_id'] = $account?->id;
            // Only a real routed HTTP request contributes route/IP (a webhook,
            // UI or API-key call); scheduler/queue runs have none.
            $request = app()->bound('request') && request()->route() !== null ? request() : null;

            ActivityLog::create([
                'user_id' => Auth::id(),
                'account_id' => $account?->id,
                'agent_id' => $account?->agent_id,
                'module_name' => self::MODULE,
                'action_type' => $payload['decision'],
                'route_path' => $request ? mb_substr($request->path(), 0, 255) : null,
                'ip_address' => $request?->ip(),
                'old_values' => null,
                'new_values' => $payload,
            ]);
        } catch (Throwable $e) {
            Log::warning('EntitlementAuditLogger: could not record an authorization decision.', [
                'account_id' => $account?->id,
                'exception' => class_basename($e),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function sanitize(array $fields): array
    {
        $clean = [];

        foreach (self::FIELDS as $key) {
            if (! array_key_exists($key, $fields) || $key === 'decision' || $key === 'account_id') {
                continue;
            }

            $value = $fields[$key];

            if (is_array($value)) {
                $value = array_values(array_slice(array_filter($value, fn ($v) => is_string($v) || is_int($v)), 0, 20));
                $value = array_map(fn ($v) => is_string($v) ? mb_substr($v, 0, 64) : $v, $value);
            } elseif (is_string($value)) {
                $value = mb_substr($value, 0, 191);
            } elseif (! is_int($value) && ! is_bool($value) && $value !== null) {
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }
}
