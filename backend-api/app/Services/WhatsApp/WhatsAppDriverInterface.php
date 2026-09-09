<?php

namespace App\Services\WhatsApp;

interface WhatsAppDriverInterface
{
    /**
     * Send a text message through this engine.
     *
     * @param string $to WhatsApp-formatted recipient number (E.164, no '+').
     * @param string $message Plain text body.
     * @param array<string, mixed> $metaData Driver-specific extras (e.g. a
     *        template payload for MetaCloudApiDriver); ignored by drivers
     *        that don't understand a given key.
     * @return array{success: bool, message_id?: string, error?: string, status_code?: int, raw?: mixed}
     */
    public function sendMessage(string $to, string $message, array $metaData = []): array;
}
