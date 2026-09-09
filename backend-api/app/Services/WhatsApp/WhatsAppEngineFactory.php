<?php

namespace App\Services\WhatsApp;

use App\Models\Account;
use RuntimeException;

/**
 * Strategy Pattern factory: resolves the WhatsAppDriverInterface
 * implementation an Account should use, based on its current
 * subscription's engine_type ('qr' | 'meta'). Nothing outside this class
 * needs to know which concrete driver it got back.
 */
class WhatsAppEngineFactory
{
    public static function make(Account $account): WhatsAppDriverInterface
    {
        $engineType = $account->currentSubscription?->engine_type;

        return match ($engineType) {
            'qr' => new BaileysDriver($account->id),
            'meta' => self::makeMetaDriver($account),
            default => throw new RuntimeException(
                "Account {$account->id} has no WhatsApp engine configured (engine_type={$engineType})."
            ),
        };
    }

    private static function makeMetaDriver(Account $account): MetaCloudApiDriver
    {
        $session = $account->whatsAppSession;

        if (! $session || ! $session->hasMetaConfigured()) {
            throw new RuntimeException(
                "Account {$account->id} is on the Meta engine but has no Meta credentials saved yet."
            );
        }

        return new MetaCloudApiDriver($session->meta_phone_number_id, $session->meta_access_token);
    }
}
