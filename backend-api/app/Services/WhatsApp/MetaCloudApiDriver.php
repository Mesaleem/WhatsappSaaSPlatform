<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Dispatches outbound messages via the official Meta WhatsApp Cloud API.
 * Uses Laravel's Http facade — itself a fluent wrapper around Guzzle — for
 * consistency with the rest of this codebase (WhatsAppController,
 * MetaConfigController) rather than instantiating GuzzleHttp\Client directly.
 */
class MetaCloudApiDriver implements WhatsAppDriverInterface
{
    private const API_VERSION = 'v18.0';
    private const BASE_URL = 'https://graph.facebook.com';

    public function __construct(
        private readonly string $phoneNumberId,
        private readonly string $accessToken,
    ) {
    }

    /**
     * Module 10 addition: $metaData can override 'type' to send non-text
     * messages (e.g. 'image', 'document', 'interactive') — ChatbotEngineService
     * does this for `media`/`interactive` chatbot rule responses. When it
     * does, the default 'text' key is stripped so the outgoing payload
     * never carries a stray/contradictory 'text' field alongside a
     * different 'type' (Meta's Cloud API expects only the fields that
     * belong to the declared type).
     */
    public function sendMessage(string $to, string $message, array $metaData = []): array
    {
        $payload = array_replace([
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $message],
        ], $metaData);

        if (($metaData['type'] ?? 'text') !== 'text') {
            unset($payload['text']);
        }

        try {
            $response = Http::withToken($this->accessToken)
                ->timeout(15)
                ->post(self::BASE_URL.'/'.self::API_VERSION."/{$this->phoneNumberId}/messages", $payload);
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => 'Meta Graph API is unreachable.',
            ];
        }

        if ($response->failed()) {
            return [
                'success' => false,
                'error' => $response->json('error.message') ?? 'Meta rejected the request.',
                'status_code' => $response->status(),
            ];
        }

        return [
            'success' => true,
            'message_id' => $response->json('messages.0.id'),
            'raw' => $response->json(),
        ];
    }
}
