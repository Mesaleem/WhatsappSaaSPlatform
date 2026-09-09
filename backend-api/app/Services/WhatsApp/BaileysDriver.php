<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispatches outbound messages to qr-engine-service for accounts on the
 * 'qr' (Baileys) engine, via POST {qr_engine_url}/api/message/send —
 * qr-engine-service's src/server.js now implements that route (see its
 * requireInternalSecret-guarded handler and sessionManager.sendMessage()).
 */
class BaileysDriver implements WhatsAppDriverInterface
{
    /**
     * Matches Baileys' JID format (see qr-engine-service's
     * sessionManager.sendMessage()) — "{digits}@s.whatsapp.net". $to is
     * expected pre-normalized to bare digits by
     * App\Support\PhoneNumberNormalizer (ProcessPaymentAlertJob does this
     * before calling sendMessage()).
     */
    private const JID_SUFFIX = '@s.whatsapp.net';

    public function __construct(private readonly int $accountId)
    {
    }

    public function sendMessage(string $to, string $message, array $metaData = []): array
    {
        $baseUrl = rtrim((string) config('services.qr_engine.url'), '/');
        $secret = config('services.qr_engine.internal_secret');
        $jid = str_contains($to, '@') ? $to : $to.self::JID_SUFFIX;

        try {
            $response = Http::withHeaders(['X-Internal-Secret' => $secret])
                ->timeout(15)
                ->post("{$baseUrl}/api/message/send", [
                    'account_id' => $this->accountId,
                    'to' => $jid,
                    'message' => $message,
                    ...$metaData,
                ]);
        } catch (Throwable $e) {
            // Connection refused, DNS failure, or the 15s timeout above
            // (Http::timeout() throws Illuminate\Http\Client\ConnectionException,
            // a Throwable) all land here — logged with the real exception
            // message instead of failing silently, and returned as a normal
            // failed result so ProcessPaymentAlertJob marks the alert
            // 'failed' instead of hanging.
            Log::error('BaileysDriver: qr-engine-service unreachable.', [
                'account_id' => $this->accountId,
                'exception' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'qr-engine-service is unreachable: '.$e->getMessage(),
            ];
        }

        $body = $response->json() ?? [];
        // Trust the JSON 'success' flag over the HTTP status class alone —
        // a well-behaved handler returns a non-2xx on failure, but this
        // stays correct even if it doesn't.
        $succeeded = $response->successful() && ($body['success'] ?? false) === true;

        if (! $succeeded) {
            Log::warning('BaileysDriver: send failed.', [
                'account_id' => $this->accountId,
                'status_code' => $response->status(),
                'response' => $body,
            ]);

            return [
                'success' => false,
                'error' => $body['error'] ?? $body['message'] ?? 'The QR engine rejected the message.',
                'status_code' => $response->status(),
            ];
        }

        return [
            'success' => true,
            'message_id' => $body['message_id'] ?? null,
            'raw' => $body,
        ];
    }
}
