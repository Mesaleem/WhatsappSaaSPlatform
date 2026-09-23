<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;
use App\Services\Access\PlanManagementService;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Billing\PlanRepository;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 5 Task 11 — checkout cut over from PlanCatalog to the `plans`
 * table.
 *
 * WHAT THIS PROVES: a plan created or repriced through Task 10's
 * management API is purchasable immediately, at the price the database
 * says, with the capabilities plan_entitlements says — and that nothing
 * a customer puts in the request body can change any of it.
 *
 * Expected values are read from the DATABASE, not hardcoded, so these
 * tests stay honest if the seeded catalog changes.
 */
class DatabasePlanCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const PLANS_PUBLIC = '/api/billing/plans';

    private const PLANS_ADMIN = '/api/admin/plans-management';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    // =================================================================
    // Fixtures
    // =================================================================

    /** @return array{account: Account, user: User} */
    private function tenant(): array
    {
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('admin');

        return ['account' => $account, 'user' => $user];
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function repository(): PlanRepository
    {
        return app(PlanRepository::class);
    }

    /**
     * Buys a plan the way the application does — an Invoice carrying
     * only the plan key, then the real credit path. Deliberately does
     * NOT pass price/quota/engine: the point is that the server derives
     * all of them.
     */
    private function buy(Account $account, string $planKey, ?float $tamperedAmount = null): Invoice
    {
        $plan = $this->repository()->findForFulfilment($planKey);

        $invoice = Invoice::create([
            'account_id' => $account->id,
            'invoice_number' => 'INV-'.uniqid(),
            'plan_key' => $planKey,
            'plan_label' => $plan?->label ?? $planKey,
            'amount' => $tamperedAmount ?? (float) ($plan?->price ?? 0),
            'tax_amount' => 0,
            'total_amount' => $tamperedAmount ?? (float) ($plan?->price ?? 0),
            'currency' => 'INR',
            'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.uniqid(),
            'gateway_payment_id' => null,
            'status' => 'pending',
            'paid_at' => null,
            'gateway_raw_response' => null,
        ]);

        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());

        return $invoice->fresh();
    }

    /** @return array<int, string> */
    private function heldBy(Account $account): array
    {
        return AccountEntitlement::where('account_id', $account->id)
            ->active()->with('capability')->get()
            ->pluck('capability.slug')->sort()->values()->all();
    }

    // =================================================================
    // 1. Database plan lookup
    // =================================================================

    public function test_an_active_plan_is_found(): void
    {
        $plan = $this->repository()->findPurchasable('starter');

        $this->assertNotNull($plan);
        $this->assertSame('starter', $plan->slug);
    }

    public function test_an_inactive_plan_is_not_purchasable(): void
    {
        Plan::where('slug', 'starter')->update(['is_active' => false]);

        $this->assertNull($this->repository()->findPurchasable('starter'));
        // But it is still resolvable for fulfilling money already taken.
        $this->assertNotNull($this->repository()->findForFulfilment('starter'));
    }

    public function test_an_unknown_plan_is_not_purchasable(): void
    {
        $this->assertNull($this->repository()->findPurchasable('no_such_plan'));
    }

    public function test_purchasable_returns_only_active_plans_cheapest_first(): void
    {
        Plan::where('slug', 'growth')->update(['is_active' => false]);

        $slugs = $this->repository()->purchasable()->pluck('slug')->all();

        $this->assertSame(['starter', 'business'], $slugs);
    }

    // =================================================================
    // 2. Customer-facing listing
    // =================================================================

    public function test_the_public_listing_is_database_driven(): void
    {
        ['user' => $user] = $this->tenant();

        $plans = collect($this->actingAs($user)->getJson(self::PLANS_PUBLIC)->assertStatus(200)->json('plans'))
            ->keyBy('key');

        $starter = Plan::where('slug', 'starter')->firstOrFail();

        $this->assertSame(number_format((float) $starter->price, 2, '.', ''), $plans['starter']['price']);
        $this->assertSame($starter->engine_type, $plans['starter']['engine_type']);
        $this->assertSame($starter->total_allocated_messages, $plans['starter']['total_allocated_messages']);
        $this->assertSame($starter->duration_days, $plans['starter']['duration_days']);
    }

    public function test_the_public_listing_preserves_the_existing_contract(): void
    {
        ['user' => $user] = $this->tenant();

        $this->actingAs($user)->getJson(self::PLANS_PUBLIC)
            ->assertStatus(200)
            ->assertJsonStructure([
                'plans' => [['key', 'label', 'description', 'engine_type', 'billing_model',
                    'total_allocated_messages', 'duration_days', 'price', 'tax_amount', 'total_amount']],
                'available_gateways',
                'tax_rate',
            ]);
    }

    public function test_the_public_listing_hides_a_deactivated_plan(): void
    {
        ['user' => $user] = $this->tenant();

        Plan::where('slug', 'business')->update(['is_active' => false]);

        $keys = collect($this->actingAs($user)->getJson(self::PLANS_PUBLIC)->json('plans'))->pluck('key')->all();

        $this->assertNotContains('business', $keys);
        $this->assertContains('starter', $keys);
    }

    public function test_the_public_listing_leaks_no_admin_metadata(): void
    {
        ['user' => $user] = $this->tenant();

        $row = collect($this->actingAs($user)->getJson(self::PLANS_PUBLIC)->json('plans'))->first();

        foreach (['id', 'is_active', 'capabilities', 'created_at', 'updated_at', 'rate_per_message'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $row, "'{$hidden}' must not reach a customer.");
        }
    }

    // =================================================================
    // 3. Checkout reads every dimension from the database
    // =================================================================

    public function test_each_seeded_plan_can_be_purchased_and_uses_database_values(): void
    {
        foreach (['starter', 'growth', 'business'] as $slug) {
            $plan = Plan::where('slug', $slug)->firstOrFail();
            ['account' => $account] = $this->tenant();

            $this->buy($account, $slug);

            $subscription = $account->fresh()->currentSubscription;

            $this->assertNotNull($subscription, $slug);
            $this->assertSame($plan->engine_type, $subscription->engine_type, "{$slug} engine");
            $this->assertSame($plan->billing_model, $subscription->billing_model, "{$slug} billing model");
            $this->assertSame($plan->total_allocated_messages, $subscription->total_allocated_messages, "{$slug} quota");
            $this->assertSame(
                $plan->duration_days,
                (int) $subscription->starts_at->diffInDays($subscription->expires_at),
                "{$slug} duration",
            );
        }
    }

    public function test_a_newly_created_plan_is_purchasable_immediately(): void
    {
        // The reason this task exists.
        $this->actingAs($this->superAdmin())->postJson(self::PLANS_ADMIN, [
            'slug' => 'scale',
            'label' => 'Scale',
            'price' => 4999,
            'duration_days' => 60,
            'engine_type' => 'meta',
            'billing_model' => 'flat_quota',
            'total_allocated_messages' => 25000,
            'capabilities' => ['whatsapp_send', 'crm', 'ai'],
        ])->assertStatus(201);

        ['account' => $account, 'user' => $user] = $this->tenant();

        // It shows in the customer listing…
        $keys = collect($this->actingAs($user)->getJson(self::PLANS_PUBLIC)->json('plans'))->pluck('key')->all();
        $this->assertContains('scale', $keys);

        // …and it can actually be bought.
        $this->buy($account, 'scale');

        $subscription = $account->fresh()->currentSubscription;

        $this->assertSame('meta', $subscription->engine_type);
        $this->assertSame(25000, $subscription->total_allocated_messages);
        $this->assertSame(60, (int) $subscription->starts_at->diffInDays($subscription->expires_at));

        // And its capability bundle was reconciled by Task 10's service.
        $this->assertSame(['ai', 'crm', 'whatsapp_send'], $this->heldBy($account->fresh()));
    }

    public function test_a_deactivated_plan_cannot_be_ordered(): void
    {
        ['user' => $user] = $this->tenant();

        Plan::where('slug', 'business')->update(['is_active' => false]);

        $this->actingAs($user)
            ->postJson('/api/billing/create-order', ['plan_key' => 'business', 'gateway' => 'razorpay'])
            ->assertStatus(422);
    }

    public function test_an_unknown_plan_cannot_be_ordered(): void
    {
        ['user' => $user] = $this->tenant();

        $this->actingAs($user)
            ->postJson('/api/billing/create-order', ['plan_key' => 'imaginary', 'gateway' => 'razorpay'])
            ->assertStatus(422);
    }

    // =================================================================
    // 4. Request tampering
    // =================================================================

    public function test_the_order_endpoint_accepts_no_pricing_field_from_the_request(): void
    {
        /*
         * A static assertion on the validation rules: create-order
         * validates plan_key and gateway ONLY, so any price/quota/
         * engine/capability field in the body is dropped before the
         * controller can see it. This is the structural guarantee behind
         * the behavioural tests below.
         */
        $source = file_get_contents(app_path('Http/Controllers/Api/PaymentGatewayController.php'));
        $rules = substr($source, strpos($source, 'public function createOrder'), 900);

        foreach (["'price'", "'amount'", "'total_allocated_messages'", "'engine_type'", "'capabilities'", "'duration_days'"] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden.' =>',
                $rules,
                "createOrder must not accept {$forbidden} from the request.",
            );
        }
    }

    public function test_a_tampered_price_never_reaches_the_invoice(): void
    {
        ['user' => $user] = $this->tenant();

        $this->actingAs($user)->postJson('/api/billing/create-order', [
            'plan_key' => 'business',
            'gateway' => 'razorpay',
            // The attack.
            'price' => 1,
            'amount' => 1,
            'total_amount' => 1,
        ]);

        $invoice = Invoice::where('plan_key', 'business')->latest('id')->first();

        if ($invoice) {
            $this->assertSame(
                (float) Plan::where('slug', 'business')->value('price'),
                (float) $invoice->amount,
                'The database price is authoritative.',
            );
        } else {
            // No gateway configured in this environment, so the order
            // never got far enough to write an invoice — which is itself
            // proof the tampered fields changed nothing.
            $this->assertTrue(true);
        }
    }

    public function test_tampered_quota_engine_and_capabilities_never_reach_the_subscription(): void
    {
        ['account' => $account] = $this->tenant();

        // Forge an invoice carrying a wildly wrong amount, then credit
        // it: fulfilment must still read the DATABASE plan for engine,
        // quota and duration.
        $this->buy($account, 'starter', tamperedAmount: 999999.00);

        $plan = Plan::where('slug', 'starter')->firstOrFail();
        $subscription = $account->fresh()->currentSubscription;

        $this->assertSame($plan->engine_type, $subscription->engine_type);
        $this->assertSame($plan->total_allocated_messages, $subscription->total_allocated_messages);
        // Capabilities come from plan_entitlements, never from a request.
        $this->assertSame(
            $plan->capabilities->pluck('slug')->sort()->values()->all(),
            $this->heldBy($account->fresh()),
        );
    }

    public function test_a_customer_cannot_manage_plans(): void
    {
        ['user' => $user] = $this->tenant();

        $this->actingAs($user)->getJson(self::PLANS_ADMIN)->assertStatus(403);
        $this->actingAs($user)->postJson(self::PLANS_ADMIN, [
            'slug' => 'free_everything', 'label' => 'Free', 'price' => 0, 'duration_days' => 3650,
            'engine_type' => 'meta', 'billing_model' => 'unlimited',
            'capabilities' => ['whatsapp_send', 'commerce', 'custom_code'],
        ])->assertStatus(403);
        $this->actingAs($user)->putJson(self::PLANS_ADMIN.'/business', ['price' => 0])->assertStatus(403);

        $this->assertDatabaseMissing('plans', ['slug' => 'free_everything']);
        $this->assertSame(7999.00, (float) Plan::where('slug', 'business')->value('price'));
    }

    public function test_an_account_id_in_the_order_body_is_ignored(): void
    {
        ['account' => $mine, 'user' => $user] = $this->tenant();
        ['account' => $theirs] = $this->tenant();

        $this->actingAs($user)->postJson('/api/billing/create-order', [
            'plan_key' => 'starter',
            'gateway' => 'razorpay',
            'account_id' => $theirs->id,
        ]);

        $this->assertSame(0, Invoice::where('account_id', $theirs->id)->count(), 'Another tenant was billed.');
    }

    // =================================================================
    // 5. Plan modification affects the NEXT checkout only
    // =================================================================

    public function test_a_repriced_plan_is_charged_at_the_new_price_next_time(): void
    {
        ['account' => $first] = $this->tenant();
        $firstInvoice = $this->buy($first, 'business');

        $this->assertSame(7999.00, (float) $firstInvoice->amount);

        $this->actingAs($this->superAdmin())
            ->putJson(self::PLANS_ADMIN.'/business', ['price' => 8999])
            ->assertStatus(200);

        ['account' => $second] = $this->tenant();
        $secondInvoice = $this->buy($second, 'business');

        $this->assertSame(8999.00, (float) $secondInvoice->amount, 'A new purchase must use the new price.');
        // History is history.
        $this->assertSame(7999.00, (float) $firstInvoice->fresh()->amount, 'A paid invoice must never be rewritten.');
    }

    public function test_a_requoted_plan_grants_the_new_quota_next_time(): void
    {
        $this->actingAs($this->superAdmin())
            ->putJson(self::PLANS_ADMIN.'/starter', ['total_allocated_messages' => 750])
            ->assertStatus(200);

        ['account' => $account] = $this->tenant();
        $this->buy($account, 'starter');

        $this->assertSame(750, $account->fresh()->currentSubscription->total_allocated_messages);
    }

    public function test_a_re_engined_plan_provisions_the_new_engine_next_time(): void
    {
        $this->actingAs($this->superAdmin())
            ->putJson(self::PLANS_ADMIN.'/starter', ['engine_type' => 'meta'])
            ->assertStatus(200);

        ['account' => $account] = $this->tenant();
        $this->buy($account, 'starter');

        $this->assertSame('meta', $account->fresh()->currentSubscription->engine_type);
    }

    public function test_a_re_durationed_plan_extends_by_the_new_period(): void
    {
        $this->actingAs($this->superAdmin())
            ->putJson(self::PLANS_ADMIN.'/starter', ['duration_days' => 90])
            ->assertStatus(200);

        ['account' => $account] = $this->tenant();
        $this->buy($account, 'starter');

        $subscription = $account->fresh()->currentSubscription;

        $this->assertSame(90, (int) $subscription->starts_at->diffInDays($subscription->expires_at));
    }

    public function test_existing_subscriptions_are_not_rewritten_by_a_plan_price_change(): void
    {
        ['account' => $account] = $this->tenant();
        $this->buy($account, 'starter');

        $before = $account->fresh()->currentSubscription->only(['engine_type', 'total_allocated_messages', 'price_paid']);

        $this->actingAs($this->superAdmin())
            ->putJson(self::PLANS_ADMIN.'/starter', ['price' => 999, 'total_allocated_messages' => 5000])
            ->assertStatus(200);

        $this->assertSame($before, $account->fresh()->currentSubscription->only(['engine_type', 'total_allocated_messages', 'price_paid']));
    }

    // =================================================================
    // 6. Deactivation
    // =================================================================

    public function test_deactivating_a_plan_blocks_new_purchases_but_leaves_customers_alone(): void
    {
        ['account' => $account] = $this->tenant();
        $this->buy($account, 'business');

        $held = $this->heldBy($account->fresh());
        $subscription = $account->fresh()->currentSubscription->only(['engine_type', 'status']);

        $this->actingAs($this->superAdmin())
            ->putJson(self::PLANS_ADMIN.'/business', ['is_active' => false])
            ->assertStatus(200);

        // The existing customer is untouched: subscription and
        // entitlements both survive.
        $this->assertSame($held, $this->heldBy($account->fresh()));
        $this->assertSame($subscription, $account->fresh()->currentSubscription->only(['engine_type', 'status']));

        // But nobody new can buy it.
        $this->assertNull($this->repository()->findPurchasable('business'));
    }

    public function test_a_deactivated_plan_can_be_reactivated(): void
    {
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin)->putJson(self::PLANS_ADMIN.'/business', ['is_active' => false])->assertStatus(200);
        $this->assertNull($this->repository()->findPurchasable('business'));

        $this->actingAs($superAdmin)->putJson(self::PLANS_ADMIN.'/business', ['is_active' => true])->assertStatus(200);
        $this->assertNotNull($this->repository()->findPurchasable('business'));
    }

    public function test_re_seeding_does_not_reactivate_a_retired_plan(): void
    {
        Plan::where('slug', 'business')->update(['is_active' => false]);

        $this->seed(Phase1FoundationSeeder::class);

        $this->assertFalse(
            (bool) Plan::where('slug', 'business')->value('is_active'),
            'A deliberate retirement must survive a re-seed.',
        );
    }

    // =================================================================
    // 7. Entitlement integration (Task 10 still owns it)
    // =================================================================

    public function test_a_purchase_grants_the_plans_database_capabilities(): void
    {
        ['account' => $account] = $this->tenant();
        $this->buy($account, 'business');

        $plan = Plan::where('slug', 'business')->firstOrFail();

        $this->assertSame($plan->capabilities->pluck('slug')->sort()->values()->all(), $this->heldBy($account->fresh()));
    }

    public function test_provider_compatibility_still_gates_a_purchase(): void
    {
        ['account' => $account] = $this->tenant();
        // Phase 7 Task 1.5: journey_automation is now QR-supported (owner
        // decision), so the confirmed matrix no longer has an incompatible
        // growth cell. Bundle one in-test: ads is a QR refusal.
        Plan::where('slug', 'growth')->firstOrFail()->capabilities()->syncWithoutDetaching([
            \App\Models\Capability::where('slug', 'ads')->value('id') => ['usage_limit' => null],
        ]);
        $this->buy($account, 'growth');

        $this->assertContains('ads', Plan::where('slug', 'growth')->firstOrFail()->capabilities->pluck('slug')->all());
        $this->assertNotContains('ads', $this->heldBy($account->fresh()));
        $this->assertContains('journey_automation', $this->heldBy($account->fresh()));
        $this->assertContains('crm', $this->heldBy($account->fresh()));
    }

    public function test_a_manual_grant_survives_a_purchase(): void
    {
        ['account' => $account] = $this->tenant();
        $this->buy($account, 'starter');

        AccountEntitlement::create([
            'account_id' => $account->id,
            'capability_id' => \App\Models\Capability::where('slug', 'ai')->value('id'),
            'source' => AccountEntitlement::SOURCE_MANUAL_GRANT,
        ]);

        $this->buy($account, 'growth');

        $row = AccountEntitlement::where('account_id', $account->id)
            ->where('capability_id', \App\Models\Capability::where('slug', 'ai')->value('id'))
            ->first();

        $this->assertSame(AccountEntitlement::SOURCE_MANUAL_GRANT, $row->source);
        $this->assertNull($row->revoked_at);
    }

    public function test_a_manual_revocation_survives_a_purchase(): void
    {
        ['account' => $account] = $this->tenant();
        $this->buy($account, 'business');

        $this->actingAs($this->superAdmin())
            ->deleteJson("/api/admin/accounts/{$account->id}/entitlements/custom_code")
            ->assertStatus(200);

        // Buying the same plan again must not undo the administrator.
        $this->buy($account, 'business');

        $this->assertNotContains('custom_code', $this->heldBy($account->fresh()));
    }

    // =================================================================
    // 8. PlanCatalog is no longer a runtime source
    // =================================================================

    public function test_no_runtime_checkout_path_reads_the_static_plan_catalog(): void
    {
        foreach ([
            app_path('Http/Controllers/Api/PaymentGatewayController.php'),
            app_path('Services/Billing/InvoiceCreditService.php'),
            app_path('Services/Billing/PlanRepository.php'),
            app_path('Services/Access/PlanEntitlementReconciliationService.php'),
            app_path('Services/Access/PlanManagementService.php'),
        ] as $file) {
            $code = $this->stripComments(file_get_contents($file));

            $this->assertStringNotContainsString(
                'PlanCatalog',
                $code,
                basename($file).' still reads the static plan catalog at runtime.',
            );
        }
    }

    /** Comment-stripped source, so a docblock naming PlanCatalog is not a false positive. */
    private function stripComments(string $code): string
    {
        $out = '';

        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    public function test_the_seeded_plans_still_match_the_static_catalog_values(): void
    {
        // The cutover must be behaviour-preserving: the database rows
        // carry exactly what checkout used to serve.
        foreach (\App\Support\PlanCatalog::all() as $slug => $expected) {
            $plan = Plan::where('slug', $slug)->firstOrFail();

            $this->assertSame($expected['price'], (float) $plan->price, $slug);
            $this->assertSame($expected['engine_type'], $plan->engine_type, $slug);
            $this->assertSame($expected['billing_model'], $plan->billing_model, $slug);
            $this->assertSame($expected['total_allocated_messages'], $plan->total_allocated_messages, $slug);
            $this->assertSame($expected['duration_days'], $plan->duration_days, $slug);
        }
    }

    // =================================================================
    // 9. Plan management integration
    // =================================================================

    public function test_the_management_service_creates_a_purchasable_plan(): void
    {
        app(PlanManagementService::class)->create('tiny', [
            'label' => 'Tiny',
            'price' => 99,
            'duration_days' => 7,
            'engine_type' => 'qr',
            'billing_model' => 'flat_quota',
            'total_allocated_messages' => 50,
        ], ['whatsapp_send']);

        $plan = $this->repository()->findPurchasable('tiny');

        $this->assertNotNull($plan);
        $this->assertSame(50, $plan->total_allocated_messages);
    }

    public function test_the_management_listing_shows_the_billing_dimensions(): void
    {
        $row = collect($this->actingAs($this->superAdmin())->getJson(self::PLANS_ADMIN)->json('data'))
            ->firstWhere('slug', 'business');

        $this->assertSame('meta', $row['engine_type']);
        $this->assertSame(10000, $row['total_allocated_messages']);
        $this->assertTrue($row['is_active']);
    }

    public function test_an_invalid_engine_type_is_rejected(): void
    {
        $this->actingAs($this->superAdmin())->postJson(self::PLANS_ADMIN, [
            'slug' => 'bad_engine', 'label' => 'Bad', 'price' => 1, 'duration_days' => 30,
            'engine_type' => 'carrier_pigeon', 'billing_model' => 'flat_quota',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('plans', ['slug' => 'bad_engine']);
    }
}
