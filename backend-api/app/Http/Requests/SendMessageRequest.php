<?php

namespace App\Http\Requests;

use App\Models\MessageTemplate;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Group Messaging Phase 4 — Developer Send Message API's payload
 * validator, POST /api/v1/send-message. A NEW FormRequest rather than
 * reusing SendTemplateMessageRequest: that class's rules() are
 * unconditional (recipient_phone always required), whereas this
 * endpoint's required fields depend on recipient_type — 'individual'
 * needs recipient_phone, 'group' needs group_code instead. The per-variable
 * dynamic-schema rules (targetSchema()/MessageTemplate::variableValidationRules())
 * are copied from that class verbatim, since both endpoints validate a
 * template's `variables` payload against the exact same template schema
 * concept.
 *
 * [Refactor, disclosed]: template_id/group_id (raw DB primary keys)
 * replaced with template_code/group_code (human-readable, string
 * identifiers) -- the same convention send-template's
 * SendTemplateByCodeRequest already established for templates, extended
 * here to groups too. Resolution of both codes into their actual rows
 * happens in TemplateMessageController::sendMessage(), not here -- this
 * class only validates shape/presence, exactly as it did for the
 * integer ids before this change.
 */
class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authenticated by the auth.apikey route middleware before this
        // FormRequest resolves — same as SendTemplateMessageRequest.
        return true;
    }

    /**
     * Field-Specific Validation Error Messages -- `message` states the
     * FIRST failing field concretely; `errors` still carries every
     * failing field. `success` (not this class's previous `status` key)
     * -- this endpoint's OWN controller action
     * (TemplateMessageController::sendMessage()) already returns
     * `success: false` for every OTHER failure mode on this exact route
     * (TEMPLATE_NOT_APPROVED, INVALID_VARIABLES, WHATSAPP_DISCONNECTED,
     * INSUFFICIENT_QUOTA, ...) -- this class's OLD `status` key was
     * already an inconsistency on THIS SAME endpoint, not a contract
     * this change is breaking. (The older, separate /v1/messages/
     * send-template endpoint -- Api\V1\TemplateMessageController::send(),
     * SendTemplateByCodeRequest -- still uses `status` throughout; that
     * class is untouched here since it wasn't named in this request and
     * changing only its validation-failure envelope while its other
     * branches keep `status` would introduce a NEW inconsistency on
     * that endpoint instead of fixing one.)
     */
    protected function failedValidation(Validator $validator): void
    {
        $firstMessage = $validator->errors()->first() ?: 'The given data was invalid.';

        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => "Validation failed: {$firstMessage}",
            'errors' => $validator->errors(),
        ], 422));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'recipient_type' => ['required', Rule::in(['individual', 'group'])],
            'template_code' => ['required', 'string', 'max:100'],
            'recipient_phone' => ['required_if:recipient_type,individual', 'string', 'max:20'],
            'group_code' => ['required_if:recipient_type,group', 'string', 'max:100'],
            'variables' => ['sometimes', 'array'],
            // Media Templates (send-time override, QR/Baileys-only,
            // individual recipients only -- see TemplateMessageController::
            // sendToGroup(), unchanged) -- same lenient, never-422-on-bad-URL
            // rule as SendTemplateMessageRequest's own media_url.
            'media_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            ...MessageTemplate::variableValidationRules($this->targetSchema()),
        ];
    }

    /**
     * Dynamic Field Names in Errors -- see SendTemplateMessageRequest::
     * messages()'s identical wildcard-key rationale.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'recipient_phone.required_if' => 'The :attribute field is required when recipient_type is "individual".',
            'group_code.required_if' => 'The :attribute field is required when recipient_type is "group".',
            'variables.*.required' => 'The :attribute field is required.',
            'variables.*.numeric' => 'The :attribute field must be a number.',
            'variables.*.date' => 'The :attribute field must be a valid date.',
            'variables.*.string' => 'The :attribute field must be text.',
            'variables.*.in' => 'The :attribute field must be one of the allowed options.',
            'variables.*.max' => 'The :attribute field is too long.',
        ];
    }

    /**
     * Attribute Mapping -- see SendTemplateMessageRequest::attributes()'s
     * identical rationale (map every field to its own raw name, strip
     * the "variables." dot-path prefix).
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [
            'recipient_type' => 'recipient_type',
            'template_code' => 'template_code',
            'recipient_phone' => 'recipient_phone',
            'group_code' => 'group_code',
            'variables' => 'variables',
            'media_url' => 'media_url',
        ];

        foreach ($this->targetSchema() as $field) {
            $attributes['variables.'.$field['key']] = $field['key'];
        }

        return $attributes;
    }

    /**
     * Same caching/scope rationale as SendTemplateMessageRequest's own
     * targetSchema(): [] when template_code is missing/blank, and the
     * business check (does this template exist / is it approved for
     * this account) is deliberately left to the controller, not here.
     *
     * @return list<array{key: string, label: string, type: string, required: bool, options?: list<string>}>
     */
    private function targetSchema(): array
    {
        $templateCode = $this->input('template_code');

        if (! is_string($templateCode) || $templateCode === '') {
            return [];
        }

        $template = MessageTemplate::where('template_code', $templateCode)->first();

        return $template?->effectiveVariablesSchema() ?? [];
    }
}
