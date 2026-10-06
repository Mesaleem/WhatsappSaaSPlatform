<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Same as Eloquent's 'encrypted' cast, except a value that cannot be decrypted reads as null instead of throwing.
 *
 * Why: a database copied to another machine (or restored after APP_KEY changed) holds values encrypted with the OLD
 * APP_KEY. The stock cast then throws "The MAC is invalid." on every read, which 500s whole pages (Social Gateway
 * Settings, Social Accounts). Reading as null makes the value look "not set" so a Super Admin can simply re-enter it
 * (writing re-encrypts with the current key) and a user can reconnect the account. Writes are unchanged.
 */
class EncryptedOrNull implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes)
    {
        if ($value === null || $value === '') {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException $e) {
            Log::warning('Encrypted column could not be decrypted (APP_KEY differs from the key that wrote it?).', [
                'model' => class_basename($model), 'column' => $key, 'id' => $model->getKey(),
            ]);

            return null;
        }
    }

    public function set($model, string $key, $value, array $attributes)
    {
        return $value === null ? null : Crypt::encryptString((string) $value);
    }
}
