<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 6 — CRM Hardening, Issue 2. POST /api/crm/contacts.
 *
 * No account_id: the tenant comes from the authenticated context only.
 * No uniqueness rule on phone_number either — a repeat number is not an
 * error here, it returns the existing contact (see ContactService
 * ::create), and a `unique:contacts` rule would turn that into a 422.
 *
 * The `email` rule is Laravel's own, matching TeamController::store()
 * and AccountController::store(); nothing in this codebase lower-cases
 * an email before storing it, so nothing here does either.
 */
class StoreCrmContactRequest extends FormRequest
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
            'phone_number' => ['required', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
        ];
    }
}
