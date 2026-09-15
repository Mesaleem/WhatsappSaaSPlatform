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
 * needs recipient_phone, 'group' needs group_id instead. The per-variable
 * dynamic-schema rules (targetSchema()/MessageTemplate::variableValidationRules())
 * are copied from that class verbatim, since both endpoints validate a
 * template's `variables` payload against the exact same template schema
 * concept.
 */
class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authenticated by the auth.apikey route middleware before this
        // FormRequest resolves — same as SendTemplateMessageRequest.
        return true;
    }

    /** Same external-API envelope contract as SendTemplateMessageRequest::failedValidation(). */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'status' => false,
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
            'recipient_type' => ['required', Rule::in(['individual', 'group'])],
            'template_id' => ['required', 'integer'],
            'recipient_phone' => ['required_if:recipient_type,individual', 'string', 'max:20'],
            'group_id' => ['required_if:recipient_type,group', 'integer'],
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
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            'recipient_phone.required_if' => 'The "recipient_phone" field is required when recipient_type is "individual".',
            'group_id.required_if' => 'The "group_id" field is required when recipient_type is "group".',
        ];

        foreach ($this->targetSchema() as $field) {
            $attribute = 'variables.'.$field['key'];
            $label = $field['label'] ?? $field['key'];

            $messages["{$attribute}.required"] = "The \"{$label}\" field is required.";
            $messages["{$attribute}.numeric"] = "The \"{$label}\" field must be a number.";
            $messages["{$attribute}.date"] = "The \"{$label}\" field must be a valid date.";
            $messages["{$attribute}.string"] = "The \"{$label}\" field must be text.";
            $messages["{$attribute}.in"] = "The \"{$label}\" field must be one of the allowed options.";
            $messages["{$attribute}.max"] = "The \"{$label}\" field is too long.";
        }

        return $messages;
    }

    /**
     * Same caching/scope rationale as SendTemplateMessageRequest's own
     * targetSchema(): [] when template_id is missing/invalid, and the
     * business check (does this template exist / is it approved for
     * this account) is deliberately left to the dispatcher, not here.
     *
     * @return list<array{key: string, label: string, type: string, required: bool, options?: list<string>}>
     */
    private function targetSchema(): array
    {
        $templateId = $this->input('template_id');

        if (! is_numeric($templateId)) {
            return [];
        }

        $template = MessageTemplate::find((int) $templateId);

        return $template?->effectiveVariablesSchema() ?? [];
    }
}
