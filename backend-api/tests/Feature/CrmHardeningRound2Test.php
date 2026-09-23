<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\ApiKey;
use App\Models\Capability;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\ContactGroupMember;
use App\Models\CrmCaptureLinkFailure;
use App\Models\CrmLead;
use App\Models\Lead;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Access\PlanManagementService;
use App\Services\Crm\CaptureLeadLinker;
use App\Services\Crm\ContactService;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 6 — CRM Hardening Round 2. One section per issue/limitation from
 * the round-2 brief.
 *
 * Assertions that can only hold on MySQL/MariaDB (composite foreign
 * keys, which SQLite cannot add through ALTER TABLE) are guarded by
 * onMySql() rather than skipped wholesale, so the surrounding
 * application-level behaviour is still exercised on both engines and the
 * database-level proof runs where the brief says it is authoritative.
 */
class CrmHardeningRound2Test extends TestCase
{
    use RefreshDatabase;

    private const LEADS = '/api/crm/leads';

    private const CONTACTS = '/api/crm/contacts';

    private const V1_LEADS = '/api/v1/crm/leads';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    private function onMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /** @return array{account: Account, user: User} */
    private function tenant(bool $withCrm = true): array
    {
        $account = Account::factory()->create();
        Subscription::factory()->create(['account_id' => $account->id]);

        if ($withCrm) {
            $this->grant($account, 'crm');
        }

        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('admin');

        return ['account' => $account->fresh(), 'user' => $user];
    }

    private function grant(Account $account, string $slug): void
    {
        $capability = Capability::where('slug', $slug)->firstOrFail();

        AccountEntitlement::firstOrCreate(
            ['account_id' => $account->id, 'capability_id' => $capability->id],
            ['source' => 'manual_grant', 'granted_by_account_id' => null],
        );
    }

    private function member(Account $account, bool $active = true, bool $crm = true): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => $active]);

        if ($crm) {
            $user->givePermissionTo('manage-crm');
        }

        return $user;
    }

    private function captureLead(Account $account, string $phone, string $provider = 'meta', ?string $name = null, ?string $email = null): Lead
    {
        return Lead::create([
            'account_id' => $account->id,
            'provider' => $provider,
            'provider_lead_id' => $provider.':'.uniqid(),
            'lead_name' => $name,
            'lead_phone' => $phone,
            'lead_email' => $email,
        ]);
    }

    /** @return array{key: string, account: Account} */
    private function apiKeyFor(Account $account): array
    {
        $plain = 'sk_test_'.bin2hex(random_bytes(12));

        ApiKey::create([
            'account_id' => $account->id,
            'name' => 'Test key',
            'key_prefix' => substr($plain, 0, 10),
            'key_hash' => ApiKey::hashKey($plain),
        ]);

        return ['key' => $plain, 'account' => $account];
    }

    // =================================================================
    // ISSUE 1 — Public Developer API, source = api
    // =================================================================

    public function test_the_public_api_creates_a_crm_lead_forced_to_the_api_source(): void
    {
        $t = $this->tenant();
        ['key' => $key] = $this->apiKeyFor($t['account']);

        $response = $this->withHeader('X-API-KEY', $key)->postJson(self::V1_LEADS, [
            'phone_number' => '+91 98765 43210',
            'name' => 'Ada',
            'email' => 'ada@example.com',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.source', CrmLead::SOURCE_API);
        $response->assertJsonPath('data.status', CrmLead::STATUS_NEW);
        $response->assertJsonPath('data.contact.phone_number', '919876543210');
        $response->assertJsonPath('data.contact.email', 'ada@example.com');

        $this->assertDatabaseHas('crm_leads', [
            'id' => $response->json('data.id'),
            'account_id' => $t['account']->id,
            'source' => CrmLead::SOURCE_API,
        ]);
    }

    public function test_the_public_api_ignores_a_caller_supplied_source(): void
    {
        $t = $this->tenant();
        ['key' => $key] = $this->apiKeyFor($t['account']);

        $response = $this->withHeader('X-API-KEY', $key)->postJson(self::V1_LEADS, [
            'phone_number' => '9876543210',
            'source' => CrmLead::SOURCE_META_AD,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.source', CrmLead::SOURCE_API);
    }

    public function test_the_public_api_ignores_a_caller_supplied_account_and_assignee(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        ['key' => $key] = $this->apiKeyFor($t['account']);

        $response = $this->withHeader('X-API-KEY', $key)->postJson(self::V1_LEADS, [
            'phone_number' => '9876543210',
            'account_id' => $other['account']->id,
            'assigned_user_id' => $other['user']->id,
        ]);

        $response->assertStatus(201);

        $lead = CrmLead::findOrFail($response->json('data.id'));
        $this->assertSame($t['account']->id, $lead->account_id, 'The API key account must be authoritative.');
        $this->assertNull($lead->assigned_user_id);
    }

    public function test_an_api_key_cannot_create_a_lead_in_another_account(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        ['key' => $keyA] = $this->apiKeyFor($a['account']);

        $this->withHeader('X-API-KEY', $keyA)
            ->postJson(self::V1_LEADS, ['phone_number' => '9876543210'])
            ->assertStatus(201);

        $this->assertSame(0, CrmLead::query()->forAccount($b['account']->id)->count());
        $this->assertSame(1, CrmLead::query()->forAccount($a['account']->id)->count());
    }

    public function test_the_public_api_denies_an_account_without_the_crm_capability(): void
    {
        $t = $this->tenant(withCrm: false);
        ['key' => $key] = $this->apiKeyFor($t['account']);

        $this->withHeader('X-API-KEY', $key)
            ->postJson(self::V1_LEADS, ['phone_number' => '9876543210'])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');

        $this->assertDatabaseCount('crm_leads', 0);
    }

    public function test_the_public_api_keeps_the_existing_api_key_auth_behaviour(): void
    {
        $this->postJson(self::V1_LEADS, ['phone_number' => '9876543210'])->assertStatus(401);

        $this->withHeader('X-API-KEY', 'nonsense')
            ->postJson(self::V1_LEADS, ['phone_number' => '9876543210'])
            ->assertStatus(401);
    }

    public function test_a_revoked_api_key_is_still_refused(): void
    {
        $t = $this->tenant();
        ['key' => $key] = $this->apiKeyFor($t['account']);
        ApiKey::query()->update(['revoked_at' => now()]);

        $this->withHeader('X-API-KEY', $key)
            ->postJson(self::V1_LEADS, ['phone_number' => '9876543210'])
            ->assertStatus(401);
    }

    public function test_the_public_api_validates_its_input(): void
    {
        $t = $this->tenant();
        ['key' => $key] = $this->apiKeyFor($t['account']);

        $this->withHeader('X-API-KEY', $key)
            ->postJson(self::V1_LEADS, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone_number']);

        $this->withHeader('X-API-KEY', $key)
            ->postJson(self::V1_LEADS, ['phone_number' => '9876543210', 'status' => 'won'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    /** Idempotency-Key is inherited from the /v1 group and must still work. */
    public function test_the_public_api_honours_idempotency(): void
    {
        $t = $this->tenant();
        ['key' => $key] = $this->apiKeyFor($t['account']);

        $headers = ['X-API-KEY' => $key, 'Idempotency-Key' => 'crm-lead-abc-123'];

        $first = $this->withHeaders($headers)->postJson(self::V1_LEADS, ['phone_number' => '9876543210']);
        $second = $this->withHeaders($headers)->postJson(self::V1_LEADS, ['phone_number' => '9876543210']);

        $first->assertStatus(201);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('crm_leads', 1);
    }

    public function test_ordinary_outbound_message_endpoints_create_no_crm_lead(): void
    {
        $t = $this->tenant();
        ['key' => $key] = $this->apiKeyFor($t['account']);

        $this->withHeader('X-API-KEY', $key)->postJson('/api/v1/messages/send-payment-alert', [
            'recipient_phone' => '9876543210',
            'customer_name' => 'Ada',
            'amount' => 10.5,
            'payment_ref' => 'REF-1',
        ]);

        $this->assertDatabaseCount('crm_leads', 0);
        $this->assertDatabaseCount('contacts', 0);
    }

    // =================================================================
    // LIMITATION 1 — composite tenant FK for the capture link
    // =================================================================

    public function test_the_capture_link_lives_on_crm_leads(): void
    {
        $this->assertTrue(Schema::hasColumn('crm_leads', 'capture_lead_id'));
        $this->assertFalse(Schema::hasColumn('leads', 'crm_lead_id'));
    }

    public function test_the_database_itself_refuses_a_cross_tenant_capture_link(): void
    {
        if (! $this->onMySql()) {
            $this->markTestSkipped('Composite foreign keys cannot be added through ALTER TABLE on SQLite; MySQL/MariaDB is authoritative for this guarantee.');
        }

        $a = Account::factory()->create();
        $b = Account::factory()->create();
        $foreignCapture = $this->captureLead($b, '9876543210');
        $contact = Contact::factory()->forAccount($a)->create();

        $this->expectException(QueryException::class);

        // Straight through the query builder: every Eloquent event is
        // bypassed, so only the database can refuse this.
        DB::table('crm_leads')->insert([
            'account_id' => $a->id,
            'contact_id' => $contact->id,
            'capture_lead_id' => $foreignCapture->id,
            'status' => CrmLead::STATUS_NEW,
            'source' => CrmLead::SOURCE_META_AD,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_one_capture_row_can_only_be_promoted_once(): void
    {
        $account = Account::factory()->create();
        $lead = $this->captureLead($account, '9876543210');
        app(CaptureLeadLinker::class)->link($lead);

        $this->expectException(QueryException::class);

        DB::table('crm_leads')->insert([
            'account_id' => $account->id,
            'contact_id' => Contact::factory()->forAccount($account)->create()->id,
            'capture_lead_id' => $lead->id,
            'status' => CrmLead::STATUS_NEW,
            'source' => CrmLead::SOURCE_META_AD,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // =================================================================
    // LIMITATION 2 — contact group membership tenant integrity
    // =================================================================

    public function test_a_membership_carries_its_groups_account(): void
    {
        $t = $this->tenant();
        $group = ContactGroup::create(['account_id' => $t['account']->id, 'name' => 'G', 'group_code' => 'G1', 'is_default' => false]);

        $member = ContactGroupMember::create(['group_id' => $group->id, 'phone_number' => '919876543210']);

        $this->assertSame($t['account']->id, $member->fresh()->account_id);
    }

    public function test_the_database_itself_refuses_a_cross_tenant_membership_contact(): void
    {
        if (! $this->onMySql()) {
            $this->markTestSkipped('Composite foreign keys cannot be added through ALTER TABLE on SQLite; MySQL/MariaDB is authoritative for this guarantee.');
        }

        $a = Account::factory()->create();
        $b = Account::factory()->create();
        $group = ContactGroup::create(['account_id' => $a->id, 'name' => 'G', 'group_code' => 'G2', 'is_default' => false]);
        $foreignContact = Contact::factory()->forAccount($b)->create();

        $this->expectException(QueryException::class);

        DB::table('contact_group_members')->insert([
            'group_id' => $group->id,
            'account_id' => $a->id,
            'contact_id' => $foreignContact->id,
            'phone_number' => '919876543210',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_database_itself_refuses_a_membership_claiming_the_wrong_account(): void
    {
        if (! $this->onMySql()) {
            $this->markTestSkipped('Composite foreign keys cannot be added through ALTER TABLE on SQLite; MySQL/MariaDB is authoritative for this guarantee.');
        }

        $a = Account::factory()->create();
        $b = Account::factory()->create();
        $group = ContactGroup::create(['account_id' => $a->id, 'name' => 'G', 'group_code' => 'G3', 'is_default' => false]);

        $this->expectException(QueryException::class);

        DB::table('contact_group_members')->insert([
            'group_id' => $group->id,
            'account_id' => $b->id,
            'phone_number' => '919876543210',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_group_membership_still_works_end_to_end(): void
    {
        $t = $this->tenant();
        $group = ContactGroup::create(['account_id' => $t['account']->id, 'name' => 'G', 'group_code' => 'G4', 'is_default' => false]);

        $this->actingAs($t['user'])->postJson('/api/groups/add-contacts', [
            'group_id' => $group->id,
            'contacts' => [['phone_number' => '9876543210', 'name' => 'Ada']],
        ])->assertOk();

        $member = ContactGroupMember::where('group_id', $group->id)->firstOrFail();
        $this->assertSame($t['account']->id, $member->account_id);
        $this->assertNotNull($member->contact_id);
        $this->assertSame('919876543210', $member->phone_number);
        $this->assertSame('Ada', $member->name);
    }

    // =================================================================
    // LIMITATION 3 — dynamic / custom roles
    // =================================================================

    public function test_a_custom_role_without_manage_crm_is_denied(): void
    {
        $t = $this->tenant();
        $role = Role::create(['name' => 'custom_no_crm']);
        $role->givePermissionTo('send-messages');

        $user = User::factory()->create(['account_id' => $t['account']->id, 'is_active' => true]);
        $user->assignRole($role);

        $this->actingAs($user)->getJson(self::LEADS)->assertStatus(403);
        $this->actingAs($user)->getJson(self::CONTACTS)->assertStatus(403);
    }

    public function test_a_custom_role_with_manage_crm_is_allowed(): void
    {
        $t = $this->tenant();
        $role = Role::create(['name' => 'custom_with_crm']);
        $role->givePermissionTo('manage-crm');

        $user = User::factory()->create(['account_id' => $t['account']->id, 'is_active' => true]);
        $user->assignRole($role);

        $this->actingAs($user)->getJson(self::LEADS)->assertOk();
        $this->actingAs($user)->getJson(self::CONTACTS)->assertOk();
    }

    /**
     * Issue 4 asks that a Super Admin can assign `manage-crm` through
     * the EXISTING role-management flow. What that flow needs from this
     * hardening pass is that the permission is in the catalog, because
     * RoleController::store()/update() validate every submitted
     * permission with Rule::exists('permissions', 'name') and then
     * syncPermissions() it. This asserts exactly that, plus that the
     * assignment itself takes effect and opens the CRM.
     *
     * [Pre-existing defect, disclosed, NOT fixed here]: POST /api/roles
     * currently returns 500 for EVERY permission — verified against
     * `manage-social-leads`, which predates this phase entirely — because
     * all permission rows carry guard_name 'web' (config/auth.php's
     * default) while a Sanctum-authenticated request resolves the guard
     * as 'sanctum', so Spatie's findByName() throws
     * PermissionDoesNotExist. That is an unrelated, long-standing
     * guard-configuration bug in the role-management endpoint, not
     * something this task introduced or should silently repair inside a
     * CRM hardening pass. It is reported in Remaining Limitations.
     */
    public function test_manage_crm_is_in_the_catalog_and_assignable_to_a_role(): void
    {
        $this->assertSame(1, DB::table('permissions')->where('name', 'manage-crm')->count());

        $t = $this->tenant();
        $role = Role::create(['name' => 'Sales Desk']);
        $role->syncPermissions(['manage-crm']);

        $this->assertTrue(Role::findByName('Sales Desk')->hasPermissionTo('manage-crm'));

        $user = User::factory()->create(['account_id' => $t['account']->id, 'is_active' => true]);
        $user->assignRole($role);

        $this->actingAs($user)->getJson(self::LEADS)->assertOk();
    }

    public function test_reseeding_does_not_disturb_the_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(1, DB::table('permissions')->where('name', 'manage-crm')->count());
        $this->assertTrue(Role::findByName('admin')->hasPermissionTo('manage-crm'));
        // Unrelated permissions are untouched.
        $this->assertTrue(Role::findByName('admin')->hasPermissionTo('manage-social-leads'));
        $this->assertFalse(Role::findByName('user')->hasPermissionTo('manage-crm'));
    }

    // =================================================================
    // LIMITATION 4 — search
    // =================================================================

    public function test_the_name_filter_is_a_prefix_match(): void
    {
        $t = $this->tenant();
        $ada = Contact::factory()->forAccount($t['account'])->create(['name' => 'Ada Lovelace', 'phone_number' => '919000000001']);
        Contact::factory()->forAccount($t['account'])->create(['name' => 'Grace Ada', 'phone_number' => '919000000002']);

        $response = $this->actingAs($t['user'])->getJson(self::CONTACTS.'?name=Ada');

        $response->assertOk();
        $this->assertSame([$ada->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_free_text_search_still_matches_a_substring(): void
    {
        $t = $this->tenant();
        $grace = Contact::factory()->forAccount($t['account'])->create(['name' => 'Grace Ada', 'phone_number' => '919000000003']);

        $response = $this->actingAs($t['user'])->getJson(self::CONTACTS.'?search=Ada');

        $response->assertOk();
        $this->assertContains($grace->id, collect($response->json('data'))->pluck('id')->all());
    }

    public function test_free_text_search_normalizes_a_pasted_phone_number(): void
    {
        $t = $this->tenant();
        $contact = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919876543210', 'name' => 'Ada']);

        $response = $this->actingAs($t['user'])->getJson(self::CONTACTS.'?search='.urlencode('+91 98765 43210'));

        $response->assertOk();
        $this->assertSame([$contact->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_the_search_index_exists(): void
    {
        $this->assertTrue(Schema::hasColumn('contacts', 'name'));

        if (! $this->onMySql()) {
            return;
        }

        $indexes = collect(DB::select('SHOW INDEX FROM contacts'))->pluck('Key_name')->unique();
        $this->assertContains('contacts_account_id_name_index', $indexes->all());
        $this->assertContains('contacts_account_id_phone_number_unique', $indexes->all());
    }

    // =================================================================
    // LIMITATION 5 — capture link failures are observable
    // =================================================================

    public function test_a_successful_link_records_no_failure(): void
    {
        $account = Account::factory()->create();

        app(CaptureLeadLinker::class)->link($this->captureLead($account, '9876543210'));

        $this->assertDatabaseCount('crm_capture_link_failures', 0);
    }

    public function test_an_unlinkable_capture_is_recorded_and_the_capture_survives(): void
    {
        $account = Account::factory()->create();
        // Task 10 — linkQuietly() now promotes only for a CRM-entitled account.
        $this->grant($account, 'crm');
        $lead = $this->captureLead($account, '');
        $lead->forceFill(['lead_phone' => null])->save();

        $this->assertNull(app(CaptureLeadLinker::class)->linkQuietly($lead));

        $failure = CrmCaptureLinkFailure::query()->where('lead_id', $lead->id)->firstOrFail();
        $this->assertSame(CrmCaptureLinkFailure::REASON_UNRESOLVABLE_PHONE, $failure->reason);
        $this->assertSame($account->id, $failure->account_id);
        $this->assertSame($lead->provider, $failure->provider);
        $this->assertSame(1, $failure->attempts);
        $this->assertNull($failure->resolved_at);

        // The capture record is untouched.
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'lead_phone' => null]);
    }

    public function test_repeated_failures_update_one_row_rather_than_piling_up(): void
    {
        $account = Account::factory()->create();
        $lead = $this->captureLead($account, '');
        $lead->forceFill(['lead_phone' => null])->save();
        $linker = app(CaptureLeadLinker::class);

        $linker->linkQuietly($lead);
        $linker->linkQuietly($lead);
        $linker->linkQuietly($lead);

        $this->assertDatabaseCount('crm_capture_link_failures', 1);
        $this->assertSame(3, CrmCaptureLinkFailure::query()->where('lead_id', $lead->id)->value('attempts'));
    }

    public function test_a_retry_that_succeeds_resolves_the_failure(): void
    {
        $account = Account::factory()->create();
        $this->grant($account, 'crm'); // Task 10 — see above.
        $lead = $this->captureLead($account, '');
        $lead->forceFill(['lead_phone' => null])->save();
        $linker = app(CaptureLeadLinker::class);
        $linker->linkQuietly($lead);

        // The operator corrects the capture, then retries.
        $lead->forceFill(['lead_phone' => '919876543210'])->save();
        $failure = CrmCaptureLinkFailure::query()->where('lead_id', $lead->id)->firstOrFail();

        $crmLead = $linker->retry($failure);

        $this->assertNotNull($crmLead);
        $this->assertNotNull($failure->fresh()->resolved_at);
        $this->assertSame($crmLead->id, $failure->fresh()->resolved_crm_lead_id);
    }

    public function test_a_retry_is_idempotent(): void
    {
        $account = Account::factory()->create();
        $this->grant($account, 'crm'); // Task 10 — see above.
        $lead = $this->captureLead($account, '');
        $lead->forceFill(['lead_phone' => null])->save();
        $linker = app(CaptureLeadLinker::class);
        $linker->linkQuietly($lead);
        $lead->forceFill(['lead_phone' => '919876543210'])->save();
        $failure = CrmCaptureLinkFailure::query()->where('lead_id', $lead->id)->firstOrFail();

        $first = $linker->retry($failure);
        $second = $linker->retry($failure->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('crm_leads', 1);
        $this->assertDatabaseCount('contacts', 1);
    }

    public function test_a_failure_record_carries_no_payload(): void
    {
        $account = Account::factory()->create();
        $lead = $this->captureLead($account, '');
        $lead->forceFill(['lead_phone' => null, 'raw_field_data' => ['secret' => 'PAGE_TOKEN_XYZ']])->save();

        app(CaptureLeadLinker::class)->linkQuietly($lead);

        $row = DB::table('crm_capture_link_failures')->where('lead_id', $lead->id)->first();
        $this->assertStringNotContainsString('PAGE_TOKEN_XYZ', json_encode($row));
    }

    // =================================================================
    // LIMITATION 6 — contact merge
    // =================================================================

    public function test_a_merge_moves_everything_onto_the_target_and_removes_the_source(): void
    {
        $t = $this->tenant();
        $source = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000011', 'name' => 'Ada L', 'email' => 'ada@example.com']);
        $target = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000012', 'name' => null, 'email' => null]);

        $sourceLead = CrmLead::factory()->forContact($source)->create();
        $targetLead = CrmLead::factory()->forContact($target)->create();

        $group = ContactGroup::create(['account_id' => $t['account']->id, 'name' => 'G', 'group_code' => 'G5', 'is_default' => false]);
        $membership = ContactGroupMember::create([
            'group_id' => $group->id,
            'contact_id' => $source->id,
            'phone_number' => '919000000011',
        ]);

        $capture = $this->captureLead($t['account'], '919000000011');
        $capture->crmLead()->save($sourceLead);

        $response = $this->actingAs($t['user'])->postJson(
            self::CONTACTS.'/'.$source->id.'/merge/'.$target->id,
            ['confirm_phone_discard' => true],
        );

        $response->assertOk();
        $response->assertJsonPath('data.id', $target->id);

        // Everything moved; nothing was destroyed except the source.
        $this->assertDatabaseHas('crm_leads', ['id' => $sourceLead->id, 'contact_id' => $target->id]);
        $this->assertDatabaseHas('crm_leads', ['id' => $targetLead->id, 'contact_id' => $target->id]);
        $this->assertDatabaseHas('contact_group_members', ['id' => $membership->id, 'contact_id' => $target->id]);
        $this->assertDatabaseHas('leads', ['id' => $capture->id]);
        $this->assertDatabaseMissing('contacts', ['id' => $source->id]);

        // Blank target fields were filled from the source; phone was not.
        $fresh = $target->fresh();
        $this->assertSame('Ada L', $fresh->name);
        $this->assertSame('ada@example.com', $fresh->email);
        $this->assertSame('919000000012', $fresh->phone_number);

        // The capture linkage survives the move.
        $this->assertSame($target->id, $capture->fresh()->crmLead->contact_id);
    }

    public function test_a_merge_never_overwrites_curated_target_fields(): void
    {
        $t = $this->tenant();
        $source = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000021', 'name' => 'Typo', 'email' => 'typo@example.com']);
        $target = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000022', 'name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

        $this->actingAs($t['user'])
            ->postJson(self::CONTACTS.'/'.$source->id.'/merge/'.$target->id, ['confirm_phone_discard' => true])
            ->assertOk();

        $fresh = $target->fresh();
        $this->assertSame('Ada Lovelace', $fresh->name);
        $this->assertSame('ada@example.com', $fresh->email);
    }

    public function test_a_merge_requires_explicit_confirmation_of_the_discarded_phone(): void
    {
        $t = $this->tenant();
        $source = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000031']);
        $target = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000032']);

        $this->actingAs($t['user'])
            ->postJson(self::CONTACTS.'/'.$source->id.'/merge/'.$target->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['confirm_phone_discard']);

        $this->assertDatabaseHas('contacts', ['id' => $source->id]);
    }

    public function test_a_contact_cannot_be_merged_into_itself(): void
    {
        $t = $this->tenant();
        $contact = Contact::factory()->forAccount($t['account'])->create();

        $this->actingAs($t['user'])
            ->postJson(self::CONTACTS.'/'.$contact->id.'/merge/'.$contact->id, ['confirm_phone_discard' => true])
            ->assertStatus(422);

        $this->assertDatabaseHas('contacts', ['id' => $contact->id]);
    }

    public function test_a_merge_cannot_cross_tenants(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $mine = Contact::factory()->forAccount($t['account'])->create();
        $theirs = Contact::factory()->forAccount($other['account'])->create();

        $this->actingAs($t['user'])
            ->postJson(self::CONTACTS.'/'.$mine->id.'/merge/'.$theirs->id, ['confirm_phone_discard' => true])
            ->assertStatus(404);

        $this->actingAs($t['user'])
            ->postJson(self::CONTACTS.'/'.$theirs->id.'/merge/'.$mine->id, ['confirm_phone_discard' => true])
            ->assertStatus(404);

        $this->assertDatabaseHas('contacts', ['id' => $mine->id]);
        $this->assertDatabaseHas('contacts', ['id' => $theirs->id]);
    }

    public function test_a_merge_requires_the_crm_capability(): void
    {
        $t = $this->tenant(withCrm: false);
        $source = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000041']);
        $target = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000042']);

        $this->actingAs($t['user'])
            ->postJson(self::CONTACTS.'/'.$source->id.'/merge/'.$target->id, ['confirm_phone_discard' => true])
            ->assertStatus(403);

        $this->assertDatabaseHas('contacts', ['id' => $source->id]);
    }

    /** A merge that throws part-way must leave the database exactly as it was. */
    public function test_a_failed_merge_rolls_everything_back(): void
    {
        $a = Account::factory()->create();
        $b = Account::factory()->create();
        $source = Contact::factory()->forAccount($a)->create(['phone_number' => '919000000051']);
        $target = Contact::factory()->forAccount($b)->create(['phone_number' => '919000000052']);
        CrmLead::factory()->forContact($source)->create();

        try {
            app(ContactService::class)->merge($source, $target, true);
            $this->fail('A cross-tenant merge was accepted at the service layer.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertDatabaseHas('contacts', ['id' => $source->id]);
        $this->assertDatabaseHas('contacts', ['id' => $target->id]);
        $this->assertSame(1, CrmLead::query()->where('contact_id', $source->id)->count());
    }

    // =================================================================
    // LIMITATION 7 — captureLeads() semantics
    // =================================================================

    public function test_capture_leads_returns_linked_captures_only(): void
    {
        $account = Account::factory()->create();
        $linked = $this->captureLead($account, '9876543210');
        $crmLead = app(CaptureLeadLinker::class)->link($linked);
        $contact = $crmLead->contact;

        // Same person, same account, never promoted.
        $unlinked = $this->captureLead($account, '9876543210');

        // Another account entirely.
        $otherAccount = Account::factory()->create();
        $foreign = $this->captureLead($otherAccount, '9876543210');
        app(CaptureLeadLinker::class)->link($foreign);

        $ids = $contact->fresh()->captureLeads->pluck('id')->all();

        $this->assertContains($linked->id, $ids);
        $this->assertNotContains($unlinked->id, $ids, 'captureLeads() must mean linked captures, not phone matches.');
        $this->assertNotContains($foreign->id, $ids);
    }

    // =================================================================
    // LIMITATION 8 / ISSUE 10 — assignment eligibility
    // =================================================================

    public function test_an_inactive_user_cannot_receive_a_new_assignment(): void
    {
        $t = $this->tenant();
        $inactive = $this->member($t['account'], active: false);

        $this->actingAs($t['user'])
            ->postJson(self::LEADS, ['phone_number' => '9876543210', 'assigned_user_id' => $inactive->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['assigned_user_id']);
    }

    public function test_a_user_without_crm_access_cannot_receive_a_new_assignment(): void
    {
        $t = $this->tenant();
        $noCrm = $this->member($t['account'], crm: false);

        $this->actingAs($t['user'])
            ->postJson(self::LEADS, ['phone_number' => '9876543210', 'assigned_user_id' => $noCrm->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['assigned_user_id']);
    }

    public function test_an_eligible_user_can_receive_an_assignment(): void
    {
        $t = $this->tenant();
        $eligible = $this->member($t['account']);

        $this->actingAs($t['user'])
            ->postJson(self::LEADS, ['phone_number' => '9876543210', 'assigned_user_id' => $eligible->id])
            ->assertStatus(201)
            ->assertJsonPath('data.assigned_user.id', $eligible->id);
    }

    /** History is not rewritten: deactivating someone never unassigns their leads. */
    public function test_an_existing_assignment_survives_the_assignee_becoming_ineligible(): void
    {
        $t = $this->tenant();
        $member = $this->member($t['account']);
        $lead = CrmLead::factory()->assignedTo($member)->create(['account_id' => $t['account']->id]);

        $member->forceFill(['is_active' => false])->save();
        $member->revokePermissionTo('manage-crm');

        $this->assertSame($member->id, $lead->fresh()->assigned_user_id);

        // And an unrelated edit does not silently drop the assignment.
        $this->actingAs($t['user'])
            ->patchJson(self::LEADS.'/'.$lead->id, ['status' => 'contacted'])
            ->assertOk();

        $this->assertSame($member->id, $lead->fresh()->assigned_user_id);
    }

    // =================================================================
    // ISSUE 12 — status invariants
    // =================================================================

    /** @return array<string, array{string}> */
    public static function openStatusProvider(): array
    {
        return ['new' => ['new'], 'contacted' => ['contacted']];
    }

    /** @dataProvider openStatusProvider */
    public function test_an_open_status_carries_no_outcome(string $status): void
    {
        $t = $this->tenant();
        $lead = CrmLead::factory()->create(['account_id' => $t['account']->id]);

        $this->actingAs($t['user'])->patchJson(self::LEADS.'/'.$lead->id, ['status' => $status])->assertOk();

        $fresh = $lead->fresh();
        $this->assertSame($status, $fresh->status);
        $this->assertNull($fresh->converted_at);
        $this->assertNull($fresh->not_converted_at);
        $this->assertNull($fresh->not_converted_reason);
    }

    public function test_converted_carries_a_conversion_time_and_no_outcome_reason(): void
    {
        $t = $this->tenant();
        $lead = CrmLead::factory()->create(['account_id' => $t['account']->id]);

        $this->actingAs($t['user'])->patchJson(self::LEADS.'/'.$lead->id, [
            'status' => 'not_converted',
            'not_converted_reason' => 'Budget.',
        ])->assertOk();

        $this->actingAs($t['user'])->patchJson(self::LEADS.'/'.$lead->id, ['status' => 'converted'])->assertOk();

        $fresh = $lead->fresh();
        $this->assertNotNull($fresh->converted_at);
        $this->assertNull($fresh->not_converted_at);
        $this->assertNull($fresh->not_converted_reason, 'A converted lead cannot keep a not-converted reason.');
    }

    public function test_not_converted_carries_a_close_time_and_may_carry_a_reason(): void
    {
        $t = $this->tenant();
        $lead = CrmLead::factory()->create(['account_id' => $t['account']->id]);

        $this->actingAs($t['user'])->patchJson(self::LEADS.'/'.$lead->id, [
            'status' => 'not_converted',
            'not_converted_reason' => 'Budget not approved.',
        ])->assertOk();

        $fresh = $lead->fresh();
        $this->assertNotNull($fresh->not_converted_at);
        $this->assertNull($fresh->converted_at);
        $this->assertSame('Budget not approved.', $fresh->not_converted_reason);
    }

    /** The reason is optional — the existing product rule, since the column shipped nullable. */
    public function test_not_converted_does_not_require_a_reason(): void
    {
        $t = $this->tenant();
        $lead = CrmLead::factory()->create(['account_id' => $t['account']->id]);

        $this->actingAs($t['user'])->patchJson(self::LEADS.'/'.$lead->id, ['status' => 'not_converted'])->assertOk();

        $fresh = $lead->fresh();
        $this->assertSame('not_converted', $fresh->status);
        $this->assertNotNull($fresh->not_converted_at);
        $this->assertNull($fresh->not_converted_reason);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function impossibleStateProvider(): array
    {
        return [
            'new with a conversion time' => [['status' => 'new', 'converted_at' => '2026-01-01 00:00:00']],
            'contacted with a close time' => [['status' => 'contacted', 'not_converted_at' => '2026-01-01 00:00:00']],
            'converted with no conversion time' => [['status' => 'converted']],
            'converted with a close time' => [['status' => 'converted', 'converted_at' => '2026-01-01 00:00:00', 'not_converted_at' => '2026-01-01 00:00:00']],
            'converted with an outcome reason' => [['status' => 'converted', 'converted_at' => '2026-01-01 00:00:00', 'not_converted_reason' => 'x']],
            'not_converted with no close time' => [['status' => 'not_converted']],
            'not_converted with a conversion time' => [['status' => 'not_converted', 'not_converted_at' => '2026-01-01 00:00:00', 'converted_at' => '2026-01-01 00:00:00']],
            'open with an outcome reason' => [['status' => 'new', 'not_converted_reason' => 'x']],
        ];
    }

    /**
     * @dataProvider impossibleStateProvider
     *
     * @param array<string, mixed> $attributes
     */
    public function test_impossible_status_combinations_are_refused(array $attributes): void
    {
        $account = Account::factory()->create();
        $contact = Contact::factory()->forAccount($account)->create();

        $this->expectException(ValidationException::class);

        CrmLead::create(array_merge([
            'account_id' => $account->id,
            'contact_id' => $contact->id,
        ], $attributes));
    }

    // =================================================================
    // ISSUE 13 — contact email from capture data
    // =================================================================

    public function test_a_meta_capture_fills_a_blank_contact_email(): void
    {
        $account = Account::factory()->create();

        $crmLead = app(CaptureLeadLinker::class)->link(
            $this->captureLead($account, '9876543210', 'meta', 'Ada', 'ada@example.com'),
        );

        $this->assertSame(CrmLead::SOURCE_META_AD, $crmLead->source);
        $this->assertSame('ada@example.com', $crmLead->contact->email);
        $this->assertSame('Ada', $crmLead->contact->name);
    }

    public function test_a_journey_capture_fills_a_blank_contact_email(): void
    {
        $account = Account::factory()->create();

        $crmLead = app(CaptureLeadLinker::class)->link(
            $this->captureLead($account, '9876543210', 'whatsapp_journey', 'Grace', 'grace@example.com'),
        );

        $this->assertSame(CrmLead::SOURCE_JOURNEY, $crmLead->source);
        $this->assertSame('grace@example.com', $crmLead->contact->email);
    }

    public function test_a_capture_never_overwrites_a_curated_contact_email(): void
    {
        $account = Account::factory()->create();
        $contact = Contact::factory()->forAccount($account)->create([
            'phone_number' => '919876543210',
            'email' => 'curated@example.com',
            'name' => 'Curated Name',
        ]);

        app(CaptureLeadLinker::class)->link(
            $this->captureLead($account, '9876543210', 'meta', 'From Meta', 'meta@example.com'),
        );

        $fresh = $contact->fresh();
        $this->assertSame('curated@example.com', $fresh->email);
        $this->assertSame('Curated Name', $fresh->name);
    }

    public function test_the_capture_row_itself_is_never_modified_by_promotion(): void
    {
        $account = Account::factory()->create();
        $lead = $this->captureLead($account, '9876543210', 'meta', 'Ada', 'ada@example.com');
        $before = $lead->fresh()->toArray();

        app(CaptureLeadLinker::class)->link($lead);

        $this->assertSame($before, $lead->fresh()->toArray());
    }

    // =================================================================
    // ISSUE 15 — plan / capability management
    // =================================================================

    public function test_a_plan_can_be_created_including_crm(): void
    {
        $plan = app(PlanManagementService::class)->create('crm-plan', [
            'label' => 'CRM Plan',
            'price' => 999,
            'duration_days' => 30,
        ], ['crm']);

        $this->assertTrue($plan->capabilities->pluck('slug')->contains('crm'));
    }

    public function test_crm_can_be_added_to_and_removed_from_a_plan(): void
    {
        $service = app(PlanManagementService::class);
        $plan = $service->create('crm-plan-2', ['label' => 'P', 'price' => 1, 'duration_days' => 30], []);

        $added = $service->modify($plan->fresh(), [], ['crm']);
        $this->assertContains('crm', $added['added']);
        $this->assertTrue($added['bundle_changed']);

        $removed = $service->modify($added['plan']->fresh(), [], []);
        $this->assertContains('crm', $removed['removed']);
        $this->assertFalse($removed['plan']->capabilities->pluck('slug')->contains('crm'));
    }

    public function test_revoking_the_crm_entitlement_denies_access_immediately(): void
    {
        $t = $this->tenant();
        $this->actingAs($t['user'])->getJson(self::LEADS)->assertOk();

        AccountEntitlement::query()
            ->where('account_id', $t['account']->id)
            ->whereHas('capability', fn ($q) => $q->where('slug', 'crm'))
            ->get()
            ->each(fn (AccountEntitlement $e) => $e->forceFill([
                'revoked_at' => now(),
                'revoked_reason' => AccountEntitlement::REVOKED_PLAN_DOWNGRADE,
            ])->save());

        // No TTL, no stale window — see EnsureCapabilityMiddleware's
        // "not cached, and that is a decision" docblock.
        $this->actingAs($t['user'])->getJson(self::LEADS)->assertStatus(403);
        $this->actingAs($t['user'])->getJson(self::CONTACTS)->assertStatus(403);
    }

    public function test_revoking_the_crm_entitlement_also_closes_the_public_api(): void
    {
        $t = $this->tenant();
        ['key' => $key] = $this->apiKeyFor($t['account']);

        $this->withHeader('X-API-KEY', $key)
            ->postJson(self::V1_LEADS, ['phone_number' => '9876543210'])
            ->assertStatus(201);

        AccountEntitlement::query()->where('account_id', $t['account']->id)->get()
            ->each(fn (AccountEntitlement $e) => $e->forceFill(['revoked_at' => now(), 'revoked_reason' => 'manual'])->save());

        $this->withHeader('X-API-KEY', $key)
            ->postJson(self::V1_LEADS, ['phone_number' => '9000000099'])
            ->assertStatus(403);
    }

    public function test_the_crm_capability_is_seeded(): void
    {
        $this->assertDatabaseHas('capabilities', ['slug' => 'crm'], null);
        $this->assertTrue(Plan::query()->exists());
    }

    // =================================================================
    // ISSUE 9 — backfill idempotence
    // =================================================================

    public function test_relinking_everything_a_second_time_changes_nothing(): void
    {
        $account = Account::factory()->create();
        $linker = app(CaptureLeadLinker::class);

        $linker->link($this->captureLead($account, '9876543210', 'meta', 'Ada', 'ada@example.com'));
        $linker->link($this->captureLead($account, '+91 98765 43210', 'whatsapp_journey'));
        $linker->link($this->captureLead($account, '919000000077', 'whatsapp_ctwa'));

        $contacts = Contact::count();
        $crmLeads = CrmLead::count();

        foreach (Lead::all() as $lead) {
            $linker->linkQuietly($lead);
        }

        $this->assertSame($contacts, Contact::count());
        $this->assertSame($crmLeads, CrmLead::count());
        // The two phone formats were one person all along.
        $this->assertSame(2, $contacts);
    }
}
