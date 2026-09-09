<?php

namespace App\Services\Templates;

use App\Models\Account;
use App\Models\MessageTemplate;
use App\Services\PaymentAlerts\PaymentAlertDispatcher;
use App\Services\WhatsApp\WhatsAppEngineFactory;
use App\Support\PhoneNumberNormalizer;
use App\Support\TemplateRenderer;
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
    public static function dispatch(int $accountId, int $templateId, string $recipientPhone, array $variables): array
    {
        $account = Account::with(['currentSubscription', 'whatsAppSession'])->find($accountId);

        if (! $account) {
            return ['status' => 'not_found', 'message' => 'Account not found.'];
        }

        if (! $account->hasActiveSubscription()) {
            return ['status' => 'quota_exhausted', 'message' => 'This account has no active subscription, or its message quota is exhausted.'];
        }

        $template = MessageTemplate::query()->approvedFor($account->id)->find($templateId);

        if (! $template) {
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
            return ['status' => 'failed', 'message' => "Recipient phone number '{$recipientPhone}' is not a valid number after normalization."];
        }

        try {
            $driver = WhatsAppEngineFactory::make($account);
        } catch (RuntimeException $e) {
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }

        $result = $driver->sendMessage($normalizedPhone, $renderedMessage, ['template_id' => $template->id]);

        if (empty($result['success'])) {
            return ['status' => 'failed', 'message' => $result['error'] ?? 'The WhatsApp engine rejected the message.'];
        }

        $subscription = $account->currentSubscription;
        if ($subscription && $subscription->billing_model === 'per_message') {
            // Same lock-then-increment pattern as ProcessPaymentAlertJob,
            // so a template send and a payment-alert send racing each
            // other can never both read a stale used_messages value.
            DB::transaction(function () use ($subscription) {
                $locked = $subscription->newQuery()->lockForUpdate()->find($subscription->id);
                $locked->increment('used_messages');
                $locked->refreshStatus();
            });
        }

        return ['status' => 'sent', 'rendered_message' => $renderedMessage];
    }
}
