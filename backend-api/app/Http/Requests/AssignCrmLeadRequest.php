<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 6 — CRM Task 4. PATCH /api/crm/leads/{id}/assignee.
 *
 * `present` + `nullable` is the whole point of this class, and the two
 * rules do different jobs:
 *   present   the key must appear in the body. An ownership endpoint
 *             called with {} is a mistake, not a no-op, so it is a 422
 *             rather than a silent success.
 *   nullable  the value may be null, and null MEANS something here —
 *             unassign. Without it Laravel would reject the explicit
 *             unassign that the brief requires as a first-class
 *             operation.
 * So the three cases are distinguishable end to end:
 *   absent            -> 422 (this endpoint exists to change ownership)
 *   present, integer  -> assign / reassign
 *   present, null     -> unassign
 * The general PATCH /crm/leads/{id} keeps `sometimes` instead, because
 * there an absent field correctly means "leave ownership alone".
 *
 * NO `exists:users,id` RULE, for the same reason StoreCrmLeadRequest
 * has none: that rule passes for a real user in ANOTHER account and
 * fails for an id that exists nowhere — two distinguishable errors, and
 * therefore a cross-tenant enumeration oracle. CrmLead's saving guard
 * refuses foreign, missing, inactive and non-CRM-capable assignees with
 * one identical message instead.
 *
 * NO account_id. The tenant comes from the authenticated context only.
 */
class AssignCrmLeadRequest extends FormRequest
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
            'assigned_user_id' => ['present', 'nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'assigned_user_id.present' => 'Send assigned_user_id — a user id to assign, or null to unassign.',
        ];
    }
}
