<?php

namespace App\Services\WhatsApp;

use App\Models\Account;
use App\Models\MessageDispatchLog;
use App\Services\PaymentAlerts\PaymentAlertDispatcher;
use App\Support\PhoneNumberNormalizer;
use App\Support\WhatsAppMediaPayloadBuilder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Developer API Platform for WhatsApp Group Creation & Unified
 * Messaging -- requirement 3's individual-recipient text/media path
 * (message_type 'text'|'template' -- 'template' stays on the existing
 * TemplateMessageDispatcher, unchanged; see
 * Api\V1\UnifiedMessageController). Deliberately a NEW, separate class
 * rather than an extension of TemplateMessageDispatcher: that class's
 * entire contract (missing-variable checks, template lookup,
 * TemplateRenderer::render()) is template-specific and does not apply
 * here, and every existing caller of TemplateMessageDispatcher::dispatch()
 * (MessageTemplateController, Api\V1\TemplateMessageController) is left
 * completely untouched by adding this sibling.
 *
 * Mirrors that class's overall shape (account/subscription/connection
 * checks, MessageDispatchLog::record() at every terminal branch,
 * unconditional used_messages increment on a confirmed successful send)
 * so a text/media send costs and logs identically to a template send.
 */
class DirectMessageDispatcher
{
    /**
     * @param array<string, mixed> $content For messageType 'text': ['body' => string].
     *        For messageType 'media': ['media_type','url','caption'?,'filename'?] (see WhatsAppMediaPayloadBuilder).
     * @return array{status: 'sent'|'failed'|'disconnected'|'not_found'|'quota_exhausted', message?: string, dispatch_log_id?: int}
     */
    public static function dispatch(
        int $accountId,
        string $recipientPhone,
        string $messageType,
        array $content,
        string $source = 'api',
        ?int $apiKeyId = null,
    ): array {
        $account = Account::with(['currentSubscription', 'whatsAppSession'])->find($accountId);

        if (! $account) {
            return ['status' => 'not_found', 'message' => 'Account not found.'];
        }

        if (! $account->hasActiveSubscription()) {
            MessageDispatchLog::record($accountId, $source, $recipientPhone, success: false, errorReason: 'No active subscription or quota exhausted.', apiKeyId: $apiKeyId, referenceType: $messageType);

            return ['status' => 'quota_exhausted', 'message' => 'This account has no active subscription, or its message quota is exhausted.'];
        }

        if (PaymentAlertDispatcher::isWhatsAppDisconnected($account)) {
            MessageDispatchLog::record($accountId, $source, $recipientPhone, success: false, errorReason: 'WhatsApp account is disconnected.', apiKeyId: $apiKeyId, referenceType: $messageType);

            return ['status' => 'disconnected', 'message' => 'WhatsApp account is disconnected. Please connect your device first.'];
        }

        $normalizedPhone = PhoneNumberNormalizer::normalize($recipientPhone);
        if ($normalizedPhone === '') {
            $msg = "Recipient phone number '{$recipientPhone}' is not a valid number after normalization.";
            MessageDispatchLog::record($accountId, $source, $recipientPhone, success: false, errorReason: $msg, apiKeyId: $apiKeyId, referenceType: $messageType);

            return ['status' => 'failed', 'message' => $msg];
        }

        try {
            $driver = WhatsAppEngineFactory::make($account);
        } catch (RuntimeException $e) {
            MessageDispatchLog::record($accountId, $source, $normalizedPhone, success: false, errorReason: $e->getMessage(), apiKeyId: $apiKeyId, referenceType: $messageType);

            return ['status' => 'failed', 'message' => $e->getMessage()];
        }

        $engineType = $account->currentSubscription?->engine_type;

        if ($messageType === 'text') {
            $body = (string) ($content['body'] ?? '');
            $result = $driver->sendMessage($normalizedPhone, $body, []);
            $preview = $body;
        } else {
            [$driverMessage, $metaData] = WhatsAppMediaPayloadBuilder::build($engineType, $content);
            $result = $driver->sendMessage($normalizedPhone, $driverMessage, $metaData);
            $preview = $content['caption'] ?? '['.($content['media_type'] ?? 'media').']';
        }

        if (empty($result['success'])) {
            $msg = $result['error'] ?? 'The WhatsApp engine rejected the message.';
            MessageDispatchLog::record($accountId, $source, $normalizedPhone, success: false, errorReason: $msg, apiKeyId: $apiKeyId, referenceType: $messageType, messagePreview: $preview);

            return ['status' => 'failed', 'message' => $msg];
        }

        // Same lock-then-increment pattern, and the same "unconditional
        // on billing_model" fix, as TemplateMessageDispatcher::dispatch().
        $subscription = $account->currentSubscription;
        if ($subscription) {
            DB::transaction(function () use ($subscription) {
                $locked = $subscription->newQuery()->lockForUpdate()->find($subscription->id);
                $locked->increment('used_messages');
                $locked->refreshStatus();
            });
        }

        $log = MessageDispatchLog::record($accountId, $source, $normalizedPhone, success: true, apiKeyId: $apiKeyId, referenceType: $messageType, messagePreview: $preview, gatewayMessageId: $result['message_id'] ?? null);

        return ['status' => 'sent', 'dispatch_log_id' => $log->id];
    }
}
