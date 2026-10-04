<?php

namespace Tests\Feature;

use App\Logging\Redactor;
use App\Logging\StructuredLogFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

/**
 * Phase 12 Task 3 — the `json` log channel and the redaction rules behind it.
 */
class StructuredLoggingTest extends TestCase
{
    use RefreshDatabase;

    private function line(string $message, array $context = [], array $extra = [], Level $level = Level::Info): array
    {
        $record = new LogRecord(new \DateTimeImmutable('2026-10-02T10:11:12.123+00:00'), 'testing', $level, $message, $context, $extra);

        $json = (new StructuredLogFormatter())->format($record);
        $this->assertStringEndsWith("\n", $json);
        $this->assertSame(1, substr_count($json, "\n"), 'one record is exactly one line');

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_the_default_logging_behaviour_is_unchanged_and_json_is_opt_in(): void
    {
        $this->assertNotContains('json', config('logging.channels.stack.channels'));
        $this->assertNotSame('json', config('logging.default'));
        $this->assertSame(StreamHandler::class, config('logging.channels.json.handler'));
        $this->assertSame(StructuredLogFormatter::class, config('logging.channels.json.formatter'));
        // pre-existing channels are still defined as before
        foreach (['single', 'daily', 'stderr', 'stack', 'null'] as $channel) {
            $this->assertArrayHasKey($channel, config('logging.channels'));
        }
    }

    public function test_a_record_has_the_structured_fields(): void
    {
        $out = $this->line('Something happened', ['order_ref' => 'A-1'], [
            'request_id' => 'req-abc-12345', 'account_id' => 7, 'user_id' => 9,
            'job' => ['class' => 'App\\Jobs\\X', 'queue' => 'journeys', 'connection' => 'database', 'attempt' => 2, 'uuid' => 'u-1'],
        ], Level::Warning);

        $this->assertSame('2026-10-02T10:11:12.123+00:00', $out['timestamp']);
        $this->assertSame('warning', $out['level']);
        $this->assertSame('testing', $out['channel']);
        $this->assertSame('Something happened', $out['message']);
        $this->assertSame('req-abc-12345', $out['request_id']);
        $this->assertSame(7, $out['account_id']);
        $this->assertSame(9, $out['user_id']);
        $this->assertSame('journeys', $out['job']['queue']);
        $this->assertSame(2, $out['job']['attempt']);
        $this->assertSame(['order_ref' => 'A-1'], $out['context']);
    }

    public function test_absent_ids_are_omitted_rather_than_written_as_null(): void
    {
        $out = $this->line('cli message');

        foreach (['request_id', 'account_id', 'user_id', 'job', 'exception', 'context'] as $key) {
            $this->assertArrayNotHasKey($key, $out);
        }
    }

    public function test_exception_information_is_structured_and_the_message_is_scrubbed(): void
    {
        $e = new \RuntimeException('upstream said Bearer abcdefghijklmnop1234 is invalid', 42);
        $out = $this->line('boom', ['exception' => $e, 'attempt' => 1]);

        $this->assertSame(\RuntimeException::class, $out['exception']['class']);
        $this->assertSame(42, $out['exception']['code']);
        $this->assertSame(basename(__FILE__), $out['exception']['file']);
        $this->assertIsInt($out['exception']['line']);
        $this->assertStringNotContainsString('abcdefghijklmnop1234', $out['exception']['message']);
        $this->assertArrayNotHasKey('trace', $out['exception'], 'traces are off unless LOG_JSON_TRACE is enabled');
        $this->assertArrayNotHasKey('exception', $out['context']);

        $withTrace = json_decode((new StructuredLogFormatter(true))->format(new LogRecord(
            new \DateTimeImmutable(), 'testing', Level::Error, 'boom', ['exception' => $e], [],
        )), true);
        $this->assertIsArray($withTrace['exception']['trace']);
        $this->assertLessThanOrEqual(30, count($withTrace['exception']['trace']));
    }

    public function test_secrets_and_message_content_never_reach_the_line(): void
    {
        $secrets = [
            'password' => 'hunter2-PASSWORD',
            'access_token' => 'EAAGsupersecretaccesstoken0123456789ABCDEFGHIJKLMNOP',
            'Authorization' => 'Bearer eyJhbGciOiJIUzI1NiJ9.payload.signature',
            'api_key' => 'wa_live_APIKEYVALUE1234567890',
            'X-API-SECRET' => 'APISECRETVALUE9999',
            'webhook_secret' => 'whsec_WEBHOOKSECRET555',
            'client_secret' => 'CLIENTSECRET777',
            'razorpay_signature' => 'SIGNATUREVALUE',
            'meta_access_token' => 'META_TOKEN_VALUE',
            'card_number' => '4111111111111111',
            'cookie' => 'laravel_session=abcdef',
        ];
        $content = [
            'body' => 'Hi Bob, your OTP is 482913',
            'message_preview' => 'Dear customer your invoice is due',
            'caption' => 'private photo caption',
            'rendered_message' => 'rendered text with secrets',
        ];
        $out = $this->line('request failed', array_merge($secrets, $content, ['nested' => ['inner' => ['password' => 'deep-PASSWORD', 'keep' => 'visible']]]));

        $dump = json_encode($out);
        foreach (array_merge($secrets, $content) as $key => $value) {
            $this->assertStringNotContainsString($value, $dump, "{$key} leaked");
        }
        $this->assertStringNotContainsString('deep-PASSWORD', $dump);
        $this->assertSame('visible', $out['context']['nested']['inner']['keep']);
        $this->assertSame(Redactor::MASK, $out['context']['password']);
    }

    public function test_secrets_embedded_in_free_text_are_scrubbed_everywhere(): void
    {
        $text = 'call failed: Authorization: Bearer abcdefghijklmnopqrstuv, token 12|'.str_repeat('A', 40)
            .' url https://graph.example/x?access_token=URLTOKEN123&ok=1 phone +919876543210 key sk-live-ABCDEFGHIJKLMNOPQRST';
        $out = $this->line($text, ['reason' => $text]);
        $dump = json_encode($out);

        foreach (['abcdefghijklmnopqrstuv', str_repeat('A', 40), 'URLTOKEN123', '+919876543210', '9876543210', 'ABCDEFGHIJKLMNOPQRST'] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }
        $this->assertStringContainsString('ok=1', $out['message'], 'non-secret query parameters survive');
    }

    public function test_phone_numbers_are_masked_to_the_last_four_digits(): void
    {
        $out = $this->line('delivery update', [
            'recipient_id' => '919876543210', 'phone' => '+91 98765 43210', 'from' => '919111111111', 'wa_id' => 919222222222,
            'phone_number_id' => '109876543210987', 'status' => 'failed',
        ]);

        $this->assertSame('********3210', $out['context']['recipient_id']);
        $this->assertSame('********3210', $out['context']['phone']);
        $this->assertSame('********1111', $out['context']['from']);
        $this->assertSame('********2222', $out['context']['wa_id']);
        $this->assertSame('109876543210987', $out['context']['phone_number_id'], 'Meta phone-number *object* id is operational, not PII');
        $this->assertSame('failed', $out['context']['status']);
        $this->assertSame('****', Redactor::maskPhone('12'));
    }

    public function test_counters_named_tokens_are_not_mistaken_for_secrets(): void
    {
        $out = $this->line('ai usage', ['tokens' => 120, 'max_tokens' => 800, 'prompt_tokens' => 20, 'token_count' => 5, 'access_token' => 'x-secret']);

        $this->assertSame(120, $out['context']['tokens']);
        $this->assertSame(800, $out['context']['max_tokens']);
        $this->assertSame(20, $out['context']['prompt_tokens']);
        $this->assertSame(5, $out['context']['token_count']);
        $this->assertSame(Redactor::MASK, $out['context']['access_token']);
    }

    public function test_api_key_requests_get_their_account_from_request_attributes_only(): void
    {
        $request = \Illuminate\Http\Request::create('/api/v1/x', 'POST', ['account_id' => 999]);   // input must be ignored
        $request->attributes->set('api_account_id', 31);
        $this->app->instance('request', $request);

        $out = $this->line('v1 call');
        $this->assertSame(31, $out['account_id']);

        $this->app->instance('request', \Illuminate\Http\Request::create('/api/v1/x', 'POST', ['account_id' => 999]));
        $this->assertArrayNotHasKey('account_id', $this->line('v1 call without auth'));
    }

    public function test_the_json_channel_writes_valid_json_through_laravel_with_the_context(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'jsonlog');
        config([
            'logging.channels.json.with.stream' => $path,
            'logging.default' => 'json',
        ]);
        Log::forgetChannel('json');

        Context::add('request_id', 'req-through-laravel-1');
        Log::warning('through the real channel', ['password' => 'p4ss', 'recipient_id' => '919876543210', 'attempt' => 3]);

        $line = trim((string) file_get_contents($path));
        @unlink($path);
        $out = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('warning', $out['level']);
        $this->assertSame('req-through-laravel-1', $out['request_id']);
        $this->assertSame(3, $out['context']['attempt']);
        $this->assertStringNotContainsString('p4ss', $line);
        $this->assertStringNotContainsString('919876543210', $line);
    }

    public function test_the_bulk_job_warning_masks_the_recipient_number_at_the_source(): void
    {
        $lines = [];
        Log::listen(function ($m) use (&$lines) {
            $lines[] = $m->message.' '.json_encode($m->context);
        });

        // an account that does not exist makes the dispatcher answer 'not_found' → the job's operational warning
        (new \App\Jobs\SendWhatsAppTemplateJob(999999, 1, '919876543210', []))->handle();

        $joined = implode("\n", $lines);
        $this->assertStringContainsString('SendWhatsAppTemplateJob: bulk recipient did not send.', $joined);
        $this->assertStringContainsString('********3210', $joined);
        $this->assertStringNotContainsString('919876543210', $joined);
    }
}
