<?php

namespace App\Http\Requests;

use App\Models\CrmLead;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Phase 6 — CRM, Task 2. PUT/PATCH /api/crm/leads/{id}.
 *
 * `sometimes` throughout, so a PATCH carrying one field leaves the rest
 * alone and a PUT carrying all of them works identically — the caller
 * chooses the verb, the semantics do not change.
 *
 * account_id, contact_id and phone_number are absent from these rules on
 * purpose and are therefore never in validated(), which is the only
 * array CrmLeadService::update() reads. A request that sends them is not
 * rejected (that would be a pointless error for an over-eager client);
 * they are simply ignored, and a test asserts the lead's tenant and
 * contact are unchanged afterwards. Moving a lead between tenants or
 * between people is not an edit — it is a different record.
 */
class UpdateCrmLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(CrmLead::STATUSES)],
            // Nullable so a lead can be explicitly UNASSIGNED; see
            // StoreCrmLeadRequest for why there is no exists: rule.
            'assigned_user_id' => ['sometimes', 'nullable', 'integer'],
            'not_converted_reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Corrects the resolved contact's display name — the one
            // deliberate exception to ContactResolver's never-overwrite
            // rule, because this is an explicit request rather than an
            // automatic side effect of lead capture.
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
