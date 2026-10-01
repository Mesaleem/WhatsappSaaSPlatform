<?php

namespace App\Services\Groups;

use App\Models\Account;
use App\Services\Access\AccessControlService;
use App\Services\Access\EntitlementAuditLogger;

/**
 * Phase 5 P5-C — the `whatsapp_groups` capability check for the Native
 * WhatsApp Group actions that cannot be gated per route (the same
 * endpoint also serves `internal_segment` contact lists, which stay
 * behind `module.guard:contact_groups` + permission only).
 *
 * Not a new authorization system: the decision is the existing
 * AccessControlService::canTenant() (the call behind
 * `capability.guard` / `capability.apikey`), and a refusal is recorded
 * through the existing EntitlementAuditLogger with the same category and
 * error code those middlewares use. The whole-route native endpoints
 * (available-native, import-native, recreate) use
 * `capability.guard:whatsapp_groups` directly.
 *
 * Scope decision (owner, P5-C): `whatsapp_groups` means Native WhatsApp
 * Groups — the capability the provider matrix marks unsupported on Meta.
 * Contact-list groups keep working on every provider.
 */
final class NativeGroupEntitlement
{
    public const CAPABILITY = 'whatsapp_groups';

    public const ERROR_CODE = 'CAPABILITY_NOT_ENTITLED';

    public const MESSAGE = 'Your current plan does not include WhatsApp Groups. Please upgrade your subscription to unlock native WhatsApp groups.';

    /**
     * True when $account holds an active `whatsapp_groups` entitlement.
     * A refusal is audited on $account (always the resolved tenant or the
     * API key's own account, never a request value).
     *
     * $superAdminBypass mirrors EnsureCapabilityMiddleware for the
     * session-authenticated tenant routes; runtime paths (the group
     * dispatchers, API-key requests) pass false.
     */
    public static function allows(Account $account, string $action, string $source, bool $superAdminBypass = false): bool
    {
        if ($superAdminBypass) {
            return true;
        }

        if (app(AccessControlService::class)->canTenant($account, self::CAPABILITY)) {
            return true;
        }

        app(EntitlementAuditLogger::class)->record($account, false, [
            'action' => $action,
            'resource_type' => 'contact_group',
            'source' => $source,
            'category' => 'capability_not_entitled',
            'capability' => self::CAPABILITY,
            'error_code' => self::ERROR_CODE,
            'http_status' => 403,
        ]);

        return false;
    }

    /** The 403 body used by the tenant/API controllers. */
    public static function denialBody(): array
    {
        return ['success' => false, 'error_code' => self::ERROR_CODE, 'message' => self::MESSAGE];
    }
}
