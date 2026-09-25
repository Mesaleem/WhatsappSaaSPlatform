<?php

namespace App\Services\Templates;

use App\Models\Account;
use App\Models\MessageDispatchLog;
use App\Models\MessageTemplate;
use App\Services\PaymentAlerts\PaymentAlertDispatcher;
use App\Services\WhatsApp\WhatsAppEngineFactory;
use App\Support\PhoneNumberNormalizer;
use App\Support\TemplateRenderer;
use App\Support\WhatsAppMediaPayloadBuilder;
use Illuminate\Support\Facades\DB;
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

        // [Bug fix, disclosed]: resolved once and reused below for the
        // success MessageDispatchLog::record() call's $hasMedia flag --
        // previously this dispatcher built media metadata and actually
        // sent the attachment, but never told the audit log a file was
        // attached, so Message Logs' "Media Attachment" column read "No"
        // even for a real Media Template send (see record()'s own
        // docblock for the full root cause).
        // Moved up (it is a pure function) so the historical snapshot
        // below can record the media that was actually attached.
        $mediaMetaData = self::resolveMediaMetaData($template, $account, $mediaUrl);

        // Message Log "View Message": frozen copy of exactly what this
        // send used, so the log never has to guess from the CURRENT
        // (possibly since-edited) template definition.
        $snapshot = self::buildSnapshot($template, $account, $renderVariables, $renderedMessage, $mediaMetaData);

        $normalizedPhone = PhoneNumberNormalizer::normalize($recipientPhone);
        if ($normalizedPhone === '') {
            $msg = "Recipient phone number '{$recipientPhone}' is not a valid number after normalization.";
            MessageDispatchLog::record($accountId, $source, $recipientPhone, success: false, errorReason: $msg, apiKeyId: $apiKeyId, referenceType: 'template', referenceId: $template->id, templateName: $template->title, messagePreview: $renderedMessage, templateSnapshot: $snapshot);

            return ['status' => 'failed', 'message' => $msg];
        }

        try {
            $driver = WhatsAppEngineFactory::make($account);
        } catch (RuntimeException $e) {
            MessageDispatchLog::record($accountId, $source, $normalizedPhone, success: false, errorReason: $e->getMessage(), apiKeyId: $apiKeyId, referenceType: 'template', referenceId: $template->id, templateName: $template->title, messagePreview: $renderedMessage, templateSnapshot: $snapshot);

            return ['status' => 'failed', 'message' => $e->getMessage()];
        }

        $metaData = [
            'template_id' => $template->id,
            ...$mediaMetaData,
        ];

        $result = $driver->sendMessage($normalizedPhone, $renderedMessage, $metaData);

        if (empty($result['success'])) {
            $msg = $result['error'] ?? 'The WhatsApp engine rejected the message.';
            MessageDispatchLog::record($accountId, $source, $normalizedPhone, success: false, errorReason: $msg, apiKeyId: $apiKeyId, referenceType: 'template', referenceId: $template->id, templateName: $template->title, messagePreview: $renderedMessage, templateSnapshot: $snapshot);

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
            // Same lock-then-increment pattern as ProcessPaymentAlertJob,
            // so a template send and a payment-alert send racing each
            // other can never both read a stale used_messages value.
            DB::transaction(function () use ($subscription) {
                $locked = $subscription->newQuery()->lockForUpdate()->find($subscription->id);
                $locked->increment('used_messages');
                $locked->refreshStatus();
            });
        }

        // Group Messaging Phase 4 — dispatch_log_id is additive (a new
        // key on this return array), so the existing internal Send Alert
        // caller and Api\V1\TemplateMessageController::send() (which only
        // destructure 'status'/'rendered_message'/'message') are both
        // unaffected. Api\V1\TemplateMessageController::sendMessage()
        // (the new /v1/send-message individual-recipient path) is the
        // first caller that actually reads it, to return it as this
        // endpoint's own dispatch_id.
        $log = MessageDispatchLog::record($accountId, $source, $normalizedPhone, success: true, apiKeyId: $apiKeyId, referenceType: 'template', referenceId: $template->id, templateName: $template->title, messagePreview: $renderedMessage, gatewayMessageId: $result['message_id'] ?? null, hasMedia: array_key_exists('media_url', $mediaMetaData), mediaUrl: $mediaMetaData['media_url'] ?? null, templateSnapshot: $snapshot);

        return ['status' => 'sent', 'rendered_message' => $renderedMessage, 'dispatch_log_id' => $log->id];
    }

    /**
     * Message Log "View Message" -- the historical snapshot stored on the
     * MessageDispatchLog row (template_snapshot). Everything here is what
     * THIS send actually used; nothing is re-read later from
     * message_templates.
     *
     * $renderedMessage is the full outgoing text for an individual send.
     * For a group dispatch it is null, because each member's text is only
     * rendered later inside ProcessGroupDispatchJob (see
     * GroupMessageDispatcher); scope 'group' plus a preview says so.
     *
     * This app's templates are its own text templates (the WhatsApp
     * drivers send plain text, plus an attachment on the QR engine), so
     * there is no language, category, namespace, footer or button data to
     * capture; those fields are deliberately absent, not faked.
     *
     * @param  array<string, mixed>  $variables  the exact values rendered with
     * @param  array{media_type?: string, media_url?: string, filename?: string}  $mediaMetaData
     * @return array<string, mixed>
     */
    public static function buildSnapshot(
        MessageTemplate $template,
        Account $account,
        array $variables,
        ?string $renderedMessage,
        array $mediaMetaData = [],
        string $scope = 'individual',
        ?string $groupPreview = null,
    ): array {
        $parameters = [];
        $seen = [];
        foreach ($template->effectiveVariablesSchema() as $field) {
            $key = $field['key'];
            $seen[$key] = true;
            $parameters[] = [
                'key' => $key,
                'label' => $field['label'] ?? $key,
                'value' => array_key_exists($key, $variables) ? self::snapshotValue($variables[$key]) : null,
            ];
        }
        // Values the caller sent that the schema doesn't list are still
        // part of what was rendered with, so keep them too.
        foreach ($variables as $key => $value) {
            if (! isset($seen[$key]) && is_scalar($value)) {
                $parameters[] = ['key' => (string) $key, 'label' => (string) $key, 'value' => (string) $value];
            }
        }

        return [
            'version' => 1,
            'scope' => $scope,
            'template_id' => $template->id,
            'template_code' => $template->template_code,
            'template_title' => $template->title,
            'template_body' => $template->template_body,
            'header_type' => $template->header_type,
            'parameters' => $parameters,
            'rendered_message' => $renderedMessage,
            'group_preview' => $groupPreview,
            'engine' => $account->currentSubscription?->engine_type,
            'media' => array_key_exists('media_url', $mediaMetaData)
                ? [
                    'type' => $mediaMetaData['media_type'] ?? null,
                    'url' => $mediaMetaData['media_url'],
                    'filename' => $mediaMetaData['filename'] ?? null,
                ]
                : null,
            'captured_at' => now()->toIso8601String(),
        ];
    }

    private static function snapshotValue(mixed $value): string
    {
        return is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value);
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
