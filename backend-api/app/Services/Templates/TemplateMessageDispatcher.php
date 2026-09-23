<?php

namespace App\Services\Templates;

use App\Models\Account;
use App\Models\MessageDispatchLog;
use App\Models\MessageTemplate;
use App\Services\Messaging\MessageQuotaService;
use App\Services\PaymentAlerts\PaymentAlertDispatcher;
use App\Services\WhatsApp\WhatsAppEngineFactory;
use App\Support\PhoneNumberNormalizer;
use App\Support\TemplateRenderer;
use App\Support\WhatsAppMediaPayloadBuilder;
use RuntimeException;

/**
 * Dynamic Templates & Variables System — shared send logic for BOTH the
 * internal, Sanctum-authenticated Send Alert dynamic form
 * (MessageTemplateController-adjacent route) and the external, API-key
 * authenticated POST /v1/messages/send-template endpoint
 * (Api\V1\TemplateMessageController) — same DRY rationale as
 * PaymentAlertDispatcher's own docblock: one path, so a fix or a quota
 * rule can never silently apply to only one of the two callers.
 *
 * Deliberately synchronous (no queued Job, unlike ProcessPaymentAlertJob)
 * — there is no persisted "template message" row in this turn's scope
 * (only message_templates, the template DEFINITIONS, was asked for), so
 * there is nothing for a queued job to update afterward; the caller gets
 * the WhatsApp driver's real send result directly in the response,
 * mirroring NotificationBroadcastController's existing synchronous-send
 * pattern (QUEUE_CONNECTION=sync in this environment, confirmed before
 * writing that controller).
 */
class TemplateMessageDispatcher
{
    /**
     * @return array{
     *   status: 'sent'|'failed'|'disconnected'|'not_found'|'missing_variables'|'quota_exhausted',
     *   message?: string,
     *   missing?: list<string>,
     *   rendered_message?: string,
     * }
     */
    // [New feature, disclosed]: $source/$apiKeyId label the
    // MessageDispatchLog row this method now writes at every return
    // point below that represents an actual attempted or completed
    // send — mirrors ProcessPaymentAlertJob's fail()/success logging
    // completeness (no active subscription, quota exhausted, driver
    // resolution error, driver rejection, and the final success are
    // all logged there too).
    public static function dispatch(
        int $accountId,
        int $templateId,
        string $recipientPhone,
        array $variables,
        string $source = 'web_template',
        ?int $apiKeyId = null,
        // Media Templates (send-time override) -- ONE optional media_url
        // key in the send payload (SendTemplateMessageRequest/
        // SendMessageRequest), deliberately with no separate media_type
        // field alongside it -- see resolveMediaMetaData() below, which
        // infers image vs document from the URL's extension. Falls back
        // to the template's own configured header_media_url (Template
        // Manager/Request-a-Template) when the caller doesn't supply
        // one, and to plain text if neither is present or valid.
        ?string $mediaUrl = null,
    ): array
    {
        $account = Account::with(['currentSubscription', 'whatsAppSession'])->find($accountId);

        if (! $account) {
            return ['status' => 'not_found', 'message' => 'Account not found.'];
        }

        if (! $account->hasActiveSubscription()) {
            MessageDispatchLog::record($accountId, $source, $recipientPhone, success: false, errorReason: 'No active subscription or quota exhausted.', apiKeyId: $apiKeyId, referenceType: 'template', referenceId: $templateId);

            return ['status' => 'quota_exhausted', 'message' => $account->quotaExhaustedMessage()];
        }

        $template = MessageTemplate::query()->approvedFor($account->id)->find($templateId);

        if (! $template) {
            MessageDispatchLog::record($accountId, $source, $recipientPhone, success: false, errorReason: 'Template not found, not approved, or not available to this account.', apiKeyId: $apiKeyId, referenceType: 'template', referenceId: $templateId);

            return ['status' => 'not_found', 'message' => 'This template does not exist, is not approved, or is not available to this account.'];
        }

        // Dynamic Template Engine — Variable Type & Validation Controls:
        // "required" now comes from the template's configured schema, not
        // "every {{token}} is required" — a variable the Configurator Panel
        // marked optional must NOT block sending just because the caller
        // left it blank. SendTemplateMessageRequest already enforces this
        // same required-ness (plus per-field type) for both entry points
        // that reach this method; this check stays as defense-in-depth so
        // dispatch() is correct even if called some other way in future.
        $requiredKeys = array_values(array_map(
            fn (array $field) => $field['key'],
            array_filter($template->effectiveVariablesSchema(), fn (array $field) => ! empty($field['required'])),
        ));
        $missing = array_values(array_filter($requiredKeys, fn (string $name) => ! array_key_exists($name, $variables) || $variables[$name] === ''));

        if (! empty($missing)) {
            return ['status' => 'missing_variables', 'message' => 'Missing value(s) for: '.implode(', ', $missing), 'missing' => $missing];
        }

        // Phase 4 Task 5 (requirement 4) -- a Meta-defined template names a
        // template registered inside a WABA, so it is only sendable by an
        // account actually on the Meta provider; a QR account has no WABA
        // for the name to resolve against. Guarded strictly on
        // isMetaDefined(), which is false for every template that existed
        // before this task (meta_template_name is a new nullable column),
        // so no existing send path changes behaviour.
        if ($template->isMetaDefined() && $account->currentSubscription?->engine_type !== 'meta') {
            $msg = 'This template is registered with Meta and can only be sent by an account using the Meta Cloud API provider.';
            MessageDispatchLog::record($accountId, $source, $recipientPhone, success: false, errorReason: $msg, apiKeyId: $apiKeyId, referenceType: 'template', referenceId: $template->id, templateName: $template->title);

            return ['status' => 'failed', 'message' => $msg];
        }

        if (PaymentAlertDispatcher::isWhatsAppDisconnected($account)) {
            MessageDispatchLog::record($accountId, $source, $recipientPhone, success: false, errorReason: 'WhatsApp account is disconnected.', apiKeyId: $apiKeyId, referenceType: 'template', referenceId: $template->id, templateName: $template->title);

            return ['status' => 'disconnected', 'message' => 'WhatsApp account is disconnected. Please connect your device first.'];
        }

        // An optional variable the caller left out must not render as a
        // literal "{{token}}" in the outgoing WhatsApp message —
        // TemplateRenderer::render() deliberately leaves unmatched tokens
        // untouched (by design, for the Mail Template Manager's own use —
        // see that class's docblock), so this fills every schema variable
        // not supplied with '' before rendering, scoped to this dispatcher
        // only. A missing REQUIRED variable never reaches this line — the
        // missing-variables check above already returned for that case.
        $renderVariables = $variables;
        foreach ($template->effectiveVariablesSchema() as $field) {
            if (! array_key_exists($field['key'], $renderVariables)) {
                $renderVariables[$field['key']] = '';
            }
        }

        $renderedMessage = TemplateRenderer::render($template->template_body, $renderVariables);

        $normalizedPhone = PhoneNumberNormalizer::normalize($recipientPhone);
        if ($normalizedPhone === '') {
            $msg = "Recipient phone number '{$recipientPhone}' is not a valid number after normalization.";
            MessageDispatchLog::record($accountId, $source, $recipientPhone, success: false, errorReason: $msg, apiKeyId: $apiKeyId, referenceType: 'template', referenceId: $template->id, templateName: $template->title, messagePreview: $renderedMessage);

            return ['status' => 'failed', 'message' => $msg];
        }

        try {
            $driver = WhatsAppEngineFactory::make($account);
        } catch (RuntimeException $e) {
            MessageDispatchLog::record($accountId, $source, $normalizedPhone, success: false, errorReason: $e->getMessage(), apiKeyId: $apiKeyId, referenceType: 'template', referenceId: $template->id, templateName: $template->title, messagePreview: $renderedMessage);

            return ['status' => 'failed', 'message' => $e->getMessage()];
        }

        // [Bug fix, disclosed]: resolved once and reused below for the
        // success MessageDispatchLog::record() call's $hasMedia flag --
        // previously this dispatcher built media metadata and actually
        // sent the attachment, but never told the audit log a file was
        // attached, so Message Logs' "Media Attachment" column read "No"
        // even for a real Media Template send (see record()'s own
        // docblock for the full root cause).
        $mediaMetaData = self::resolveMediaMetaData($template, $account, $mediaUrl);

        // Phase 4 Task 6 -- a Meta-DEFINED template on a Meta account is
        // the only case that switches to Graph's `type: template` payload.
        // Everything else (every QR send, and a Meta account sending an
        // ordinary non-Meta-defined template) keeps the existing
        // rendered-text path byte for byte.
        $isMetaTemplateSend = $template->isMetaDefined()
            && $account->currentSubscription?->engine_type === 'meta';

        // Phase 4 Task 7 -- set only when this send actually emits a Meta
        // media header component. resolveMediaMetaData() returns [] for
        // every Meta account (by design: it is the QR media path), so
        // without this the audit row for a Meta media-header send would
        // read "Media Attachment: No" even though a file was attached --
        // the exact bug MessageDispatchLog::record()'s own docblock
        // records for the QR path. Nothing else reads it.
        $metaHeaderMediaUrl = null;

        if ($isMetaTemplateSend) {
            // Validation BEFORE the Graph call -- a send that Meta is
            // certain to reject is refused locally instead, and no
            // request ever leaves this process.
            if (! filled($template->language)) {
                $msg = 'This Meta template has no language code configured, so it cannot be sent. Set its language (for example "en_US") first.';
                MessageDispatchLog::record($accountId, $source, $normalizedPhone, success: false, errorReason: $msg, apiKeyId: $apiKeyId, referenceType: 'template', referenceId: $template->id, templateName: $template->title, messagePreview: $renderedMessage);

                return ['status' => 'failed', 'message' => $msg];
            }

            // Phase 4 Task 7 -- a schema that cannot be expressed as a
            // valid Meta component set (a 'footer' parameter, a button
            // field with no sub_type/index, two header parameters, a
            // media header that also declares header text) is refused
            // here rather than sent and rejected by Graph. Checked BEFORE
            // the missing-value check: no set of supplied values can make
            // a structurally invalid definition sendable. Returns
            // 'failed', which both API controllers already map to 422.
            $structureErrors = TemplateComponentTranslator::componentStructureErrors($template);

            if ($structureErrors !== []) {
                $msg = 'This template\'s variable schema cannot be sent to Meta: '.implode(' ', $structureErrors);
                MessageDispatchLog::record($accountId, $source, $normalizedPhone, success: false, errorReason: $msg, apiKeyId: $apiKeyId, referenceType: 'template', referenceId: $template->id, templateName: $template->title, messagePreview: $renderedMessage);

                return ['status' => 'failed', 'message' => $msg];
            }

            // Meta requires every positional parameter to be present and
            // non-empty; the plain-text path's ''-substitution for an
            // optional variable is not valid here.
            $missingForMeta = TemplateComponentTranslator::missingComponentValues($template, $variables);

            if ($missingForMeta !== []) {
                $msg = 'Missing value(s) for: '.implode(', ', $missingForMeta);
                MessageDispatchLog::record($accountId, $source, $normalizedPhone, success: false, errorReason: $msg, apiKeyId: $apiKeyId, referenceType: 'template', referenceId: $template->id, templateName: $template->title, messagePreview: $renderedMessage);

                return ['status' => 'missing_variables', 'message' => $msg, 'missing' => $missingForMeta];
            }

            // Phase 4 Task 7 -- the send-time $mediaUrl override is passed
            // through so a media-header template carries the file this
            // send supplied, exactly as the QR path already allows via
            // resolveMediaMetaData(). No new media storage is involved:
            // the URL is the caller's own, and the template's configured
            // header_media_url remains the fallback.
            $components = TemplateComponentTranslator::toComponents($template, $variables, $mediaUrl);
            $metaHeaderMediaUrl = TemplateComponentTranslator::headerMediaUrlIn($components);

            // Deliberately NOT spread with $mediaMetaData or
            // 'template_id': both are engine-internal hints that would
            // land in the Graph body as unknown top-level keys.
            // resolveMediaMetaData() already returns [] for a Meta
            // account, so nothing is lost.
            $metaData = [
                'type' => 'template',
                'template' => array_filter([
                    'name' => $template->meta_template_name,
                    'language' => ['code' => $template->language],
                    // Omitted entirely when the template takes no
                    // parameters -- Meta rejects an empty components
                    // array on such a template.
                    'components' => $components !== [] ? $components : null,
                ], static fn ($value) => $value !== null),
            ];
        } else {
            $metaData = [
                'template_id' => $template->id,
                ...$mediaMetaData,
            ];
        }

        $result = $driver->sendMessage($normalizedPhone, $renderedMessage, $metaData);

        if (empty($result['success'])) {
            $msg = $result['error'] ?? 'The WhatsApp engine rejected the message.';
            MessageDispatchLog::record($accountId, $source, $normalizedPhone, success: false, errorReason: $msg, apiKeyId: $apiKeyId, referenceType: 'template', referenceId: $template->id, templateName: $template->title, messagePreview: $renderedMessage);

            return ['status' => 'failed', 'message' => $msg];
        }

        $subscription = $account->currentSubscription;
        // [Bug fix, disclosed]: this increment was previously gated behind
        // `billing_model === 'per_message'`, so a 'flat_quota' or
        // 'unlimited' account's used_messages NEVER moved for a message
        // sent via Send Template — confirmed root cause of "message
        // metrics and quota counters are not incrementing" for template
        // sends specifically. ProcessPaymentAlertJob (the Send Alert path)
        // increments used_messages unconditionally for every successful
        // send regardless of billing_model, and correctly reserves the
        // per_message check ONLY for its separate cost_deducted (money
        // actually charged) calculation — a concept that doesn't even
        // exist here, since a template send creates no payment_alerts
        // row. total_allocated_messages/used_messages tracking applies to
        // every capped plan, not only 'per_message' ones (see
        // ProcessPaymentAlertJob's own quotaExhausted check above, which
        // is likewise unconditional on billing_model), so a 'flat_quota'
        // account could previously send unlimited template messages with
        // zero quota consumption — a real usage-tracking/billing gap, not
        // just a display bug. Now unconditional, matching
        // ProcessPaymentAlertJob exactly.
        if ($subscription) {
            // Phase 5 Task 3 -- the transaction + row lock + increment +
            // refreshStatus that used to sit inline here is now
            // MessageQuotaService::consume(), byte-for-byte the same
            // operation in one shared place. This path keeps its
            // check -> send -> consume order exactly: the message is
            // already on WhatsApp by this line, so usage is RECORDED,
            // never re-gated. Turning this into reserve() would change
            // when a send is refused and is deliberately out of scope.
            app(MessageQuotaService::class)->consume($subscription, 1);
        }

        // Group Messaging Phase 4 — dispatch_log_id is additive (a new
        // key on this return array), so the existing internal Send Alert
        // caller and Api\V1\TemplateMessageController::send() (which only
        // destructure 'status'/'rendered_message'/'message') are both
        // unaffected. Api\V1\TemplateMessageController::sendMessage()
        // (the new /v1/send-message individual-recipient path) is the
        // first caller that actually reads it, to return it as this
        // endpoint's own dispatch_id.
        $log = MessageDispatchLog::record($accountId, $source, $normalizedPhone, success: true, apiKeyId: $apiKeyId, referenceType: 'template', referenceId: $template->id, templateName: $template->title, messagePreview: $renderedMessage, gatewayMessageId: $result['message_id'] ?? null, hasMedia: array_key_exists('media_url', $mediaMetaData) || $metaHeaderMediaUrl !== null, mediaUrl: $mediaMetaData['media_url'] ?? $metaHeaderMediaUrl);

        return ['status' => 'sent', 'rendered_message' => $renderedMessage, 'dispatch_log_id' => $log->id];
    }

    /**
     * Media Templates (QR/Baileys-only) -- ONE place both dispatch() and
     * MessageTemplateController::test() (the Testing Gate's own send)
     * call, so "is there usable media, and what kind" is answered
     * identically for both.
     *
     * Precedence: a send-time $mediaUrl (from the caller's payload) wins
     * over the template's own configured header_media_url -- letting the
     * exact same approved template carry a DIFFERENT file per send (e.g.
     * a customer's own bill PDF) without needing a separate template per
     * customer. Falls back to the template's default when the caller
     * doesn't supply one, and to plain text (empty array -- caller
     * spreads this into $metaData, so nothing extra is added) when
     * neither is present, isn't http(s), or the account isn't on the
     * 'qr' engine -- MetaCloudApiDriver has no support for arbitrary
     * media URLs (a Meta Cloud API template's media must go through
     * Meta's own template-media registration/approval, which this
     * feature does not implement), so a 'meta'-engine account always
     * gets plain text regardless of either media_url.
     *
     * Deliberately never rejects/errors on a bad media_url -- an invalid
     * or unreachable-looking URL just means "send as text", exactly like
     * omitting it, rather than failing the whole send over an
     * attachment.
     *
     * @return array{media_type?: string, media_url?: string, filename?: string}
     */
    public static function resolveMediaMetaData(MessageTemplate $template, Account $account, ?string $mediaUrl): array
    {
        $effectiveUrl = filled($mediaUrl) ? $mediaUrl : $template->header_media_url;

        if (! filled($effectiveUrl) || $account->currentSubscription?->engine_type !== 'qr') {
            return [];
        }

        if (filter_var($effectiveUrl, FILTER_VALIDATE_URL) === false || ! preg_match('#^https?://#i', $effectiveUrl)) {
            return [];
        }

        $mediaType = WhatsAppMediaPayloadBuilder::inferMediaType($effectiveUrl);
        $metaData = ['media_type' => $mediaType, 'media_url' => $effectiveUrl];

        if ($mediaType === 'document') {
            $path = parse_url($effectiveUrl, PHP_URL_PATH) ?: '';
            $metaData['filename'] = basename($path) ?: 'document';
        }

        return $metaData;
    }
}
