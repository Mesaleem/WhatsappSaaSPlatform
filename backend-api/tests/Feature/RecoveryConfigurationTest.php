<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\MailSetting;
use App\Models\SocialProviderConfig;
use App\Models\Subscription;
use App\Models\WebhookSubscription;
use App\Models\WhatsAppSession;
use App\Services\Ops\RecoveryReadiness;
use App\Support\ThrowawayDatabaseGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Phase 12 Task 6 — APP_KEY escrow / provider-credential recovery checks, and proof that a backup holds no usable secret.
 */
#[Group('release-safety')]
class RecoveryConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private function newKey(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }

    private function useKey(string $key, array $previous = []): void
    {
        config(['app.key' => $key, 'app.previous_keys' => $previous]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
    }

    private function fingerprintOf(string $key): string
    {
        return substr(hash('sha256', base64_decode(substr($key, 7))), 0, 12);
    }

    /** @return array{ok: bool, checks: list<array<string, string>>, raw: string} */
    private function runCheck(array $args = []): array
    {
        $code = Artisan::call('ops:check-recovery', ['--json' => true, '--skip-database' => true] + $args);
        $raw = Artisan::output();
        $decoded = json_decode($raw, true);
        $decoded['raw'] = $raw;
        $decoded['code'] = $code;

        return $decoded;
    }

    private function st(array $report, string $check): string
    {
        return collect($report['checks'])->firstWhere('name', $check)['status'] ?? 'absent';
    }

    // ---- APP_KEY ---------------------------------------------------------------------------------------------

    public function test_a_missing_app_key_fails_without_printing_anything_secret(): void
    {
        $this->useKey('');
        $r = $this->runCheck();
        $this->assertSame(1, $r['code']);
        $this->assertSame('fail', $this->st($r, 'app_key.present'));
    }

    public function test_an_invalid_app_key_fails(): void
    {
        foreach (['base64:'.base64_encode('too-short'), 'base64:!!!not-base64!!!', 'short'] as $bad) {
            $this->useKey($bad);
            $r = $this->runCheck();
            $this->assertSame(1, $r['code'], $bad);
            $this->assertSame('fail', $this->st($r, 'app_key.valid'), $bad);
            $this->assertStringNotContainsString($bad, $r['raw'], 'an invalid key must not be echoed either');
        }
    }

    public function test_a_valid_key_without_a_recorded_fingerprint_warns_and_strict_mode_fails(): void
    {
        $this->useKey($this->newKey());
        config(['recovery.app_key_fingerprint' => null]);

        $r = $this->runCheck();
        $this->assertSame(0, $r['code']);
        $this->assertSame('pass', $this->st($r, 'app_key.valid'));
        $this->assertSame('warn', $this->st($r, 'app_key.escrow_fingerprint'));
        $this->assertSame(1, $this->runCheck(['--strict' => true])['code']);
    }

    public function test_the_escrow_fingerprint_must_match_the_running_key(): void
    {
        $key = $this->newKey();
        $this->useKey($key);

        config(['recovery.app_key_fingerprint' => $this->fingerprintOf($key)]);
        $this->assertSame('pass', $this->st($this->runCheck(), 'app_key.escrow_fingerprint'));

        config(['recovery.app_key_fingerprint' => $this->fingerprintOf($this->newKey())]);
        $r = $this->runCheck();
        $this->assertSame('fail', $this->st($r, 'app_key.escrow_fingerprint'));
        $this->assertSame(1, $r['code']);
    }

    public function test_a_fingerprint_of_a_previous_key_warns_that_the_escrow_record_is_stale(): void
    {
        $old = $this->newKey();
        $new = $this->newKey();
        $this->useKey($new, [$old]);
        config(['recovery.app_key_fingerprint' => $this->fingerprintOf($old)]);

        $r = $this->runCheck();
        $this->assertSame('warn', $this->st($r, 'app_key.escrow_fingerprint'));
        $this->assertSame(0, $r['code']);
    }

    public function test_an_invalid_previous_key_fails(): void
    {
        $this->useKey($this->newKey(), ['base64:'.base64_encode('nope')]);
        $r = $this->runCheck();
        $this->assertSame('fail', $this->st($r, 'app_key.previous_keys'));
        $this->assertSame(1, $r['code']);
    }

    public function test_the_report_never_contains_the_key_a_previous_key_or_a_credential_value(): void
    {
        $key = $this->newKey();
        $previous = $this->newKey();
        $this->useKey($key, [$previous]);
        config(['recovery.app_key_fingerprint' => $this->fingerprintOf($key)]);
        $_ENV['OPENAI_API_KEY'] = $_SERVER['OPENAI_API_KEY'] = 'sk-PROVIDER-SENTINEL-1234567890';
        putenv('OPENAI_API_KEY=sk-PROVIDER-SENTINEL-1234567890');
        config(['recovery.env_credentials.OPENAI_API_KEY.configured' => true]);

        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$logged) {
            $logged[] = $m->message.json_encode($m->context);
        });

        $out = '';
        foreach ([['--json' => true], [], ['--strict' => true]] as $args) {
            Artisan::call('ops:check-recovery', $args);
            $out .= Artisan::output();
        }
        $out .= implode("\n", $logged);

        foreach ([$key, substr($key, 7), $previous, substr($previous, 7), 'sk-PROVIDER-SENTINEL'] as $secret) {
            $this->assertStringNotContainsString($secret, $out);
        }
        putenv('OPENAI_API_KEY');
        unset($_ENV['OPENAI_API_KEY'], $_SERVER['OPENAI_API_KEY']);
    }

    // ---- provider credentials --------------------------------------------------------------------------------

    public function test_unconfigured_and_placeholder_provider_credentials_are_flagged_by_name_only(): void
    {
        config([
            'recovery.env_credentials.GEMINI_API_KEY.configured' => false,
            'recovery.env_credentials.INTERNAL_API_SECRET.configured' => true,
            'recovery.env_credentials.INTERNAL_API_SECRET.placeholder' => true,
            'recovery.env_credentials.DB_PASSWORD.configured' => true,
            'recovery.env_credentials.DB_PASSWORD.placeholder' => false,
        ]);
        $r = $this->runCheck();

        $this->assertSame('warn', $this->st($r, 'credential.GEMINI_API_KEY'));
        $this->assertSame('warn', $this->st($r, 'credential.INTERNAL_API_SECRET'));
        $this->assertSame('pass', $this->st($r, 'credential.DB_PASSWORD'));
        $this->assertStringContainsString('placeholder', collect($r['checks'])->firstWhere('name', 'credential.INTERNAL_API_SECRET')['detail']);
    }

    public function test_every_encrypted_model_column_is_in_the_recovery_inventory_and_exists(): void
    {
        $declared = [];
        foreach (glob(app_path('Models/*.php')) as $file) {
            if (! preg_match_all("/'([a-z_]+)'\s*=>\s*'encrypted'/", (string) file_get_contents($file), $m)) {
                continue;
            }
            $class = 'App\\Models\\'.basename($file, '.php');
            $table = (new $class)->getTable();
            foreach ($m[1] as $column) {
                $declared[$table][] = $column;
            }
        }
        $this->assertNotEmpty($declared);

        $inventory = config('recovery.encrypted_columns');
        foreach ($declared as $table => $columns) {
            $this->assertArrayHasKey($table, $inventory, "a model now encrypts {$table} but config/recovery.php does not list it");
            $this->assertEqualsCanonicalizing($columns, $inventory[$table], "{$table}: encrypted columns differ from the recovery inventory");
        }
        foreach ($inventory as $table => $columns) {
            $this->assertArrayHasKey($table, $declared, "{$table} is listed in the recovery inventory but no model encrypts it");
            foreach ($columns as $column) {
                $this->assertTrue(Schema::hasColumn($table, $column), "{$table}.{$column} does not exist");
            }
        }
    }

    // ---- decryptability with the live database ----------------------------------------------------------------

    private function storeSecrets(): void
    {
        $account = Account::factory()->create();
        Subscription::factory()->create(['account_id' => $account->id]);
        WebhookSubscription::create(['account_id' => $account->id, 'url' => 'https://example.test/h', 'secret' => 'whsec_SENTINEL_1', 'events' => ['message.sent'], 'is_active' => true]);
    }

    public function test_stored_secrets_decrypt_with_the_running_key_and_not_with_a_different_one(): void
    {
        $this->storeSecrets();
        $readiness = app(RecoveryReadiness::class);
        $this->assertSame('pass', $readiness->databaseDecryptability(null)['status']);

        $this->useKey($this->newKey());
        $r = $readiness->databaseDecryptability(null);
        $this->assertSame('fail', $r['status']);
        $this->assertStringContainsString('webhook_subscriptions.secret', $r['detail']);
        $this->assertStringNotContainsString('whsec_SENTINEL_1', $r['detail']);

        $this->assertSame(1, Artisan::call('ops:check-recovery', ['--json' => true]));
    }

    public function test_key_rotation_with_the_old_key_in_previous_keys_keeps_secrets_readable(): void
    {
        $this->storeSecrets();
        $old = (string) config('app.key');
        $this->useKey($this->newKey(), [$old]);

        $this->assertSame('pass', app(RecoveryReadiness::class)->databaseDecryptability(null)['status']);
    }

    // ---- a backup holds no usable secret ---------------------------------------------------------------------

    public function test_a_full_table_dump_contains_only_ciphertext_and_hashes_never_a_credential(): void
    {
        $sentinels = [
            'token' => 'EAAG-META-TOKEN-SENTINEL-111',
            'smtp' => 'SMTP-PASSWORD-SENTINEL-222',
            'client' => 'OAUTH-CLIENT-SECRET-SENTINEL-333',
            'hook' => 'WEBHOOK-SECRET-SENTINEL-444',
            'gemini' => 'GEMINI-KEY-SENTINEL-555',
            'apikey' => 'wasaas_live_APIKEY-SENTINEL-666',
        ];

        $account = Account::factory()->create(['gemini_api_key' => $sentinels['gemini']]);
        WhatsAppSession::create(['account_id' => $account->id, 'status' => 'connected', 'meta_phone_number_id' => '1', 'meta_waba_id' => '2', 'meta_access_token' => $sentinels['token'], 'meta_webhook_verify_token' => 'verify-1']);
        MailSetting::create(['host' => 'smtp.example.test', 'port' => 587, 'username' => 'u', 'password' => $sentinels['smtp'], 'from_address' => 'a@example.test', 'from_name' => 'A', 'encryption' => 'tls'] + $this->mailDefaults());
        SocialProviderConfig::create(['provider' => 'meta', 'client_id' => 'app-id', 'client_secret' => $sentinels['client'], 'is_active' => true]);
        WebhookSubscription::create(['account_id' => $account->id, 'url' => 'https://example.test/h', 'secret' => $sentinels['hook'], 'events' => ['message.sent'], 'is_active' => true]);
        \App\Models\ApiKey::create(['account_id' => $account->id, 'name' => 'k', 'key_prefix' => substr($sentinels['apikey'], 0, 20), 'key_hash' => \App\Models\ApiKey::hashKey($sentinels['apikey'])]);

        $dump = '';
        foreach (Schema::getTableListing() as $table) {
            $dump .= json_encode(DB::table($table)->get()->all());
        }

        foreach (['token', 'smtp', 'client', 'hook', 'gemini'] as $k) {
            $this->assertStringNotContainsString($sentinels[$k], $dump, "plaintext {$k} secret is present in the data a backup captures");
        }
        $this->assertStringNotContainsString($sentinels['apikey'], $dump, 'the API key itself must be stored only as a hash (a prefix is stored for display)');
        $this->assertSame(64, strlen((string) DB::table('api_keys')->value('key_hash')));
    }

    /** MailSetting may require more columns than the ones this test cares about. */
    private function mailDefaults(): array
    {
        return [];
    }

    // ---- documentation ---------------------------------------------------------------------------------------

    public function test_the_runbook_explains_app_key_escrow_recovery_and_provider_credentials_without_containing_secrets(): void
    {
        $doc = (string) file_get_contents(config('recovery.runbook_path'));

        foreach ((array) config('recovery.runbook_required_headings') as $heading) {
            $this->assertMatchesRegularExpression('/^##\s+'.preg_quote($heading, '/').'/mi', $doc, "runbook lacks the '{$heading}' section");
        }
        foreach (['RECOVERY_APP_KEY_FINGERPRINT', 'APP_PREVIOUS_KEYS', 'ops:check-recovery', 'ops:verify-restore', 'two independent secret stores', 'permanently unreadable', 'INTERNAL_API_SECRET', 'api_keys'] as $needle) {
            $this->assertStringContainsString($needle, $doc, "runbook does not mention '{$needle}'");
        }
        // Every credential the inventory names appears in the provider-credential table.
        foreach (array_keys((array) config('recovery.env_credentials')) as $name) {
            if (in_array($name, ['DB_PASSWORD', 'REDIS_PASSWORD', 'MAIL_PASSWORD', 'AWS_SECRET_ACCESS_KEY'], true)) {
                continue; // grouped on one row (DB_PASSWORD / REDIS_PASSWORD / MAIL_PASSWORD / AWS_*)
            }
            $this->assertStringContainsString($name, $doc, "{$name} is not documented");
        }
        $this->assertDoesNotMatchRegularExpression('/base64:[A-Za-z0-9+\/=]{20,}/', $doc, 'the runbook must not contain a key');
        $this->assertDoesNotMatchRegularExpression('/\b(sk-[A-Za-z0-9]{16,}|AKIA[0-9A-Z]{12,}|EAAG[A-Za-z0-9]{20,})/', $doc);
    }

    public function test_a_missing_or_incomplete_runbook_is_reported(): void
    {
        config(['recovery.runbook_path' => base_path('docs/does-not-exist.md')]);
        $this->assertSame('warn', app(RecoveryReadiness::class)->runbook()['status']);

        $tmp = tempnam(sys_get_temp_dir(), 'rb');
        file_put_contents($tmp, "## APP_KEY escrow and recovery\nonly this\n");
        config(['recovery.runbook_path' => $tmp]);
        $r = app(RecoveryReadiness::class)->runbook();
        unlink($tmp);
        $this->assertSame('fail', $r['status']);
        $this->assertStringContainsString('Provider credential recovery', $r['detail']);
    }

    // ---- the throwaway rule ----------------------------------------------------------------------------------

    public function test_the_throwaway_rule_accepts_only_throwaway_names(): void
    {
        foreach (['wa_throwaway_test', 'wa_throwaway_restore', 'restore_verify_1', 'wa_rehearsal', 'scratch_db', 'wa_test', 'test_wa', ':memory:', '/tmp/x/restored_verify.sqlite', 'C:\\temp\\wa_throwaway.sqlite'] as $ok) {
            $this->assertTrue(ThrowawayDatabaseGuard::isThrowaway($ok), $ok);
        }
        foreach (['', 'wa_saas_restore', 'wa_saas_platform', 'wa_saas', 'production', 'prod', 'wa_live', 'wa_testimonials', '/var/throwaway/wa_saas_platform.sqlite'] as $no) {
            $this->assertFalse(ThrowawayDatabaseGuard::isThrowaway($no), "'{$no}' must be refused");
        }
    }
}
