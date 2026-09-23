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
use App\Models\Lead;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Access\PlanEntitlementReconciliationService;
use App\Services\Access\PlanManagementService;
use App\Services\Crm\CaptureLeadLinker;
use App\Services\Crm\CrmLeadService;
use App\Services\Crm\CrmTagService;
use App\Traits\LogsActivity;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 6 — CRM Task 7. Lead Tags & Segmentation Foundation.
 *
 * Tags are tenant-owned metadata on CRM leads: CRUD, idempotent
 * attach/detach, tag filtering on the shared list/pipeline filter, and
 * canonical serialization — all without touching crm_leads.status or the
 * Task 5 lifecycle.
 */
class CrmLeadTagTest extends TestCase
{
    use RefreshDatabase;

    private const TAGS = '/api/crm/tags';

    private const LEADS = '/api/crm/leads';

    private const PIPELINE = '/api/crm/pipeline';

    private const CONTACTS = '/api/crm/contacts';

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

    private function revokeAllEntitlements(Account $account): void
    {
        AccountEntitlement::query()
            ->where('account_id', $account->id)
            ->get()
            ->each(fn (AccountEntitlement $e) => $e->forceFill([
                'revoked_at' => now(),
                'revoked_reason' => AccountEntitlement::REVOKED_PLAN_DOWNGRADE,
            ])->save());
    }

    private function member(Account $account): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->givePermissionTo('manage-crm');

        return $user;
    }

    private function leadAt(Account $account, string $status = CrmLead::STATUS_NEW, array $attributes = [], array $contactAttributes = []): CrmLead
    {
        $contact = Contact::factory()->forAccount($account)->create($contactAttributes);
        $lead = CrmLead::factory()->forContact($contact)->create();

        if ($attributes !== []) {
            $lead->forceFill($attributes)->save();
        }

        if ($status !== CrmLead::STATUS_NEW) {
            app(CrmLeadService::class)->changeStatus($lead, $status);
        }

        return $lead->fresh();
    }

    private function tag(Account $account, string $name): CrmTag
    {
        return app(CrmTagService::class)->create($account, $name);
    }

    private function attach(CrmLead $lead, CrmTag ...$tags): void
    {
        foreach ($tags as $tag) {
            app(CrmTagService::class)->attach($lead, $tag);
        }
    }

    /** @return list<int> */
    private function tagIdsOf(CrmLead $lead): array
    {
        return CrmLeadTag::query()
            ->where('crm_lead_id', $lead->id)
            ->orderBy('crm_tag_id')
            ->pluck('crm_tag_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @return array<string, array<string, mixed>> */
    private function columns(array $json): array
    {
        return collect($json['data']['pipeline'])->keyBy('status')->all();
    }

    /**
     * The body a production client sees. With APP_DEBUG on, Laravel adds
     * exception/file/line/trace to error JSON — those differ by call site
     * and are never served when debug is off, so they are stripped
     * before two error bodies are compared.
     */
    private function publicBody(\Illuminate\Testing\TestResponse $response): array
    {
        return array_diff_key((array) $response->json(), array_flip(['exception', 'file', 'line', 'trace']));
    }

    // =================================================================
    // Schema
    // =================================================================

    public function test_the_schema_has_tag_tables_and_no_tag_status_columns(): void
    {
        $this->assertTrue(Schema::hasTable('crm_tags'));
        $this->assertTrue(Schema::hasTable('crm_lead_tags'));
        $this->assertTrue(Schema::hasColumns('crm_tags', ['id', 'account_id', 'name', 'normalized_name', 'created_at', 'updated_at']));
        $this->assertTrue(Schema::hasColumns('crm_lead_tags', ['crm_lead_id', 'crm_tag_id', 'account_id', 'created_at']));

        foreach (['tag_status', 'lead_stage', 'pipeline_tag', 'custom_status', 'pipeline_status', 'stage', 'tag_id', 'tags'] as $column) {
            $this->assertFalse(Schema::hasColumn('crm_leads', $column), "crm_leads gained a {$column} column.");
        }
        $this->assertFalse(Schema::hasTable('tags'), 'No global tag namespace may exist.');
    }

    public function test_the_lifecycle_definitions_are_unchanged(): void
    {
        $this->assertSame(['new', 'contacted', 'converted', 'not_converted'], CrmLead::STATUSES);
        foreach (CrmLead::STATUSES as $from) {
            $this->assertSame(CrmLead::STATUSES, CrmLead::STATUS_TRANSITIONS[$from]);
            foreach (CrmLead::STATUSES as $to) {
                $this->assertTrue(CrmLead::canTransitionTo($from, $to));
            }
        }
        $this->assertSame(['new', 'contacted', 'converted', 'not_converted'], array_column(CrmLead::pipelineStatuses(), 'status'));
    }

    // =================================================================
    // Tag CRUD
    // =================================================================

    public function test_a_tag_can_be_created(): void
    {
        $t = $this->tenant();

        $response = $this->actingAs($t['user'])->postJson(self::TAGS, ['name' => 'Hot']);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Tag created.')
            ->assertJsonPath('data.name', 'Hot')
            ->assertJsonPath('data.lead_count', 0);
        $this->assertSame(['id', 'name', 'lead_count', 'created_at', 'updated_at'], array_keys($response->json('data')));

        $tag = CrmTag::findOrFail($response->json('data.id'));
        $this->assertSame($t['account']->id, $tag->account_id);
        $this->assertSame('hot', $tag->normalized_name);
    }

    public function test_whitespace_is_trimmed_and_collapsed(): void
    {
        $t = $this->tenant();

        $response = $this->actingAs($t['user'])->postJson(self::TAGS, ['name' => "  Follow \t  Up  "]);

        $response->assertStatus(201)->assertJsonPath('data.name', 'Follow Up');
        $this->assertSame('follow up', CrmTag::findOrFail($response->json('data.id'))->normalized_name);
    }

    public function test_tags_are_listed_by_name_with_tenant_scoped_counts(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();

        $vip = $this->tag($t['account'], 'VIP');
        $hot = $this->tag($t['account'], 'hot');
        $demo = $this->tag($t['account'], 'Demo Requested');
        $this->tag($other['account'], 'Foreign');

        $a = $this->leadAt($t['account']);
        $b = $this->leadAt($t['account']);
        $this->attach($a, $hot, $vip);
        $this->attach($b, $hot);

        $response = $this->actingAs($t['user'])->getJson(self::TAGS);

        $response->assertOk()->assertJsonPath('total', 3);
        $data = $response->json('data');
        $this->assertSame(['Demo Requested', 'hot', 'VIP'], array_column($data, 'name'));
        $this->assertSame([$demo->id, $hot->id, $vip->id], array_column($data, 'id'));
        $this->assertSame([0, 2, 1], array_column($data, 'lead_count'));
    }

    public function test_a_tag_can_be_shown(): void
    {
        $t = $this->tenant();
        $tag = $this->tag($t['account'], 'Interested');
        $this->attach($this->leadAt($t['account']), $tag);

        $this->actingAs($t['user'])->getJson(self::TAGS.'/'.$tag->id)
            ->assertOk()
            ->assertJsonPath('data.id', $tag->id)
            ->assertJsonPath('data.name', 'Interested')
            ->assertJsonPath('data.lead_count', 1);
    }

    public function test_a_tag_can_be_renamed_with_put_and_patch(): void
    {
        $t = $this->tenant();
        $tag = $this->tag($t['account'], 'Hot');
        $lead = $this->leadAt($t['account']);
        $this->attach($lead, $tag);

        $this->actingAs($t['user'])->putJson(self::TAGS.'/'.$tag->id, ['name' => 'Very Hot'])
            ->assertOk()->assertJsonPath('data.name', 'Very Hot')->assertJsonPath('data.lead_count', 1);
        $this->actingAs($t['user'])->patchJson(self::TAGS.'/'.$tag->id, ['name' => 'Scorching'])
            ->assertOk()->assertJsonPath('data.name', 'Scorching');

        $this->assertSame('scorching', $tag->fresh()->normalized_name);
        $this->assertSame([$tag->id], $this->tagIdsOf($lead), 'Renaming must not disturb assignments.');
    }

    public function test_a_case_only_rename_of_the_same_tag_is_allowed(): void
    {
        $t = $this->tenant();
        $tag = $this->tag($t['account'], 'hot');

        $this->actingAs($t['user'])->patchJson(self::TAGS.'/'.$tag->id, ['name' => 'HOT'])
            ->assertOk()->assertJsonPath('data.name', 'HOT');
    }

    public function test_renaming_onto_another_tags_name_is_refused(): void
    {
        $t = $this->tenant();
        $this->tag($t['account'], 'Hot');
        $vip = $this->tag($t['account'], 'VIP');

        $this->actingAs($t['user'])->patchJson(self::TAGS.'/'.$vip->id, ['name' => 'hOT'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name' => 'A tag with this name already exists.']);

        $this->assertSame('VIP', $vip->fresh()->name);
    }

    public function test_a_tag_can_be_deleted(): void
    {
        $t = $this->tenant();
        $tag = $this->tag($t['account'], 'Hot');

        $this->actingAs($t['user'])->deleteJson(self::TAGS.'/'.$tag->id)
            ->assertOk()->assertJsonPath('message', 'Tag deleted.');

        $this->assertNull(CrmTag::find($tag->id));
    }

    /** @dataProvider caseVariants */
    public function test_duplicate_names_are_case_insensitive_within_an_account(string $first, string $second): void
    {
        $t = $this->tenant();
        $this->actingAs($t['user'])->postJson(self::TAGS, ['name' => $first])->assertStatus(201);

        $this->actingAs($t['user'])->postJson(self::TAGS, ['name' => $second])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name' => 'A tag with this name already exists.']);

        $this->assertSame(1, CrmTag::forAccount($t['account']->id)->count());
        $this->assertSame(CrmTag::clean($first), CrmTag::forAccount($t['account']->id)->value('name'), 'A duplicate must never rename the existing tag.');
    }

    public static function caseVariants(): array
    {
        return [
            'lower' => ['Hot', 'hot'],
            'upper' => ['Hot', 'HOT'],
            'mixed' => ['hot', 'hOt'],
            'whitespace' => ['Follow Up', '  follow    up '],
            'multibyte' => ['Été', 'ÉTÉ'],
        ];
    }

    public function test_the_same_name_is_allowed_in_different_accounts(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();

        $this->actingAs($a['user'])->postJson(self::TAGS, ['name' => 'Hot'])->assertStatus(201);
        $this->actingAs($b['user'])->postJson(self::TAGS, ['name' => 'hot'])->assertStatus(201);

        $this->assertSame(1, CrmTag::forAccount($a['account']->id)->count());
        $this->assertSame(1, CrmTag::forAccount($b['account']->id)->count());
    }

    /** @dataProvider invalidNames */
    public function test_invalid_names_are_rejected(mixed $name): void
    {
        $t = $this->tenant();

        $this->actingAs($t['user'])->postJson(self::TAGS, ['name' => $name])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertSame(0, CrmTag::count());
    }

    public static function invalidNames(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'whitespace only' => ['    '],
            'array' => [['Hot']],
            'integer' => [42],
            'too long' => [str_repeat('a', CrmTag::NAME_MAX + 1)],
        ];
    }

    public function test_a_missing_name_is_rejected(): void
    {
        $t = $this->tenant();

        $this->actingAs($t['user'])->postJson(self::TAGS, [])->assertStatus(422)->assertJsonValidationErrors('name');
        $tag = $this->tag($t['account'], 'Hot');
        $this->actingAs($t['user'])->patchJson(self::TAGS.'/'.$tag->id, [])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_the_maximum_length_is_accepted(): void
    {
        $t = $this->tenant();

        $this->actingAs($t['user'])->postJson(self::TAGS, ['name' => str_repeat('b', CrmTag::NAME_MAX)])->assertStatus(201);
    }

    public function test_the_model_guard_enforces_the_rules_for_non_http_writers(): void
    {
        $t = $this->tenant();
        CrmTag::create(['account_id' => $t['account']->id, 'name' => 'Hot']);

        try {
            CrmTag::create(['account_id' => $t['account']->id, 'name' => ' HOT ']);
            $this->fail('A case-variant duplicate was stored.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->errors());
        }

        $this->expectException(ValidationException::class);
        CrmTag::create(['account_id' => $t['account']->id, 'name' => '   ']);
    }

    public function test_the_unique_index_is_the_race_backstop(): void
    {
        $t = $this->tenant();
        $this->tag($t['account'], 'Hot');

        $this->expectException(QueryException::class);
        DB::table('crm_tags')->insert([
            'account_id' => $t['account']->id,
            'name' => 'HOT',
            'normalized_name' => 'hot',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_tag_cannot_be_moved_to_another_account(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        $tag = $this->tag($a['account'], 'Hot');

        $this->expectException(ValidationException::class);
        $tag->forceFill(['account_id' => $b['account']->id])->save();
    }

    public function test_search_is_a_scoped_case_insensitive_prefix_match(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $this->tag($t['account'], 'Follow Up');
        $this->tag($t['account'], 'Follow Later');
        $this->tag($t['account'], 'Unfollowed');
        $this->tag($other['account'], 'Follow Foreign');

        $names = array_column($this->actingAs($t['user'])->getJson(self::TAGS.'?search=FOL')->json('data'), 'name');

        $this->assertSame(['Follow Later', 'Follow Up'], $names);
    }

    public function test_search_escapes_like_wildcards(): void
    {
        $t = $this->tenant();
        $this->tag($t['account'], '100% Sure');
        $this->tag($t['account'], '100 Leads');
        $this->tag($t['account'], 'a_b');
        $this->tag($t['account'], 'axb');

        $this->assertSame(['100% Sure'], array_column($this->actingAs($t['user'])->getJson(self::TAGS.'?search='.urlencode('100%'))->json('data'), 'name'));
        $this->assertSame(['a_b'], array_column($this->actingAs($t['user'])->getJson(self::TAGS.'?search=a_')->json('data'), 'name'));
    }

    public function test_the_tag_list_paginates_and_caps_the_page_size(): void
    {
        $t = $this->tenant();
        foreach (range(1, 5) as $i) {
            $this->tag($t['account'], "Tag {$i}");
        }

        $page = $this->actingAs($t['user'])->getJson(self::TAGS.'?per_page=2&page=2');
        $page->assertOk()->assertJsonPath('total', 5)->assertJsonPath('per_page', 2);
        $this->assertSame(['Tag 3', 'Tag 4'], array_column($page->json('data'), 'name'));

        $this->actingAs($t['user'])->getJson(self::TAGS.'?per_page=1000')->assertJsonPath('per_page', 100);
        $this->actingAs($t['user'])->getJson(self::TAGS.'?per_page=0')->assertStatus(422);
    }

    public function test_the_tag_list_uses_one_query_regardless_of_tag_count(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account']);
        $this->attach($lead, $this->tag($t['account'], 'A'));
        $this->actingAs($t['user'])->getJson(self::TAGS)->assertOk();

        $count = function () use ($t): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($t['user'])->getJson(self::TAGS)->assertOk();
            $n = count(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'crm_tags')));
            DB::disableQueryLog();

            return $n;
        };

        $small = $count();
        foreach (range(1, 8) as $i) {
            $this->attach($lead, $this->tag($t['account'], "More {$i}"));
        }
        $this->assertSame($small, $count(), 'Tag counts must not be one query per tag.');
    }

    // =================================================================
    // Tenant ownership of tags
    // =================================================================

    public function test_client_supplied_tenant_fields_are_ignored_on_create(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();

        $response = $this->actingAs($t['user'])->postJson(
            self::TAGS.'?account_id='.$other['account']->id,
            ['name' => 'Hot', 'account_id' => $other['account']->id, 'agent_id' => $other['account']->id, 'tenant_id' => $other['account']->id],
        );

        $response->assertStatus(201);
        $this->assertSame($t['account']->id, CrmTag::findOrFail($response->json('data.id'))->account_id);
        $this->assertSame(0, CrmTag::forAccount($other['account']->id)->count());
    }

    public function test_client_supplied_tenant_fields_are_ignored_on_update(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $tag = $this->tag($t['account'], 'Hot');

        $this->actingAs($t['user'])->patchJson(self::TAGS.'/'.$tag->id, ['name' => 'Warm', 'account_id' => $other['account']->id])
            ->assertOk();

        $this->assertSame($t['account']->id, $tag->fresh()->account_id);
    }

    public function test_spoofed_tenant_query_parameters_do_not_widen_the_list(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $this->tag($t['account'], 'Mine');
        $this->tag($other['account'], 'Theirs');

        $names = array_column($this->actingAs($t['user'])->getJson(
            self::TAGS.'?account_id='.$other['account']->id.'&agent_id='.$other['account']->id.'&tenant_id='.$other['account']->id,
        )->json('data'), 'name');

        $this->assertSame(['Mine'], $names);
    }

    public function test_a_foreign_tag_is_indistinguishable_from_a_missing_one(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $foreign = $this->tag($other['account'], 'Theirs');
        $missing = $foreign->id + 1000;

        foreach ([['getJson', null], ['putJson', ['name' => 'X']], ['patchJson', ['name' => 'X']], ['deleteJson', null]] as [$verb, $body]) {
            $f = $this->actingAs($t['user'])->{$verb}(self::TAGS.'/'.$foreign->id, $body ?? []);
            $m = $this->actingAs($t['user'])->{$verb}(self::TAGS.'/'.$missing, $body ?? []);

            $f->assertStatus(404);
            $m->assertStatus(404);
            $this->assertSame($this->publicBody($m), $this->publicBody($f), "{$verb}: a foreign tag must look exactly like a missing one.");
        }

        $this->assertSame('Theirs', $foreign->fresh()->name, 'A foreign tag must not be modified.');
    }

    public function test_a_non_numeric_tag_id_is_a_plain_404(): void
    {
        $t = $this->tenant();

        $this->actingAs($t['user'])->getJson(self::TAGS.'/abc')->assertStatus(404);
    }

    public function test_a_super_admin_must_select_a_tenant_and_then_acts_within_it(): void
    {
        $t = $this->tenant();
        $admin = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $admin->assignRole('super_admin');
        if (! $admin->can('manage-crm')) {
            $admin->givePermissionTo('manage-crm');
        }

        $this->actingAs($admin)->postJson(self::TAGS, ['name' => 'Hot'])->assertStatus(422);

        $this->actingAs($admin)->postJson(self::TAGS.'?account_id='.$t['account']->id, ['name' => 'Hot'])->assertStatus(201);
        $this->assertSame(1, CrmTag::forAccount($t['account']->id)->count());
    }

    public function test_an_agent_reaches_only_its_own_sub_clients_tags(): void
    {
        $agent = $this->tenant(accountAttributes: ['account_type' => 'agent']);
        $sub = $this->tenant(accountAttributes: ['agent_id' => $agent['account']->id]);
        $stranger = $this->tenant();
        $this->tag($sub['account'], 'SubTag');
        $this->tag($stranger['account'], 'StrangerTag');

        $this->actingAs($agent['user'])->getJson(self::TAGS.'?account_id='.$sub['account']->id)
            ->assertOk()->assertJsonPath('data.0.name', 'SubTag');

        $this->actingAs($agent['user'])->getJson(self::TAGS.'?account_id='.$stranger['account']->id)
            ->assertStatus(404);
    }

    // =================================================================
    // Assignment
    // =================================================================

    public function test_a_tag_can_be_attached_without_touching_the_lead(): void
    {
        $t = $this->tenant();
        $member = $this->member($t['account']);
        $lead = $this->leadAt($t['account'], CrmLead::STATUS_CONTACTED, ['assigned_user_id' => $member->id]);
        $this->travel(5)->minutes();
        $tag = $this->tag($t['account'], 'Hot');
        $before = $lead->fresh()->getAttributes();

        $leadWrites = 0;
        CrmLead::saving(function () use (&$leadWrites): void {
            $leadWrites++;
        });

        $response = $this->actingAs($t['user'])->postJson(self::LEADS.'/'.$lead->id.'/tags/'.$tag->id);

        $response->assertOk()
            ->assertJsonPath('message', 'Tag attached.')
            ->assertJsonPath('data.status', 'contacted')
            ->assertJsonPath('data.tags', [['id' => $tag->id, 'name' => 'Hot']]);

        $this->assertSame(0, $leadWrites, 'Attaching a tag must not write to crm_leads.');
        $this->assertSame($before, $lead->fresh()->getAttributes(), 'Status, owner, contact and updated_at must be unchanged.');
        $this->assertSame([$tag->id], $this->tagIdsOf($lead));
        $this->assertSame($t['account']->id, (int) CrmLeadTag::where('crm_lead_id', $lead->id)->value('account_id'));
    }

    public function test_attaching_twice_is_an_idempotent_no_op(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account']);
        $tag = $this->tag($t['account'], 'Hot');

        $this->actingAs($t['user'])->postJson(self::LEADS.'/'.$lead->id.'/tags/'.$tag->id)->assertOk();

        $created = 0;
        CrmLeadTag::created(function () use (&$created): void {
            $created++;
        });

        $this->actingAs($t['user'])->postJson(self::LEADS.'/'.$lead->id.'/tags/'.$tag->id)
            ->assertOk()
            ->assertJsonPath('message', 'Tag already attached.')
            ->assertJsonPath('data.tags', [['id' => $tag->id, 'name' => 'Hot']]);

        $this->assertSame(0, $created);
        $this->assertSame(1, CrmLeadTag::count());
    }

    public function test_a_tag_can_be_detached_and_detaching_again_is_a_no_op(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account'], CrmLead::STATUS_CONVERTED);
        $hot = $this->tag($t['account'], 'Hot');
        $vip = $this->tag($t['account'], 'VIP');
        $this->attach($lead, $hot, $vip);

        $this->actingAs($t['user'])->deleteJson(self::LEADS.'/'.$lead->id.'/tags/'.$hot->id)
            ->assertOk()
            ->assertJsonPath('message', 'Tag detached.')
            ->assertJsonPath('data.status', 'converted')
            ->assertJsonPath('data.tags', [['id' => $vip->id, 'name' => 'VIP']]);

        $this->actingAs($t['user'])->deleteJson(self::LEADS.'/'.$lead->id.'/tags/'.$hot->id)
            ->assertOk()
            ->assertJsonPath('message', 'Tag was not attached.');

        $this->assertSame([$vip->id], $this->tagIdsOf($lead));
        $this->assertNotNull(CrmTag::find($hot->id), 'Detaching must not delete the tag.');
        $this->assertSame('converted', $lead->fresh()->status);
    }

    public function test_a_lead_can_carry_several_tags_and_attaching_never_detaches(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account'], CrmLead::STATUS_CONTACTED);
        $tags = [
            $this->tag($t['account'], 'Hot'),
            $this->tag($t['account'], 'Follow Up'),
            $this->tag($t['account'], 'VIP'),
        ];

        foreach ($tags as $tag) {
            $this->actingAs($t['user'])->postJson(self::LEADS.'/'.$lead->id.'/tags/'.$tag->id)->assertOk();
        }

        $show = $this->actingAs($t['user'])->getJson(self::LEADS.'/'.$lead->id);
        $this->assertSame(['Follow Up', 'Hot', 'VIP'], array_column($show->json('data.tags'), 'name'));
        $this->assertSame('contacted', $show->json('data.status'));
    }

    public function test_one_tag_can_sit_on_many_leads(): void
    {
        $t = $this->tenant();
        $tag = $this->tag($t['account'], 'Hot');
        $leads = [$this->leadAt($t['account']), $this->leadAt($t['account']), $this->leadAt($t['account'])];

        foreach ($leads as $lead) {
            $this->actingAs($t['user'])->postJson(self::LEADS.'/'.$lead->id.'/tags/'.$tag->id)->assertOk();
        }

        $this->actingAs($t['user'])->getJson(self::TAGS.'/'.$tag->id)->assertJsonPath('data.lead_count', 3);
    }

    public function test_a_foreign_or_missing_lead_is_the_same_404(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $tag = $this->tag($t['account'], 'Hot');
        $foreignLead = $this->leadAt($other['account']);

        foreach (['postJson', 'deleteJson'] as $verb) {
            $f = $this->actingAs($t['user'])->{$verb}(self::LEADS.'/'.$foreignLead->id.'/tags/'.$tag->id);
            $m = $this->actingAs($t['user'])->{$verb}(self::LEADS.'/'.($foreignLead->id + 1000).'/tags/'.$tag->id);
            $f->assertStatus(404)->assertJsonPath('message', 'Lead not found.');
            $m->assertStatus(404);
            $this->assertSame($this->publicBody($m), $this->publicBody($f));
        }

        $this->assertSame(0, CrmLeadTag::count());
    }

    public function test_a_foreign_or_missing_tag_is_the_same_404_and_nothing_is_written(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $lead = $this->leadAt($t['account']);
        $foreignTag = $this->tag($other['account'], 'Theirs');

        foreach (['postJson', 'deleteJson'] as $verb) {
            $f = $this->actingAs($t['user'])->{$verb}(self::LEADS.'/'.$lead->id.'/tags/'.$foreignTag->id);
            $m = $this->actingAs($t['user'])->{$verb}(self::LEADS.'/'.$lead->id.'/tags/'.($foreignTag->id + 1000));
            $f->assertStatus(404)->assertJsonPath('message', 'Tag not found.');
            $m->assertStatus(404);
            $this->assertSame($this->publicBody($m), $this->publicBody($f), 'A foreign tag id must not be distinguishable from a missing one.');
            $this->assertStringNotContainsString('Theirs', $f->getContent());
        }

        $this->assertSame(0, CrmLeadTag::count());
    }

    public function test_a_foreign_tag_cannot_be_detached_from_its_own_lead_by_another_tenant(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $theirLead = $this->leadAt($other['account']);
        $theirTag = $this->tag($other['account'], 'Theirs');
        $this->attach($theirLead, $theirTag);

        $this->actingAs($t['user'])->deleteJson(self::LEADS.'/'.$theirLead->id.'/tags/'.$theirTag->id)->assertStatus(404);

        $this->assertSame([$theirTag->id], $this->tagIdsOf($theirLead));
    }

    public function test_the_service_refuses_a_cross_tenant_pair(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        $lead = $this->leadAt($a['account']);
        $tag = $this->tag($b['account'], 'Theirs');

        try {
            app(CrmTagService::class)->attach($lead, $tag);
            $this->fail('A cross-tenant attach succeeded.');
        } catch (ValidationException $e) {
            $this->assertSame(['tag' => ['The selected tag is not available.']], $e->errors());
        }

        $this->assertSame(0, CrmLeadTag::count());
    }

    public function test_the_assignment_model_guard_refuses_a_cross_tenant_row(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        $lead = $this->leadAt($a['account']);
        $tag = $this->tag($b['account'], 'Theirs');

        foreach ([$a['account']->id, $b['account']->id] as $claimedAccount) {
            try {
                CrmLeadTag::create(['crm_lead_id' => $lead->id, 'crm_tag_id' => $tag->id, 'account_id' => $claimedAccount]);
                $this->fail('A cross-tenant assignment was stored through the model.');
            } catch (ValidationException) {
                // expected
            }
        }

        $this->assertSame(0, CrmLeadTag::count());
    }

    /**
     * The composite foreign keys, bypassing every application layer. Both
     * engines enforce them: the keys are declared inside CREATE TABLE,
     * which SQLite supports (unlike ALTER-added keys).
     */
    public function test_the_database_refuses_a_cross_tenant_assignment(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        $lead = $this->leadAt($a['account']);
        $foreignTag = $this->tag($b['account'], 'Theirs');

        foreach ([$a['account']->id, $b['account']->id] as $claimedAccount) {
            try {
                DB::table('crm_lead_tags')->insert([
                    'crm_lead_id' => $lead->id,
                    'crm_tag_id' => $foreignTag->id,
                    'account_id' => $claimedAccount,
                ]);
                $this->fail("The database stored a cross-tenant assignment claiming account {$claimedAccount}.");
            } catch (QueryException) {
                // expected — one of the two composite FKs must fail
            }
        }

        $this->assertSame(0, DB::table('crm_lead_tags')->count());
    }

    public function test_the_database_refuses_a_duplicate_pair(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account']);
        $tag = $this->tag($t['account'], 'Hot');
        $this->attach($lead, $tag);

        $this->expectException(QueryException::class);
        DB::table('crm_lead_tags')->insert(['crm_lead_id' => $lead->id, 'crm_tag_id' => $tag->id, 'account_id' => $t['account']->id]);
    }

    public function test_non_numeric_assignment_ids_are_a_plain_404(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account']);

        $this->actingAs($t['user'])->postJson(self::LEADS.'/'.$lead->id.'/tags/abc')->assertStatus(404);
        $this->actingAs($t['user'])->postJson(self::LEADS.'/abc/tags/1')->assertStatus(404);
    }

    public function test_tags_cannot_be_written_through_the_general_lead_update_or_create(): void
    {
        $t = $this->tenant();
        $tag = $this->tag($t['account'], 'Hot');
        $lead = $this->leadAt($t['account']);

        $this->actingAs($t['user'])->patchJson(self::LEADS.'/'.$lead->id, ['tags' => [$tag->id], 'tag_ids' => [$tag->id]])->assertOk();
        $this->actingAs($t['user'])->postJson(self::LEADS, ['phone_number' => '919000000001', 'tags' => [$tag->id], 'tag_ids' => [$tag->id]])->assertStatus(201);

        $this->assertSame(0, CrmLeadTag::count(), 'Assignment has one write path: the tag endpoints.');
    }

    // =================================================================
    // Serialization
    // =================================================================

    public function test_every_lead_surface_serializes_tags_identically(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account']);
        $this->attach($lead, $this->tag($t['account'], 'VIP'), $this->tag($t['account'], 'hot'));

        $fromShow = $this->actingAs($t['user'])->getJson(self::LEADS.'/'.$lead->id)->json('data');
        $fromIndex = collect($this->actingAs($t['user'])->getJson(self::LEADS)->json('data'))->firstWhere('id', $lead->id);
        $fromPipeline = collect($this->columns($this->actingAs($t['user'])->getJson(self::PIPELINE)->json())['new']['leads'])->firstWhere('id', $lead->id);
        $fromContact = collect($this->actingAs($t['user'])->getJson(self::CONTACTS.'/'.$lead->contact_id.'/leads')->json('data'))->firstWhere('id', $lead->id);

        $this->assertSame(['hot', 'VIP'], array_column($fromShow['tags'], 'name'));
        $this->assertSame([['id', 'name'], ['id', 'name']], array_map('array_keys', $fromShow['tags']));
        $this->assertSame($fromShow, $fromIndex);
        $this->assertSame($fromShow, $fromPipeline);
        $this->assertSame($fromShow, $fromContact);
    }

    public function test_an_untagged_lead_has_an_empty_tag_list(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account']);

        $this->actingAs($t['user'])->getJson(self::LEADS.'/'.$lead->id)->assertJsonPath('data.tags', []);
    }

    public function test_write_endpoints_return_tags_too(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account']);
        $tag = $this->tag($t['account'], 'Hot');
        $this->attach($lead, $tag);

        $this->actingAs($t['user'])->patchJson(self::LEADS.'/'.$lead->id.'/status', ['status' => 'contacted'])
            ->assertOk()->assertJsonPath('data.tags.0.id', $tag->id)->assertJsonPath('data.status', 'contacted');
        $this->actingAs($t['user'])->patchJson(self::LEADS.'/'.$lead->id, ['status' => 'new'])
            ->assertOk()->assertJsonPath('data.tags.0.id', $tag->id);
        $this->assertSame([$tag->id], $this->tagIdsOf($lead), 'A status change must not touch tags.');
    }

    /**
     * @return array{0: int, 1: int} [total queries, tag queries] for one request
     */
    private function measure(User $user, string $url): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user)->getJson($url)->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $tagQueries = array_filter($log, fn ($q) => str_contains($q['query'], 'crm_tags'));

        return [count($log), count($tagQueries)];
    }

    /** @dataProvider listSurfaces */
    public function test_tag_serialization_is_not_n_plus_one(string $surface): void
    {
        $t = $this->tenant();
        $contact = Contact::factory()->forAccount($t['account'])->create();
        $tags = [$this->tag($t['account'], 'A'), $this->tag($t['account'], 'B'), $this->tag($t['account'], 'C')];

        $make = function (int $n) use ($contact, $tags): void {
            for ($i = 0; $i < $n; $i++) {
                $lead = CrmLead::factory()->forContact($contact)->create();
                $this->attach($lead, ...$tags);
            }
        };

        $url = match ($surface) {
            'list' => self::LEADS,
            'pipeline' => self::PIPELINE,
            'contact' => self::CONTACTS.'/'.$contact->id.'/leads',
            'filtered list' => self::LEADS.'?tag_id='.$tags[0]->id,
            'filtered pipeline' => self::PIPELINE.'?tag_ids[]='.$tags[0]->id.'&tag_ids[]='.$tags[1]->id,
        };

        $make(2);
        $this->actingAs($t['user'])->getJson($url)->assertOk();
        [$small, $smallTagQueries] = $this->measure($t['user'], $url);

        $make(12);
        [$large, $largeTagQueries] = $this->measure($t['user'], $url);

        $this->assertSame($small, $large, 'Query count grew with the number of tagged leads.');
        $this->assertSame($smallTagQueries, $largeTagQueries);
        // One eager load of tags for the page (the pipeline only has one
        // non-empty column here), plus one crm_tags `exists` validation
        // query per tag id in the filter.
        $expected = match ($surface) {
            'filtered list' => 2,
            'filtered pipeline' => 3,
            default => 1,
        };
        $this->assertSame($expected, $largeTagQueries, 'Tags must be eager-loaded once per result set.');
    }

    public static function listSurfaces(): array
    {
        return [
            'list' => ['list'],
            'pipeline' => ['pipeline'],
            'contact' => ['contact'],
            'filtered list' => ['filtered list'],
            'filtered pipeline' => ['filtered pipeline'],
        ];
    }

    // =================================================================
    // Filtering
    // =================================================================

    public function test_the_list_filters_by_one_tag(): void
    {
        $t = $this->tenant();
        $hot = $this->tag($t['account'], 'Hot');
        $vip = $this->tag($t['account'], 'VIP');
        $a = $this->leadAt($t['account']);
        $b = $this->leadAt($t['account']);
        $c = $this->leadAt($t['account']);
        $this->attach($a, $hot);
        $this->attach($b, $hot, $vip);
        $this->attach($c, $vip);

        $ids = array_column($this->actingAs($t['user'])->getJson(self::LEADS.'?tag_id='.$hot->id)->json('data'), 'id');

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids);
    }

    public function test_multiple_tags_use_and_semantics(): void
    {
        $t = $this->tenant();
        $hot = $this->tag($t['account'], 'Hot');
        $vip = $this->tag($t['account'], 'VIP');
        $demo = $this->tag($t['account'], 'Demo Requested');
        $onlyHot = $this->leadAt($t['account']);
        $both = $this->leadAt($t['account']);
        $all = $this->leadAt($t['account']);
        $this->attach($onlyHot, $hot);
        $this->attach($both, $hot, $vip);
        $this->attach($all, $hot, $vip, $demo);

        $ids = array_column($this->actingAs($t['user'])->getJson(self::LEADS.'?tag_ids[]='.$hot->id.'&tag_ids[]='.$vip->id)->json('data'), 'id');
        $this->assertEqualsCanonicalizing([$both->id, $all->id], $ids);

        // tag_id and tag_ids[] combine with AND too.
        $ids = array_column($this->actingAs($t['user'])->getJson(self::LEADS.'?tag_id='.$demo->id.'&tag_ids[]='.$hot->id)->json('data'), 'id');
        $this->assertSame([$all->id], $ids);
    }

    public function test_a_foreign_tag_filter_is_rejected_exactly_like_a_missing_one(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $foreign = $this->tag($other['account'], 'Theirs');
        $this->attach($this->leadAt($other['account']), $foreign);

        foreach ([self::LEADS, self::PIPELINE] as $endpoint) {
            foreach (['tag_id=', 'tag_ids[]='] as $param) {
                $f = $this->actingAs($t['user'])->getJson($endpoint.'?'.$param.$foreign->id);
                $m = $this->actingAs($t['user'])->getJson($endpoint.'?'.$param.($foreign->id + 1000));
                $f->assertStatus(422);
                $this->assertSame($m->json(), $f->json());
                $this->assertStringContainsString('The selected tag is not available.', $f->getContent());
                $this->assertStringNotContainsString('Theirs', $f->getContent());
            }
        }
    }

    /** @dataProvider invalidTagFilters */
    public function test_malformed_tag_filters_are_rejected(string $query): void
    {
        $t = $this->tenant();

        $this->actingAs($t['user'])->getJson(self::LEADS.'?'.$query)->assertStatus(422);
        $this->actingAs($t['user'])->getJson(self::PIPELINE.'?'.$query)->assertStatus(422);
    }

    public static function invalidTagFilters(): array
    {
        return [
            'text' => ['tag_id=abc'],
            'zero' => ['tag_id=0'],
            'negative' => ['tag_id=-3'],
            'tag_ids not array' => ['tag_ids=5'],
            'tag_ids text' => ['tag_ids[]=abc'],
            'too many' => [implode('&', array_map(fn ($i) => "tag_ids[]={$i}", range(1, CrmLead::TAG_FILTER_MAX + 1)))],
        ];
    }

    public function test_duplicate_tag_ids_are_rejected(): void
    {
        $t = $this->tenant();
        $tag = $this->tag($t['account'], 'Hot');

        $this->actingAs($t['user'])->getJson(self::LEADS.'?tag_ids[]='.$tag->id.'&tag_ids[]='.$tag->id)->assertStatus(422);
    }

    public function test_the_tag_filter_combines_with_status_source_assignee_and_search(): void
    {
        $t = $this->tenant();
        $member = $this->member($t['account']);
        $hot = $this->tag($t['account'], 'Hot');

        $match = $this->leadAt($t['account'], CrmLead::STATUS_CONTACTED, ['source' => 'whatsapp', 'assigned_user_id' => $member->id], ['name' => 'Ada Lovelace']);
        $wrongStatus = $this->leadAt($t['account'], CrmLead::STATUS_NEW, ['source' => 'whatsapp', 'assigned_user_id' => $member->id], ['name' => 'Ada Byron']);
        $wrongSource = $this->leadAt($t['account'], CrmLead::STATUS_CONTACTED, ['source' => 'api', 'assigned_user_id' => $member->id], ['name' => 'Ada King']);
        $unassigned = $this->leadAt($t['account'], CrmLead::STATUS_CONTACTED, ['source' => 'whatsapp'], ['name' => 'Ada Other']);
        $wrongName = $this->leadAt($t['account'], CrmLead::STATUS_CONTACTED, ['source' => 'whatsapp', 'assigned_user_id' => $member->id], ['name' => 'Grace Hopper']);
        $untagged = $this->leadAt($t['account'], CrmLead::STATUS_CONTACTED, ['source' => 'whatsapp', 'assigned_user_id' => $member->id], ['name' => 'Ada Untagged']);
        foreach ([$match, $wrongStatus, $wrongSource, $unassigned, $wrongName] as $lead) {
            $this->attach($lead, $hot);
        }

        $base = self::LEADS.'?tag_id='.$hot->id;
        $ids = fn (string $q) => array_column($this->actingAs($t['user'])->getJson($base.$q)->json('data'), 'id');

        $this->assertEqualsCanonicalizing([$match->id, $wrongSource->id, $unassigned->id, $wrongName->id], $ids('&status=contacted'));
        $this->assertEqualsCanonicalizing([$match->id, $wrongStatus->id, $unassigned->id, $wrongName->id], $ids('&source=whatsapp'));
        $this->assertEqualsCanonicalizing([$match->id, $wrongStatus->id, $wrongSource->id, $wrongName->id], $ids('&assigned_user_id='.$member->id));
        $this->assertSame([$unassigned->id], $ids('&assigned_user_id=none'));
        $this->assertEqualsCanonicalizing([$match->id, $wrongStatus->id, $wrongSource->id, $unassigned->id], $ids('&search=Ada'));
        $this->assertSame([$match->id], $ids('&status=contacted&source=whatsapp&assigned_user_id='.$member->id.'&search=Ada'));
        $this->assertNotContains($untagged->id, $ids('&search=Ada'));
    }

    public function test_tag_filtered_pagination_is_exact(): void
    {
        $t = $this->tenant();
        $hot = $this->tag($t['account'], 'Hot');
        $vip = $this->tag($t['account'], 'VIP');
        $tagged = [];
        for ($i = 0; $i < 5; $i++) {
            $lead = $this->leadAt($t['account']);
            $this->attach($lead, $hot, $vip); // two tags each: a join would duplicate rows
            $tagged[] = $lead->id;
        }
        $this->leadAt($t['account']);

        $seen = [];
        foreach ([1, 2, 3] as $page) {
            $response = $this->actingAs($t['user'])->getJson(self::LEADS.'?tag_id='.$hot->id.'&per_page=2&page='.$page);
            $response->assertJsonPath('total', 5)->assertJsonPath('last_page', 3);
            $seen = array_merge($seen, array_column($response->json('data'), 'id'));
        }

        $this->assertSame(count($seen), count(array_unique($seen)));
        $this->assertEqualsCanonicalizing($tagged, $seen);
    }

    public function test_the_pipeline_filters_by_tag_and_columns_stay_status_based(): void
    {
        $t = $this->tenant();
        $hot = $this->tag($t['account'], 'Hot');
        $newHot = $this->leadAt($t['account'], CrmLead::STATUS_NEW);
        $contactedHot = $this->leadAt($t['account'], CrmLead::STATUS_CONTACTED);
        $contactedHot2 = $this->leadAt($t['account'], CrmLead::STATUS_CONTACTED);
        $this->leadAt($t['account'], CrmLead::STATUS_CONVERTED); // untagged
        $this->attach($newHot, $hot);
        $this->attach($contactedHot, $hot);
        $this->attach($contactedHot2, $hot);

        $json = $this->actingAs($t['user'])->getJson(self::PIPELINE.'?tag_id='.$hot->id.'&per_page=1')->json();
        $columns = $this->columns($json);

        $this->assertSame(['new', 'contacted', 'converted', 'not_converted'], array_column($json['data']['pipeline'], 'status'));
        $this->assertSame(1, $columns['new']['total']);
        $this->assertSame(2, $columns['contacted']['total']);
        $this->assertSame(0, $columns['converted']['total']);
        $this->assertSame(2, $columns['contacted']['last_page']);
        $this->assertTrue($columns['contacted']['has_more']);
        $this->assertSame([$newHot->id], array_column($columns['new']['leads'], 'id'));

        $narrow = $this->columns($this->actingAs($t['user'])->getJson(self::PIPELINE.'?tag_id='.$hot->id.'&status=contacted')->json());
        $this->assertSame(['contacted'], array_keys($narrow));
        $this->assertEqualsCanonicalizing([$contactedHot->id, $contactedHot2->id], array_column($narrow['contacted']['leads'], 'id'));
    }

    public function test_the_pipeline_without_a_tag_filter_is_unchanged(): void
    {
        $t = $this->tenant();
        $tagged = $this->leadAt($t['account']);
        $this->attach($tagged, $this->tag($t['account'], 'Hot'));
        $plain = $this->leadAt($t['account']);

        $columns = $this->columns($this->actingAs($t['user'])->getJson(self::PIPELINE)->json());

        $this->assertSame(2, $columns['new']['total']);
        $this->assertEqualsCanonicalizing([$tagged->id, $plain->id], array_column($columns['new']['leads'], 'id'));
    }

    public function test_the_tag_filter_never_reaches_another_tenants_leads(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $mine = $this->tag($t['account'], 'Hot');
        $theirs = $this->tag($other['account'], 'Hot');
        $myLead = $this->leadAt($t['account']);
        $theirLead = $this->leadAt($other['account']);
        $this->attach($myLead, $mine);
        $this->attach($theirLead, $theirs);

        $ids = array_column($this->actingAs($t['user'])->getJson(self::LEADS.'?tag_id='.$mine->id.'&account_id='.$other['account']->id)->json('data'), 'id');

        $this->assertSame([$myLead->id], $ids);
    }

    // =================================================================
    // Delete / merge interactions
    // =================================================================

    public function test_deleting_a_tag_removes_only_its_assignments(): void
    {
        $t = $this->tenant();
        $capture = Lead::create([
            'account_id' => $t['account']->id,
            'provider' => 'meta',
            'provider_lead_id' => 'meta:'.uniqid(),
            'lead_phone' => '919876543210',
            'lead_name' => 'Ada',
        ]);
        $captured = app(CaptureLeadLinker::class)->link($capture);
        app(CrmLeadService::class)->changeStatus($captured, CrmLead::STATUS_CONVERTED);
        $other = $this->leadAt($t['account'], CrmLead::STATUS_CONTACTED);
        $hot = $this->tag($t['account'], 'Hot');
        $vip = $this->tag($t['account'], 'VIP');
        $this->attach($captured, $hot, $vip);
        $this->attach($other, $hot);

        $leadsBefore = CrmLead::orderBy('id')->get()->map->getAttributes()->all();
        $contactsBefore = Contact::orderBy('id')->get()->map->getAttributes()->all();
        $captureBefore = $capture->fresh()->getAttributes();

        $this->actingAs($t['user'])->deleteJson(self::TAGS.'/'.$hot->id)->assertOk();

        $this->assertSame(0, CrmLeadTag::where('crm_tag_id', $hot->id)->count());
        $this->assertSame([$vip->id], $this->tagIdsOf($captured));
        $this->assertSame([], $this->tagIdsOf($other));
        $this->assertSame($leadsBefore, CrmLead::orderBy('id')->get()->map->getAttributes()->all(), 'Leads (and their statuses) must be untouched.');
        $this->assertSame($contactsBefore, Contact::orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame($captureBefore, $capture->fresh()->getAttributes());
    }

    public function test_deleting_a_lead_leaves_no_orphaned_assignments(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account']);
        $keep = $this->leadAt($t['account']);
        $hot = $this->tag($t['account'], 'Hot');
        $this->attach($lead, $hot);
        $this->attach($keep, $hot);

        $this->actingAs($t['user'])->deleteJson(self::LEADS.'/'.$lead->id)->assertOk();

        $this->assertSame(0, DB::table('crm_lead_tags')->where('crm_lead_id', $lead->id)->count());
        $this->assertSame(0, DB::table('crm_lead_tags')->whereNotIn('crm_lead_id', CrmLead::pluck('id'))->count());
        $this->assertNotNull(CrmTag::find($hot->id), 'Deleting a lead must not delete the tag.');
        $this->assertSame([$hot->id], $this->tagIdsOf($keep));
    }

    public function test_deleting_an_account_cascades_its_tags_and_assignments(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $lead = $this->leadAt($t['account']);
        $this->attach($lead, $this->tag($t['account'], 'Hot'));
        $theirLead = $this->leadAt($other['account']);
        $theirTag = $this->tag($other['account'], 'Hot');
        $this->attach($theirLead, $theirTag);

        DB::table('users')->where('account_id', $t['account']->id)->delete();
        DB::table('accounts')->where('id', $t['account']->id)->delete();

        $this->assertSame(0, DB::table('crm_tags')->where('account_id', $t['account']->id)->count());
        $this->assertSame(0, DB::table('crm_lead_tags')->where('account_id', $t['account']->id)->count());
        $this->assertSame([$theirTag->id], $this->tagIdsOf($theirLead), 'Another tenant must be untouched.');
    }

    public function test_a_contact_merge_keeps_every_leads_tags_valid_and_unduplicated(): void
    {
        $t = $this->tenant();
        $source = Contact::factory()->forAccount($t['account'])->create();
        $target = Contact::factory()->forAccount($t['account'])->create();
        $hot = $this->tag($t['account'], 'Hot');
        $vip = $this->tag($t['account'], 'VIP');

        $sourceLead = CrmLead::factory()->forContact($source)->create();
        $targetLead = CrmLead::factory()->forContact($target)->create();
        $this->attach($sourceLead, $hot, $vip);
        $this->attach($targetLead, $hot);

        $this->actingAs($t['user'])
            ->postJson(self::CONTACTS.'/'.$source->id.'/merge/'.$target->id, ['confirm_phone_discard' => true])
            ->assertOk();

        $this->assertSame($target->id, $sourceLead->fresh()->contact_id);
        $this->assertSame([$hot->id, $vip->id], $this->tagIdsOf($sourceLead));
        $this->assertSame([$hot->id], $this->tagIdsOf($targetLead));

        // Each (lead, tag) pair exists at most once, and every row is in-tenant.
        $pairs = DB::table('crm_lead_tags')->get()->map(fn ($r) => $r->crm_lead_id.':'.$r->crm_tag_id)->all();
        $this->assertSame(count($pairs), count(array_unique($pairs)));
        $this->assertSame(0, DB::table('crm_lead_tags')->where('account_id', '!=', $t['account']->id)->count());

        $leads = collect($this->actingAs($t['user'])->getJson(self::CONTACTS.'/'.$target->id.'/leads')->json('data'))->keyBy('id');
        $this->assertSame(['Hot', 'VIP'], array_column($leads[$sourceLead->id]['tags'], 'name'));
        $this->actingAs($t['user'])->getJson(self::TAGS.'/'.$hot->id)->assertJsonPath('data.lead_count', 2);
    }

    public function test_reassigning_a_leads_contact_keeps_its_tags(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account']);
        $newContact = Contact::factory()->forAccount($t['account'])->create();
        $hot = $this->tag($t['account'], 'Hot');
        $this->attach($lead, $hot);

        $this->actingAs($t['user'])->patchJson(self::LEADS.'/'.$lead->id.'/contact', ['contact_id' => $newContact->id])
            ->assertOk()->assertJsonPath('data.tags.0.id', $hot->id);
    }

    // =================================================================
    // Security
    // =================================================================

    /** @return list<array{0: string, 1: string}> */
    private function endpoints(CrmLead $lead, CrmTag $tag): array
    {
        return [
            ['getJson', self::TAGS],
            ['postJson', self::TAGS],
            ['getJson', self::TAGS.'/'.$tag->id],
            ['putJson', self::TAGS.'/'.$tag->id],
            ['patchJson', self::TAGS.'/'.$tag->id],
            ['deleteJson', self::TAGS.'/'.$tag->id],
            ['postJson', self::LEADS.'/'.$lead->id.'/tags/'.$tag->id],
            ['deleteJson', self::LEADS.'/'.$lead->id.'/tags/'.$tag->id],
            ['getJson', self::LEADS.'?tag_id='.$tag->id],
            ['getJson', self::PIPELINE.'?tag_id='.$tag->id],
        ];
    }

    public function test_every_tag_endpoint_requires_authentication(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account']);
        $tag = $this->tag($t['account'], 'Hot');

        foreach ($this->endpoints($lead, $tag) as [$verb, $url]) {
            $this->{$verb}($url, ['name' => 'X'])->assertStatus(401);
        }
    }

    public function test_every_tag_endpoint_requires_the_lead_crm_module(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account']);
        $tag = $this->tag($t['account'], 'Hot');
        $t['account']->forceFill(['allowed_modules' => array_values(array_diff(Account::MODULES, ['lead_crm']))])->save();

        foreach ($this->endpoints($lead, $tag) as [$verb, $url]) {
            $this->actingAs($t['user'])->{$verb}($url, ['name' => 'X'])
                ->assertStatus(403)->assertJsonPath('error_code', 'MODULE_DISABLED');
        }
    }

    public function test_every_tag_endpoint_requires_the_crm_capability(): void
    {
        $t = $this->tenant(withCrm: false);
        $lead = $this->leadAt($t['account']);
        $tag = $this->tag($t['account'], 'Hot');

        foreach ($this->endpoints($lead, $tag) as [$verb, $url]) {
            $response = $this->actingAs($t['user'])->{$verb}($url, ['name' => 'X']);
            $response->assertStatus(403)->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
            $this->assertNull($response->json('data'));
        }
        $this->assertSame('Hot', $tag->fresh()->name);
    }

    /** Role built before the first actingAs() — see the Round 2 guard-configuration note. */
    public function test_every_tag_endpoint_requires_manage_crm(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account']);
        $tag = $this->tag($t['account'], 'Hot');
        $role = Role::create(['name' => 'tags_denied']);
        $role->givePermissionTo('manage-social-leads');
        $caller = User::factory()->create(['account_id' => $t['account']->id, 'is_active' => true]);
        $caller->assignRole($role);

        foreach ($this->endpoints($lead, $tag) as [$verb, $url]) {
            $this->actingAs($caller)->{$verb}($url, ['name' => 'X'])->assertStatus(403);
        }
        $this->assertSame(0, CrmLeadTag::count());
    }

    public function test_an_expired_subscription_is_read_only_for_tags(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account']);
        $tag = $this->tag($t['account'], 'Hot');
        Subscription::where('account_id', $t['account']->id)->update(['expires_at' => now()->subDay(), 'status' => 'expired']);

        $this->actingAs($t['user'])->getJson(self::TAGS)->assertOk();
        $this->actingAs($t['user'])->postJson(self::TAGS, ['name' => 'New'])
            ->assertStatus(403)->assertJsonPath('error_code', 'SUBSCRIPTION_EXPIRED');
        $this->actingAs($t['user'])->postJson(self::LEADS.'/'.$lead->id.'/tags/'.$tag->id)
            ->assertStatus(403)->assertJsonPath('error_code', 'SUBSCRIPTION_EXPIRED');
        $this->assertSame(0, CrmLeadTag::count());
    }

    public function test_no_tag_specific_permission_or_capability_exists(): void
    {
        $this->assertSame(0, Permission::where('name', 'like', '%tag%')->count());
        $this->assertSame(0, Capability::where('slug', 'like', '%tag%')->count());
    }

    // =================================================================
    // Plan / capability
    // =================================================================

    public function test_revoking_crm_denies_tags_but_preserves_tags_and_assignments_and_regrant_restores(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account'], CrmLead::STATUS_CONTACTED);
        $hot = $this->tag($t['account'], 'Hot');
        $this->attach($lead, $hot);

        $this->revokeAllEntitlements($t['account']);

        $this->actingAs($t['user'])->getJson(self::TAGS)->assertStatus(403)->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
        $this->assertNotNull(CrmTag::find($hot->id), 'Revocation must not delete tags.');
        $this->assertSame([$hot->id], $this->tagIdsOf($lead), 'Revocation must not delete assignments.');

        $this->grantCrm($t['account']->fresh());

        $this->actingAs($t['user'])->getJson(self::TAGS)->assertOk()->assertJsonPath('data.0.lead_count', 1);
        $this->actingAs($t['user'])->getJson(self::LEADS.'/'.$lead->id)
            ->assertJsonPath('data.tags', [['id' => $hot->id, 'name' => 'Hot']])
            ->assertJsonPath('data.status', 'contacted');
    }

    public function test_reconciliation_preserves_tag_data(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account']);
        $hot = $this->tag($t['account'], 'Hot');
        $this->attach($lead, $hot);

        app(PlanEntitlementReconciliationService::class)->reconcile($t['account']->fresh());

        $this->assertSame(1, CrmTag::count());
        $this->assertSame([$hot->id], $this->tagIdsOf($lead));
    }

    public function test_a_plan_still_carries_and_drops_the_crm_capability(): void
    {
        $service = app(PlanManagementService::class);

        $plan = $service->create('tags-plan', ['label' => 'T', 'price' => 1, 'duration_days' => 30], ['crm']);
        $this->assertTrue($plan->capabilities->pluck('slug')->contains('crm'));

        $removed = $service->modify($plan->fresh(), [], []);
        $this->assertContains('crm', $removed['removed']);
    }

    // =================================================================
    // Developer API
    // =================================================================

    public function test_the_developer_api_gains_no_tag_surface(): void
    {
        $t = $this->tenant();
        $tag = $this->tag($t['account'], 'Hot');
        $plain = 'sk_test_'.bin2hex(random_bytes(12));
        ApiKey::create([
            'account_id' => $t['account']->id,
            'name' => 'Key',
            'key_prefix' => substr($plain, 0, 10),
            'key_hash' => ApiKey::hashKey($plain),
        ]);

        $this->withHeader('X-API-KEY', $plain)->getJson('/api/v1/crm/tags')->assertStatus(404);
        $this->withHeader('X-API-KEY', $plain)->postJson('/api/v1/crm/tags', ['name' => 'X'])->assertStatus(404);

        // The existing lead intake still works and cannot write tags.
        $response = $this->withHeader('X-API-KEY', $plain)->postJson('/api/v1/crm/leads', [
            'phone_number' => '919811111111',
            'tag_ids' => [$tag->id],
            'tags' => ['Hot'],
        ]);
        $response->assertStatus(201);
        $this->assertArrayNotHasKey('tags', $response->json('data'));
        $this->assertSame(0, CrmLeadTag::count());
    }

    // =================================================================
    // Audit (LogsActivity)
    // =================================================================

    public function test_tags_and_assignments_use_the_existing_audit_trait(): void
    {
        $this->assertContains(LogsActivity::class, class_uses(CrmTag::class));
        $this->assertContains(LogsActivity::class, class_uses(CrmLeadTag::class));
    }

    /**
     * activity_logs rows are never written under PHPUnit (recordActivity()
     * returns early when running in console — PROJECT_STATE §7), so this
     * asserts the model events LogsActivity hooks, with the acting user
     * visible inside the listener.
     */
    public function test_tag_mutations_fire_the_audited_model_events_for_the_acting_user(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account']);

        $events = [];
        foreach (['created', 'updated', 'deleted'] as $event) {
            CrmTag::{$event}(function (CrmTag $tag) use (&$events, $event): void {
                $events[] = ['tag.'.$event, Auth::id(), $event === 'updated' ? array_values(array_diff(array_keys($tag->getChanges()), ['updated_at'])) : null];
            });
            if ($event !== 'updated') {
                CrmLeadTag::{$event}(function (CrmLeadTag $row) use (&$events, $event): void {
                    $events[] = ['assignment.'.$event, Auth::id(), [$row->crm_lead_id, $row->crm_tag_id, $row->account_id]];
                });
            }
        }

        $id = $this->actingAs($t['user'])->postJson(self::TAGS, ['name' => 'Hot'])->json('data.id');
        $this->actingAs($t['user'])->patchJson(self::TAGS.'/'.$id, ['name' => 'Warm']);
        $this->actingAs($t['user'])->postJson(self::LEADS.'/'.$lead->id.'/tags/'.$id);
        $this->actingAs($t['user'])->postJson(self::LEADS.'/'.$lead->id.'/tags/'.$id); // no-op: no event
        $this->actingAs($t['user'])->deleteJson(self::LEADS.'/'.$lead->id.'/tags/'.$id);
        $this->actingAs($t['user'])->deleteJson(self::LEADS.'/'.$lead->id.'/tags/'.$id); // no-op: no event
        $this->actingAs($t['user'])->deleteJson(self::TAGS.'/'.$id);

        $uid = $t['user']->id;
        $aid = $t['account']->id;
        $this->assertSame([
            ['tag.created', $uid, null],
            ['tag.updated', $uid, ['name', 'normalized_name']],
            ['assignment.created', $uid, [$lead->id, $id, $aid]],
            ['assignment.deleted', $uid, [$lead->id, $id, $aid]],
            ['tag.deleted', $uid, null],
        ], $events);
    }

    public function test_the_audit_diff_excludes_the_derived_normalized_name(): void
    {
        $t = $this->tenant();
        $tag = $this->tag($t['account'], 'Hot');
        $tag->name = 'Warm';
        $tag->save();

        $method = new \ReflectionMethod($tag, 'auditableAttributes');
        $diff = $method->invoke($tag, $tag->getChanges());

        $this->assertSame(['name' => 'Warm'], $diff);
    }
}
