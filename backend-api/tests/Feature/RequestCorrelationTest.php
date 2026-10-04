<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use App\Support\Observability\RequestId;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\Support\CapturesLogs;
use Tests\TestCase;

/**
 * Phase 12 Task 3 — request correlation ids for every /api route.
 */
class RequestCorrelationTest extends TestCase
{
    use CapturesLogs;
    use RefreshDatabase;

    private const UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        Route::middleware('api')->prefix('api')->get('_t/log-probe', function () {
            Log::info('correlation probe');

            return response()->json(['ok' => true]);
        });
        Route::middleware(['api', 'auth:sanctum'])->prefix('api')->get('_t/authed-log-probe', function () {
            Log::info('authed-only probe');

            return response()->json(['ok' => true]);
        });
    }

    public function test_a_request_id_is_generated_and_returned_in_the_response_header(): void
    {
        $response = $this->getJson('/api/_t/log-probe');

        $response->assertOk();
        $this->assertMatchesRegularExpression(self::UUID, $response->headers->get('X-Request-Id'));
    }

    public function test_every_request_gets_a_different_generated_id(): void
    {
        $a = $this->getJson('/api/_t/log-probe')->headers->get('X-Request-Id');
        $b = $this->getJson('/api/_t/log-probe')->headers->get('X-Request-Id');

        $this->assertNotSame($a, $b);
    }

    public function test_a_valid_incoming_request_id_is_accepted_and_echoed(): void
    {
        foreach (['abcd1234', 'req_2024-01-01.abc', 'Caller-Supplied-3f1c9d7e-aaaa-bbbb-cccc-0123456789ab', str_repeat('a', 64)] as $id) {
            $this->withHeaders(['X-Request-Id' => $id])->getJson('/api/_t/log-probe')
                ->assertOk()->assertHeader('X-Request-Id', $id);
        }
    }

    public function test_an_invalid_or_oversized_incoming_request_id_is_replaced(): void
    {
        $bad = [
            str_repeat('a', 65),            // oversized
            'short',                        // too short
            'has space in it 12345',        // whitespace
            'semi;colon,and"quote12',       // separators / quotes
            '<script>alert(1)</script>',    // markup
            "line\\nbreak-12345678",        // escape characters
            '../../etc/passwd-12345',       // path characters
        ];

        foreach ($bad as $id) {
            $response = $this->withHeaders(['X-Request-Id' => $id])->getJson('/api/_t/log-probe')->assertOk();
            $returned = $response->headers->get('X-Request-Id');

            $this->assertNotSame($id, $returned, "unsafe id was echoed: {$id}");
            $this->assertMatchesRegularExpression(self::UUID, $returned);
        }

        // a control character cannot even be put in a header by the client; the validator rejects it anyway
        $this->assertNull(RequestId::accept("abc\r\ndef12345"));
        $this->assertNull(RequestId::accept(['array']));
        $this->assertNull(RequestId::accept(null));
        $this->assertNull(RequestId::accept(''));
    }

    public function test_the_request_id_is_in_the_log_context_of_the_request(): void
    {
        $logs = $this->captureLogs();

        $response = $this->withHeaders(['X-Request-Id' => 'trace-me-0001'])->getJson('/api/_t/log-probe');

        $records = $this->recordsMatching($logs, 'correlation probe');
        $this->assertCount(1, $records);
        $this->assertSame('trace-me-0001', $records[0]->extra['request_id']);
        $response->assertHeader('X-Request-Id', 'trace-me-0001');
    }

    public function test_a_generated_id_is_what_the_log_context_carries(): void
    {
        $logs = $this->captureLogs();

        $response = $this->getJson('/api/_t/log-probe');

        $record = $this->recordsMatching($logs, 'correlation probe')[0];
        $this->assertSame($response->headers->get('X-Request-Id'), $record->extra['request_id']);
    }

    public function test_an_unauthenticated_request_is_correlated_and_has_no_user_or_account(): void
    {
        $logs = $this->captureLogs();

        $response = $this->getJson('/api/whatsapp/status');          // auth:sanctum → 401

        $response->assertUnauthorized();
        $this->assertMatchesRegularExpression(self::UUID, $response->headers->get('X-Request-Id'));

        $this->getJson('/api/_t/log-probe');
        $record = $this->recordsMatching($logs, 'correlation probe')[0];
        $this->assertArrayNotHasKey('user_id', $record->extra);
        $this->assertArrayNotHasKey('account_id', $record->extra);
    }

    public function test_a_404_and_a_validation_error_also_carry_the_header(): void
    {
        $this->getJson('/api/this-route-does-not-exist')->assertNotFound()->assertHeader('X-Request-Id');
        $this->postJson('/api/auth/login', [])->assertStatus(422)->assertHeader('X-Request-Id');
    }

    public function test_an_authenticated_tenant_request_logs_its_account_and_user_ids(): void
    {
        $logs = $this->captureLogs();
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $token = $user->createToken('api-token', ['*'])->plainTextToken;

        $this->app['auth']->forgetGuards();
        $response = $this->withToken($token)->getJson('/api/_t/authed-log-probe');

        $response->assertOk();
        $record = $this->recordsMatching($logs, 'authed-only probe')[0];
        $this->assertSame($response->headers->get('X-Request-Id'), $record->extra['request_id']);
        $this->assertSame($user->id, $record->extra['user_id']);
        $this->assertSame($account->id, $record->extra['account_id']);

        // ids only: no token, email or name rides along in the log context
        $dump = json_encode($record->extra);
        $this->assertStringNotContainsString($token, $dump);
        $this->assertStringNotContainsString($user->email, $dump);
    }

    public function test_a_following_request_never_inherits_the_previous_callers_identity(): void
    {
        $logs = $this->captureLogs();
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $token = $user->createToken('api-token', ['*'])->plainTextToken;

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/_t/authed-log-probe')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withoutHeader('Authorization')->getJson('/api/_t/log-probe')->assertOk();

        $anonymous = $this->recordsMatching($logs, 'correlation probe')[0];
        $this->assertArrayNotHasKey('user_id', $anonymous->extra);
        $this->assertArrayNotHasKey('account_id', $anonymous->extra);
    }

    public function test_a_non_api_route_is_left_alone(): void
    {
        $this->get('/up')->assertOk()->assertHeaderMissing('X-Request-Id');
    }

    public function test_the_v1_api_request_log_uses_the_same_validated_id(): void
    {
        // log.apirequest runs even when the API key is rejected, so the row exists without issuing a key
        $response = $this->withHeaders(['X-Request-Id' => 'bad id with spaces'])->postJson('/api/v1/whatsapp/groups/create', []);
        $returned = $response->headers->get('X-Request-Id');

        $this->assertMatchesRegularExpression(self::UUID, $returned);
        $this->assertSame($returned, \App\Models\ApiRequestLog::query()->latest('id')->value('request_id'));

        $this->withHeaders(['X-Request-Id' => 'valid-caller-id-1'])->postJson('/api/v1/whatsapp/groups/create', [])
            ->assertHeader('X-Request-Id', 'valid-caller-id-1');
        $this->assertSame('valid-caller-id-1', \App\Models\ApiRequestLog::query()->latest('id')->value('request_id'));
    }
}
