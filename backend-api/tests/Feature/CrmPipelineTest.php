<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\ApiKey;
use App\Models\Capability;
use App\Models\Contact;
use App\Models\CrmLead;
use App\Models\Lead;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Access\PlanEntitlementReconciliationService;
use App\Services\Access\PlanManagementService;
use App\Services\Crm\CaptureLeadLinker;
use App\Services\Crm\CrmLeadService;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 6 — CRM Task 6. The pipeline/Kanban read model.
 *
 * The pipeline is a VIEW of crm_leads.status — there is no second status
 * system — so this suite proves the columns derive from the canonical
 * four, that counts and cards are tenant- and filter-scoped, and that
 * the endpoint writes nothing.
 */
class CrmPipelineTest extends TestCase
{
    use RefreshDatabase;

    private const PIPELINE = '/api/crm/pipeline';

    private const LEADS = '/api/crm/leads';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    /** @return array{account: Account, user: User} */
    private function tenant(bool $withCrm = true): array
    {
        $account = Account::factory()->create();
        Subscription::factory()->create(['account_id' => $account->id]);

        if ($withCrm) {
            $this->grantCrm($account);
        }

        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('admin');

        return ['account' => $account->fresh(), 'user' => $user];
    }

    private function grantCrm(Account $account): void
    {
        $capability = Capability::where('slug', 'crm')->firstOrFail();

        $entitlement = AccountEntitlement::firstOrCreate(
            ['account_id' => $account->id, 'capability_id' => $capability->id],
            ['source' => 'manual_grant', 'granted_by_account_id' => null],
        );

        if ($entitlement->revoked_at !== null) {
            $entitlement->forceFill(['revoked_at' => null, 'revoked_reason' => null])->save();
        }
    }

    private function member(Account $account): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->givePermissionTo('manage-crm');

        return $user;
    }

    /** A lead sitting in $status, built through the service so its invariants hold. */
    private function leadAt(Account $account, string $status = CrmLead::STATUS_NEW, array $attributes = []): CrmLead
    {
        $contact = Contact::factory()->forAccount($account)->create();
        $lead = CrmLead::factory()->forContact($contact)->create();

        if ($attributes !== []) {
            $lead->forceFill($attributes)->save();
        }

        if ($status !== CrmLead::STATUS_NEW) {
            app(CrmLeadService::class)->changeStatus($lead, $status);
        }

        return $lead->fresh();
    }

    /** @return array<string, array<string, mixed>> keyed by status */
    private function columns(array $json): array
    {
        return collect($json['data']['pipeline'])->keyBy('status')->all();
    }

    // =================================================================
    // Structure
    // =================================================================

    public function test_the_pipeline_returns_the_four_canonical_columns_in_order(): void
    {
        $t = $this->tenant();

        $response = $this->actingAs($t['user'])->getJson(self::PIPELINE);

        $response->assertOk();
        $pipeline = $response->json('data.pipeline');

        $this->assertSame(
            ['new', 'contacted', 'converted', 'not_converted'],
            array_column($pipeline, 'status'),
        );
        $this->assertSame(['New', 'Contacted', 'Converted', 'Not Converted'], array_column($pipeline, 'label'));
        $this->assertSame([1, 2, 3, 4], array_column($pipeline, 'order'));
    }

    public function test_the_pipeline_columns_come_from_the_single_definition(): void
    {
        $t = $this->tenant();

        $response = $this->actingAs($t['user'])->getJson(self::PIPELINE);

        $this->assertSame(
            CrmLead::pipelineStatuses(),
            array_map(
                fn (array $c) => ['status' => $c['status'], 'label' => $c['label'], 'order' => $c['order']],
                $response->json('data.pipeline'),
            ),
        );
    }

    public function test_no_invented_stage_appears_anywhere(): void
    {
        $t = $this->tenant();

        $body = $this->actingAs($t['user'])->getJson(self::PIPELINE)->getContent();

        foreach (['qualified', 'proposal', 'negotiation', '"won"', '"lost"', 'pipeline_status', 'stage'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
    }

    public function test_the_response_is_deterministic_across_requests(): void
    {
        $t = $this->tenant();
        foreach (CrmLead::STATUSES as $status) {
            $this->leadAt($t['account'], $status);
            $this->leadAt($t['account'], $status);
        }

        $first = $this->actingAs($t['user'])->getJson(self::PIPELINE)->json();
        $second = $this->actingAs($t['user'])->getJson(self::PIPELINE)->json();

        $this->assertSame($first, $second);
    }

    public function test_no_schema_column_backs_the_pipeline(): void
    {
        foreach (['pipeline_status', 'pipeline_stage', 'kanban_status', 'custom_status', 'stage'] as $column) {
            $this->assertFalse(
                \Illuminate\Support\Facades\Schema::hasColumn('crm_leads', $column),
                "crm_leads gained a {$column} column; the pipeline must derive from `status`.",
            );
        }
    }

    // =================================================================
    // Data placement and counts
    // =================================================================

    public function test_a_lead_appears_only_in_its_own_column(): void
    {
        $t = $this->tenant();
        $leads = [];
        foreach (CrmLead::STATUSES as $status) {
            $leads[$status] = $this->leadAt($t['account'], $status);
        }

        $columns = $this->columns($this->actingAs($t['user'])->getJson(self::PIPELINE)->json());

        foreach (CrmLead::STATUSES as $status) {
            $ids = collect($columns[$status]['leads'])->pluck('id')->all();
            $this->assertSame([$leads[$status]->id], $ids, "The {$status} column holds the wrong leads.");

            foreach (CrmLead::STATUSES as $other) {
                if ($other !== $status) {
                    $this->assertNotContains($leads[$status]->id, collect($columns[$other]['leads'])->pluck('id')->all());
                }
            }
        }
    }

    public function test_column_totals_are_accurate(): void
    {
        $t = $this->tenant();
        $expected = ['new' => 3, 'contacted' => 2, 'converted' => 4, 'not_converted' => 1];

        foreach ($expected as $status => $count) {
            for ($i = 0; $i < $count; $i++) {
                $this->leadAt($t['account'], $status);
            }
        }

        $columns = $this->columns($this->actingAs($t['user'])->getJson(self::PIPELINE)->json());

        foreach ($expected as $status => $count) {
            $this->assertSame($count, $columns[$status]['total']);
        }
    }

    public function test_an_empty_column_reports_zero_rather_than_disappearing(): void
    {
        $t = $this->tenant();
        $this->leadAt($t['account']);

        $columns = $this->columns($this->actingAs($t['user'])->getJson(self::PIPELINE)->json());

        $this->assertCount(4, $columns);
        $this->assertSame(0, $columns['converted']['total']);
        $this->assertSame([], $columns['converted']['leads']);
        $this->assertSame(1, $columns['converted']['last_page']);
        $this->assertFalse($columns['converted']['has_more']);
    }

    /** The whole point of a separate count: a column can say 7 while returning 2. */
    public function test_totals_are_independent_of_the_page_size(): void
    {
        $t = $this->tenant();
        for ($i = 0; $i < 7; $i++) {
            $this->leadAt($t['account']);
        }

        $columns = $this->columns($this->actingAs($t['user'])->getJson(self::PIPELINE.'?per_page=2')->json());

        $this->assertSame(7, $columns['new']['total']);
        $this->assertCount(2, $columns['new']['leads']);
        $this->assertSame(4, $columns['new']['last_page']);
        $this->assertTrue($columns['new']['has_more']);
    }

    // =================================================================
    // Pagination
    // =================================================================

    public function test_each_column_paginates_independently_and_covers_every_lead(): void
    {
        $t = $this->tenant();
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->leadAt($t['account'])->id;
        }

        $pageOne = $this->columns($this->actingAs($t['user'])->getJson(self::PIPELINE.'?per_page=2&page=1')->json());
        $pageTwo = $this->columns($this->actingAs($t['user'])->getJson(self::PIPELINE.'?per_page=2&page=2')->json());
        $pageThree = $this->columns($this->actingAs($t['user'])->getJson(self::PIPELINE.'?per_page=2&page=3')->json());

        $seen = array_merge(
            collect($pageOne['new']['leads'])->pluck('id')->all(),
            collect($pageTwo['new']['leads'])->pluck('id')->all(),
            collect($pageThree['new']['leads'])->pluck('id')->all(),
        );

        $this->assertEqualsCanonicalizing($ids, $seen);
        $this->assertCount(5, array_unique($seen), 'Pagination must not repeat or skip a card.');
        $this->assertFalse($pageThree['new']['has_more']);
    }

    public function test_the_page_size_is_capped(): void
    {
        $t = $this->tenant();
        $this->leadAt($t['account']);

        $columns = $this->columns($this->actingAs($t['user'])->getJson(self::PIPELINE.'?per_page=100000')->json());

        $this->assertSame(100, $columns['new']['per_page']);
    }

    public function test_invalid_pagination_input_is_rejected(): void
    {
        $t = $this->tenant();

        $this->actingAs($t['user'])->getJson(self::PIPELINE.'?per_page=0')->assertStatus(422);
        $this->actingAs($t['user'])->getJson(self::PIPELINE.'?per_page=abc')->assertStatus(422);
        $this->actingAs($t['user'])->getJson(self::PIPELINE.'?page=0')->assertStatus(422);
    }

    /**
     * Query count must not grow with the number of cards — otherwise a
     * busy column becomes N+1 on contact, assignee and capture.
     */
    public function test_the_query_count_does_not_grow_with_the_number_of_leads(): void
    {
        $t = $this->tenant();
        $member = $this->member($t['account']);

        for ($i = 0; $i < 2; $i++) {
            $this->leadAt($t['account'], CrmLead::STATUS_NEW, ['assigned_user_id' => $member->id]);
        }

        /*
         * One throwaway request first. The FIRST request of a test process
         * warms caches that have nothing to do with the pipeline — Spatie's
         * role/permission load, the resolved account, the current
         * subscription — and those extra queries would otherwise be counted
         * into the baseline and make the baseline the LARGER number.
         * Measuring from a warm process is what isolates the endpoint.
         */
        $this->actingAs($t['user'])->getJson(self::PIPELINE)->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($t['user'])->getJson(self::PIPELINE)->assertOk();
        $small = count(DB::getQueryLog());
        DB::disableQueryLog();

        for ($i = 0; $i < 10; $i++) {
            $this->leadAt($t['account'], CrmLead::STATUS_NEW, ['assigned_user_id' => $member->id]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($t['user'])->getJson(self::PIPELINE)->assertOk();
        $log = DB::getQueryLog();
        $large = count($log);
        DB::disableQueryLog();

        $this->assertSame($small, $large, 'Query count grew with the lead count — the relations are not eager-loaded.');

        /*
         * And the shape itself, so a future change that keeps the count
         * flat by accident still fails: exactly one grouped COUNT for all
         * four columns, and no per-lead follow-up select.
         */
        $pipelineQueries = array_values(array_filter(
            array_map(static fn (array $q): string => $q['query'], $log),
            static fn (string $q): bool => str_contains($q, 'crm_leads')
                || str_contains($q, 'from "contacts"')
                || str_contains($q, 'from `contacts`'),
        ));

        $grouped = array_filter($pipelineQueries, static fn (string $q): bool => str_contains($q, 'COUNT(*)'));
        $contactLoads = array_filter(
            $pipelineQueries,
            static fn (string $q): bool => str_contains($q, 'from "contacts"') || str_contains($q, 'from `contacts`'),
        );

        $this->assertCount(1, $grouped, 'Totals must come from ONE grouped COUNT, not one count per column.');
        $this->assertCount(1, $contactLoads, 'Contacts must be eager-loaded once, not once per lead.');
    }

    // =================================================================
    // Filters
    // =================================================================

    public function test_a_status_filter_returns_only_that_column(): void
    {
        $t = $this->tenant();
        foreach (CrmLead::STATUSES as $status) {
            $this->leadAt($t['account'], $status);
        }

        $response = $this->actingAs($t['user'])->getJson(self::PIPELINE.'?status=converted');

        $response->assertOk();
        $pipeline = $response->json('data.pipeline');
        $this->assertCount(1, $pipeline);
        $this->assertSame('converted', $pipeline[0]['status']);
        $this->assertSame(3, $pipeline[0]['order'], 'A narrowed column keeps its pipeline order.');
        $this->assertSame(1, $pipeline[0]['total']);
    }

    /** @return array<string, array{string}> */
    public static function invalidStatusProvider(): array
    {
        return [
            'won' => ['won'],
            'lost' => ['lost'],
            'open' => ['open'],
            'closed' => ['closed'],
            'qualified' => ['qualified'],
            'nonsense' => ['zzz'],
        ];
    }

    /** @dataProvider invalidStatusProvider */
    public function test_an_invented_status_is_rejected(string $status): void
    {
        $t = $this->tenant();

        $this->actingAs($t['user'])
            ->getJson(self::PIPELINE.'?status='.$status)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_the_assignee_filter_narrows_every_column(): void
    {
        $t = $this->tenant();
        $mine = $this->member($t['account']);
        $theirs = $this->member($t['account']);

        $wanted = $this->leadAt($t['account'], CrmLead::STATUS_CONTACTED, ['assigned_user_id' => $mine->id]);
        $this->leadAt($t['account'], CrmLead::STATUS_CONTACTED, ['assigned_user_id' => $theirs->id]);
        $this->leadAt($t['account'], CrmLead::STATUS_NEW, ['assigned_user_id' => $theirs->id]);

        $columns = $this->columns($this->actingAs($t['user'])->getJson(self::PIPELINE.'?assigned_user_id='.$mine->id)->json());

        $this->assertSame([$wanted->id], collect($columns['contacted']['leads'])->pluck('id')->all());
        $this->assertSame(1, $columns['contacted']['total']);
        $this->assertSame(0, $columns['new']['total'], 'Counts must respect the filter, not just the cards.');
    }

    public function test_the_unassigned_filter_works(): void
    {
        $t = $this->tenant();
        $member = $this->member($t['account']);
        $unassigned = $this->leadAt($t['account']);
        $this->leadAt($t['account'], CrmLead::STATUS_NEW, ['assigned_user_id' => $member->id]);

        $columns = $this->columns($this->actingAs($t['user'])->getJson(self::PIPELINE.'?assigned_user_id=none')->json());

        $this->assertSame([$unassigned->id], collect($columns['new']['leads'])->pluck('id')->all());
        $this->assertSame(1, $columns['new']['total']);
    }

    public function test_the_source_filter_preserves_existing_semantics(): void
    {
        $t = $this->tenant();
        $meta = $this->leadAt($t['account'], CrmLead::STATUS_NEW, ['source' => CrmLead::SOURCE_META_AD]);
        $this->leadAt($t['account'], CrmLead::STATUS_NEW, ['source' => CrmLead::SOURCE_MANUAL]);

        $columns = $this->columns($this->actingAs($t['user'])->getJson(self::PIPELINE.'?source=meta_ad')->json());

        $this->assertSame([$meta->id], collect($columns['new']['leads'])->pluck('id')->all());
        $this->assertSame(1, $columns['new']['total']);

        $this->actingAs($t['user'])->getJson(self::PIPELINE.'?source=carrier_pigeon')->assertStatus(422);
    }

    public function test_the_search_filter_uses_the_existing_contact_search(): void
    {
        $t = $this->tenant();

        $rahulContact = Contact::factory()->forAccount($t['account'])->create(['name' => 'Rahul Sharma', 'phone_number' => '919000000001']);
        $rahul = CrmLead::factory()->forContact($rahulContact)->create();

        $otherContact = Contact::factory()->forAccount($t['account'])->create(['name' => 'Ada Lovelace', 'phone_number' => '919000000002']);
        CrmLead::factory()->forContact($otherContact)->create();

        $columns = $this->columns($this->actingAs($t['user'])->getJson(self::PIPELINE.'?search=rahul')->json());

        $this->assertSame([$rahul->id], collect($columns['new']['leads'])->pluck('id')->all());
        $this->assertSame(1, $columns['new']['total']);
    }

    public function test_search_also_matches_a_phone_number(): void
    {
        $t = $this->tenant();
        $contact = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919876543210', 'name' => 'Ada']);
        $lead = CrmLead::factory()->forContact($contact)->create();
        $this->leadAt($t['account']);

        $columns = $this->columns($this->actingAs($t['user'])->getJson(self::PIPELINE.'?search=9876543210')->json());

        $this->assertSame([$lead->id], collect($columns['new']['leads'])->pluck('id')->all());
    }

    public function test_filters_combine(): void
    {
        $t = $this->tenant();
        $member = $this->member($t['account']);

        $contact = Contact::factory()->forAccount($t['account'])->create(['name' => 'Rahul Sharma', 'phone_number' => '919000000011']);
        $wanted = CrmLead::factory()->forContact($contact)->create();
        $wanted->forceFill(['assigned_user_id' => $member->id, 'source' => CrmLead::SOURCE_META_AD])->save();
        app(CrmLeadService::class)->changeStatus($wanted, CrmLead::STATUS_CONTACTED);

        // Same person, wrong owner.
        $decoyContact = Contact::factory()->forAccount($t['account'])->create(['name' => 'Rahul Verma', 'phone_number' => '919000000012']);
        $decoy = CrmLead::factory()->forContact($decoyContact)->create();
        $decoy->forceFill(['source' => CrmLead::SOURCE_META_AD])->save();
        app(CrmLeadService::class)->changeStatus($decoy, CrmLead::STATUS_CONTACTED);

        $response = $this->actingAs($t['user'])->getJson(
            self::PIPELINE.'?status=contacted&assigned_user_id='.$member->id.'&source=meta_ad&search=rahul&per_page=5',
        );

        $response->assertOk();
        $pipeline = $response->json('data.pipeline');
        $this->assertCount(1, $pipeline);
        $this->assertSame([$wanted->id], collect($pipeline[0]['leads'])->pluck('id')->all());
        $this->assertSame(1, $pipeline[0]['total']);
        $this->assertSame(5, $pipeline[0]['per_page']);
    }

    // =================================================================
    // Ordering
    // =================================================================

    public function test_cards_are_newest_first_and_stable_when_timestamps_collide(): void
    {
        $t = $this->tenant();
        $stamp = now()->subDay();

        $ids = [];
        for ($i = 0; $i < 4; $i++) {
            $lead = $this->leadAt($t['account']);
            $lead->forceFill(['created_at' => $stamp])->save();
            $ids[] = $lead->id;
        }

        $first = collect($this->columns($this->actingAs($t['user'])->getJson(self::PIPELINE)->json())['new']['leads'])->pluck('id')->all();
        $second = collect($this->columns($this->actingAs($t['user'])->getJson(self::PIPELINE)->json())['new']['leads'])->pluck('id')->all();

        $this->assertSame($first, $second, 'Identical timestamps must still order deterministically.');
        $this->assertSame(array_reverse($ids), $first, 'Ties break on id DESC.');
    }

    public function test_cards_are_ordered_newest_first(): void
    {
        $t = $this->tenant();
        $older = $this->leadAt($t['account']);
        $older->forceFill(['created_at' => now()->subWeek()])->save();
        $newer = $this->leadAt($t['account']);

        $columns = $this->columns($this->actingAs($t['user'])->getJson(self::PIPELINE)->json());

        $this->assertSame([$newer->id, $older->id], collect($columns['new']['leads'])->pluck('id')->all());
    }

    // =================================================================
    // Serialization parity
    // =================================================================

    public function test_a_pipeline_card_is_the_canonical_crm_lead_shape(): void
    {
        $t = $this->tenant();
        $member = $this->member($t['account']);
        $capture = Lead::create([
            'account_id' => $t['account']->id,
            'provider' => 'meta',
            'provider_lead_id' => 'meta:'.uniqid(),
            'lead_phone' => '919876543210',
            'lead_name' => 'Ada',
            'lead_email' => 'ada@example.com',
        ]);
        $lead = app(CaptureLeadLinker::class)->link($capture);
        $lead->forceFill(['assigned_user_id' => $member->id])->save();

        $fromPipeline = collect($this->columns($this->actingAs($t['user'])->getJson(self::PIPELINE)->json())['new']['leads'])->firstWhere('id', $lead->id);
        $fromShow = $this->actingAs($t['user'])->getJson(self::LEADS.'/'.$lead->id)->json('data');

        $this->assertSame($fromShow, $fromPipeline, 'The pipeline must use the canonical serializer, not a second one.');
        $this->assertSame('Ada', $fromPipeline['contact']['name']);
        $this->assertSame('919876543210', $fromPipeline['contact']['phone_number']);
        $this->assertSame('ada@example.com', $fromPipeline['contact']['email']);
        $this->assertSame($member->id, $fromPipeline['assigned_user']['id']);
        $this->assertSame($capture->id, $fromPipeline['capture_lead']['id']);
    }

    public function test_a_pipeline_card_matches_the_list_endpoint_exactly(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account']);

        $fromPipeline = collect($this->columns($this->actingAs($t['user'])->getJson(self::PIPELINE)->json())['new']['leads'])->firstWhere('id', $lead->id);
        $fromIndex = collect($this->actingAs($t['user'])->getJson(self::LEADS)->json('data'))->firstWhere('id', $lead->id);

        $this->assertSame($fromIndex, $fromPipeline);
    }

    // =================================================================
    // Tenant isolation
    // =================================================================

    public function test_foreign_leads_never_appear_and_never_count(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();

        $mine = $this->leadAt($t['account']);
        $theirs = $this->leadAt($other['account']);
        $this->leadAt($other['account'], CrmLead::STATUS_CONVERTED);

        $columns = $this->columns($this->actingAs($t['user'])->getJson(self::PIPELINE)->json());

        $this->assertSame([$mine->id], collect($columns['new']['leads'])->pluck('id')->all());
        $this->assertNotContains($theirs->id, collect($columns['new']['leads'])->pluck('id')->all());
        $this->assertSame(1, $columns['new']['total']);
        $this->assertSame(0, $columns['converted']['total'], 'A foreign lead must not inflate a count.');
    }

    public function test_search_cannot_reach_another_accounts_contact(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();

        $theirContact = Contact::factory()->forAccount($other['account'])->create(['name' => 'Rahul Sharma', 'phone_number' => '919000000021']);
        CrmLead::factory()->forContact($theirContact)->create();

        $columns = $this->columns($this->actingAs($t['user'])->getJson(self::PIPELINE.'?search=rahul')->json());

        $this->assertSame([], $columns['new']['leads']);
        $this->assertSame(0, $columns['new']['total']);
    }

    public function test_a_status_filter_cannot_expose_a_foreign_lead(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $theirs = $this->leadAt($other['account'], CrmLead::STATUS_CONVERTED);

        $response = $this->actingAs($t['user'])->getJson(self::PIPELINE.'?status=converted');

        $response->assertOk();
        $this->assertSame([], $response->json('data.pipeline.0.leads'));
        $this->assertSame(0, $response->json('data.pipeline.0.total'));
        $this->assertStringNotContainsString((string) $theirs->id, json_encode($response->json('data.pipeline.0.leads')));
    }

    public function test_a_foreign_assignee_filter_leaks_nothing(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $theirMember = $this->member($other['account']);
        $this->leadAt($other['account'], CrmLead::STATUS_NEW, ['assigned_user_id' => $theirMember->id]);

        $columns = $this->columns($this->actingAs($t['user'])->getJson(self::PIPELINE.'?assigned_user_id='.$theirMember->id)->json());

        $this->assertSame([], $columns['new']['leads']);
        $this->assertSame(0, $columns['new']['total']);
    }

    public function test_spoofed_tenant_parameters_are_ignored(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $mine = $this->leadAt($t['account']);
        $theirs = $this->leadAt($other['account']);

        $columns = $this->columns($this->actingAs($t['user'])->getJson(
            self::PIPELINE.'?account_id='.$other['account']->id.'&agent_id='.$other['account']->id.'&tenant_id='.$other['account']->id,
        )->json());

        $ids = collect($columns['new']['leads'])->pluck('id')->all();
        $this->assertSame([$mine->id], $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    // =================================================================
    // Authorization
    // =================================================================

    public function test_an_unauthenticated_caller_is_refused(): void
    {
        $this->getJson(self::PIPELINE)->assertStatus(401);
    }

    public function test_the_crm_capability_is_required(): void
    {
        $t = $this->tenant(withCrm: false);
        $this->leadAt($t['account']);

        $response = $this->actingAs($t['user'])->getJson(self::PIPELINE);

        $response->assertStatus(403);
        $response->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
        // A denial must not leak counts.
        $this->assertNull($response->json('data'));
    }

    public function test_the_lead_crm_module_is_required(): void
    {
        $t = $this->tenant();
        $t['account']->forceFill(['allowed_modules' => array_values(array_diff(Account::MODULES, ['lead_crm']))])->save();

        $this->actingAs($t['user'])
            ->getJson(self::PIPELINE)
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'MODULE_DISABLED');
    }

    /** Role built before the first actingAs() — see the Round 2 guard-configuration note. */
    public function test_manage_crm_is_required(): void
    {
        $t = $this->tenant();
        $role = Role::create(['name' => 'pipeline_denied']);
        $role->givePermissionTo('manage-social-leads');

        $caller = User::factory()->create(['account_id' => $t['account']->id, 'is_active' => true]);
        $caller->assignRole($role);

        $this->actingAs($caller)->getJson(self::PIPELINE)->assertStatus(403);
    }

    // =================================================================
    // Plan / capability
    // =================================================================

    public function test_revoking_crm_denies_the_pipeline_but_leaves_leads_untouched(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account'], CrmLead::STATUS_CONTACTED);

        $this->actingAs($t['user'])->getJson(self::PIPELINE)->assertOk();

        AccountEntitlement::query()
            ->where('account_id', $t['account']->id)
            ->get()
            ->each(fn (AccountEntitlement $e) => $e->forceFill([
                'revoked_at' => now(),
                'revoked_reason' => AccountEntitlement::REVOKED_PLAN_DOWNGRADE,
            ])->save());

        $this->actingAs($t['user'])->getJson(self::PIPELINE)->assertStatus(403);
        $this->assertSame('contacted', $lead->fresh()->status);

        $this->grantCrm($t['account']->fresh());
        $this->actingAs($t['user'])->getJson(self::PIPELINE)->assertOk();
        $this->assertSame('contacted', $lead->fresh()->status);
    }

    public function test_reconciliation_does_not_modify_lead_status(): void
    {
        $t = $this->tenant();
        $converted = $this->leadAt($t['account'], CrmLead::STATUS_CONVERTED);
        $contacted = $this->leadAt($t['account'], CrmLead::STATUS_CONTACTED);

        app(PlanEntitlementReconciliationService::class)->reconcile($t['account']->fresh());

        $this->assertSame('converted', $converted->fresh()->status);
        $this->assertSame('contacted', $contacted->fresh()->status);
    }

    public function test_a_plan_can_carry_and_drop_crm(): void
    {
        $service = app(PlanManagementService::class);

        $plan = $service->create('pipeline-plan', ['label' => 'P', 'price' => 1, 'duration_days' => 30], ['crm']);
        $this->assertTrue($plan->capabilities->pluck('slug')->contains('crm'));

        $removed = $service->modify($plan->fresh(), [], []);
        $this->assertContains('crm', $removed['removed']);
    }

    // =================================================================
    // Read-only: the pipeline mutates nothing
    // =================================================================

    public function test_reading_the_pipeline_mutates_nothing(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account'], CrmLead::STATUS_CONTACTED);
        $before = $lead->fresh()->toArray();
        $contactBefore = $lead->contact->fresh()->toArray();

        $fired = 0;
        CrmLead::updated(function () use (&$fired): void {
            $fired++;
        });

        $this->actingAs($t['user'])->getJson(self::PIPELINE)->assertOk();
        $this->actingAs($t['user'])->getJson(self::PIPELINE.'?status=contacted')->assertOk();

        $this->assertSame(0, $fired);
        $this->assertSame($before, $lead->fresh()->toArray());
        $this->assertSame($contactBefore, $lead->contact->fresh()->toArray());
    }

    public function test_the_pipeline_offers_no_write_verbs(): void
    {
        $t = $this->tenant();

        foreach (['postJson', 'patchJson', 'putJson', 'deleteJson'] as $verb) {
            $this->actingAs($t['user'])->{$verb}(self::PIPELINE, ['status' => 'converted'])->assertStatus(405);
        }
    }

    /** Status still moves only through the Task 5 lifecycle endpoint. */
    public function test_status_mutation_remains_the_lifecycle_endpoints_job(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account']);

        $captured = null;
        CrmLead::updated(function (CrmLead $model) use (&$captured): void {
            $captured = $model->getChanges();
        });

        $this->actingAs($t['user'])
            ->patchJson(self::LEADS.'/'.$lead->id.'/status', ['status' => 'converted'])
            ->assertOk();

        $this->assertArrayHasKey('status', $captured);
        $this->assertNotNull($lead->fresh()->converted_at, 'Task 5 outcome bookkeeping must still run.');

        // And the card has moved column.
        $columns = $this->columns($this->actingAs($t['user'])->getJson(self::PIPELINE)->json());
        $this->assertSame([$lead->id], collect($columns['converted']['leads'])->pluck('id')->all());
        $this->assertSame([], $columns['new']['leads']);
    }

    // =================================================================
    // The Developer API gains nothing
    // =================================================================

    public function test_the_developer_api_has_no_pipeline_endpoint(): void
    {
        $t = $this->tenant();

        $plain = 'sk_test_'.bin2hex(random_bytes(12));
        ApiKey::create([
            'account_id' => $t['account']->id,
            'name' => 'Key',
            'key_prefix' => substr($plain, 0, 10),
            'key_hash' => ApiKey::hashKey($plain),
        ]);

        $this->withHeader('X-API-KEY', $plain)->getJson('/api/v1/crm/pipeline')->assertStatus(404);
    }
}
