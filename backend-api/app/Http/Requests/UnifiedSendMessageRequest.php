<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Developer API Platform for WhatsApp Group Creation & Unified
 * Messaging -- POST /api/v1/whatsapp/messages/send payload validator.
 *
 * Shape:
 *   recipient_type: 'individual' | 'group'
 *   to:             phone number, required when recipient_type=individual
 *   group_id:       ContactGroup id (int) OR the raw WhatsApp group JID
 *                   string (e.g. "120363xxx@g.us") -- accepts EITHER, per
 *                   this feature's literal spec ("WhatsApp Group JID /
 *                   Group ID") -- required when recipient_type=group.
 *                   Resolved by Api\V1\UnifiedMessageController.
 *   message_type:   'text' | 'media' | 'template'
 *   text.body:                       required when message_type=text
 *   media.media_type/url/caption?/filename?: required when message_type=media
 *   template.name:                   required when message_type=template
 *                                     (matched against MessageTemplate.title
 *                                     -- see Api\V1\UnifiedMessageController)
 *   template.components:             optional; Meta Cloud API's own
 *                                     {type, parameters} component array
 *                                     -- see TemplateComponentTranslator
 *                                     for how it maps onto this app's
 *                                     named template variables.
 */
class UnifiedSendMessageRequest extends FormRequest
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
            'recipient_type' => ['required', Rule::in(['individual', 'group'])],
            'message_type' => ['required', Rule::in(['text', 'media', 'template'])],

            'to' => ['required_if:recipient_type,individual', 'string', 'max:20'],
            'group_id' => ['required_if:recipient_type,group', 'string', 'max:64'],

            'text' => ['required_if:message_type,text', 'array'],
            'text.body' => ['required_if:message_type,text', 'string', 'max:4096'],

            'media' => ['required_if:message_type,media', 'array'],
            'media.media_type' => ['required_if:message_type,media', Rule::in(['image', 'document', 'video', 'audio'])],
            'media.url' => ['required_if:message_type,media', 'string', 'max:2048'],
            'media.caption' => ['nullable', 'string', 'max:1024'],
            'media.filename' => ['nullable', 'string', 'max:255'],

            'template' => ['required_if:message_type,template', 'array'],
            'template.name' => ['required_if:message_type,template', 'string', 'max:255'],
            'template.components' => ['sometimes', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to.required_if' => 'The "to" field is required when recipient_type is "individual".',
            'group_id.required_if' => 'The "group_id" field is required when recipient_type is "group".',
            'text.body.required_if' => 'The "text.body" field is required when message_type is "text".',
            'media.media_type.required_if' => 'The "media.media_type" field is required when message_type is "media".',
            'media.url.required_if' => 'The "media.url" field is required when message_type is "media".',
            'template.name.required_if' => 'The "template.name" field is required when message_type is "template".',
        ];
    }
}
