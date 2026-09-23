<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\ApiKey;
use App\Models\Capability;
use App\Models\Contact;
use App\Models\CrmLead;
use App\Models\CrmLeadTag;
use App\Models\CrmTag;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Access\PlanEntitlementReconciliationService;
use App\Services\Access\PlanManagementService;
use App\Services\Crm\CrmBulkLeadSelection;
use App\Services\Crm\CrmLeadService;
use App\Services\Crm\CrmTagService;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 6 — CRM Task 9. Bulk lead operations: assign/unassign, status,
 * tag attach/detach over up to CrmBulkLeadSelection::MAX_LEADS leads —
 * tenant-isolated, all or nothing, transactional, audited per lead,
 * no-op aware, and built on the same domain steps as the individual
 * endpoints.
 */
class CrmLeadBulkOperationsTest extends TestCase
{
    use RefreshDatabase;

    private const ASSIGN = '/api/crm/leads/bulk/assignee';

    private const STATUS = '/api/crm/leads/bulk/status';

    private const ATTACH = '/api/crm/leads/bulk/tags/attach';

    private const DETACH = '/api/crm/leads/bulk/tags/detach';

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
    private function tenant(bool $withCrm = true, array $accountAttributes = []): array
    {
        $account = Account::factory()->create($accountAttributes);
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

    private function member(Account $account, bool $active = true, bool $crm = true): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => $active]);
        if ($crm) {
            $user->givePermissionTo('manage-crm');
        }

        return $user;
    }

    /** @return list<CrmLead> */
    private function leads(Account $account, int $count, string $status = CrmLead::STATUS_NEW, array $attributes = []): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $contact = Contact::factory()->forAccount($account)->create();
            $lead = CrmLead::factory()->forContact($contact)->create();
            if ($attributes !== []) {
                $lead->forceFill($attributes)->save();
            }
            if ($status !== CrmLead::STATUS_NEW) {
                app(CrmLeadService::class)->changeStatus($lead, $status);
            }
            $out[] = $lead->fresh();
        }

        return $out;
    }

    /** @param list<CrmLead> $leads */
    private function ids(array $leads): array
    {
        return array_map(fn (CrmLead $l) => $l->id, $leads);
    }

    private function tag(Account $account, string $name): CrmTag
    {
        return app(CrmTagService::class)->create($account, $name);
    }

    /** Every column of every lead, for "nothing changed" assertions. */
    private function snapshot(): array
    {
        return [
            'leads' => CrmLead::orderBy('id')->get()->map->getAttributes()->all(),
            'pivots' => DB::table('crm_lead_tags')->orderBy('crm_lead_id')->orderBy('crm_tag_id')->get()->map(fn ($r) => (array) $r)->all(),
        ];
    }

    private function assertSummary($response, string $operation, int $requested, int $changed): void
    {
        $response->assertOk()->assertExactJson([
            'message' => $response->json('message'),
            'data' => ['operation' => $operation, 'requested' => $requested, 'changed' => $changed, 'unchanged' => $requested - $changed],
        ]);
    }

    // =================================================================
    // Validation
    // =================================================================

    /** @return array<string, array{0: string}> */
    public static function endpoints(): array
    {
        return ['assign' => [self::ASSIGN], 'status' => [self::STATUS], 'attach' => [self::ATTACH], 'detach' => [self::DETACH]];
    }

    private function validBody(string $endpoint, array $leadIds, ?int $tagId = null, ?int $userId = null): array
    {
        return match ($endpoint) {
            self::ASSIGN => ['lead_ids' => $leadIds, 'assigned_user_id' => $userId],
            self::STATUS => ['lead_ids' => $leadIds, 'status' => 'contacted'],
            default => ['lead_ids' => $leadIds, 'tag_id' => $tagId],
        };
    }

    /** @dataProvider endpoints */
    public function test_lead_ids_are_required_bounded_integer_and_distinct(string $endpoint): void
    {
        $t = $this->tenant();
        $tag = $this->tag($t['account'], 'Hot');
        $lead = $this->leads($t['account'], 1)[0];
        $before = $this->snapshot();

        $cases = [
            'missing' => null,
            'empty' => [],
            'not an array' => $lead->id,
            'non-integer' => ['abc'],
            'zero' => [0],
            'negative' => [-4],
            'duplicate' => [$lead->id, $lead->id],
            'too many' => range(1, CrmBulkLeadSelection::MAX_LEADS + 1),
        ];

        foreach ($cases as $label => $ids) {
            $body = $this->validBody($endpoint, [], $tag->id);
            if ($ids === null) {
                unset($body['lead_ids']);
            } else {
                $body['lead_ids'] = $ids;
            }
            $response = $this->actingAs($t['user'])->postJson($endpoint, $body);
            $response->assertStatus(422);
            $this->assertTrue(
                collect(array_keys($response->json('errors')))->contains(fn ($k) => str_starts_with($k, 'lead_ids')),
                "{$label}: expected a lead_ids error.",
            );
        }

        $this->assertSame($before, $this->snapshot());
    }

    public function test_the_maximum_batch_is_accepted(): void
    {
        $t = $this->tenant();
        $leads = $this->leads($t['account'], CrmBulkLeadSelection::MAX_LEADS);

        $this->assertSummary(
            $this->actingAs($t['user'])->postJson(self::STATUS, ['lead_ids' => $this->ids($leads), 'status' => 'contacted']),
            'status', CrmBulkLeadSelection::MAX_LEADS, CrmBulkLeadSelection::MAX_LEADS,
        );
    }

    public function test_an_invented_status_is_rejected(): void
    {
        $t = $this->tenant();
        $leads = $this->leads($t['account'], 2);

        foreach (['qualified', 'won', '', null] as $status) {
            $this->actingAs($t['user'])->postJson(self::STATUS, ['lead_ids' => $this->ids($leads), 'status' => $status])
                ->assertStatus(422)->assertJsonValidationErrors('status');
        }
        $this->assertSame(['new', 'new'], CrmLead::orderBy('id')->pluck('status')->all());
    }

    public function test_assigned_user_id_must_be_present(): void
    {
        $t = $this->tenant();
        $leads = $this->leads($t['account'], 1);

        $this->actingAs($t['user'])->postJson(self::ASSIGN, ['lead_ids' => $this->ids($leads)])
            ->assertStatus(422)->assertJsonValidationErrors('assigned_user_id');
    }

    public function test_tag_id_is_required(): void
    {
        $t = $this->tenant();
        $leads = $this->leads($t['account'], 1);

        foreach ([self::ATTACH, self::DETACH] as $endpoint) {
            $this->actingAs($t['user'])->postJson($endpoint, ['lead_ids' => $this->ids($leads)])
                ->assertStatus(422)->assertJsonValidationErrors('tag_id');
        }
    }

    // =================================================================
    // Tenant isolation
    // =================================================================

    /** @dataProvider endpoints */
    public function test_a_mixed_tenant_batch_is_rejected_whole_with_zero_mutations(string $endpoint): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $mine = $this->leads($t['account'], 99);
        $foreign = $this->leads($other['account'], 1)[0];
        $tag = $this->tag($t['account'], 'Hot');
        $assignee = $this->member($t['account']);
        $before = $this->snapshot();

        $mixed = [...$this->ids($mine), $foreign->id];
        $response = $this->actingAs($t['user'])->postJson($endpoint, $this->validBody($endpoint, $mixed, $tag->id, $assignee->id));

        $response->assertStatus(422)->assertJsonValidationErrors(['lead_ids' => CrmBulkLeadSelection::NOT_AVAILABLE]);
        $this->assertSame($before, $this->snapshot(), 'Not one valid lead may be mutated when the batch is rejected.');
    }

    /** @dataProvider endpoints */
    public function test_a_foreign_id_is_indistinguishable_from_a_missing_one(string $endpoint): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $mine = $this->leads($t['account'], 2);
        $foreign = $this->leads($other['account'], 1)[0];
        $tag = $this->tag($t['account'], 'Hot');

        $withForeign = $this->actingAs($t['user'])->postJson($endpoint, $this->validBody($endpoint, [...$this->ids($mine), $foreign->id], $tag->id));
        $withMissing = $this->actingAs($t['user'])->postJson($endpoint, $this->validBody($endpoint, [...$this->ids($mine), $foreign->id + 1000], $tag->id));

        $this->assertSame($withMissing->status(), $withForeign->status());
        $this->assertSame($withMissing->json('errors'), $withForeign->json('errors'));
        $this->assertStringNotContainsString((string) $foreign->id, $withForeign->getContent());
    }

    public function test_a_foreign_or_missing_tag_rejects_the_batch_identically(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $leads = $this->leads($t['account'], 3);
        $foreignTag = $this->tag($other['account'], 'Theirs');
        $before = $this->snapshot();

        foreach ([self::ATTACH, self::DETACH] as $endpoint) {
            $f = $this->actingAs($t['user'])->postJson($endpoint, ['lead_ids' => $this->ids($leads), 'tag_id' => $foreignTag->id]);
            $m = $this->actingAs($t['user'])->postJson($endpoint, ['lead_ids' => $this->ids($leads), 'tag_id' => $foreignTag->id + 1000]);
            $f->assertStatus(422)->assertJsonValidationErrors(['tag_id' => 'The selected tag is not available.']);
            $this->assertSame($m->json('errors'), $f->json('errors'));
            $this->assertStringNotContainsString('Theirs', $f->getContent());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_request_supplied_tenant_identifiers_are_ignored(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $theirs = $this->leads($other['account'], 2);
        $mine = $this->leads($t['account'], 2);

        // Pointing at another account's leads while claiming that account is still refused.
        $this->actingAs($t['user'])->postJson(self::STATUS.'?account_id='.$other['account']->id, [
            'lead_ids' => $this->ids($theirs), 'status' => 'converted',
            'account_id' => $other['account']->id, 'agent_id' => $other['account']->id, 'tenant_id' => $other['account']->id,
        ])->assertStatus(422);
        $this->assertSame(['new', 'new'], CrmLead::whereIn('id', $this->ids($theirs))->pluck('status')->all());

        // And the caller's own leads still work with the spoofed fields present.
        $this->actingAs($t['user'])->postJson(self::STATUS.'?account_id='.$other['account']->id, [
            'lead_ids' => $this->ids($mine), 'status' => 'converted', 'account_id' => $other['account']->id,
        ])->assertOk()->assertJsonPath('data.changed', 2);
    }

    public function test_a_super_admin_must_select_an_account_and_then_acts_only_within_it(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $mine = $this->leads($t['account'], 2);
        $theirs = $this->leads($other['account'], 1);
        $admin = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $admin->assignRole('super_admin');
        if (! $admin->can('manage-crm')) {
            $admin->givePermissionTo('manage-crm');
        }

        $this->actingAs($admin)->postJson(self::STATUS, ['lead_ids' => $this->ids($mine), 'status' => 'contacted'])->assertStatus(422);

        $this->actingAs($admin)->postJson(self::STATUS.'?account_id='.$t['account']->id, ['lead_ids' => [...$this->ids($mine), $theirs[0]->id], 'status' => 'contacted'])
            ->assertStatus(422);

        $this->actingAs($admin)->postJson(self::STATUS.'?account_id='.$t['account']->id, ['lead_ids' => $this->ids($mine), 'status' => 'contacted'])
            ->assertOk()->assertJsonPath('data.changed', 2);
        $this->assertSame('new', $theirs[0]->fresh()->status);
    }

    public function test_an_agent_reaches_only_its_own_sub_clients(): void
    {
        $agent = $this->tenant(accountAttributes: ['account_type' => 'agent']);
        $sub = $this->tenant(accountAttributes: ['agent_id' => $agent['account']->id]);
        $stranger = $this->tenant();
        $subLeads = $this->leads($sub['account'], 2);
        $strangerLeads = $this->leads($stranger['account'], 1);

        $this->actingAs($agent['user'])->postJson(self::STATUS.'?account_id='.$sub['account']->id, ['lead_ids' => $this->ids($subLeads), 'status' => 'contacted'])
            ->assertOk()->assertJsonPath('data.changed', 2);

        $this->actingAs($agent['user'])->postJson(self::STATUS.'?account_id='.$stranger['account']->id, ['lead_ids' => $this->ids($strangerLeads), 'status' => 'contacted'])
            ->assertStatus(404);
        $this->assertSame('new', $strangerLeads[0]->fresh()->status);
    }

    // =================================================================
    // Assignment
    // =================================================================

    public function test_bulk_assign_reassign_and_unassign(): void
    {
        $t = $this->tenant();
        $asha = $this->member($t['account']);
        $bala = $this->member($t['account']);
        $leads = $this->leads($t['account'], 3);
        $ids = $this->ids($leads);

        $this->assertSummary($this->actingAs($t['user'])->postJson(self::ASSIGN, ['lead_ids' => $ids, 'assigned_user_id' => $asha->id]), 'assign', 3, 3);
        $this->assertSame([$asha->id, $asha->id, $asha->id], CrmLead::whereIn('id', $ids)->orderBy('id')->pluck('assigned_user_id')->all());

        $this->assertSummary($this->actingAs($t['user'])->postJson(self::ASSIGN, ['lead_ids' => $ids, 'assigned_user_id' => $bala->id]), 'assign', 3, 3);
        $this->assertSame([$bala->id, $bala->id, $bala->id], CrmLead::whereIn('id', $ids)->orderBy('id')->pluck('assigned_user_id')->all());

        $this->assertSummary($this->actingAs($t['user'])->postJson(self::ASSIGN, ['lead_ids' => $ids, 'assigned_user_id' => null]), 'unassign', 3, 3);
        $this->assertSame([null, null, null], CrmLead::whereIn('id', $ids)->orderBy('id')->pluck('assigned_user_id')->all());
    }

    public function test_assignment_changes_nothing_but_the_owner(): void
    {
        $t = $this->tenant();
        $asha = $this->member($t['account']);
        $lead = $this->leads($t['account'], 1, CrmLead::STATUS_CONVERTED)[0];
        $tag = $this->tag($t['account'], 'Hot');
        app(CrmTagService::class)->attach($lead, $tag);
        $before = $lead->fresh()->getAttributes();

        $this->actingAs($t['user'])->postJson(self::ASSIGN, ['lead_ids' => [$lead->id], 'assigned_user_id' => $asha->id])->assertOk();

        $after = $lead->fresh()->getAttributes();
        $this->assertSame($asha->id, (int) $after['assigned_user_id']);
        foreach (['status', 'contact_id', 'account_id', 'source', 'converted_at', 'capture_lead_id'] as $column) {
            $this->assertSame($before[$column], $after[$column], "{$column} changed.");
        }
        $this->assertSame(1, CrmLeadTag::where('crm_lead_id', $lead->id)->count());
    }

    public function test_no_op_assignment_writes_and_audits_nothing(): void
    {
        $t = $this->tenant();
        $asha = $this->member($t['account']);
        $owned = $this->leads($t['account'], 2, CrmLead::STATUS_NEW, ['assigned_user_id' => $asha->id]);
        $free = $this->leads($t['account'], 1);

        $updated = [];
        CrmLead::updated(function (CrmLead $lead) use (&$updated): void {
            $updated[] = $lead->id;
        });

        $this->assertSummary(
            $this->actingAs($t['user'])->postJson(self::ASSIGN, ['lead_ids' => [...$this->ids($owned), ...$this->ids($free)], 'assigned_user_id' => $asha->id]),
            'assign', 3, 1,
        );
        $this->assertSame([$free[0]->id], $updated);

        $updated = [];
        $this->assertSummary($this->actingAs($t['user'])->postJson(self::ASSIGN, ['lead_ids' => $this->ids($free), 'assigned_user_id' => $asha->id]), 'assign', 1, 0);
        $this->assertSame([], $updated);

        // Unassigning already-unassigned leads is also a no-op.
        $other = $this->leads($t['account'], 2);
        $this->assertSummary($this->actingAs($t['user'])->postJson(self::ASSIGN, ['lead_ids' => $this->ids($other), 'assigned_user_id' => null]), 'unassign', 2, 0);
        $this->assertSame([], $updated);
    }

    public function test_ineligible_assignees_reject_the_batch_with_one_generic_message(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $leads = $this->leads($t['account'], 3);
        $before = $this->snapshot();

        $candidates = [
            'inactive' => $this->member($t['account'], active: false),
            'no manage-crm' => $this->member($t['account'], crm: false),
            'other tenant' => $this->member($other['account']),
        ];

        foreach ($candidates as $label => $user) {
            $this->actingAs($t['user'])->postJson(self::ASSIGN, ['lead_ids' => $this->ids($leads), 'assigned_user_id' => $user->id])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['assigned_user_id' => 'The selected assignee is not available.']);
        }
        $this->actingAs($t['user'])->postJson(self::ASSIGN, ['lead_ids' => $this->ids($leads), 'assigned_user_id' => 999999])
            ->assertStatus(422)->assertJsonValidationErrors(['assigned_user_id' => 'The selected assignee is not available.']);

        $this->assertSame($before, $this->snapshot());
    }

    public function test_eligibility_is_checked_once_not_per_lead(): void
    {
        $t = $this->tenant();
        $asha = $this->member($t['account']);
        $small = $this->ids($this->leads($t['account'], 2));
        $large = $this->ids($this->leads($t['account'], 20));
        $this->actingAs($t['user'])->postJson(self::ASSIGN, ['lead_ids' => $small, 'assigned_user_id' => null])->assertOk();

        $count = function (array $ids) use ($t, $asha): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($t['user'])->postJson(self::ASSIGN, ['lead_ids' => $ids, 'assigned_user_id' => $asha->id])->assertOk();
            $log = array_column(DB::getQueryLog(), 'query');
            DB::disableQueryLog();

            return [
                'users' => count(array_filter($log, fn ($q) => preg_match('/from [`"]users[`"]/', $q) && str_contains($q, 'account_id'))),
                'lead_selects' => count(array_filter($log, fn ($q) => preg_match('/^select .* from [`"]crm_leads[`"]/', $q))),
            ];
        };

        $a = $count($small);
        $b = $count($large);
        $this->assertSame($a, $b, 'Eligibility and lead lookups must not grow with the batch size.');
        $this->assertSame(1, $b['users'], 'The assignee is looked up exactly once.');
        $this->assertSame(1, $b['lead_selects'], 'The batch is selected in one query.');
    }

    // =================================================================
    // Status
    // =================================================================

    public function test_bulk_status_uses_the_lifecycle_bookkeeping(): void
    {
        $t = $this->tenant();
        $leads = $this->leads($t['account'], 3);
        $ids = $this->ids($leads);

        $this->assertSummary($this->actingAs($t['user'])->postJson(self::STATUS, ['lead_ids' => $ids, 'status' => 'converted']), 'status', 3, 3);
        foreach (CrmLead::whereIn('id', $ids)->get() as $lead) {
            $this->assertSame('converted', $lead->status);
            $this->assertNotNull($lead->converted_at);
        }

        $this->actingAs($t['user'])->postJson(self::STATUS, ['lead_ids' => $ids, 'status' => 'not_converted', 'not_converted_reason' => 'Budget'])->assertOk();
        foreach (CrmLead::whereIn('id', $ids)->get() as $lead) {
            $this->assertSame('not_converted', $lead->status);
            $this->assertNull($lead->converted_at, 'The stale outcome must be cleared, as individually.');
            $this->assertNotNull($lead->not_converted_at);
            $this->assertSame('Budget', $lead->not_converted_reason);
        }

        $this->actingAs($t['user'])->postJson(self::STATUS, ['lead_ids' => $ids, 'status' => 'contacted'])->assertOk();
        foreach (CrmLead::whereIn('id', $ids)->get() as $lead) {
            $this->assertNull($lead->not_converted_at);
            $this->assertNull($lead->not_converted_reason);
        }
    }

    public function test_bulk_and_individual_status_produce_identical_rows(): void
    {
        $t = $this->tenant();
        [$viaBulk, $viaSingle] = $this->leads($t['account'], 2);
        $this->freezeTime();

        $this->actingAs($t['user'])->postJson(self::STATUS, ['lead_ids' => [$viaBulk->id], 'status' => 'not_converted', 'not_converted_reason' => 'x'])->assertOk();
        $this->actingAs($t['user'])->patchJson('/api/crm/leads/'.$viaSingle->id.'/status', ['status' => 'not_converted', 'not_converted_reason' => 'x'])->assertOk();

        $pick = fn (CrmLead $l) => array_intersect_key($l->fresh()->getAttributes(), array_flip(['status', 'converted_at', 'not_converted_at', 'not_converted_reason', 'assigned_user_id']));
        $this->assertSame($pick($viaSingle), $pick($viaBulk));
    }

    public function test_no_op_status_writes_and_audits_nothing(): void
    {
        $t = $this->tenant();
        $contacted = $this->leads($t['account'], 2, CrmLead::STATUS_CONTACTED);
        $new = $this->leads($t['account'], 1);
        $before = CrmLead::whereIn('id', $this->ids($contacted))->orderBy('id')->get()->map->getAttributes()->all();

        $updated = [];
        CrmLead::updated(function (CrmLead $lead) use (&$updated): void {
            $updated[] = $lead->id;
        });

        $this->assertSummary(
            $this->actingAs($t['user'])->postJson(self::STATUS, ['lead_ids' => [...$this->ids($contacted), ...$this->ids($new)], 'status' => 'contacted']),
            'status', 3, 1,
        );
        $this->assertSame([$new[0]->id], $updated);
        $this->assertSame($before, CrmLead::whereIn('id', $this->ids($contacted))->orderBy('id')->get()->map->getAttributes()->all());
    }

    public function test_a_disallowed_transition_rejects_the_whole_batch(): void
    {
        $t = $this->tenant();
        $leads = $this->leads($t['account'], 2);
        $blocked = $this->leads($t['account'], 1, CrmLead::STATUS_CONVERTED)[0];
        $before = $this->snapshot();

        // Every move is permitted today (STATUS_TRANSITIONS); this proves
        // the bulk path consults the transition authority, all or nothing,
        // by making one lead's current status unknown to the matrix.
        DB::table('crm_leads')->where('id', $blocked->id)->update(['status' => 'legacy_retired']);
        $before = $this->snapshot();

        $this->actingAs($t['user'])->postJson(self::STATUS, ['lead_ids' => [...$this->ids($leads), $blocked->id], 'status' => 'contacted'])
            ->assertStatus(422)->assertJsonValidationErrors('status');

        $this->assertSame($before, $this->snapshot());
    }

    // =================================================================
    // Tags
    // =================================================================

    public function test_bulk_attach_is_idempotent_and_never_duplicates(): void
    {
        $t = $this->tenant();
        $hot = $this->tag($t['account'], 'Hot');
        $vip = $this->tag($t['account'], 'VIP');
        $leads = $this->leads($t['account'], 3);
        app(CrmTagService::class)->attach($leads[0], $hot);
        app(CrmTagService::class)->attach($leads[1], $vip);
        $leadBefore = CrmLead::orderBy('id')->get()->map->getAttributes()->all();

        $this->assertSummary($this->actingAs($t['user'])->postJson(self::ATTACH, ['lead_ids' => $this->ids($leads), 'tag_id' => $hot->id]), 'tag_attach', 3, 2);
        $this->assertSummary($this->actingAs($t['user'])->postJson(self::ATTACH, ['lead_ids' => $this->ids($leads), 'tag_id' => $hot->id]), 'tag_attach', 3, 0);

        $this->assertSame(3, CrmLeadTag::where('crm_tag_id', $hot->id)->count());
        $this->assertSame(1, CrmLeadTag::where('crm_tag_id', $vip->id)->count(), 'Other tags are untouched.');
        $pairs = DB::table('crm_lead_tags')->get()->map(fn ($r) => $r->crm_lead_id.':'.$r->crm_tag_id)->all();
        $this->assertSame(count($pairs), count(array_unique($pairs)));
        $this->assertSame($leadBefore, CrmLead::orderBy('id')->get()->map->getAttributes()->all(), 'Tagging never writes a lead row.');
    }

    public function test_bulk_detach_is_idempotent_and_touches_nothing_else(): void
    {
        $t = $this->tenant();
        $hot = $this->tag($t['account'], 'Hot');
        $vip = $this->tag($t['account'], 'VIP');
        $asha = $this->member($t['account']);
        $leads = $this->leads($t['account'], 3, CrmLead::STATUS_CONTACTED, ['assigned_user_id' => $asha->id]);
        foreach ($leads as $lead) {
            app(CrmTagService::class)->attach($lead, $vip);
        }
        app(CrmTagService::class)->attach($leads[0], $hot);
        app(CrmTagService::class)->attach($leads[1], $hot);
        $leadBefore = CrmLead::orderBy('id')->get()->map->getAttributes()->all();
        $contactsBefore = Contact::orderBy('id')->get()->map->getAttributes()->all();

        $this->assertSummary($this->actingAs($t['user'])->postJson(self::DETACH, ['lead_ids' => $this->ids($leads), 'tag_id' => $hot->id]), 'tag_detach', 3, 2);
        $this->assertSummary($this->actingAs($t['user'])->postJson(self::DETACH, ['lead_ids' => $this->ids($leads), 'tag_id' => $hot->id]), 'tag_detach', 3, 0);

        $this->assertSame(0, CrmLeadTag::where('crm_tag_id', $hot->id)->count());
        $this->assertSame(3, CrmLeadTag::where('crm_tag_id', $vip->id)->count());
        $this->assertNotNull(CrmTag::find($hot->id), 'The tag itself is never deleted.');
        $this->assertSame($leadBefore, CrmLead::orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame($contactsBefore, Contact::orderBy('id')->get()->map->getAttributes()->all());
    }

    public function test_bulk_attach_does_not_re_query_ownership_per_row(): void
    {
        $t = $this->tenant();
        $hot = $this->tag($t['account'], 'Hot');
        $small = $this->ids($this->leads($t['account'], 2));
        $large = $this->ids($this->leads($t['account'], 20));
        $this->actingAs($t['user'])->postJson(self::DETACH, ['lead_ids' => $small, 'tag_id' => $hot->id])->assertOk();

        $count = function (array $ids) use ($t, $hot): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($t['user'])->postJson(self::ATTACH, ['lead_ids' => $ids, 'tag_id' => $hot->id])->assertOk();
            $log = array_column(DB::getQueryLog(), 'query');
            DB::disableQueryLog();

            // SELECTs only — inserts necessarily scale with the rows written.
            return count(array_filter($log, fn ($q) => str_starts_with(strtolower($q), 'select') && (str_contains($q, 'crm_leads') || str_contains($q, 'crm_tags') || str_contains($q, 'crm_lead_tags'))));
        };

        $this->assertSame($count($small), $count($large));
    }

    // =================================================================
    // Transactions
    // =================================================================

    public function test_a_failure_mid_status_batch_rolls_everything_back(): void
    {
        $t = $this->tenant();
        $leads = $this->leads($t['account'], 5);
        $before = $this->snapshot();

        $n = 0;
        CrmLead::updated(function () use (&$n): void {
            if (++$n === 3) {
                throw new \RuntimeException('Simulated failure on the third lead.');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($t['user'])->postJson(self::STATUS, ['lead_ids' => $this->ids($leads), 'status' => 'converted']);
            $this->fail('The simulated failure did not surface.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated failure on the third lead.', $e->getMessage());
        }

        $this->assertSame($before, $this->snapshot(), 'Two leads were saved before the failure; both must be rolled back.');
    }

    public function test_a_failure_mid_assign_batch_rolls_everything_back(): void
    {
        $t = $this->tenant();
        $asha = $this->member($t['account']);
        $leads = $this->leads($t['account'], 4);
        $before = $this->snapshot();

        $n = 0;
        CrmLead::updated(function () use (&$n): void {
            if (++$n === 4) {
                throw new \RuntimeException('boom');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($t['user'])->postJson(self::ASSIGN, ['lead_ids' => $this->ids($leads), 'assigned_user_id' => $asha->id]);
            $this->fail('No failure.');
        } catch (\RuntimeException) {
        }

        $this->assertSame($before, $this->snapshot());
    }

    public function test_a_failure_mid_tag_batch_rolls_everything_back(): void
    {
        $t = $this->tenant();
        $hot = $this->tag($t['account'], 'Hot');
        $leads = $this->leads($t['account'], 4);
        $before = $this->snapshot();

        $n = 0;
        CrmLeadTag::created(function () use (&$n): void {
            if (++$n === 2) {
                throw new \RuntimeException('boom');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($t['user'])->postJson(self::ATTACH, ['lead_ids' => $this->ids($leads), 'tag_id' => $hot->id]);
            $this->fail('No failure.');
        } catch (\RuntimeException) {
        }

        $this->assertSame($before, $this->snapshot());
    }

    // =================================================================
    // Audit (LogsActivity)
    // =================================================================

    /**
     * The per-lead model events LogsActivity hooks, with the acting user
     * and each lead's own old/new values. The actual activity_logs rows are
     * asserted by the next test.
     */
    public function test_every_changed_lead_fires_its_own_audited_event_and_no_ops_fire_none(): void
    {
        $t = $this->tenant();
        $asha = $this->member($t['account']);
        $already = $this->leads($t['account'], 1, CrmLead::STATUS_NEW, ['assigned_user_id' => $asha->id])[0];
        $leads = $this->leads($t['account'], 2);
        $hot = $this->tag($t['account'], 'Hot');

        $events = [];
        CrmLead::updated(function (CrmLead $lead) use (&$events): void {
            $changes = array_diff_key($lead->getChanges(), ['updated_at' => true]);
            $events[] = ['lead.updated', Auth::id(), $lead->id, (int) $lead->account_id, array_intersect_key($lead->getOriginal(), $changes), $changes];
        });
        CrmLeadTag::created(function (CrmLeadTag $row) use (&$events): void {
            $events[] = ['tag.created', Auth::id(), $row->crm_lead_id, $row->account_id];
        });
        CrmLeadTag::deleted(function (CrmLeadTag $row) use (&$events): void {
            $events[] = ['tag.deleted', Auth::id(), $row->crm_lead_id, $row->account_id];
        });

        $ids = [$already->id, ...$this->ids($leads)];
        $this->actingAs($t['user'])->postJson(self::ASSIGN, ['lead_ids' => $ids, 'assigned_user_id' => $asha->id])->assertOk();
        $this->actingAs($t['user'])->postJson(self::ATTACH, ['lead_ids' => $this->ids($leads), 'tag_id' => $hot->id])->assertOk();
        $this->actingAs($t['user'])->postJson(self::DETACH, ['lead_ids' => [$leads[0]->id, $already->id], 'tag_id' => $hot->id])->assertOk();

        $uid = $t['user']->id;
        $aid = $t['account']->id;
        $this->assertSame([
            ['lead.updated', $uid, $leads[0]->id, $aid, ['assigned_user_id' => null], ['assigned_user_id' => $asha->id]],
            ['lead.updated', $uid, $leads[1]->id, $aid, ['assigned_user_id' => null], ['assigned_user_id' => $asha->id]],
            ['tag.created', $uid, $leads[0]->id, $aid],
            ['tag.created', $uid, $leads[1]->id, $aid],
            ['tag.deleted', $uid, $leads[0]->id, $aid],
        ], $events);
    }

    /**
     * ROW-LEVEL audit, inside PHPUnit. LogsActivity::recordActivity() skips
     * console processes (PROJECT_STATE §7), and PHPUnit is one — so this
     * test tells the application it is serving HTTP (the same answer
     * `php artisan serve` gives it) for the duration of the requests, and
     * then reads the real activity_logs rows.
     */
    public function test_bulk_operations_write_one_attributable_activity_row_per_changed_lead(): void
    {
        $t = $this->tenant();
        $asha = $this->member($t['account']);
        $already = $this->leads($t['account'], 1, CrmLead::STATUS_CONTACTED)[0];
        $leads = $this->leads($t['account'], 2);
        $hot = $this->tag($t['account'], 'Hot');

        $property = new \ReflectionProperty($this->app, 'isRunningInConsole');
        $property->setValue($this->app, false);
        DB::table('activity_logs')->delete();

        try {
            $this->actingAs($t['user'])->postJson(self::STATUS, ['lead_ids' => [$already->id, ...$this->ids($leads)], 'status' => 'contacted'])->assertOk();
            $this->actingAs($t['user'])->postJson(self::ASSIGN, ['lead_ids' => $this->ids($leads), 'assigned_user_id' => $asha->id])->assertOk();
            $this->actingAs($t['user'])->postJson(self::ATTACH, ['lead_ids' => [$leads[0]->id], 'tag_id' => $hot->id])->assertOk();
            $this->actingAs($t['user'])->postJson(self::DETACH, ['lead_ids' => $this->ids($leads), 'tag_id' => $hot->id])->assertOk();
            // A rejected batch writes nothing.
            $this->actingAs($t['user'])->postJson(self::STATUS, ['lead_ids' => [$leads[0]->id, 999999], 'status' => 'converted'])->assertStatus(422);
        } finally {
            $property->setValue($this->app, true);
        }

        $rows = DB::table('activity_logs')->orderBy('id')->get()->map(fn ($r) => [
            'user' => (int) $r->user_id,
            'account' => (int) $r->account_id,
            'module' => $r->module_name,
            'action' => $r->action_type,
            'route' => $r->route_path,
            // Key-sorted: MySQL 8's JSON type reorders object keys (MariaDB
            // keeps insertion order); the audit CONTENT is what is asserted.
            'old' => $this->sortedJson($r->old_values),
            'new' => $this->sortedJson($r->new_values),
        ])->all();

        $uid = $t['user']->id;
        $aid = $t['account']->id;
        [$a, $b] = $this->ids($leads);
        $this->assertSame([
            ['user' => $uid, 'account' => $aid, 'module' => 'CRM', 'action' => 'update', 'route' => 'api/crm/leads/bulk/status', 'old' => ['id' => $a, 'status' => 'new'], 'new' => ['id' => $a, 'status' => 'contacted']],
            ['user' => $uid, 'account' => $aid, 'module' => 'CRM', 'action' => 'update', 'route' => 'api/crm/leads/bulk/status', 'old' => ['id' => $b, 'status' => 'new'], 'new' => ['id' => $b, 'status' => 'contacted']],
            ['user' => $uid, 'account' => $aid, 'module' => 'CRM', 'action' => 'update', 'route' => 'api/crm/leads/bulk/assignee', 'old' => ['assigned_user_id' => null, 'id' => $a], 'new' => ['assigned_user_id' => $asha->id, 'id' => $a]],
            ['user' => $uid, 'account' => $aid, 'module' => 'CRM', 'action' => 'update', 'route' => 'api/crm/leads/bulk/assignee', 'old' => ['assigned_user_id' => null, 'id' => $b], 'new' => ['assigned_user_id' => $asha->id, 'id' => $b]],
            ['user' => $uid, 'account' => $aid, 'module' => 'CRM', 'action' => 'create', 'route' => 'api/crm/leads/bulk/tags/attach', 'old' => null, 'new' => ['account_id' => $aid, 'crm_lead_id' => $a, 'crm_tag_id' => $hot->id]],
            ['user' => $uid, 'account' => $aid, 'module' => 'CRM', 'action' => 'delete', 'route' => 'api/crm/leads/bulk/tags/detach', 'old' => ['account_id' => $aid, 'crm_lead_id' => $a, 'crm_tag_id' => $hot->id], 'new' => null],
        ], $rows, 'One row per CHANGED lead; the already-contacted lead, the lead without the tag and the rejected batch write none.');
    }

    private function sortedJson(?string $json): ?array
    {
        $value = json_decode((string) $json, true);
        if (is_array($value)) {
            ksort($value);
        }

        return $value;
    }

    public function test_models_without_audit_identity_keep_their_previous_audit_payload(): void
    {
        $t = $this->tenant();
        $tag = $this->tag($t['account'], 'Hot');

        $this->assertSame([], $tag->auditIdentityAttributes());
        $this->assertSame([], $t['account']->auditIdentityAttributes());
        $this->assertSame(['id' => $this->leads($t['account'], 1)[0]->id], CrmLead::first()->auditIdentityAttributes());
    }

    // =================================================================
    // Authorization, capability, plan
    // =================================================================

    /** @dataProvider endpoints */
    public function test_bulk_requires_authentication_module_permission_and_capability(string $endpoint): void
    {
        $t = $this->tenant();
        $leads = $this->ids($this->leads($t['account'], 2));
        $tag = $this->tag($t['account'], 'Hot');
        $body = $this->validBody($endpoint, $leads, $tag->id);
        $role = Role::create(['name' => 'bulk_denied_'.md5($endpoint)]);
        $role->givePermissionTo('manage-social-leads');
        $noCrm = User::factory()->create(['account_id' => $t['account']->id, 'is_active' => true]);
        $noCrm->assignRole($role);
        $before = $this->snapshot();

        $this->postJson($endpoint, $body)->assertStatus(401);
        $this->actingAs($noCrm)->postJson($endpoint, $body)->assertStatus(403);

        $t['account']->forceFill(['allowed_modules' => array_values(array_diff(Account::MODULES, ['lead_crm']))])->save();
        $this->actingAs($t['user'])->postJson($endpoint, $body)->assertStatus(403)->assertJsonPath('error_code', 'MODULE_DISABLED');
        $t['account']->forceFill(['allowed_modules' => null])->save();

        AccountEntitlement::where('account_id', $t['account']->id)->update(['revoked_at' => now(), 'revoked_reason' => AccountEntitlement::REVOKED_PLAN_DOWNGRADE]);
        $this->actingAs($t['user'])->postJson($endpoint, $body)->assertStatus(403)->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');

        $this->assertSame($before, $this->snapshot());

        $this->grantCrm($t['account']->fresh());
        $this->actingAs($t['user'])->postJson($endpoint, $body)->assertOk();
    }

    public function test_an_expired_subscription_blocks_bulk_mutations(): void
    {
        $t = $this->tenant();
        $leads = $this->ids($this->leads($t['account'], 2));
        Subscription::where('account_id', $t['account']->id)->update(['expires_at' => now()->subDay(), 'status' => 'expired']);

        $this->actingAs($t['user'])->postJson(self::STATUS, ['lead_ids' => $leads, 'status' => 'contacted'])
            ->assertStatus(403)->assertJsonPath('error_code', 'SUBSCRIPTION_EXPIRED');
        $this->assertSame(['new', 'new'], CrmLead::whereIn('id', $leads)->pluck('status')->all());
    }

    public function test_no_bulk_specific_permission_or_capability_exists(): void
    {
        $this->assertSame(0, Permission::where('name', 'like', '%bulk%')->count());
        $this->assertSame(0, Capability::where('slug', 'like', '%bulk%')->count());
    }

    public function test_reconciliation_and_plan_changes_still_govern_crm_and_keep_bulk_results(): void
    {
        $t = $this->tenant();
        $leads = $this->ids($this->leads($t['account'], 2));
        $this->actingAs($t['user'])->postJson(self::STATUS, ['lead_ids' => $leads, 'status' => 'converted'])->assertOk();

        app(PlanEntitlementReconciliationService::class)->reconcile($t['account']->fresh());
        $this->assertSame(['converted', 'converted'], CrmLead::whereIn('id', $leads)->pluck('status')->all());

        $service = app(PlanManagementService::class);
        $plan = $service->create('bulk-plan', ['label' => 'B', 'price' => 1, 'duration_days' => 30], ['crm']);
        $this->assertTrue($plan->capabilities->pluck('slug')->contains('crm'));
        $this->assertContains('crm', $service->modify($plan->fresh(), [], [])['removed']);
    }

    // =================================================================
    // Boundaries
    // =================================================================

    public function test_the_developer_api_has_no_bulk_crm_endpoints(): void
    {
        $t = $this->tenant();
        $plain = 'sk_test_'.bin2hex(random_bytes(12));
        ApiKey::create(['account_id' => $t['account']->id, 'name' => 'Key', 'key_prefix' => substr($plain, 0, 10), 'key_hash' => ApiKey::hashKey($plain)]);

        foreach (['/api/v1/crm/leads/bulk/status', '/api/v1/crm/leads/bulk/assignee', '/api/v1/crm/leads/bulk/tags/attach', '/api/v1/crm/leads/bulk/tags/detach'] as $url) {
            $this->withHeader('X-API-KEY', $plain)->postJson($url, ['lead_ids' => [1]])->assertStatus(404);
        }
    }

    public function test_no_bulk_delete_or_contact_reassignment_exists(): void
    {
        $t = $this->tenant();
        $leads = $this->ids($this->leads($t['account'], 1));

        // 404 (no route) or 405 (the path only matches an existing
        // single-lead route for another verb) — never a bulk action.
        foreach ([
            ['postJson', '/api/crm/leads/bulk/delete'],
            ['postJson', '/api/crm/leads/bulk/contact'],
            ['postJson', '/api/crm/leads/bulk/merge'],
            ['deleteJson', '/api/crm/leads/bulk'],
        ] as [$verb, $url]) {
            $status = $this->actingAs($t['user'])->{$verb}($url, ['lead_ids' => $leads])->status();
            $this->assertContains($status, [404, 405], "{$verb} {$url} returned {$status}.");
        }
        $this->assertSame(1, CrmLead::count());
    }

    /** Task 9 route hardening: a non-numeric id is a 404, never a 500. */
    public function test_non_numeric_ids_on_lead_and_contact_routes_are_404(): void
    {
        $t = $this->tenant();

        foreach ([
            ['getJson', '/api/crm/leads/bulk'],
            ['patchJson', '/api/crm/leads/bulk'],
            ['deleteJson', '/api/crm/leads/bulk'],
            ['patchJson', '/api/crm/leads/abc/status'],
            ['patchJson', '/api/crm/leads/abc/assignee'],
            ['patchJson', '/api/crm/leads/abc/contact'],
            ['getJson', '/api/crm/contacts/abc'],
            ['deleteJson', '/api/crm/contacts/abc'],
            ['getJson', '/api/crm/contacts/abc/leads'],
            ['postJson', '/api/crm/contacts/abc/merge/1'],
        ] as [$verb, $url]) {
            $this->actingAs($t['user'])->{$verb}($url, [])->assertStatus(404);
        }
    }

    public function test_individual_operations_still_work(): void
    {
        $t = $this->tenant();
        $asha = $this->member($t['account']);
        $lead = $this->leads($t['account'], 1)[0];
        $hot = $this->tag($t['account'], 'Hot');

        $this->actingAs($t['user'])->patchJson('/api/crm/leads/'.$lead->id.'/status', ['status' => 'contacted'])->assertOk()->assertJsonPath('data.status', 'contacted');
        $this->actingAs($t['user'])->patchJson('/api/crm/leads/'.$lead->id.'/assignee', ['assigned_user_id' => $asha->id])->assertOk()->assertJsonPath('data.assigned_user_id', $asha->id);
        $this->actingAs($t['user'])->postJson('/api/crm/leads/'.$lead->id.'/tags/'.$hot->id)->assertOk()->assertJsonPath('data.tags.0.id', $hot->id);
        $this->actingAs($t['user'])->deleteJson('/api/crm/leads/'.$lead->id.'/tags/'.$hot->id)->assertOk()->assertJsonPath('data.tags', []);
    }

    public function test_individual_assignment_is_still_checked_outside_bulk(): void
    {
        $t = $this->tenant();
        $inactive = $this->member($t['account'], active: false);
        $lead = $this->leads($t['account'], 1)[0];

        // The memo must not leak out of a bulk call into later writes.
        $this->actingAs($t['user'])->postJson(self::ASSIGN, ['lead_ids' => [$lead->id], 'assigned_user_id' => null])->assertOk();
        $this->actingAs($t['user'])->patchJson('/api/crm/leads/'.$lead->id.'/assignee', ['assigned_user_id' => $inactive->id])->assertStatus(422);
    }
}
