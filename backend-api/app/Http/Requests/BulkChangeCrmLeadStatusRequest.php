<?php

namespace App\Http\Requests;

use App\Models\CrmLead;
use Illuminate\Validation\Rule;

/**
 * Phase 6 — CRM Task 9. POST /api/crm/leads/bulk/status — the same
 * `status` / optional `not_converted_reason` contract as the individual
 * PATCH /crm/leads/{id}/status (ChangeCrmLeadStatusRequest).
 */
class BulkChangeCrmLeadStatusRequest extends BulkCrmLeadRequest
{
    public function rules(): array
    {
        return [
            ...$this->leadIdRules(),
            'status' => ['required', 'string', Rule::in(CrmLead::STATUSES)],
            'not_converted_reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'status.required' => 'Send status — one of: '.implode(', ', CrmLead::STATUSES).'.',
        ];
    }
}
