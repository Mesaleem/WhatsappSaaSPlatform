<?php

namespace App\Http\Requests;

use App\Models\CrmTag;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 6 — CRM Task 7. POST /api/crm/tags.
 *
 * Only `name` is accepted. account_id / agent_id / tenant_id are not
 * rules here, so validated() can never contain them — the owning account
 * always comes from TenantIsolationMiddleware, the same "ignore, never
 * trust" convention as StoreCrmContactRequest / StoreCrmLeadRequest.
 *
 * TrimStrings + ConvertEmptyStringsToNull run first, so "   " arrives as
 * null and fails `required`. Case-insensitive duplicate detection and
 * whitespace collapsing are the model's job (CrmTag's saving guard), so
 * the rule holds for every writer, not just this request.
 */
class StoreCrmTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.CrmTag::NAME_MAX],
        ];
    }
}
