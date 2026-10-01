<?php

namespace Tests\Feature;

use App\Models\Capability;
use App\Models\Plan;
use App\Services\Access\PlanManagementService;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Phase 5 fix P5-10 — Phase1FoundationSeeder is safe to re-run in
 * production: plans are insert-only.
 *
 *   missing plan  → created from the foundation defaults, with its baseline
 *                   capability bundle;
 *   existing plan → preserved exactly (every commercial field, is_active,
 *                   and every plan_entitlements row, including removals and
 *                   changed pivot values).
 *
 * Administrator edits go through the real admin path
 * (PlanManagementService::modify), except the whatsapp_send quota pivot,
 * which no service edits and is changed directly.
 */
class FoundationSeederSafetyTest extends TestCase
{
    use RefreshDatabase;

    /** The confirmed baseline bundles (mirror of the seeder's PLAN_CAPABILITIES). */
    private const BASELINE = [
        'starter' => ['external_api', 'social', 'whatsapp_groups', 'whatsapp_send'],
        'growth' => ['ai', 'crm', 'email', 'external_api', 'journey_automation', 'payments', 'social', 'whatsapp_groups', 'whatsapp_send'],
        'business' => ['ads', 'ai', 'commerce', 'crm', 'custom_code', 'email', 'external_api', 'journey_automation', 'payments', 'social', 'whatsapp_send'],
    ];

    private function seedFoundation(): void
    {
        $this->seed(Phase1FoundationSeeder::class);
    }

    /** @return array{plans: array<int, array>, entitlements: array<int, array>} every column of both tables */
    private function snapshot(): array
    {
        return [
            'plans' => DB::table('plans')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'entitlements' => DB::table('plan_entitlements')->orderBy('plan_id')->orderBy('capability_id')->get()->map(fn ($r) => (array) $r)->all(),
        ];
    }

    /** @return list<string> */
    private function bundle(string $slug): array
    {
        return Plan::where('slug', $slug)->firstOrFail()->capabilities()->pluck('slug')->sort()->values()->all();
    }

    private function pivot(string $planSlug, string $capabilitySlug): object
    {
        return DB::table('plan_entitlements')
            ->where('plan_id', Plan::where('slug', $planSlug)->value('id'))
            ->where('capability_id', Capability::where('slug', $capabilitySlug)->value('id'))
            ->first();
    }

    // ------------------------------------------------------------------ first run

    public function test_a_first_run_creates_the_baseline_plans_with_their_bundles(): void
    {
        $this->assertSame(0, Plan::count());

        $this->seedFoundation();

        $this->assertSame(['business', 'growth', 'starter'], Plan::orderBy('slug')->pluck('slug')->all());
        foreach (self::BASELINE as $slug => $capabilities) {
            $plan = Plan::where('slug', $slug)->firstOrFail();
            $catalog = PlanCatalog::find($slug);
            $this->assertSame($capabilities, $this->bundle($slug), "{$slug} bundle");
            $this->assertEquals($catalog['price'], $plan->price);
            $this->assertSame($catalog['engine_type'], $plan->engine_type);
            $this->assertSame($catalog['total_allocated_messages'], $plan->total_allocated_messages);
            $this->assertTrue((bool) $plan->is_active);
            $this->assertSame(0, (int) $plan->included_credits);
            $this->assertSame($catalog['total_allocated_messages'], (int) $this->pivot($slug, 'whatsapp_send')->usage_limit);
        }
    }

    // ------------------------------------------------------------------ idempotency

    public function test_repeated_runs_change_nothing_and_duplicate_nothing(): void
    {
        $this->seedFoundation();
        $afterFirst = $this->snapshot();
        $counts = [Plan::count(), DB::table('plan_entitlements')->count(), Capability::count(), DB::table('providers')->count(), DB::table('provider_capabilities')->count()];

        $this->travel(1)->hours(); // any rewrite would move updated_at
        $this->seedFoundation();
        $this->seedFoundation();
        $this->seedFoundation();

        $this->assertEquals($afterFirst, $this->snapshot(), 'plans and plan_entitlements byte-identical, timestamps included');
        $this->assertSame($counts, [Plan::count(), DB::table('plan_entitlements')->count(), Capability::count(), DB::table('providers')->count(), DB::table('provider_capabilities')->count()]);
        $this->assertSame(3, Plan::count(), 'no duplicate plans');
    }

    // ------------------------------------------------------------------ existing plan protection

    public function test_an_administrators_changes_to_an_existing_plan_survive_every_rerun(): void
    {
        Queue::fake(); // the bundle edit dispatches ReconcilePlanAccountsJob; irrelevant here
        $this->seedFoundation();
        $growth = Plan::where('slug', 'growth')->firstOrFail();

        // Commercial terms, label, engine, state — through the real admin path.
        app(PlanManagementService::class)->modify($growth, [
            'label' => 'Growth (2027 pricing)',
            'description' => 'Renegotiated.',
            'price' => 2499.00,
            'duration_days' => 90,
            'engine_type' => 'meta',
            'billing_model' => 'per_message',
            'rate_per_message' => 0.35,
            'total_allocated_messages' => 7777,
            'included_credits' => 250,
            'is_active' => false,
        ]);
        // Remove one capability (crm) through the admin path.
        app(PlanManagementService::class)->modify($growth->fresh(), [], array_values(array_diff(self::BASELINE['growth'], ['crm'])));
        // Modify capability assignments: the quota pivot and a bundle pivot.
        DB::table('plan_entitlements')->where('plan_id', $growth->id)->where('capability_id', Capability::where('slug', 'whatsapp_send')->value('id'))->update(['usage_limit' => 1234]);
        DB::table('plan_entitlements')->where('plan_id', $growth->id)->where('capability_id', Capability::where('slug', 'ai')->value('id'))->update(['usage_limit' => 42]);

        $edited = $this->snapshot();
        $this->travel(1)->hours();
        $this->seedFoundation();
        $this->seedFoundation();

        $this->assertEquals($edited, $this->snapshot(), 'nothing an administrator set was rewritten');

        $growth->refresh();
        $this->assertSame(
            ['Growth (2027 pricing)', 'Renegotiated.', 2499.0, 90, 'meta', 'per_message', 7777, 250, false],
            [$growth->label, $growth->description, (float) $growth->price, (int) $growth->duration_days, $growth->engine_type, $growth->billing_model, (int) $growth->total_allocated_messages, (int) $growth->included_credits, (bool) $growth->is_active],
        );
        $this->assertEquals(0.35, (float) $growth->rate_per_message);
        $this->assertNotContains('crm', $this->bundle('growth'), 'a removed capability is not re-attached');
        $this->assertSame(1234, (int) $this->pivot('growth', 'whatsapp_send')->usage_limit, 'quota pivot not reset to the default');
        $this->assertSame(42, (int) $this->pivot('growth', 'ai')->usage_limit, 'bundle pivot not reset to NULL');
    }

    public function test_a_plan_whose_whole_bundle_was_cleared_stays_cleared(): void
    {
        Queue::fake();
        $this->seedFoundation();

        app(PlanManagementService::class)->modify(Plan::where('slug', 'starter')->firstOrFail(), [], []);
        $this->assertSame([], $this->bundle('starter'));

        $this->seedFoundation();

        $this->assertSame([], $this->bundle('starter'), 'deleted assignments are not recreated');
    }

    // ------------------------------------------------------------------ missing plan creation

    public function test_a_missing_baseline_plan_is_recreated_and_the_others_are_left_alone(): void
    {
        Queue::fake();
        $this->seedFoundation();
        app(PlanManagementService::class)->modify(Plan::where('slug', 'business')->firstOrFail(), ['price' => 9999.00]);
        $starter = Plan::where('slug', 'starter')->firstOrFail();
        DB::table('plan_entitlements')->where('plan_id', $starter->id)->delete();
        $starter->delete();
        $others = collect($this->snapshot()['plans'])->keyBy('slug');

        $this->seedFoundation();

        $recreated = Plan::where('slug', 'starter')->firstOrFail();
        $catalog = PlanCatalog::find('starter');
        $this->assertNotSame($starter->id, $recreated->id, 'a new row');
        $this->assertSame(
            [$catalog['label'], (float) $catalog['price'], $catalog['duration_days'], $catalog['engine_type'], $catalog['billing_model'], $catalog['total_allocated_messages'], 0, true],
            [$recreated->label, (float) $recreated->price, (int) $recreated->duration_days, $recreated->engine_type, $recreated->billing_model, (int) $recreated->total_allocated_messages, (int) $recreated->included_credits, (bool) $recreated->is_active],
        );
        $this->assertSame(self::BASELINE['starter'], $this->bundle('starter'));
        $this->assertSame(500, (int) $this->pivot('starter', 'whatsapp_send')->usage_limit);
        $this->assertNull($this->pivot('starter', 'social')->usage_limit);

        $now = collect($this->snapshot()['plans'])->keyBy('slug');
        $this->assertEquals($others['growth'], $now['growth']);
        $this->assertEquals($others['business'], $now['business'], 'the edited Business price is kept');
        $this->assertEquals(9999.00, (float) $now['business']['price']);
        $this->assertSame(3, Plan::count());
    }

    // ------------------------------------------------------------------ unrelated plans

    public function test_plans_outside_the_foundation_baseline_are_untouched(): void
    {
        Queue::fake();
        $this->seedFoundation();
        app(PlanManagementService::class)->create('enterprise', [
            'label' => 'Enterprise', 'price' => 49999, 'duration_days' => 365, 'engine_type' => 'meta',
            'billing_model' => 'per_message', 'rate_per_message' => 0.2, 'total_allocated_messages' => null, 'is_active' => true,
        ], ['whatsapp_send', 'crm']);
        $before = $this->snapshot();

        $this->travel(1)->hours();
        $this->seedFoundation();

        $this->assertEquals($before, $this->snapshot());
        $this->assertSame(['crm', 'whatsapp_send'], $this->bundle('enterprise'));
    }

    // ------------------------------------------------------------------ static guard

    public function test_the_plan_path_of_the_seeder_cannot_overwrite_or_re_sync(): void
    {
        $source = file_get_contents(database_path('seeders/Phase1FoundationSeeder.php'));
        $planLoop = substr($source, strpos($source, 'foreach (self::PLANS'));

        $this->assertStringContainsString('Plan::firstOrCreate(', $planLoop);
        $this->assertStringContainsString('wasRecentlyCreated', $planLoop);
        foreach (['Plan::updateOrCreate(', 'syncWithoutDetaching(', '->sync(', '->update(', '->upsert(', '->detach('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $planLoop, "plan path must not use {$forbidden}");
        }
    }
}
