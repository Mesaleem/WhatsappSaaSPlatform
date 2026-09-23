<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\ContactGroupMember;
use App\Models\CrmLead;
use App\Models\Lead;
use App\Models\SocialAccount;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Crm\CaptureLeadLinker;
use App\Services\Crm\ContactGroupContactLinker;
use App\Services\Leads\MetaLeadWebhookHandler;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 6 — CRM Hardening. One suite per numbered issue from the
 * hardening brief, so a future reader can trace any requirement to the
 * test that holds it.
 *
 * Existing CRM coverage (CrmLeadDomainTest, CrmContactResolutionTest,
 * CrmLeadApiTest) is deliberately not duplicated or modified here.
 */
class CrmHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const LEADS = '/api/crm/leads';

    private const CONTACTS = '/api/crm/contacts';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    /**
     * @return array{account: Account, user: User}
     */
    private function tenant(bool $withCrm = true, string $role = 'admin'): array
    {
        $account = Account::factory()->create();
        Subscription::factory()->create(['account_id' => $account->id]);

        if ($withCrm) {
            $this->grant($account, 'crm');
        }

        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole($role);

        return ['account' => $account->fresh(), 'user' => $user];
    }

    /**
     * [Round 2 amendment] An ELIGIBLE CRM assignee: active, in this
     * account, and holding manage-crm. CrmLead now refuses an
     * assignment to anyone who cannot reach the CRM (Limitation 8), so
     * a bare account member is deliberately no longer valid.
     */
    private function member(Account $account): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->givePermissionTo('manage-crm');

        return $user;
    }

    private function grant(Account $account, string $slug): void
    {
        $capability = Capability::where('slug', $slug)->firstOrFail();

        AccountEntitlement::firstOrCreate(
            ['account_id' => $account->id, 'capability_id' => $capability->id],
            ['source' => 'manual_grant', 'granted_by_account_id' => null],
        );
    }

    // =================================================================
    // ISSUE 1 — dedicated manage-crm permission
    // =================================================================

    public function test_manage_crm_is_seeded_onto_exactly_the_roles_that_already_reached_the_crm(): void
    {
        $this->assertTrue(Permission::where('name', 'manage-crm')->exists());

        foreach (['super_admin', 'admin', 'social_marketer'] as $roleName) {
            $this->assertTrue(
                Role::findByName($roleName)->hasPermissionTo('manage-crm'),
                "Role {$roleName} should hold manage-crm.",
            );
        }

        foreach (['user', 'agent'] as $roleName) {
            $this->assertFalse(
                Role::findByName($roleName)->hasPermissionTo('manage-crm'),
                "Role {$roleName} should not hold manage-crm.",
            );
        }
    }

    public function test_manage_social_leads_alone_no_longer_opens_the_crm(): void
    {
        $t = $this->tenant();
        // A bespoke role holding the OLD permission and nothing else —
        // precisely the separation Issue 1 asks for.
        $role = Role::create(['name' => 'legacy_social_only']);
        $role->givePermissionTo('manage-social-leads');

        $user = User::factory()->create(['account_id' => $t['account']->id, 'is_active' => true]);
        $user->assignRole($role);

        $this->actingAs($user)->getJson(self::LEADS)->assertStatus(403);
        $this->actingAs($user)->getJson(self::CONTACTS)->assertStatus(403);
    }

    public function test_the_existing_social_leads_api_still_runs_on_manage_social_leads(): void
    {
        $t = $this->tenant();
        $role = Role::create(['name' => 'legacy_social_only_2']);
        $role->givePermissionTo('manage-social-leads');

        $user = User::factory()->create(['account_id' => $t['account']->id, 'is_active' => true]);
        $user->assignRole($role);

        $this->actingAs($user)->getJson('/api/social/leads')->assertOk();
    }

    public function test_manage_crm_alone_does_not_open_the_social_leads_api(): void
    {
        $t = $this->tenant();
        $role = Role::create(['name' => 'crm_only']);
        $role->givePermissionTo('manage-crm');

        $user = User::factory()->create(['account_id' => $t['account']->id, 'is_active' => true]);
        $user->assignRole($role);

        $this->actingAs($user)->getJson(self::LEADS)->assertOk();
        $this->actingAs($user)->getJson('/api/social/leads')->assertStatus(403);
    }

    public function test_the_capability_gate_is_still_separate_from_the_permission(): void
    {
        // Holds manage-crm, but the plan never sold CRM.
        $t = $this->tenant(withCrm: false);

        $this->actingAs($t['user'])
            ->getJson(self::CONTACTS)
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
    }

    public function test_the_module_gate_is_still_separate_too(): void
    {
        $t = $this->tenant();
        $t['account']->forceFill([
            'allowed_modules' => array_values(array_diff(Account::MODULES, ['lead_crm'])),
        ])->save();

        $this->actingAs($t['user'])
            ->getJson(self::CONTACTS)
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'MODULE_DISABLED');
    }

    // =================================================================
    // ISSUE 2 — Contacts API
    // =================================================================

    public function test_a_contact_can_be_created_listed_shown_and_updated(): void
    {
        $t = $this->tenant();

        $created = $this->actingAs($t['user'])->postJson(self::CONTACTS, [
            'phone_number' => '+91 98765 43210',
            'name' => 'Ada',
            'email' => 'ada@example.com',
        ]);
        $created->assertStatus(201);
        $created->assertJsonPath('data.phone_number', '919876543210');
        $created->assertJsonPath('data.email', 'ada@example.com');
        $id = $created->json('data.id');

        $this->actingAs($t['user'])->getJson(self::CONTACTS)
            ->assertOk()
            ->assertJsonPath('data.0.id', $id);

        $this->actingAs($t['user'])->getJson(self::CONTACTS.'/'.$id)
            ->assertOk()
            ->assertJsonPath('data.name', 'Ada');

        $this->actingAs($t['user'])->patchJson(self::CONTACTS.'/'.$id, ['name' => 'Ada Lovelace'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Ada Lovelace');
    }

    public function test_creating_a_contact_that_already_exists_returns_it_instead_of_duplicating(): void
    {
        $t = $this->tenant();

        $first = $this->actingAs($t['user'])->postJson(self::CONTACTS, ['phone_number' => '9876543210']);
        $first->assertStatus(201);

        $second = $this->actingAs($t['user'])->postJson(self::CONTACTS, ['phone_number' => '09876543210']);
        $second->assertStatus(200);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('contacts', 1);
    }

    public function test_the_contact_list_search_is_account_scoped(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();

        Contact::factory()->forAccount($t['account'])->create(['name' => 'Ada Lovelace', 'phone_number' => '919000000001']);
        $theirs = Contact::factory()->forAccount($other['account'])->create(['name' => 'Ada Byron', 'phone_number' => '919000000002']);

        $response = $this->actingAs($t['user'])->getJson(self::CONTACTS.'?search=Ada');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertCount(1, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    public function test_the_phone_filter_matches_on_the_normalized_form(): void
    {
        $t = $this->tenant();
        $contact = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919876543210']);

        $response = $this->actingAs($t['user'])->getJson(self::CONTACTS.'?phone_number='.urlencode('+91 98765 43210'));

        $response->assertOk();
        $this->assertSame([$contact->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_a_contact_from_another_account_is_not_found(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $theirs = Contact::factory()->forAccount($other['account'])->create();

        $this->actingAs($t['user'])->getJson(self::CONTACTS.'/'.$theirs->id)->assertStatus(404);
        $this->actingAs($t['user'])->patchJson(self::CONTACTS.'/'.$theirs->id, ['name' => 'X'])->assertStatus(404);
        $this->actingAs($t['user'])->deleteJson(self::CONTACTS.'/'.$theirs->id)->assertStatus(404);
    }

    public function test_a_contacts_phone_number_can_be_changed_but_not_onto_another_contact(): void
    {
        $t = $this->tenant();
        $a = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000011']);
        $b = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000012']);

        $this->actingAs($t['user'])
            ->patchJson(self::CONTACTS.'/'.$a->id, ['phone_number' => '9000000013'])
            ->assertOk()
            ->assertJsonPath('data.phone_number', '919000000013');

        $this->actingAs($t['user'])
            ->patchJson(self::CONTACTS.'/'.$a->id, ['phone_number' => $b->phone_number])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone_number']);

        $this->assertSame('919000000013', $a->fresh()->phone_number);
    }

    public function test_the_contacts_api_ignores_an_attempt_to_change_the_account(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $contact = Contact::factory()->forAccount($t['account'])->create();

        $this->actingAs($t['user'])
            ->patchJson(self::CONTACTS.'/'.$contact->id, [
                'name' => 'Renamed',
                'account_id' => $other['account']->id,
            ])
            ->assertOk();

        $this->assertSame($t['account']->id, $contact->fresh()->account_id);
    }

    // =================================================================
    // ISSUE 3 — contact email
    // =================================================================

    public function test_email_is_optional_and_validated(): void
    {
        $t = $this->tenant();

        $this->actingAs($t['user'])
            ->postJson(self::CONTACTS, ['phone_number' => '9000000021'])
            ->assertStatus(201)
            ->assertJsonPath('data.email', null);

        $this->actingAs($t['user'])
            ->postJson(self::CONTACTS, ['phone_number' => '9000000022', 'email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_an_email_is_filled_when_blank_but_never_overwritten_by_a_capture(): void
    {
        $account = Account::factory()->create();
        $linker = app(CaptureLeadLinker::class);

        $blank = Contact::factory()->forAccount($account)->create([
            'phone_number' => '919000000031',
            'email' => null,
        ]);

        $linker->link($this->captureLead($account, '9000000031', 'meta', email: 'from-meta@example.com'));
        $this->assertSame('from-meta@example.com', $blank->fresh()->email);

        $curated = Contact::factory()->forAccount($account)->create([
            'phone_number' => '919000000032',
            'email' => 'curated@example.com',
        ]);

        $linker->link($this->captureLead($account, '9000000032', 'meta', email: 'from-meta@example.com'));
        $this->assertSame('curated@example.com', $curated->fresh()->email);
    }

    // =================================================================
    // ISSUE 4 — capture lead <-> CRM relationship
    // =================================================================

    private function captureLead(Account $account, string $phone, string $provider, ?string $name = null, ?string $email = null): Lead
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

    public function test_the_chain_reads_capture_lead_to_crm_lead_to_contact(): void
    {
        $account = Account::factory()->create();
        $lead = $this->captureLead($account, '9876543210', 'meta', 'Ada', 'ada@example.com');

        $crmLead = app(CaptureLeadLinker::class)->link($lead);

        $this->assertNotNull($crmLead);
        // [Round 2] The link now lives on crm_leads.capture_lead_id,
        // under a composite tenant-proving FK. The chain it expresses is
        // unchanged; only which table records it moved.
        $this->assertSame($lead->id, $crmLead->fresh()->capture_lead_id);
        $fresh = $lead->fresh();
        $this->assertSame($crmLead->id, $fresh->crmLead->id);
        $this->assertSame('919876543210', $fresh->crmLead->contact->phone_number);
        $this->assertSame('Ada', $fresh->crmLead->contact->name);
    }

    public function test_linking_is_idempotent(): void
    {
        $account = Account::factory()->create();
        $lead = $this->captureLead($account, '9876543210', 'meta');
        $linker = app(CaptureLeadLinker::class);

        $first = $linker->link($lead);
        $second = $linker->link($lead->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('crm_leads', 1);
        $this->assertDatabaseCount('contacts', 1);
    }

    public function test_a_capture_lead_with_no_usable_phone_is_left_unlinked(): void
    {
        $account = Account::factory()->create();
        $lead = $this->captureLead($account, '', 'meta', 'Ada', 'ada@example.com');
        $lead->forceFill(['lead_phone' => null])->save();

        $this->assertNull(app(CaptureLeadLinker::class)->link($lead));
        $this->assertNull($lead->fresh()->crmLead);
        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('crm_leads', 0);
        // [Round 2, Limitation 5] and the miss is now observable.
        $this->assertDatabaseHas('crm_capture_link_failures', [
            'lead_id' => $lead->id,
            'reason' => \App\Models\CrmCaptureLinkFailure::REASON_UNRESOLVABLE_PHONE,
        ]);
    }

    public function test_two_capture_leads_for_one_person_share_a_contact(): void
    {
        $account = Account::factory()->create();
        $linker = app(CaptureLeadLinker::class);

        $a = $linker->link($this->captureLead($account, '9876543210', 'meta'));
        $b = $linker->link($this->captureLead($account, '+91 98765 43210', 'whatsapp_journey'));

        $this->assertSame($a->contact_id, $b->contact_id);
        $this->assertNotSame($a->id, $b->id);
        $this->assertDatabaseCount('contacts', 1);
    }

    /**
     * [Round 2] The same rule, now enforced from the other side — and on
     * MySQL/MariaDB by the composite (capture_lead_id, account_id)
     * foreign key itself rather than only by this model guard.
     */
    public function test_a_crm_lead_cannot_point_at_another_accounts_capture_row(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();
        $foreignCapture = $this->captureLead($accountB, '9876543210', 'meta');
        $crmLead = CrmLead::factory()->create(['account_id' => $accountA->id]);

        try {
            $crmLead->forceFill(['capture_lead_id' => $foreignCapture->id])->save();
            $this->fail('A cross-tenant capture_lead_id was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('capture_lead_id', $e->errors());
        }

        $this->assertNull($crmLead->fresh()->capture_lead_id);
    }

    public function test_deleting_a_crm_lead_leaves_the_capture_row_intact(): void
    {
        $account = Account::factory()->create();
        $lead = $this->captureLead($account, '9876543210', 'meta');
        $crmLead = app(CaptureLeadLinker::class)->link($lead);

        $crmLead->delete();

        // [Round 2] The foreign key runs the other way now, so deleting
        // the CRM lead cannot reach the capture row at all — which is
        // exactly the semantics ON DELETE SET NULL was wanted for, and
        // which MySQL refused to express in the old direction.
        $this->assertDatabaseHas('leads', ['id' => $lead->id]);
        $this->assertNull($lead->fresh()->crmLead);
    }

    // =================================================================
    // ISSUES 5 & 6 — source wiring
    // =================================================================

    /**
     * @return array<string, array{string, string}>
     */
    public static function captureProviderProvider(): array
    {
        return [
            'meta lead ads' => ['meta', CrmLead::SOURCE_META_AD],
            'journey save_lead' => ['whatsapp_journey', CrmLead::SOURCE_JOURNEY],
            // Phase 6 Task 10 — CTWA is attributed to the ad (was `whatsapp`).
            'click to whatsapp' => ['whatsapp_ctwa', CrmLead::SOURCE_META_AD],
            'unknown provider' => ['something_new', CrmLead::SOURCE_MANUAL],
        ];
    }

    /**
     * @dataProvider captureProviderProvider
     */
    public function test_a_capture_provider_maps_to_the_right_crm_source(string $provider, string $expected): void
    {
        $account = Account::factory()->create();

        $crmLead = app(CaptureLeadLinker::class)->link($this->captureLead($account, '9876543210', $provider));

        $this->assertSame($expected, $crmLead->source);
    }

    /**
     * The three capture writers must actually call the linker. A source
     * assertion rather than a full journey/webhook replay, following the
     * same convention this suite already uses elsewhere ("no driver is
     * instantiated outside the engine factory", "no quota logic remains
     * inline in the direct message path").
     */
    public function test_every_capture_writer_promotes_its_lead_into_the_crm(): void
    {
        $writers = [
            app_path('Services/Leads/MetaLeadWebhookHandler.php'),
            app_path('Services/WhatsApp/WhatsAppJourneyEngine.php'),
            app_path('Http/Controllers/Api/MetaWebhookController.php'),
        ];

        foreach ($writers as $path) {
            $source = file_get_contents($path);
            $this->assertStringContainsString(
                'CaptureLeadLinker::class)->linkQuietly(',
                $source,
                basename($path).' writes a capture lead but never promotes it into the CRM.',
            );
        }
    }

    public function test_the_meta_lead_ads_webhook_creates_a_crm_lead_end_to_end(): void
    {
        $account = Account::factory()->create();
        // Task 10 — capture promotion requires a CRM-entitled account.
        $this->grant($account, 'crm');
        $socialAccount = SocialAccount::create([
            'account_id' => $account->id,
            'provider' => 'meta',
            'asset_type' => 'facebook_page',
            'provider_id' => '777000111',
            'name' => 'Test Page',
            'access_token' => 'PAGE_TOKEN',
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'field_data' => [
                    ['name' => 'full_name', 'values' => ['Ada Lovelace']],
                    ['name' => 'phone_number', 'values' => ['+91 98765 43210']],
                    ['name' => 'email', 'values' => ['ada@example.com']],
                ],
            ], 200),
        ]);

        app(MetaLeadWebhookHandler::class)->handle([
            'changes' => [[
                'field' => 'leadgen',
                'value' => ['leadgen_id' => 'LG-1', 'page_id' => '777000111', 'form_id' => 'F1', 'ad_id' => 'A1'],
            ]],
        ]);

        // The capture record still exists, unchanged in shape.
        $this->assertDatabaseHas('leads', [
            'provider_lead_id' => 'LG-1',
            'account_id' => $account->id,
            'provider' => 'meta',
            'lead_phone' => '919876543210',
        ]);

        $lead = Lead::where('provider_lead_id', 'LG-1')->firstOrFail();
        $this->assertNotNull($lead->crmLead, 'The Meta capture was not promoted into the CRM.');
        $this->assertSame(CrmLead::SOURCE_META_AD, $lead->crmLead->source);
        $this->assertSame('919876543210', $lead->crmLead->contact->phone_number);
        $this->assertSame('ada@example.com', $lead->crmLead->contact->email);
        $this->assertSame($socialAccount->id, $lead->social_account_id);
    }

    public function test_a_crm_failure_never_breaks_the_capture(): void
    {
        $account = Account::factory()->create();
        // lead_phone is unusable, so linking returns null — the capture
        // row must still be intact and the flow must not have thrown.
        $lead = $this->captureLead($account, 'n/a', 'meta');

        $this->assertNull(app(CaptureLeadLinker::class)->linkQuietly($lead));
        $this->assertDatabaseHas('leads', ['id' => $lead->id]);
    }

    // =================================================================
    // ISSUE 8 — contact group reconciliation
    // =================================================================

    public function test_group_members_are_reconciled_to_crm_contacts(): void
    {
        $t = $this->tenant();
        $group = ContactGroup::create([
            'account_id' => $t['account']->id,
            'name' => 'VIPs',
            'group_code' => 'VIPS',
            'is_default' => false,
        ]);

        $this->actingAs($t['user'])->postJson('/api/groups/add-contacts', [
            'group_id' => $group->id,
            'contacts' => [
                ['phone_number' => '9876543210', 'name' => 'Ada'],
                ['phone_number' => '+91 90000 00099', 'name' => 'Grace'],
            ],
        ])->assertOk();

        $members = ContactGroupMember::where('group_id', $group->id)->get();
        $this->assertCount(2, $members);

        foreach ($members as $member) {
            $this->assertNotNull($member->contact_id, 'A group member was not reconciled to a CRM contact.');
            $this->assertSame($t['account']->id, $member->contact->account_id);
            $this->assertSame($member->phone_number, $member->contact->phone_number);
        }
    }

    public function test_a_group_member_and_a_crm_contact_for_one_person_converge(): void
    {
        $t = $this->tenant();
        $existing = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919876543210', 'name' => 'Ada Lovelace']);
        $group = ContactGroup::create([
            'account_id' => $t['account']->id,
            'name' => 'VIPs',
            'group_code' => 'VIPS2',
            'is_default' => false,
        ]);

        $this->actingAs($t['user'])->postJson('/api/groups/add-contacts', [
            'group_id' => $group->id,
            'contacts' => [['phone_number' => '09876543210', 'name' => 'ada']],
        ])->assertOk();

        $this->assertDatabaseCount('contacts', 1);
        $this->assertSame($existing->id, ContactGroupMember::where('group_id', $group->id)->first()->contact_id);
        // The group import must not clobber a curated name.
        $this->assertSame('Ada Lovelace', $existing->fresh()->name);
    }

    public function test_reconciliation_leaves_group_membership_behaviour_alone(): void
    {
        $t = $this->tenant();
        $group = ContactGroup::create([
            'account_id' => $t['account']->id,
            'name' => 'VIPs',
            'group_code' => 'VIPS3',
            'is_default' => false,
        ]);

        $this->actingAs($t['user'])->postJson('/api/groups/add-contacts', [
            'group_id' => $group->id,
            'contacts' => [['phone_number' => '9876543210', 'name' => 'Ada']],
        ])->assertOk();

        $member = ContactGroupMember::where('group_id', $group->id)->firstOrFail();
        $this->assertSame('919876543210', $member->phone_number);
        $this->assertSame('Ada', $member->name);

        // Re-importing the same person creates nothing new, anywhere.
        $this->actingAs($t['user'])->postJson('/api/groups/add-contacts', [
            'group_id' => $group->id,
            'contacts' => [['phone_number' => '9876543210', 'name' => 'Ada']],
        ])->assertOk();

        $this->assertDatabaseCount('contact_group_members', 1);
        $this->assertDatabaseCount('contacts', 1);
    }

    public function test_the_group_linker_never_reaches_another_tenants_contact(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();
        $theirContact = Contact::factory()->forAccount($accountB)->create(['phone_number' => '919876543210']);

        $group = ContactGroup::create(['account_id' => $accountA->id, 'name' => 'G', 'group_code' => 'G1', 'is_default' => false]);
        ContactGroupMember::create([
            'group_id' => $group->id,
            // [Round 2] NOT NULL since the composite tenant FK landed.
            'account_id' => $accountA->id,
            'phone_number' => '919876543210',
            'name' => null,
        ]);

        app(ContactGroupContactLinker::class)->linkGroup($group);

        $member = ContactGroupMember::where('group_id', $group->id)->firstOrFail();
        $this->assertNotSame($theirContact->id, $member->contact_id);
        $this->assertSame($accountA->id, $member->contact->account_id);
    }

    // =================================================================
    // ISSUE 9 — CRM lead delete
    // =================================================================

    public function test_a_lead_can_be_deleted_without_touching_its_contact(): void
    {
        $t = $this->tenant();
        $contact = Contact::factory()->forAccount($t['account'])->create();
        $keep = CrmLead::factory()->forContact($contact)->create();
        $drop = CrmLead::factory()->forContact($contact)->create();

        $this->actingAs($t['user'])->deleteJson(self::LEADS.'/'.$drop->id)->assertOk();

        $this->assertDatabaseMissing('crm_leads', ['id' => $drop->id]);
        $this->assertDatabaseHas('crm_leads', ['id' => $keep->id]);
        $this->assertDatabaseHas('contacts', ['id' => $contact->id]);
    }

    public function test_one_account_cannot_delete_anothers_lead(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $theirs = CrmLead::factory()->create(['account_id' => $other['account']->id]);

        $this->actingAs($t['user'])->deleteJson(self::LEADS.'/'.$theirs->id)->assertStatus(404);

        $this->assertDatabaseHas('crm_leads', ['id' => $theirs->id]);
    }

    public function test_deleting_a_lead_requires_the_crm_capability(): void
    {
        $t = $this->tenant(withCrm: false);
        $lead = CrmLead::factory()->create(['account_id' => $t['account']->id]);

        $this->actingAs($t['user'])->deleteJson(self::LEADS.'/'.$lead->id)->assertStatus(403);

        $this->assertDatabaseHas('crm_leads', ['id' => $lead->id]);
    }

    // =================================================================
    // ISSUE 10 — contact delete
    // =================================================================

    public function test_a_contact_with_no_dependants_can_be_deleted(): void
    {
        $t = $this->tenant();
        $contact = Contact::factory()->forAccount($t['account'])->create();

        $this->actingAs($t['user'])->deleteJson(self::CONTACTS.'/'.$contact->id)->assertOk();

        $this->assertDatabaseMissing('contacts', ['id' => $contact->id]);
    }

    public function test_a_contact_with_crm_leads_is_refused(): void
    {
        $t = $this->tenant();
        $contact = Contact::factory()->forAccount($t['account'])->create();
        CrmLead::factory()->forContact($contact)->create();

        $response = $this->actingAs($t['user'])->deleteJson(self::CONTACTS.'/'.$contact->id);

        $response->assertStatus(409);
        $response->assertJsonPath('error_code', 'CONTACT_HAS_DEPENDENTS');
        $response->assertJsonPath('dependents.crm_leads', 1);
        $this->assertDatabaseHas('contacts', ['id' => $contact->id]);
    }

    public function test_a_contact_in_a_broadcast_group_is_refused(): void
    {
        $t = $this->tenant();
        $contact = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919876543210']);
        $group = ContactGroup::create(['account_id' => $t['account']->id, 'name' => 'G', 'group_code' => 'G9', 'is_default' => false]);
        ContactGroupMember::create([
            'group_id' => $group->id,
            'account_id' => $t['account']->id,
            'contact_id' => $contact->id,
            'phone_number' => '919876543210',
        ]);

        $this->actingAs($t['user'])
            ->deleteJson(self::CONTACTS.'/'.$contact->id)
            ->assertStatus(409)
            ->assertJsonPath('dependents.group_memberships', 1);

        $this->assertDatabaseHas('contacts', ['id' => $contact->id]);
    }

    public function test_no_soft_delete_convention_was_introduced(): void
    {
        foreach (['contacts', 'crm_leads', 'leads', 'contact_group_members'] as $table) {
            $this->assertFalse(
                \Illuminate\Support\Facades\Schema::hasColumn($table, 'deleted_at'),
                "{$table} gained a deleted_at column; this project has no SoftDeletes convention.",
            );
        }
    }
}
