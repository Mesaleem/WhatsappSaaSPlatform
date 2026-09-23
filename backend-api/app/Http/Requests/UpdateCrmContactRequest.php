<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 6 — CRM Hardening, Issue 2. PUT/PATCH /api/crm/contacts/{id}.
 *
 * `sometimes` throughout, so a PATCH with one field leaves the rest
 * alone. account_id is absent and therefore never reaches validated(),
 * which is the only array ContactService::update() reads — a request
 * that sends it is ignored rather than rejected, and a test asserts the
 * contact's tenant is unchanged afterwards.
 *
 * phone_number uniqueness within the account is NOT expressed as a
 * `unique:` rule here: it has to be checked against the NORMALIZED form
 * (so that "+91 98765 43210" collides with "9876543210"), which a
 * validation rule running on raw input cannot do. ContactService::update()
 * normalizes first and then checks, raising the same field-keyed 422.
 */
class UpdateCrmContactRequest extends FormRequest
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
            'phone_number' => ['sometimes', 'string', 'max:32'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
        ];
    }
}
