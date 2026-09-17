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
     * [Correction, disclosed]: the docblock this replaced claimed this
     * class is shared "byte-for-byte" by both the internal Sanctum route
     * and the external Developer API. That was already inaccurate before
     * this change -- grep confirms the external POST /v1/messages/
     * send-template endpoint (Api\V1\TemplateMessageController::send())
     * type-hints SendTemplateByCodeRequest, a separate class, not this
     * one. This class's only actual caller is the internal, Sanctum-
     * authenticated MessageTemplateController::send() (the Send Alert
     * page's own form submit) -- there is no external-API envelope
     * contract to stay consistent with here.
     *
     * Field-Specific Validation Error Messages -- `message` states the
     * FIRST failing field concretely (never Laravel's generic "The given
     * data was invalid."); `errors` still carries every failing field's
     * message(s) so a caller that wants all of them doesn't have to
     * parse the message string. `success` (not this class's previous
     * `status` key -- see the correction above, there is no competing
     * convention on this route to preserve) matches this app's more
     * common envelope key (ContactGroupController, Api\V1\GroupController,
     * TemplateMessageController::sendMessage() all already use
     * `success`). The frontend's extractErrorMessage()/extractFieldErrors()
     * (src/utils/apiError.ts) only ever read `.message`/`.errors` --
     * never `.status`/`.success` -- so this key rename has no visible
     * effect on the existing Send Alert page.
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
            'template_id' => ['required', 'integer'],
            'recipient_phone' => ['required', 'string', 'max:20'],
            'variables' => ['sometimes', 'array'],
            // Media Templates (send-time override, QR/Baileys-only) --
            // ONE optional key, deliberately with no separate media_type
            // field: TemplateMessageDispatcher::resolveMediaMetaData()
            // infers image vs document from the URL's extension. NOT
            // validated as a strict URL here on purpose -- an
            // unparseable value is simply treated as "no media" and the
            // template still sends as plain text (see that method's own
            // docblock), rather than 422-rejecting the whole send over a
            // bad attachment link.
            'media_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            ...MessageTemplate::variableValidationRules($this->targetSchema()),
        ];
    }

    /**
     * Dynamic Field Names in Errors -- wildcard keys ("variables.*.
     * required", not "variables.customer_name.required"). Laravel still
     * resolves the `:attribute` token per the SPECIFIC failing field
     * (via attributes() below), not the literal "*", so one entry per
     * rule type covers every variable the template's schema defines
     * instead of looping to build one entry per field.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'variables.*.required' => 'The :attribute field is required.',
            'variables.*.numeric' => 'The :attribute field must be a number.',
            'variables.*.date' => 'The :attribute field must be a valid date.',
            'variables.*.string' => 'The :attribute field must be text.',
            'variables.*.in' => 'The :attribute field must be one of the allowed options.',
            'variables.*.max' => 'The :attribute field is too long.',
        ];
    }

    /**
     * Attribute Mapping -- maps every payload key to ITS OWN raw name,
     * so a validation message's `:attribute` token (both the wildcard
     * ones above and Laravel's own defaults for template_id/
     * recipient_phone) renders the exact field the caller sent -- e.g.
     * "template_id", "customer_name" -- never Laravel's default
     * humanized form (which would turn "template_id" into "template
     * id", spaces and all) and never the nested "variables.customer_name"
     * dot-path leaking into the sentence.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [
            'template_id' => 'template_id',
            'recipient_phone' => 'recipient_phone',
            'variables' => 'variables',
            'media_url' => 'media_url',
        ];

        foreach ($this->targetSchema() as $field) {
            $attributes['variables.'.$field['key']] = $field['key'];
        }

        return $attributes;
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
