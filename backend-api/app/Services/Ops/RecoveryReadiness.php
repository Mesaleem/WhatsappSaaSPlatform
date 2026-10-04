<?php

namespace App\Services\Ops;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Phase 12 Task 6 — recovery-configuration checks. Every check returns
 * {name, status: pass|warn|fail, detail}. Details name variables, tables and COUNTS only: no key, secret, token,
 * ciphertext or row value is ever read into a report.
 */
class RecoveryReadiness
{
    /** @return list<array{name: string, status: string, detail: string}> */
    public function report(bool $checkDatabase = true): array
    {
        $checks = [
            ...$this->appKey(),
            ...$this->previousKeys(),
            ...$this->envCredentials(),
            $this->runbook(),
        ];

        if ($checkDatabase) {
            $checks[] = $this->databaseDecryptability(null);
        }

        return $checks;
    }

    /** @param  list<array{status: string}>  $checks */
    public static function failed(array $checks, bool $strict = false): bool
    {
        foreach ($checks as $c) {
            if ($c['status'] === 'fail' || ($strict && $c['status'] === 'warn')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{name: string, status: string, detail: string}> */
    public function appKey(): array
    {
        $raw = (string) config('app.key');
        $cipher = (string) config('app.cipher');

        if ($raw === '') {
            return [$this->c('app_key.present', 'fail', 'APP_KEY is not set. Encrypted columns, signed URLs and sessions cannot work; restore it from escrow (see the runbook).')];
        }

        $bytes = $this->decodeKey($raw);
        if ($bytes === null || ! Encrypter::supported($bytes, $cipher)) {
            return [$this->c('app_key.present', 'pass', 'APP_KEY is set.'), $this->c('app_key.valid', 'fail', "APP_KEY is not a valid key for the {$cipher} cipher (expected base64: followed by the right number of bytes).")];
        }

        $out = [$this->c('app_key.present', 'pass', 'APP_KEY is set.'), $this->c('app_key.valid', 'pass', "APP_KEY is a valid {$cipher} key.")];

        $fingerprint = $this->fingerprint($bytes);
        $recorded = strtolower(trim((string) config('recovery.app_key_fingerprint')));

        if ($recorded === '') {
            $out[] = $this->c('app_key.escrow_fingerprint', 'warn', 'No escrow fingerprint recorded (RECOVERY_APP_KEY_FINGERPRINT). Record the fingerprint when the key is escrowed so this check can confirm the running key is the escrowed one.');
        } elseif (hash_equals($recorded, $fingerprint)) {
            $out[] = $this->c('app_key.escrow_fingerprint', 'pass', "Running key matches the recorded escrow fingerprint ({$fingerprint}).");
        } elseif ($this->matchesPreviousKey($recorded)) {
            $out[] = $this->c('app_key.escrow_fingerprint', 'warn', 'The recorded fingerprint belongs to a PREVIOUS key (APP_PREVIOUS_KEYS): update the escrow record to the current key.');
        } else {
            $out[] = $this->c('app_key.escrow_fingerprint', 'fail', 'The running APP_KEY does not match the recorded escrow fingerprint. Either the wrong key is deployed or the escrow record is stale.');
        }

        return $out;
    }

    /** @return list<array{name: string, status: string, detail: string}> */
    public function previousKeys(): array
    {
        $previous = array_values(array_filter((array) config('app.previous_keys')));
        if ($previous === []) {
            return [$this->c('app_key.previous_keys', 'pass', 'No previous keys configured.')];
        }

        $cipher = (string) config('app.cipher');
        $bad = 0;
        foreach ($previous as $key) {
            $bytes = $this->decodeKey((string) $key);
            if ($bytes === null || ! Encrypter::supported($bytes, $cipher)) {
                $bad++;
            }
        }

        return [$bad === 0
            ? $this->c('app_key.previous_keys', 'pass', count($previous).' previous key(s) configured and valid.')
            : $this->c('app_key.previous_keys', 'fail', "{$bad} of ".count($previous).' APP_PREVIOUS_KEYS entries are not valid keys.')];
    }

    /** @return list<array{name: string, status: string, detail: string}> */
    public function envCredentials(): array
    {
        $out = [];

        foreach ((array) config('recovery.env_credentials', []) as $name => $info) {
            $purpose = (string) ($info['purpose'] ?? '');
            if (! ($info['configured'] ?? false)) {
                $out[] = $this->c("credential.{$name}", 'warn', "{$name} ({$purpose}) is not configured here - fine if that feature is unused, otherwise restore it from the operator secret store.");
            } elseif ($info['placeholder'] ?? false) {
                $out[] = $this->c("credential.{$name}", 'warn', "{$name} still looks like the .env.example placeholder; replace it before production.");
            } else {
                $out[] = $this->c("credential.{$name}", 'pass', "{$name} is configured.");
            }
        }

        return $out;
    }

    /** @return array{name: string, status: string, detail: string} */
    public function runbook(): array
    {
        $path = (string) config('recovery.runbook_path');
        if (! is_file($path)) {
            return $this->c('runbook.present', 'warn', 'Recovery runbook not found at the configured path; ship docs/RELEASE_AND_RECOVERY.md with the deployment.');
        }

        $text = (string) file_get_contents($path);
        $missing = array_values(array_filter((array) config('recovery.runbook_required_headings'), fn ($h) => stripos($text, (string) $h) === false));

        return $missing === []
            ? $this->c('runbook.present', 'pass', 'Runbook present with APP_KEY escrow, restore verification, provider credential and migration sections.')
            : $this->c('runbook.present', 'fail', 'Runbook is missing section(s): '.implode(', ', $missing).'.');
    }

    /**
     * Every `encrypted` column can be decrypted with the running key (current or APP_PREVIOUS_KEYS). Samples a bounded
     * number of non-null rows per column; reports counts only.
     *
     * @return array{name: string, status: string, detail: string}
     */
    public function databaseDecryptability(?string $connection): array
    {
        try {
            $r = $this->decryptStats($connection);
        } catch (Throwable $e) {
            return $this->c('encrypted_columns.decryptable', 'warn', 'Could not read the database to sample encrypted columns ('.class_basename($e).').');
        }

        if ($r['failed'] > 0) {
            return $this->c('encrypted_columns.decryptable', 'fail', "{$r['failed']} of {$r['checked']} sampled encrypted value(s) cannot be decrypted with the running APP_KEY ({$r['bad_columns']}). The key does not match this data.");
        }

        return $this->c('encrypted_columns.decryptable', 'pass', $r['checked'] === 0
            ? 'No encrypted values stored yet (nothing to verify).'
            : "{$r['checked']} sampled encrypted value(s) all decrypt with the running APP_KEY.");
    }

    /** @return array{checked: int, failed: int, bad_columns: string} */
    public function decryptStats(?string $connection): array
    {
        $db = DB::connection($connection);
        $schema = Schema::connection($connection);
        $limit = max(1, (int) config('recovery.decrypt_sample_size', 20));
        $checked = $failed = 0;
        $bad = [];

        foreach ((array) config('recovery.encrypted_columns', []) as $table => $columns) {
            if (! $schema->hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (! $schema->hasColumn($table, $column)) {
                    continue;
                }
                $values = $db->table($table)->whereNotNull($column)->where($column, '!=', '')->limit($limit)->pluck($column);
                foreach ($values as $value) {
                    $checked++;
                    try {
                        Crypt::decryptString((string) $value);
                    } catch (Throwable) {
                        $failed++;
                        $bad["{$table}.{$column}"] = true;
                    }
                }
            }
        }

        return ['checked' => $checked, 'failed' => $failed, 'bad_columns' => implode(', ', array_keys($bad))];
    }

    public function fingerprint(string $keyBytes): string
    {
        return substr(hash('sha256', $keyBytes), 0, 12);
    }

    private function matchesPreviousKey(string $recorded): bool
    {
        foreach (array_filter((array) config('app.previous_keys')) as $key) {
            $bytes = $this->decodeKey((string) $key);
            if ($bytes !== null && hash_equals($recorded, $this->fingerprint($bytes))) {
                return true;
            }
        }

        return false;
    }

    private function decodeKey(string $key): ?string
    {
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            return $decoded === false ? null : $decoded;
        }

        return $key; // Laravel also accepts a raw key string
    }

    /** @return array{name: string, status: string, detail: string} */
    private function c(string $name, string $status, string $detail): array
    {
        return ['name' => $name, 'status' => $status, 'detail' => $detail];
    }
}
