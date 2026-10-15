<?php

namespace App\Services\Access;

/**
 * Which menu modules a set of capabilities opens (plan restructure,
 * 2026-10-15). A client on a plan (Account::$modules_from_plan) gets exactly
 * these modules — the plan decides, there is no per-client module checklist
 * any more. The Create Client form shows the same list for the chosen plan.
 *
 * The paid Custom Contact Groups add-on is a free baseline module
 * (Account::withFreeBaselineModules) and Super-Admin-only Device Settings is
 * never opened by a plan.
 */
final class PlanModules
{
    /** Every client has these, whatever the plan. */
    public const BASE = ['dashboard', 'analytics', 'billing', 'team_management', 'notifications'];

    /** capability slug => modules it opens */
    public const BY_CAPABILITY = [
        'whatsapp_send' => ['whatsapp_setup', 'send_alert', 'chatbot', 'templates', 'message_logs'],
        'journey_automation' => ['chatbot'],
        'crm' => ['lead_crm'],
        'social' => ['social_accounts', 'social_inbox', 'comment_automation', 'reports'],
        // Ads need the connected ad account (Social Accounts) and capture leads into the CRM.
        'ads' => ['meta_ads', 'social_accounts', 'lead_crm'],
        'external_api' => ['developer_api'],
    ];

    /** The capability that (first) opens $module — for "your plan lacks X" refusals and audit rows. */
    public static function capabilityFor(string $module): ?string
    {
        if ($module === 'industry_modules') {
            return null;
        }
        foreach (self::BY_CAPABILITY as $capability => $modules) {
            if (in_array($module, $modules, true)) {
                return $capability;
            }
        }

        return null;
    }

    /**
     * @param  iterable<string>  $capabilitySlugs
     * @return list<string>
     */
    public static function forCapabilities(iterable $capabilitySlugs): array
    {
        $modules = self::BASE;
        foreach ($capabilitySlugs as $slug) {
            $modules = array_merge($modules, self::BY_CAPABILITY[$slug] ?? []);
            // Any industry capability (industry_education, ...) opens the Industry Modules area.
            if (str_starts_with($slug, 'industry_')) {
                $modules[] = 'industry_modules';
            }
        }

        return array_values(array_unique($modules));
    }
}
