<?php

namespace App\Http\Requests;

/**
 * Phase 6 — CRM Task 9. POST /api/crm/leads/bulk/assignee.
 * `assigned_user_id` follows the individual PATCH /crm/leads/{id}/assignee
 * contract exactly: `present`, an id to assign/reassign, or null to
 * unassign. Eligibility is decided by the domain (CrmLead::assigneeIsEligible).
 */
class BulkAssignCrmLeadsRequest extends BulkCrmLeadRequest
{
    public function rules(): array
    {
        return [
            ...$this->leadIdRules(),
            'assigned_user_id' => ['present', 'nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'assigned_user_id.present' => 'Send assigned_user_id — a user id to assign, or null to unassign.',
        ];
    }
}
