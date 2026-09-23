<?php

namespace App\Http\Requests;

use App\Services\Crm\CrmBulkLeadSelection;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 6 — CRM Task 9. The lead-selection half of every bulk CRM request.
 * Subclasses add the one operation field they need; nothing else is ever
 * accepted (account_id / agent_id / tenant_id are not rules, so they can
 * never reach validated() — the tenant comes only from
 * TenantIsolationMiddleware, as on every CRM endpoint).
 *
 *   lead_ids     required, array, 1..MAX_LEADS entries
 *   lead_ids.*   positive integers, DISTINCT — a duplicate id is a 422,
 *                not silently collapsed, so the caller's count and the
 *                server's `requested` always agree.
 *
 * Ownership of each id is not a validation rule here: CrmBulkLeadSelection
 * checks it under lock inside the operation's transaction, with one
 * generic message for foreign and missing ids alike.
 */
abstract class BulkCrmLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function leadIdRules(): array
    {
        return [
            'lead_ids' => ['required', 'array', 'min:1', 'max:'.CrmBulkLeadSelection::MAX_LEADS],
            'lead_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }

    /**
     * @return list<int>
     */
    public function leadIds(): array
    {
        return array_map('intval', $this->validated()['lead_ids']);
    }

    public function messages(): array
    {
        return [
            'lead_ids.max' => 'Select at most '.CrmBulkLeadSelection::MAX_LEADS.' leads per bulk action.',
            'lead_ids.*.distinct' => 'Each lead may only be selected once.',
        ];
    }
}
