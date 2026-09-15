<?php

namespace App\Http\Requests;

use App\Models\ContactGroup;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Developer API Platform for WhatsApp Group Creation & Unified
 * Messaging -- POST /api/v1/whatsapp/groups/create payload validator.
 * Rules mirror ContactGroupController::store()'s existing inline
 * validation exactly (same field names/constraints), since
 * Api\V1\GroupController::create() ultimately creates the SAME kind of
 * ContactGroup row through the SAME NativeGroupCreationService for the
 * native path.
 */
class CreateWhatsAppGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authenticated by the auth.apisecret route middleware before
        // this FormRequest resolves.
        return true;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'error_code' => 'VALIDATION_ERROR',
            'message' => $validator->errors()->first() ?: 'The given data was invalid.',
            'errors' => $validator->errors(),
        ], 422));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // group_type is optional -- 'internal_segment' (a plain
            // broadcast segment, requirement 2's "internal broadcast
            // segments" clause) is the default when omitted, matching
            // ContactGroupController::store()'s own default.
            'group_type' => ['sometimes', 'string', Rule::in(ContactGroup::GROUP_TYPES)],
            // Required only for group_type=native_wa_group -- Baileys'
            // groupCreate() has no concept of a zero-participant group.
            'contacts' => ['required_if:group_type,'.ContactGroup::GROUP_TYPE_NATIVE, 'array', 'min:1'],
            'contacts.*.phone_number' => ['required_with:contacts', 'string', 'max:32'],
            'contacts.*.name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
