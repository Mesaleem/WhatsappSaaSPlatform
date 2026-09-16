<?php

namespace App\Http\Requests;

use App\Models\MessageTemplate;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * External Developer API only -- POST /api/v1/messages/send-template.
 *
 * Deliberately a SEPARATE class from SendTemplateMessageRequest, not a
 * modification of it: that class is explicitly shared, byte-for-byte,
 * with the internal Sanctum "Send Alert" route
 * (MessageTemplateController::send()), which this feature does not
 * touch and which still identifies a template by template_id. Changing
 * the shared rule set to require template_code would have silently
 * broken that unrelated internal page. This class exists purely to
 * swap `template_id` (the raw DB primary key -- forced external
 * clients to fetch/guess another tenant's auto-increment id) for a
 * human-readable `template_code` on this one external endpoint.
 *
 * Deliberately does NOT scope targetTemplate()'s lookup to the calling
 * API key's account_id: template_code is globally unique (see the
 * add_template_code_to_message_templates_table migration), so an
 * unscoped lookup by code is already unambiguous, and this mirrors
 * SendTemplateMessageRequest::targetSchema()'s own precedent of an
 * unscoped find-by-unique-identifier used ONLY to resolve the
 * variable-shape validation rules below. The real "does this account
 * own/may-use this template" authorization stays entirely in
 * TemplateMessageController::send()'s own scoped query (account_id
 * match or global/null) and in TemplateMessageDispatcher::dispatch()'s
 * approvedFor() re-check -- unchanged.
 */
class SendTemplateByCodeRequest extends FormRequest
{
    private MessageTemplate|false|null $resolvedTemplate = null;

    public function authorize(): bool
    {
        // Authenticated by the auth.apikey/auth.apisecret route
        // middleware before this FormRequest resolves -- see
        // SendTemplateMessageRequest::authorize()'s identical note.
        return true;
    }

    /**
     * Same {"status": false, ...} envelope as SendTemplateMessageRequest
     * -- see that class's own docblock for why the external Developer
     * API contract needs the `status` key on every failure mode.
     */
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
            'template_code' => ['required', 'string', 'max:100'],
            'recipient_phone' => ['required', 'string', 'max:20'],
            'variables' => ['sometimes', 'array'],
            // Media Templates (send-time override, QR/Baileys-only) --
            // identical, deliberately lenient handling to
            // SendTemplateMessageRequest::rules()'s own media_url rule;
            // see that class's docblock for why this is not validated
            // as a strict URL.
            'media_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            ...MessageTemplate::variableValidationRules($this->targetSchema()),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [];

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
     * The template matching this request's template_code, looked up
     * once and cached (false sentinel = "looked up, no match") since
     * rules() and messages() both need it and it is otherwise one extra
     * query each. Returns null when template_code is missing/blank --
     * the `required` rule above still fires and produces the normal
     * validation error in that case.
     *
     * @return list<array{key: string, label: string, type: string, required: bool, options?: list<string>}>
     */
    private function targetSchema(): array
    {
        $code = $this->input('template_code');

        if (! is_string($code) || $code === '') {
            return [];
        }

        if ($this->resolvedTemplate === null) {
            $this->resolvedTemplate = MessageTemplate::query()->where('template_code', $code)->first() ?? false;
        }

        return $this->resolvedTemplate ? $this->resolvedTemplate->effectiveVariablesSchema() : [];
    }
}
