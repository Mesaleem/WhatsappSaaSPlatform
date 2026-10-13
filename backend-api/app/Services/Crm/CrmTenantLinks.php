<?php

namespace App\Services\Crm;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CRM Sales Phase 1 — the one tenant check for a record's links (lead, contact,
 * company, deal, stage). Every linked id must belong to the SAME account as the
 * record; a foreign id and a missing id fail with the SAME message, so the check can
 * never be used to probe another tenant's ids (the rule CrmLead's own guard follows).
 *
 * Used by the saving guards of CrmDeal and CrmActivity and by Contact for its company,
 * so it holds for every writer (controller, service, job, seeder), not only the API.
 */
final class CrmTenantLinks
{
    /** field => table */
    public const TABLES = [
        'crm_lead_id' => 'crm_leads',
        'contact_id' => 'contacts',
        'crm_company_id' => 'crm_companies',
        'crm_deal_id' => 'crm_deals',
        'stage_id' => 'crm_pipeline_stages',
    ];

    private const LABELS = [
        'crm_lead_id' => 'lead',
        'contact_id' => 'contact',
        'crm_company_id' => 'company',
        'crm_deal_id' => 'deal',
        'stage_id' => 'stage',
    ];

    /**
     * @param array<string, int|null> $links field => id (null = no link, skipped)
     * @throws ValidationException
     */
    public static function assertBelong(int $accountId, array $links): void
    {
        foreach ($links as $field => $id) {
            if ($id === null) {
                continue;
            }

            $exists = DB::table(self::TABLES[$field])->where('id', $id)->where('account_id', $accountId)->exists();

            if (! $exists) {
                throw ValidationException::withMessages([
                    $field => ['The selected '.self::LABELS[$field].' is not available.'],
                ]);
            }
        }
    }
}
