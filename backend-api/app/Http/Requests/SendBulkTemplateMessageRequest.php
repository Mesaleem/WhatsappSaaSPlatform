<?php

namespace App\Http\Requests;

use App\Models\MessageTemplate;
use App\Services\Templates\BulkMessageCooldown;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Anti-Spam Bulk Dispatch -- POST /api/alerts/send-template-bulk's own
 * validator. Deliberately a SEPARATE class from SendTemplateMessageRequest
 * rather than teaching that one to accept an array, for the same reason
 * this feature got a brand-new route instead of changing the existing
 * one: SendTemplateMessageRequest's rules() are shared byte-for-byte by
 * BOTH the internal /alerts/send-template route and the external,
 * API-key-authenticated POST /v1/messages/send-template (see that
 * class's own docblock) -- widening `recipient_phone` there to also
 * accept an array would change that external Developer API's contract,
 * which is explicitly out of scope for this internal-only, Send-Alert-
 * page bulk feature. This class duplicates the (small) variables-schema
 * validation pattern from SendTemplateMessageRequest rather than sharing
 * it, to keep that existing class completely untouched.
 */
class SendBulkTemplateMessageRequest extends FormRequest
{
    /**
     * Strict Bulk Messaging Limit -- 150 recipients per request, not a
     * soft default. Delegates to BulkMessageCooldown::
     * MAX_RECIPIENTS_PER_BATCH (single source of truth -- that class
     * also needs this exact number to decide whether a completed
     * dispatch should start the tier-based cooldown) rather than
     * redeclaring it here.
     */
    public const MAX_RECIPIENTS = BulkMessageCooldown::MAX_RECIPIENTS_PER_BATCH;

    public function authorize(): bool
    {
        // Same reasoning as SendTemplateMessageRequest::authorize() --
        // route middleware (Sanctum + permission:send-messages) has
        // already authenticated/authorized the caller before this
        // FormRequest resolves.
        return true;
    }

    protected function failedValidation(Validator $validator): void
    {
        // `success: false` -- added for envelope consistency with this
        // same feature's other error paths (the 429 cooldown response
        // and the 404 template-not-found response both already use
        // `success`), not because this route has an external-API
        // contract to honor (it doesn't -- see this class's own
        // docblock).
        //
        // [Fix, self-review]: prefixed with "Validation failed: " and
        // switched messages()/attributes() to the same wildcard-key +
        // raw-attribute-name pattern as SendTemplateMessageRequest /
        // SendMessageRequest -- this class was added in an earlier pass
        // and missed that refactor, so the bulk/CSV endpoint was
        // returning a differently-shaped, differently-worded error
        // ("The \"Customer Name\" field is required.") than the
        // single-recipient endpoints ("The customer_name field is
        // required.") for the exact same failure. Both routes now match.
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
            'recipient_phones' => ['required', 'array', 'min:1', 'max:'.self::MAX_RECIPIENTS],
            'recipient_phones.*' => ['required', 'string', 'max:20'],
            'variables' => ['sometimes', 'array'],
            // Media Templates override -- same contract as
            // SendTemplateMessageRequest::rules(), applied identically to
            // every recipient in this batch (one template + one set of
            // variables + one optional media_url per bulk request, only
            // the phone number varies per job).
            'media_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            ...MessageTemplate::variableValidationRules($this->targetSchema()),
        ];
    }

    /**
     * Dynamic Field Names in Errors -- wildcard keys ("variables.*.
     * required", not "variables.customer_name.required"), matching
     * SendTemplateMessageRequest::messages(). Laravel resolves the
     * `:attribute` token per the SPECIFIC failing field via attributes()
     * below, so one entry per rule type covers every variable the
     * template's schema defines.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // Literal copy requested for this feature -- kept identical
            // to the frontend's own input-validation warning
            // (SendAlertPage.tsx) so a caller sees the exact same wording
            // whether the 150-cap is caught client-side or server-side.
            'recipient_phones.max' => 'Maximum '.self::MAX_RECIPIENTS.' contacts allowed per batch. For larger lists, create a Group or upgrade your plan.',
            'variables.*.required' => 'The :attribute field is required.',
            'variables.*.numeric' => 'The :attribute field must be a number.',
            'variables.*.date' => 'The :attribute field must be a valid date.',
            'variables.*.string' => 'The :attribute field must be text.',
            'variables.*.in' => 'The :attribute field must be one of the allowed options.',
            'variables.*.max' => 'The :attribute field is too long.',
        ];
    }

    /**
     * Attribute Mapping -- maps every payload key (including each
     * `variables.{key}` dot-path) to its own raw name, so `:attribute`
     * renders e.g. "customer_name", never Laravel's humanized default
     * ("customer name") and never the raw "variables.customer_name"
     * dot-path. Matches SendTemplateMessageRequest::attributes().
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [
            'template_id' => 'template_id',
            'recipient_phones' => 'recipient_phones',
            'variables' => 'variables',
            'media_url' => 'media_url',
        ];

        foreach ($this->targetSchema() as $field) {
            $attributes['variables.'.$field['key']] = $field['key'];
        }

        return $attributes;
    }

    /**
     * Same caching rationale as SendTemplateMessageRequest::targetSchema()
     * -- both rules() and messages() need it.
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
