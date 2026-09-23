<?php

namespace App\Http\Requests;

use App\Models\CrmLead;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Phase 6 — CRM Task 5. PATCH /api/crm/leads/{id}/status.
 *
 * `required` rather than `sometimes`: a lifecycle endpoint called with
 * no status is a mistake, not a no-op, so it is a 422. The general
 * PATCH /crm/leads/{id} keeps `sometimes`, where an absent status
 * correctly means "leave the lifecycle alone" — the same split the
 * assignee endpoint makes for ownership.
 *
 * Rule::in(CrmLead::STATUSES) is the ONE vocabulary, read from the model
 * constant rather than restated here, so `null`, `''`, `0`, `[]`, `{}`,
 * `'foo'` and the forbidden pipeline stages (`qualified`, `won`,
 * `lost`, …) are all rejected by construction — and adding a canonical
 * status later needs no edit in this file. The `string` rule ahead of it
 * is what turns an array or object into a clean field error instead of
 * a type juggle.
 *
 * NO account_id, agent_id or tenant_id. The tenant comes from the
 * authenticated context only; anything of that shape in the body is
 * never read.
 *
 * not_converted_reason is accepted because it is part of the OUTCOME
 * this endpoint records, not a separate edit — `not_converted` with a
 * reason is one lifecycle event. It is optional, matching the column
 * and the general update route, and CrmLead's invariants refuse it on
 * any status other than not_converted.
 */
class ChangeCrmLeadStatusRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::in(CrmLead::STATUSES)],
            'not_converted_reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'Send status — one of: '.implode(', ', CrmLead::STATUSES).'.',
        ];
    }
}
