<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Services\Billing\InvoiceCreditService;
use App\Services\WhatsApp\BaileysDriver;
use App\Services\WhatsApp\MetaCloudApiDriver;
use App\Services\WhatsApp\WhatsAppEngineFactory;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 4 Task 1 -- Meta WhatsApp Provider Foundation.
 *
 * The provider itself (MetaCloudApiDriver, the meta_* columns on
 * whatsapp_sessions, the encrypted-cast token, MetaConfigController's
 * verify-before-save and masked responses, WhatsAppEngineFactory's
 * engine_type resolution) already existed; no test covered ANY of it --
 * a repo-wide grep for a Meta config test found none. This file is that
 * missing coverage, plus the two genuine gaps the audit turned up:
 *
 *   1. meta_phone_number_id had no uniqueness. MetaWebhookController
 *      resolves the owning tenant purely by that column, so two tenants
 *      claiming the same number would have silently routed one tenant's
 *      inbound messages to the other. Now a 409 + a unique index.
 *   2. A verified Meta connection left whatsapp_sessions.status at its
 *      'disconnected' default forever, because only the QR status
 *      callback ever wrote that column.
 *
 * Every Graph API call is faked -- no test here reaches Meta.
 */
class MetaProviderFoundationTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE_ID = '123456789012345';

    private const TOKEN = 'EAAG_super_secret_meta_token_value_0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        /*
         * Phase 5 Task 11 — the `plans` table is now the runtime source of
         * truth for checkout AND fulfilment, so markPaidAndCreditQuota()
         * can no longer credit a payment on an unseeded database. Real
         * environments always have this seeded; seeding it here makes the
         * fixture match production rather than relying on the static
         * PlanCatalog the cutover removed from every runtime path.
         */
        $this->seed(Phase1FoundationSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    /** Graph API responds as if the credentials are valid. */
    private function fakeMetaValid(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'verified_name' => 'Acme Support',
                'display_phone_number' => '+91 99999 99999',
                'id' => self::PHONE_ID,
            ], 200),
        ]);
    }

    private function fakeMetaRejects(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Invalid OAuth access token.'],
            ], 401),
        ]);
    }

    private function giveActiveSubscription(Account $account, string $planKey = 'business'): void
    {
        $plan = PlanCatalog::find($planKey);

        $invoice = Invoice::create([
            'account_id' => $account->id,
            'invoice_number' => 'INV-'.uniqid(),
            'plan_key' => $planKey,
            'plan_label' => $plan['label'],
            'amount' => $plan['price'],
            'tax_amount' => 0,
            'total_amount' => $plan['price'],
            'currency' => 'INR',
            'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.uniqid(),
            'gateway_payment_id' => null,
            'status' => 'pending',
            'paid_at' => null,
            'gateway_raw_response' => null,
        ]);

        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());
    }

    /**
     * An Admin of a tenant on the Meta engine. 'business' is the only
     * PlanCatalog entry whose engine_type is 'meta' -- resolved from the
     * catalog rather than hardcoded here, so this never pins a plan name
     * to a capability.
     */
    private function makeMetaTenant(array $accountOverrides = []): array
    {
        $account = Account::factory()->create($accountOverrides);
        $this->giveActiveSubscription($account);

        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('admin');

        return ['account' => $account, 'user' => $user];
    }

    /** @return array<string, string> */
    private function validPayload(string $phoneId = self::PHONE_ID): array
    {
        return [
            'meta_phone_number_id' => $phoneId,
            'meta_waba_id' => '987654321098765',
            'meta_access_token' => self::TOKEN,
        ];
    }

    // ------------------------------------------------------------------
    // Meta connection creation
    // ------------------------------------------------------------------
    public function test_admin_can_create_a_meta_connection(): void
    {
        $this->fakeMetaValid();
        $t = $this->makeMetaTenant();

        $response = $this->actingAs($t['user'])->postJson('/api/whatsapp/meta-config', $this->validPayload());

        $response->assertStatus(201);
        $response->assertJson([
            'message' => 'Meta credentials saved.',
            'meta_phone_number_id' => self::PHONE_ID,
            'meta_waba_id' => '987654321098765',
            'verified_name' => 'Acme Support',
        ]);

        $session = WhatsAppSession::where('account_id', $t['account']->id)->firstOrFail();
        $this->assertSame(self::PHONE_ID, $session->meta_phone_number_id);
        $this->assertSame(self::TOKEN, $session->meta_access_token, 'the encrypted cast must round-trip');
        $this->assertNotEmpty($session->meta_webhook_verify_token);
        $this->assertTrue($session->hasMetaConfigured());
    }

    public function test_connection_status_becomes_connected_once_credentials_verify(): void
    {
        $this->fakeMetaValid();
        $t = $this->makeMetaTenant();

        // Before: no session row at all -> reported as disconnected.
        $this->actingAs($t['user'])->getJson('/api/whatsapp/meta-config')
            ->assertStatus(200)
            ->assertJson(['configured' => false, 'connection_status' => 'disconnected']);

        // Phase 4 Task 2: the save response carries the same key, so the UI
        // can render the live status without a second round trip.
        $this->actingAs($t['user'])->postJson('/api/whatsapp/meta-config', $this->validPayload())
            ->assertStatus(201)
            ->assertJson(['connection_status' => 'connected']);

        $session = WhatsAppSession::where('account_id', $t['account']->id)->firstOrFail();
        $this->assertSame('connected', $session->status);
        $this->assertNotNull($session->last_connected_at);

        $this->actingAs($t['user'])->getJson('/api/whatsapp/meta-config')
            ->assertStatus(200)
            ->assertJson(['configured' => true, 'connection_status' => 'connected']);
    }

    public function test_saving_again_updates_the_same_row_rather_than_creating_a_second(): void
    {
        $this->fakeMetaValid();
        $t = $this->makeMetaTenant();

        $this->actingAs($t['user'])->postJson('/api/whatsapp/meta-config', $this->validPayload())->assertStatus(201);
        $firstVerifyToken = WhatsAppSession::where('account_id', $t['account']->id)->value('meta_webhook_verify_token');

        $this->actingAs($t['user'])->postJson('/api/whatsapp/meta-config', $this->validPayload())->assertStatus(201);

        $this->assertSame(1, WhatsAppSession::where('account_id', $t['account']->id)->count());
        // The verify token is what the admin pasted into Meta's dashboard —
        // re-saving credentials must not silently invalidate it.
        $this->assertSame(
            $firstVerifyToken,
            WhatsAppSession::where('account_id', $t['account']->id)->value('meta_webhook_verify_token'),
        );
    }

    // ------------------------------------------------------------------
    // Encrypted credential storage / no token exposure
    // ------------------------------------------------------------------
    public function test_access_token_is_encrypted_at_rest(): void
    {
        $this->fakeMetaValid();
        $t = $this->makeMetaTenant();
        $this->actingAs($t['user'])->postJson('/api/whatsapp/meta-config', $this->validPayload())->assertStatus(201);

        // Read the raw column, bypassing Eloquent's cast entirely.
        $raw = DB::table('whatsapp_sessions')->where('account_id', $t['account']->id)->value('meta_access_token');

        $this->assertNotSame(self::TOKEN, $raw);
        $this->assertStringNotContainsString(self::TOKEN, (string) $raw);
        $this->assertStringNotContainsString('EAAG', (string) $raw);
        // ...but still decrypts through the model.
        $this->assertSame(self::TOKEN, WhatsAppSession::where('account_id', $t['account']->id)->first()->meta_access_token);
    }

    public function test_token_is_never_returned_by_any_meta_config_response(): void
    {
        $this->fakeMetaValid();
        $t = $this->makeMetaTenant();

        $store = $this->actingAs($t['user'])->postJson('/api/whatsapp/meta-config', $this->validPayload());
        $show = $this->actingAs($t['user'])->getJson('/api/whatsapp/meta-config');

        foreach ([$store, $show] as $response) {
            $body = $response->getContent();
            $this->assertStringNotContainsString(self::TOKEN, $body);
            $this->assertStringNotContainsString('EAAG', $body);
            // Only the fixed-shape mask is exposed. Asserted on the decoded
            // value, not the raw body: '•' serializes as \u2022 in JSON.
            $this->assertStringContainsString('••••', (string) $response->json('meta_access_token_masked'));
        }

        $show->assertJsonMissingPath('meta_access_token');
        $store->assertJsonMissingPath('meta_access_token');
    }

    public function test_model_serialization_never_leaks_the_token(): void
    {
        $this->fakeMetaValid();
        $t = $this->makeMetaTenant();
        $this->actingAs($t['user'])->postJson('/api/whatsapp/meta-config', $this->validPayload());

        $session = WhatsAppSession::where('account_id', $t['account']->id)->firstOrFail();

        $this->assertArrayNotHasKey('meta_access_token', $session->toArray());
        $this->assertStringNotContainsString(self::TOKEN, $session->toJson());
    }

    public function test_token_is_never_written_to_the_log(): void
    {
        $this->fakeMetaValid();
        $t = $this->makeMetaTenant();

        $lines = [];
        Log::listen(function ($message) use (&$lines) {
            $lines[] = $message->message.' '.json_encode($message->context);
        });

        $this->actingAs($t['user'])->postJson('/api/whatsapp/meta-config', $this->validPayload())->assertStatus(201);
        $this->actingAs($t['user'])->getJson('/api/whatsapp/meta-config')->assertStatus(200);

        $logged = implode("\n", $lines);
        $this->assertStringNotContainsString(self::TOKEN, $logged);
        $this->assertStringNotContainsString('EAAG', $logged);
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------
    public function test_missing_required_fields_are_rejected_with_field_errors(): void
    {
        $this->fakeMetaValid();
        $t = $this->makeMetaTenant();

        $response = $this->actingAs($t['user'])->postJson('/api/whatsapp/meta-config', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['meta_phone_number_id', 'meta_waba_id', 'meta_access_token']);
        $this->assertSame(0, WhatsAppSession::count());
        Http::assertNothingSent();
    }

    public function test_credentials_meta_rejects_are_never_persisted(): void
    {
        $this->fakeMetaRejects();
        $t = $this->makeMetaTenant();

        $response = $this->actingAs($t['user'])->postJson('/api/whatsapp/meta-config', $this->validPayload());

        $response->assertStatus(422);
        $response->assertJson(['message' => 'Could not verify these credentials with Meta. Nothing was saved.']);
        $this->assertSame(0, WhatsAppSession::count());
    }

    public function test_test_connection_requires_both_fields(): void
    {
        $t = $this->makeMetaTenant();

        $this->actingAs($t['user'])->postJson('/api/whatsapp/meta-config/test-connection', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['meta_phone_number_id', 'meta_access_token']);
    }

    // ------------------------------------------------------------------
    // Duplicate / conflicting connection
    // ------------------------------------------------------------------
    public function test_a_phone_number_already_connected_to_another_tenant_is_a_409(): void
    {
        $this->fakeMetaValid();
        $first = $this->makeMetaTenant();
        $second = $this->makeMetaTenant();

        $this->actingAs($first['user'])->postJson('/api/whatsapp/meta-config', $this->validPayload())
            ->assertStatus(201);

        $conflict = $this->actingAs($second['user'])
            ->postJson('/api/whatsapp/meta-config', $this->validPayload());

        $conflict->assertStatus(409);
        $conflict->assertJson(['error_code' => 'META_NUMBER_ALREADY_CONNECTED']);

        // The second tenant got nothing, and the first tenant still owns it.
        $this->assertSame(0, WhatsAppSession::where('account_id', $second['account']->id)->count());
        $this->assertSame(
            $first['account']->id,
            WhatsAppSession::where('meta_phone_number_id', self::PHONE_ID)->value('account_id'),
        );
    }

    public function test_the_conflicting_attempt_never_reaches_meta(): void
    {
        $this->fakeMetaValid();
        $first = $this->makeMetaTenant();
        $second = $this->makeMetaTenant();

        $this->actingAs($first['user'])->postJson('/api/whatsapp/meta-config', $this->validPayload());
        $sentAfterFirst = count(Http::recorded());

        $this->actingAs($second['user'])->postJson('/api/whatsapp/meta-config', $this->validPayload())
            ->assertStatus(409);

        $this->assertCount($sentAfterFirst, Http::recorded(), 'a conflicting save must be refused before the Graph call');
    }

    public function test_the_database_refuses_a_duplicate_phone_number_id(): void
    {
        $a = Account::factory()->create();
        $b = Account::factory()->create();

        WhatsAppSession::create(['account_id' => $a->id, 'meta_phone_number_id' => self::PHONE_ID, 'status' => 'connected']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        WhatsAppSession::create(['account_id' => $b->id, 'meta_phone_number_id' => self::PHONE_ID, 'status' => 'connected']);
    }

    public function test_accounts_without_meta_configured_are_unaffected_by_the_unique_index(): void
    {
        // Multiple NULLs must remain legal — every QR account has one.
        $a = Account::factory()->create();
        $b = Account::factory()->create();

        WhatsAppSession::create(['account_id' => $a->id, 'status' => 'connected']);
        WhatsAppSession::create(['account_id' => $b->id, 'status' => 'disconnected']);

        $this->assertSame(2, WhatsAppSession::whereNull('meta_phone_number_id')->count());
    }

    // ------------------------------------------------------------------
    // Tenant isolation
    // ------------------------------------------------------------------
    public function test_one_tenant_never_sees_another_tenants_meta_config(): void
    {
        $this->fakeMetaValid();
        $owner = $this->makeMetaTenant();
        $other = $this->makeMetaTenant();

        $this->actingAs($owner['user'])->postJson('/api/whatsapp/meta-config', $this->validPayload())->assertStatus(201);

        $response = $this->actingAs($other['user'])->getJson('/api/whatsapp/meta-config');

        $response->assertStatus(200);
        $response->assertJson(['configured' => false, 'meta_phone_number_id' => null]);
        $this->assertStringNotContainsString(self::PHONE_ID, $response->getContent());
    }

    public function test_account_id_cannot_be_injected_to_write_into_another_tenant(): void
    {
        $this->fakeMetaValid();
        $owner = $this->makeMetaTenant();
        $attacker = $this->makeMetaTenant();

        $this->actingAs($attacker['user'])->postJson('/api/whatsapp/meta-config', [
            ...$this->validPayload('555000111222333'),
            'account_id' => $owner['account']->id,
        ])->assertStatus(201);

        // The row landed on the attacker's own account, not the injected one.
        $this->assertSame(0, WhatsAppSession::where('account_id', $owner['account']->id)->count());
        $this->assertSame(1, WhatsAppSession::where('account_id', $attacker['account']->id)->count());
    }

    // ------------------------------------------------------------------
    // Authorization: unauthenticated / inactive / wrong role / module
    // ------------------------------------------------------------------
    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/whatsapp/meta-config')->assertStatus(401);
        $this->postJson('/api/whatsapp/meta-config', $this->validPayload())->assertStatus(401);
    }

    public function test_an_inactive_account_cannot_save_meta_credentials(): void
    {
        $this->fakeMetaValid();
        $t = $this->makeMetaTenant(['status' => 'inactive']);

        $this->actingAs($t['user'])->postJson('/api/whatsapp/meta-config', $this->validPayload())
            ->assertStatus(403);

        $this->assertSame(0, WhatsAppSession::count());
    }

    public function test_a_non_admin_role_cannot_manage_meta_credentials(): void
    {
        $this->fakeMetaValid();
        $account = Account::factory()->create();
        $this->giveActiveSubscription($account);
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('user');

        $this->actingAs($user)->getJson('/api/whatsapp/meta-config')->assertStatus(403);
        $this->actingAs($user)->postJson('/api/whatsapp/meta-config', $this->validPayload())->assertStatus(403);
        $this->assertSame(0, WhatsAppSession::count());
    }

    public function test_module_denial_blocks_meta_configuration(): void
    {
        $this->fakeMetaValid();
        // whatsapp_setup deliberately absent from allowed_modules — the
        // existing entitlement mechanism this route is gated by
        // (module.guard:whatsapp_setup), not a new capability check.
        $t = $this->makeMetaTenant(['allowed_modules' => ['dashboard', 'analytics']]);

        $this->actingAs($t['user'])->postJson('/api/whatsapp/meta-config', $this->validPayload())
            ->assertStatus(403);

        $this->assertSame(0, WhatsAppSession::count());
    }

    // ------------------------------------------------------------------
    // Provider resolution (Meta) + QR regression
    // ------------------------------------------------------------------
    public function test_meta_engine_account_resolves_to_the_meta_driver(): void
    {
        $this->fakeMetaValid();
        $t = $this->makeMetaTenant();
        $this->actingAs($t['user'])->postJson('/api/whatsapp/meta-config', $this->validPayload())->assertStatus(201);

        $account = Account::with(['currentSubscription', 'whatsAppSession'])->find($t['account']->id);

        $this->assertSame('meta', $account->currentSubscription->engine_type);
        $this->assertInstanceOf(MetaCloudApiDriver::class, WhatsAppEngineFactory::make($account));
    }

    public function test_meta_engine_without_credentials_refuses_to_resolve_a_driver(): void
    {
        $t = $this->makeMetaTenant();
        $account = Account::with(['currentSubscription', 'whatsAppSession'])->find($t['account']->id);

        $this->expectException(RuntimeException::class);
        WhatsAppEngineFactory::make($account);
    }

    /** QR regression: the existing provider must be completely untouched by all of the above. */
    public function test_qr_engine_account_still_resolves_to_the_baileys_driver(): void
    {
        $account = Account::factory()->create();
        $this->giveActiveSubscription($account, 'starter'); // starter is the qr engine

        $fresh = Account::with(['currentSubscription', 'whatsAppSession'])->find($account->id);

        $this->assertSame('qr', $fresh->currentSubscription->engine_type);
        $this->assertInstanceOf(BaileysDriver::class, WhatsAppEngineFactory::make($fresh));
    }

    public function test_a_qr_account_is_unaffected_by_another_tenants_meta_connection(): void
    {
        $this->fakeMetaValid();
        $metaTenant = $this->makeMetaTenant();
        $this->actingAs($metaTenant['user'])->postJson('/api/whatsapp/meta-config', $this->validPayload())->assertStatus(201);

        $qrAccount = Account::factory()->create();
        $this->giveActiveSubscription($qrAccount, 'starter');
        WhatsAppSession::create(['account_id' => $qrAccount->id, 'status' => 'connected']);

        $fresh = Account::with(['currentSubscription', 'whatsAppSession'])->find($qrAccount->id);

        $this->assertInstanceOf(BaileysDriver::class, WhatsAppEngineFactory::make($fresh));
        $this->assertSame('connected', $fresh->whatsAppSession->status);
        $this->assertNull($fresh->whatsAppSession->meta_phone_number_id);
        $this->assertFalse($fresh->whatsAppSession->hasMetaConfigured());
    }

    public function test_engine_type_comes_from_the_plan_catalog_not_a_hardcoded_plan_name(): void
    {
        // Guards rule 10: the provider is chosen by the subscription's
        // engine_type, so a catalog change flows through without code edits.
        $this->assertSame('meta', PlanCatalog::find('business')['engine_type']);
        $this->assertSame('qr', PlanCatalog::find('starter')['engine_type']);

        $metaPlans = array_filter(PlanCatalog::all(), fn ($p) => $p['engine_type'] === 'meta');
        $this->assertNotEmpty($metaPlans, 'at least one catalog plan must map to the meta provider');
    }
}
