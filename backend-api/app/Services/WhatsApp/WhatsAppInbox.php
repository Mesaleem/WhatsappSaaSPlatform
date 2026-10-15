<?php

namespace App\Services\WhatsApp;

use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * QA fixes (2026-10-14) — records the WhatsApp inbox.
 *
 * recordInbound(): every customer message the QR engine or the Meta webhook
 * delivers. recordOutbound(): every message the platform sends successfully
 * (MessageDispatchLog::record calls it — Send Notification, templates, groups,
 * batches, chatbot, journeys, Developer API, inbox replies). Best effort:
 * the inbox must never break sending or the inbound pipeline.
 */
class WhatsAppInbox
{
    /**
     * account:phone => conversation id, for this process. A journey or a batch sends
     * many messages to the same customer; the conversation is looked up once.
     *
     * @var array<string, int>
     */
    private static array $conversationIds = [];

    public function recordInbound(int $accountId, string $phone, string $body, string $type = 'text', ?string $name = null, ?string $externalId = null, ?int $numberId = null): ?WaMessage
    {
        try {
            $phone = $this->normalize($phone);
            if ($phone === '') {
                return null;
            }
            if ($externalId !== null && WaMessage::query()->where('account_id', $accountId)->where('external_id', $externalId)->exists()) {
                return null;
            }

            $conversation = $this->conversation($accountId, $phone);
            $now = now();
            $conversation->forceFill([
                'contact_name' => $name ?: $conversation->contact_name,
                'whatsapp_number_id' => $numberId ?? $conversation->whatsapp_number_id,
                'last_message_preview' => Str::limit($body, 250, '…'),
                'last_direction' => 'in',
                'last_message_at' => $now,
                'last_inbound_at' => $now,
                'unread_count' => (int) $conversation->unread_count + 1,
            ])->save();

            return WaMessage::query()->create([
                'wa_conversation_id' => $conversation->id,
                'account_id' => $accountId,
                'direction' => 'in',
                'message_type' => $type ?: 'text',
                'body' => $body,
                'external_id' => $externalId,
                'created_at' => $now,
            ]);
        } catch (Throwable $e) {
            Log::warning('WhatsAppInbox: inbound message not recorded.', ['account_id' => $accountId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public function recordOutbound(int $accountId, string $phone, ?string $body, ?string $source = null, ?string $externalId = null, ?int $userId = null, string $type = 'text'): ?WaMessage
    {
        try {
            $phone = $this->normalize($phone);
            if ($phone === '') {
                return null;
            }
            $now = now();
            $changes = [
                'last_message_preview' => Str::limit((string) $body, 250, '…'),
                'last_direction' => 'out',
                'last_message_at' => $now,
                'updated_at' => $now,
            ];
            $conversationId = $this->conversationId($accountId, $phone);
            if (WaConversation::query()->whereKey($conversationId)->where('account_id', $accountId)->update($changes) === 0) {
                // The remembered conversation is gone: look it up (or create it) again.
                unset(self::$conversationIds[$accountId.':'.$phone]);
                $conversationId = $this->conversationId($accountId, $phone);
                WaConversation::query()->whereKey($conversationId)->update($changes);
            }

            return WaMessage::query()->create([
                'wa_conversation_id' => $conversationId,
                'account_id' => $accountId,
                'direction' => 'out',
                'message_type' => $type,
                'body' => $body,
                'external_id' => $externalId,
                'status' => 'sent',
                'source' => $source,
                'sent_by_user_id' => $userId,
                'created_at' => $now,
            ]);
        } catch (Throwable $e) {
            Log::warning('WhatsAppInbox: outbound message not recorded.', ['account_id' => $accountId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function conversationId(int $accountId, string $phone): int
    {
        $key = $accountId.':'.$phone;
        if (isset(self::$conversationIds[$key]) && count(self::$conversationIds) < 5000) {
            return self::$conversationIds[$key];
        }

        return self::$conversationIds[$key] = (int) $this->conversation($accountId, $phone)->id;
    }

    /** Tests: forget remembered conversation ids. */
    public static function flushMemo(): void
    {
        self::$conversationIds = [];
    }

    private function conversation(int $accountId, string $phone): WaConversation
    {
        $existing = WaConversation::query()->where('account_id', $accountId)->where('contact_phone', $phone)->first();
        if ($existing !== null) {
            return $existing;
        }

        try {
            return WaConversation::query()->create(['account_id' => $accountId, 'contact_phone' => $phone]);
        } catch (QueryException) {
            // Two messages for a new number at once: the other one created it.
            return WaConversation::query()->where('account_id', $accountId)->where('contact_phone', $phone)->firstOrFail();
        }
    }

    private function normalize(string $phone): string
    {
        return (string) preg_replace('/\D+/', '', $phone);
    }
}
