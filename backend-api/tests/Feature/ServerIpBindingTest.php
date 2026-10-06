<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ApiKey;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ApiAccess\ServerIpBindingService;
use Carbon\Carbon;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Developer API server IP binding: a key works only from the account's registered server IP. The first save is free,
 * one change is free 14 days after it, and every change after that needs a paid plan invoice.
 */
class ServerIpBindingTest extends TestCase
{
    use RefreshDatabase;

    private const OWN_SERVER = '203.0.113.10';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A client with an active plan (so the key is included) and an admin user. */
    private function client(): array
    {
        $account = Account::factory()->create(['allowed_modules' => ['dashboard']]);
        Subscription::factory()->for($account)->create([
            'engine_type' => 'qr',
            'status' => 'active',
            'expires_at' => now()->addMonth(),
        ]);

        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');

        $plainKey = 'wasaas_live_'.Str::random(40);
        ApiKey::create([
            'account_id' => $account->id,
            'name' => 'Client API Key',
            'key_prefix' => substr($plainKey, 0, 20),
            'key_hash' => ApiKey::hashKey($plainKey),
        ]);

        return [$account, $user, $plainKey];
    }

    /** A DELETE on the Developer API: it passes the gate and then answers 404 for an unknown id. */
    private function callApi(string $plainKey, string $fromIp = '127.0.0.1')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $fromIp])
            ->withHeaders(['X-API-KEY' => $plainKey, 'Idempotency-Key' => 'ip-'.Str::random(8)])
            ->deleteJson('/api/v1/scheduled-messages/999999');
    }

    private function saveIp($user, string $ip)
    {
        return $this->actingAs($user)->putJson('/api/account/api-key/server-ip', ['authorized_server_ip' => $ip]);
    }

    public function test_a_request_from_the_registered_server_is_allowed(): void
    {
        [$account, $user, $plainKey] = $this->client();
        $account->forceFill(['authorized_server_ip' => '127.0.0.1'])->save();

        $this->callApi($plainKey, '127.0.0.1')->assertNotFound();
    }

    public function test_a_request_from_another_server_is_refused_with_a_generic_message(): void
    {
        [$account, $user, $plainKey] = $this->client();
        $account->forceFill(['authorized_server_ip' => self::OWN_SERVER])->save();

        $response = $this->callApi($plainKey, '198.51.100.7');

        $response->assertForbidden()
            ->assertJsonPath('error_code', 'API_CLIENT_NOT_AUTHORIZED');
        $this->assertStringNotContainsString(self::OWN_SERVER, $response->getContent(), 'the registered IP must not be disclosed');
    }

    public function test_a_key_with_no_registered_ip_is_refused(): void
    {
        [, , $plainKey] = $this->client();

        $this->callApi($plainKey)
            ->assertForbidden()
            ->assertJsonPath('error_code', 'API_SERVER_BINDING_REQUIRED');
    }

    public function test_the_first_save_is_free_and_does_not_count_as_an_edit(): void
    {
        [$account, $user] = $this->client();

        $this->saveIp($user, self::OWN_SERVER)
            ->assertOk()
            ->assertJsonPath('server_ip.authorized_server_ip', self::OWN_SERVER)
            ->assertJsonPath('server_ip.ip_edit_count', 0);
    }

    public function test_an_invalid_ip_or_a_range_is_refused(): void
    {
        [, $user] = $this->client();

        $this->saveIp($user, 'not-an-ip')->assertStatus(422)->assertJsonPath('error', 'INVALID_IP');
        $this->saveIp($user, '203.0.113.0/24')->assertStatus(422)->assertJsonPath('error', 'INVALID_IP');
    }

    public function test_one_change_is_free_only_after_fourteen_days(): void
    {
        [$account, $user] = $this->client();
        $this->saveIp($user, self::OWN_SERVER)->assertOk();

        Carbon::setTestNow(now()->addDays(13));
        $this->saveIp($user, '198.51.100.7')
            ->assertForbidden()
            ->assertJsonPath('error', 'IP_EDIT_TOO_EARLY');

        Carbon::setTestNow(now()->addDay()->addMinute());
        $this->saveIp($user, '198.51.100.7')
            ->assertOk()
            ->assertJsonPath('server_ip.authorized_server_ip', '198.51.100.7')
            ->assertJsonPath('server_ip.ip_edit_count', 1);
    }

    public function test_a_change_after_the_free_one_needs_a_paid_plan_invoice(): void
    {
        [$account, $user] = $this->client();
        $this->saveIp($user, self::OWN_SERVER)->assertOk();

        Carbon::setTestNow(now()->addDays(15));
        $this->saveIp($user, '198.51.100.7')->assertOk();

        Carbon::setTestNow(now()->addDays(40));
        $this->saveIp($user, '192.0.2.44')
            ->assertForbidden()
            ->assertJsonPath('error', 'LIMIT_EXCEEDED')
            ->assertJsonPath('require_payment', true);

        Invoice::query()->create([
            'account_id' => $account->id,
            'invoice_number' => 'INV-IP-'.Str::random(6),
            'plan_key' => 'starter',
            'plan_label' => 'Starter',
            'amount' => 599,
            'tax_amount' => 0,
            'total_amount' => 599,
            'currency' => 'INR',
            'payment_gateway' => 'manual',
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $this->saveIp($user, '192.0.2.44')
            ->assertOk()
            ->assertJsonPath('server_ip.ip_edit_count', 2);
    }

    public function test_the_gate_follows_the_saved_ip_after_a_change(): void
    {
        [$account, $user, $plainKey] = $this->client();
        $this->saveIp($user, self::OWN_SERVER)->assertOk();

        $this->callApi($plainKey, '127.0.0.1')->assertForbidden();
        $this->saveIp($user, '127.0.0.1');
        Carbon::setTestNow(now()->addDays(15));
        $this->saveIp($user, '127.0.0.1')->assertOk();

        $this->callApi($plainKey, '127.0.0.1')->assertNotFound();
    }

    public function test_the_key_page_reports_the_ip_state_and_the_price_to_recharge(): void
    {
        [, $user] = $this->client();

        $this->actingAs($user)->getJson('/api/account/api-key')
            ->assertOk()
            ->assertJsonPath('server_ip.authorized_server_ip', null)
            ->assertJsonPath('server_ip.can_edit', true)
            ->assertJsonPath('server_ip.recharge_price', ServerIpBindingService::DEFAULT_RECHARGE_PRICE);
    }

    public function test_a_key_cannot_be_generated_before_the_server_ip_is_saved(): void
    {
        [, $user] = $this->client();

        $this->actingAs($user)->postJson('/api/account/api-key/regenerate', ['acknowledge_ip_restriction' => true])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'SERVER_IP_REQUIRED');
    }

    public function test_a_key_cannot_be_generated_without_acknowledging_the_ip_restriction(): void
    {
        [$account, $user] = $this->client();
        $account->forceFill(['authorized_server_ip' => self::OWN_SERVER])->save();

        $this->actingAs($user)->postJson('/api/account/api-key/regenerate')->assertStatus(422)
            ->assertJsonValidationErrors(['acknowledge_ip_restriction']);
    }

    public function test_a_refused_ip_gets_a_verify_flag_without_the_registered_ip(): void
    {
        [$account, , $plainKey] = $this->client();
        $account->forceFill(['authorized_server_ip' => self::OWN_SERVER])->save();

        $response = $this->callApi($plainKey, '198.51.100.7');

        $response->assertForbidden()->assertJsonPath('action', 'verify_server_ip');
        $this->assertStringNotContainsString(self::OWN_SERVER, $response->getContent());
    }

    public function test_a_super_admin_reset_gives_one_free_ip_change_again(): void
    {
        [$account, $user] = $this->client();
        $this->saveIp($user, self::OWN_SERVER)->assertOk();
        Carbon::setTestNow(now()->addDays(15));
        $this->saveIp($user, '198.51.100.7')->assertOk();

        $admin = User::factory()->create(['account_id' => null]);
        $admin->assignRole('super_admin');

        $this->actingAs($admin)->postJson("/api/admin/accounts/{$account->id}/reset-ip-edit-count")
            ->assertOk()
            ->assertJsonPath('server_ip.ip_edit_count', 0);

        $this->saveIp($user, '192.0.2.44')->assertOk()->assertJsonPath('server_ip.ip_edit_count', 1);
    }

    public function test_a_client_admin_cannot_reset_the_ip_edit_count(): void
    {
        [$account, $user] = $this->client();

        $this->actingAs($user)->postJson("/api/admin/accounts/{$account->id}/reset-ip-edit-count")->assertForbidden();
    }
}
