<?php

namespace App\Http\Requests;

use App\Models\CrmLead;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Phase 6 — CRM, Task 2. POST /api/crm/leads.
 *
 * Follows this codebase's existing FormRequest convention (see
 * CreateWhatsAppGroupRequest / UnifiedSendMessageRequest): authorize()
 * returns true because authentication, tenant resolution, permission,
 * module and capability are all enforced by route middleware before this
 * runs — a FormRequest here validates SHAPE, never authority.
 *
 * NOTE WHAT IS ABSENT, ON PURPOSE:
 *  - No account_id / contact_id. The tenant comes from the authenticated
 *    context only (TenantIsolationMiddleware -> requireAccount()), and
 *    the contact is resolved from the phone number. A caller cannot name
 *    either one, so neither can be pointed at another tenant.
 *  - No `exists:users,id` on assigned_user_id. That rule would pass for
 *    a real user in ANOTHER account and fail for an id that exists
 *    nowhere — two different errors for two different reasons, which is
 *    precisely the cross-tenant existence oracle the brief forbids.
 *    CrmLead's own saving guard rejects both with one identical message
 *    instead, so this layer stays out of its way and only checks type.
 *  - No status default. Absent means "use the domain default" (new),
 *    applied once in CrmLeadService.
 *
 * SOURCE IS LOCKED TO `manual`. This endpoint is the manual Add lead path
 * only; automated origins (meta_ad, journey, api, whatsapp) are written
 * by their own server-side writers. `source` may be omitted or sent as
 * `manual`; any other value is a 422 rather than a silently relabelled
 * lead, and CrmLeadController::store() forces `manual` regardless, so
 * the payload can never set another origin.
 */
class StoreCrmLeadRequest extends FormRequest
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
            // max:32 matches ContactGroupController::addContacts()'s own
            // phone rule; normalization happens in ContactResolver, which
            // is also what rejects a string with no digits in it.
            'phone_number' => ['required', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(CrmLead::STATUSES)],
            'source' => ['nullable', 'string', Rule::in([CrmLead::SOURCE_MANUAL])],
            'assigned_user_id' => ['nullable', 'integer'],
            'not_converted_reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
