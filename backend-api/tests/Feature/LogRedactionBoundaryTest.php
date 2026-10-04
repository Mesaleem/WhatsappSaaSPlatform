<?php

namespace Tests\Feature;

use App\Logging\RedactingLogManager;
use App\Logging\Redactor;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Phase 12 Task 3 (redaction fix) — redaction is a property of the logging BOUNDARY, not of the opt-in `json`
 * channel. Real file handlers are used throughout, so the assertions are on the bytes actually written.
 */
class LogRedactionBoundaryTest extends TestCase
{
    private const SECRETS = [
        'password' => 'Pw-hunter2-SECRET',
        'sanctum' => '42|abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMN12',
        'bearer' => 'eyJhbGciOiJIUzI1NiJ9.TESTPAYLOAD.TESTSIG',
        'meta_token' => 'EAAGtestmetaaccesstoken0123456789abcdefghijklmnopqrstuv',
        'api_key' => 'wa_live_TESTAPIKEY1234567890',
        'webhook_secret' => 'whsec_TESTWEBHOOKSECRET',
        'signature' => 'sha256=TESTSIGNATUREVALUE',
        'payment' => 'rzp_test_PAYMENTSECRET99',
        'cookie' => 'laravel_session=TESTCOOKIEVALUE',
        'body' => 'Hi Asha, your one time code is 771199',
        'caption' => 'TESTCAPTIONTEXT',
        'preview' => 'TESTPREVIEWTEXT invoice due',
        'phone' => '919876543210',
        'phone_plus' => '+14155550123',
        'query_secret' => 'QUERYSECRETVALUE',
    ];

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function file(): string
    {
        return $this->files[] = tempnam(sys_get_temp_dir(), 'redact');
    }

    /** Points every selectable channel at its own temp file and returns [single, json]. */
    private function configureChannels(string $default, array $stack = ['single']): array
    {
        [$single, $json] = [$this->file(), $this->file()];
        config([
            'logging.default' => $default,
            'logging.channels.single.path' => $single,
            'logging.channels.json.with.stream' => $json,
            'logging.channels.stack.channels' => $stack,
        ]);
        foreach (['single', 'json', 'stack'] as $c) {
            Log::forgetChannel($c);
        }

        return [$single, $json];
    }

    private function emitSensitive(): void
    {
        $s = self::SECRETS;
        Context::add('request_id', 'req-safe-0001');
        Context::add('account_id', 12);
        Context::add('user_id', 34);
        Context::add('job', ['class' => 'App\\Jobs\\ExampleJob', 'queue' => 'journeys', 'connection' => 'database', 'attempt' => 2, 'uuid' => 'job-uuid-1']);

        Log::warning(
            "upstream rejected Authorization: Bearer {$s['bearer']} for token {$s['sanctum']} to {$s['phone']} / {$s['phone_plus']} "
            ."via https://graph.example/v1?access_token={$s['query_secret']}&fields=id",
            [
                'password' => $s['password'], 'access_token' => $s['meta_token'], 'api_key' => $s['api_key'],
                'webhook_secret' => $s['webhook_secret'], 'x-hub-signature-256' => $s['signature'], 'razorpay_key_secret' => $s['payment'],
                'cookie' => $s['cookie'], 'headers' => ['Authorization' => 'Bearer '.$s['bearer'], 'Accept' => 'application/json'],
                'body' => $s['body'], 'caption' => $s['caption'], 'message_preview' => $s['preview'],
                'recipient_phone' => $s['phone'], 'customer_phone' => $s['phone_plus'], 'nested' => ['deep' => ['password' => $s['password'], 'ok' => 'visible']],
                // safe operational fields
                'phone_number_id' => '109876543210987', 'status' => 'failed', 'duration_ms' => 812.5, 'query_fingerprint' => 'a1b2c3d4e5f6',
                'tokens' => 120, 'template_id' => 9,
            ],
        );
    }

    private function assertClean(string $output, string $where): void
    {
        $this->assertNotSame('', trim($output), "{$where}: nothing was written");
        foreach (self::SECRETS as $name => $value) {
            $this->assertStringNotContainsString($value, $output, "{$where}: {$name} reached the log");
        }
        // phone numbers without their country-code prefix could still identify a person
        // (phone_number_id 109876543210987 is Meta's object id, kept on purpose, and merely contains those digits)
        $withoutObjectId = str_replace('109876543210987', 'PHONE_NUMBER_ID', $output);
        foreach (['9876543210', '4155550123'] as $fragment) {
            $this->assertStringNotContainsString($fragment, $withoutObjectId, "{$where}: phone digits {$fragment} reached the log");
        }
    }

    private function assertSafeFieldsKept(string $output, string $where): void
    {
        foreach ([
            'req-safe-0001', 'ExampleJob', 'journeys', 'job-uuid-1', 'a1b2c3d4e5f6', '812.5', '109876543210987', 'visible', 'fields=id',
        ] as $kept) {
            $this->assertStringContainsString($kept, $output, "{$where}: safe field {$kept} was lost");
        }
        $this->assertMatchesRegularExpression('/account_id"?:\s*12/', $output, "{$where}: account_id lost");
        $this->assertMatchesRegularExpression('/user_id"?:\s*34/', $output, "{$where}: user_id lost");
        $this->assertStringContainsString('3210', $output, "{$where}: the last 4 phone digits are kept for correlation");
        $this->assertStringContainsString('"tokens":120', str_replace(' ', '', $output), "{$where}: a usage counter named tokens was masked");
    }

    public function test_the_application_log_manager_is_the_redacting_one(): void
    {
        $this->assertInstanceOf(RedactingLogManager::class, app('log'));
        $this->assertInstanceOf(RedactingLogManager::class, Log::getFacadeRoot());
    }

    public function test_the_default_channel_never_writes_a_secret(): void
    {
        [$single] = $this->configureChannels('single');

        $this->emitSensitive();

        $out = (string) file_get_contents($single);
        $this->assertClean($out, 'default single channel');
        $this->assertSafeFieldsKept($out, 'default single channel');
    }

    public function test_the_default_stack_configuration_never_writes_a_secret(): void
    {
        // the shipped default: LOG_CHANNEL=stack, LOG_STACK=single
        $this->assertSame('stack', config('logging.channels.stack.driver'));
        [$single] = $this->configureChannels('stack', ['single']);

        $this->emitSensitive();

        $out = (string) file_get_contents($single);
        $this->assertClean($out, 'default stack');
        $this->assertSafeFieldsKept($out, 'default stack');
    }

    public function test_the_json_channel_never_writes_a_secret_and_keeps_its_structured_fields(): void
    {
        [, $json] = $this->configureChannels('json');

        $this->emitSensitive();

        $line = trim((string) file_get_contents($json));
        $this->assertClean($line, 'json channel');
        $this->assertSafeFieldsKept($line, 'json channel');
        $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('req-safe-0001', $decoded['request_id']);
        $this->assertSame(12, $decoded['account_id']);
        $this->assertSame(34, $decoded['user_id']);
        $this->assertSame('journeys', $decoded['job']['queue']);
        $this->assertSame('********3210', $decoded['context']['recipient_phone']);
    }

    public function test_a_stack_containing_the_json_channel_writes_nothing_sensitive_to_either_member(): void
    {
        [$single, $json] = $this->configureChannels('stack', ['single', 'json']);

        $this->emitSensitive();

        $this->assertClean((string) file_get_contents($single), 'stack member single');
        $this->assertClean((string) file_get_contents($json), 'stack member json');
        $this->assertSafeFieldsKept((string) file_get_contents($single), 'stack member single');
        $this->assertSafeFieldsKept((string) file_get_contents($json), 'stack member json');
    }

    public function test_adhoc_stacks_and_named_channels_are_covered_too(): void
    {
        [$single, $json] = $this->configureChannels('single');

        Log::stack(['single'])->warning('adhoc', ['password' => self::SECRETS['password'], 'recipient_phone' => self::SECRETS['phone']]);
        Log::channel('json')->warning('named', ['api_key' => self::SECRETS['api_key']]);
        Log::build(['driver' => 'single', 'path' => $single])->warning('ondemand', ['access_token' => self::SECRETS['meta_token']]);

        $all = file_get_contents($single).file_get_contents($json);
        foreach (['password', 'api_key', 'meta_token', 'phone'] as $name) {
            $this->assertStringNotContainsString(self::SECRETS[$name], $all, "{$name} leaked");
        }
        foreach (['adhoc', 'named', 'ondemand'] as $marker) {
            $this->assertStringContainsString($marker, $all);
        }
    }

    public function test_an_exception_keeps_its_class_and_code_but_not_a_secret_in_its_message(): void
    {
        [$single, $json] = $this->configureChannels('stack', ['single', 'json']);
        $e = new \InvalidArgumentException(
            'Graph call failed: Bearer '.self::SECRETS['bearer'].' token '.self::SECRETS['sanctum'].' phone '.self::SECRETS['phone'].' url ?access_token='.self::SECRETS['query_secret'],
            4711,
            new \RuntimeException('previous with '.self::SECRETS['meta_token']),
        );

        Log::error('call failed', ['exception' => $e]);

        foreach ([$single, $json] as $file) {
            $out = (string) file_get_contents($file);
            $this->assertStringContainsString('InvalidArgumentException', $out);
            $this->assertStringContainsString('4711', $out);
            $this->assertStringContainsString('RuntimeException', $out, 'the previous exception class is kept');
            $this->assertStringContainsString('LogRedactionBoundaryTest.php', $out, 'file and line stay for diagnosis');
            foreach (['bearer', 'sanctum', 'phone', 'query_secret', 'meta_token'] as $name) {
                $this->assertStringNotContainsString(self::SECRETS[$name], $out, "{$name} leaked through the exception");
            }
        }
    }

    public function test_the_frameworks_own_exception_report_goes_through_the_boundary(): void
    {
        [$single] = $this->configureChannels('single');

        report(new \RuntimeException('boom with '.self::SECRETS['meta_token'].' and '.self::SECRETS['password'].'=x'));

        $out = (string) file_get_contents($single);
        $this->assertStringContainsString('RuntimeException', $out);
        $this->assertStringNotContainsString(self::SECRETS['meta_token'], $out);
    }

    public function test_models_and_objects_are_never_serialised_with_their_attributes(): void
    {
        [$single] = $this->configureChannels('single');
        $user = \App\Models\User::factory()->make(['id' => 5, 'email' => 'private.person@example.com', 'password' => 'Pw-hunter2-SECRET']);
        $user->id = 5;

        Log::info('model in context', ['user' => $user, 'when' => new \DateTimeImmutable('2026-10-02T10:00:00+00:00')]);

        $out = (string) file_get_contents($single);
        $this->assertStringContainsString('App\\\\Models\\\\User#5', $out);
        $this->assertStringContainsString('2026-10-02T10:00:00+00:00', $out);
        $this->assertStringNotContainsString('private.person@example.com', $out);
        $this->assertStringNotContainsString('Pw-hunter2-SECRET', $out);
    }

    public function test_redaction_is_idempotent_so_the_json_formatter_can_apply_it_again(): void
    {
        $once = Redactor::redact(['recipient_id' => '919876543210', 'body' => 'x', 'exception' => new \LogicException('a Bearer abcdefghijklmnop12')]);
        $twice = Redactor::redact($once);

        $this->assertSame($once, $twice);
        $this->assertSame('********3210', $twice['recipient_id']);
        $this->assertSame('****', Redactor::maskPhone('12'));
    }

    public function test_harmless_values_under_phone_like_keys_and_long_ids_are_left_alone(): void
    {
        $out = Redactor::redact(['to' => 'completed', 'from' => 'journey', 'phone_number_id' => '109876543210987', 'wa_id' => '919876543210', 'epoch_ms' => 1700000000000]);

        $this->assertSame('completed', $out['to']);
        $this->assertSame('journey', $out['from']);
        $this->assertSame('109876543210987', $out['phone_number_id']);
        $this->assertSame('********3210', $out['wa_id']);
        $this->assertSame(1700000000000, $out['epoch_ms']);

        $text = (string) Redactor::scrubText('ids 109876543210987 at 1700000000000 for 919876543210');
        $this->assertStringContainsString('109876543210987', $text);
        $this->assertStringContainsString('1700000000000', $text);
        $this->assertStringNotContainsString('919876543210', $text);
    }

    public function test_a_redaction_failure_fails_closed(): void
    {
        $processor = new \App\Logging\RedactingProcessor();
        $hostile = new class implements \Stringable {
            public function __toString(): string
            {
                throw new \RuntimeException('cannot stringify');
            }
        };
        $record = new \Monolog\LogRecord(new \DateTimeImmutable(), 'testing', \Monolog\Level::Info, 'msg Bearer abcdefghijklmnop12', ['x' => $hostile, 'password' => 'p'], ['request_id' => 'r']);

        $out = $processor($record);

        $this->assertSame(['redaction_failed' => true], $out->context);
        $this->assertSame('msg Bearer [REDACTED]', $out->message);
    }
}
