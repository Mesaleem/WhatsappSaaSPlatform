<?php

namespace App\Http\Requests;

use App\Models\MessageTemplate;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Dynamic Template Engine — API Runtime Validator.
 *
 * This is the first FormRequest class in this codebase (every other
 * controller validates inline via $request->validate()) — a deliberate
 * exception, not a drive-by convention change: the rule set for
 * `variables` cannot be known statically, it depends on looking up the
 * target template's effectiveVariablesSchema() first, and this exact
 * rule set must be shared, byte-for-byte, by BOTH send-template entry
 * points (MessageTemplateController::send() — internal, Sanctum-auth —
 * and Api\V1\TemplateMessageController::send() — external, API-key
 * auth). A FormRequest's rules() can perform that DB-driven lookup and
 * be injected into both controllers unchanged; duplicating this logic
 * inline in each controller is exactly the kind of two-parallel-systems
 * drift this codebase's own prior docblocks (TemplateMessageDispatcher,
 * AuthenticateApiKey) have repeatedly chosen to avoid.
 *
 * Scope note: this validates the SHAPE of the payload against the
 * template's configured schema (types, required-ness, select options).
 * Business rules that depend on WHICH account is sending (is this
 * template approved for this tenant? is the account's quota exhausted?
 * is WhatsApp connected?) stay in TemplateMessageDispatcher, which is
 * the one place that already knows how to resolve "account" identically
 * for both a Sanctum session and an API-key request.
 */
class SendTemplateMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Both callers are already authenticated by route middleware
        // (Sanctum for the internal route, AuthenticateApiKey for the
        // external one) before this FormRequest resolves — nothing
        // further to check here.
        return true;
    }

    /**
     * Both send-template entry points reach this ONE FormRequest, but
     * they have different response-envelope contracts: the internal,
     * Sanctum-authenticated route is just another page in this SPA and
     * should get Laravel's ordinary {message, errors} validation shape,
     * like every other form in the app (so the shared frontend
     * extractErrorMessage() utility handles it with no special case).
     * The external Developer API
     * (Api\V1\TemplateMessageController) contractually returns
     * {"status": false, "message": ...} for every other failure mode
     * (auth, not-found, quota, disconnected — see AuthenticateApiKey and
     * that controller) — a 422 that dropped the `status` key would be an
     * inconsistent envelope for an external integrator checking
     * response.status. This adds `status: false` unconditionally — a
     * harmless extra field for the internal caller, the contractually
     * required one for the external caller — rather than forking the
     * response shape by route.
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
            'template_id' => ['required', 'integer'],
            'recipient_phone' => ['required', 'string', 'max:20'],
            'variables' => ['sometimes', 'array'],
            ...MessageTemplate::variableValidationRules($this->targetSchema()),
        ];
    }

    /**
     * Friendly, field-labelled messages — "The Payment Amount field is
     * required." instead of Laravel's default "The variables.amount
     * field is required.", which leaks the internal payload shape and
     * means nothing to whoever is filling in the dynamic form.
     *
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
     * The target template's effective schema — cached per request since
     * both rules() and messages() need it, and it is otherwise one extra
     * query each. Returns [] (no per-variable rules beyond the static
     * ones above) when template_id is missing/invalid/not found; the
     * `exists:message_templates,id` business check on `template_id`
     * itself is deliberately NOT added here — TemplateMessageDispatcher
     * already returns a clean, uniform "not_found" result for a bad or
     * unapproved-for-this-account template_id, and duplicating that as a
     * raw exists() rule here would produce a second, differently-worded
     * 404/422 for the exact same condition.
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
