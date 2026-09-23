<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\ApiKey;
use App\Models\Capability;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\ContactGroupMember;
use App\Models\CrmLead;
use App\Models\Lead;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Crm\CaptureLeadLinker;
use App\Services\Crm\ContactResolver;
use App\Services\Crm\ContactService;
use App\Services\Crm\CrmLeadService;
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
 * Phase 6 — CRM Task 3. The Contact <-> CRM Lead relationship end to end.
 *
 * The three records stay separate throughout, and most of this suite
 * exists to prove that separation holds under every operation:
 *   Contact      the tenant's current CRM identity for a person
 *   CrmLead      one opportunity's lifecycle
 *   capture Lead the original, immutable provider submission
 *
 * Existing CRM coverage (CrmLeadDomainTest, CrmContactResolutionTest,
 * CrmLeadApiTest, CrmHardeningTest, CrmHardeningRound2Test) is not
 * duplicated or modified here.
 */
class CrmContactLeadLinkingTest extends TestCase
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
            $capability = Capability::where('slug', 'crm')->firstOrFail();
            AccountEntitlement::firstOrCreate(
                ['account_id' => $account->id, 'capability_id' => $capability->id],
                ['source' => 'manual_grant', 'granted_by_account_id' => null],
            );
        }

        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('admin');

        return ['account' => $account->fresh(), 'user' => $user];
    }

    private function apiKeyFor(Account $account): string
    {
        $plain = 'sk_test_'.bin2hex(random_bytes(12));

        ApiKey::create([
            'account_id' => $account->id,
            'name' => 'Test key',
            'key_prefix' => substr($plain, 0, 10),
            'key_hash' => ApiKey::hashKey($plain),
        ]);

        return $plain;
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
            'raw_field_data' => ['verbatim' => 'PROVIDER_PAYLOAD_XYZ'],
        ]);
    }

    // =================================================================
    // 1. Relationship contract — every CRM lead has a same-account Contact
    // =================================================================

    public function test_the_contact_column_is_required_at_the_database(): void
    {
        $account = Account::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('crm_leads')->insert([
            'account_id' => $account->id,
            'contact_id' => null,
            'status' => CrmLead::STATUS_NEW,
            'source' => CrmLead::SOURCE_MANUAL,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, array{string}> */
    public static function creationPathProvider(): array
    {
        return [
            'manual crm api' => ['manual'],
            'developer api' => ['api'],
            'meta capture' => ['meta'],
            'journey capture' => ['whatsapp_journey'],
            'click to whatsapp' => ['whatsapp_ctwa'],
        ];
    }

    /**
     * Every creation path the product has must produce a lead with a
     * Contact of the same account. No path may leave one dangling.
     *
     * @dataProvider creationPathProvider
     */
    public function test_every_creation_path_produces_a_same_account_contact(string $path): void
    {
        $t = $this->tenant();

        $lead = match ($path) {
            'manual' => CrmLead::findOrFail(
                $this->actingAs($t['user'])
                    ->postJson(self::LEADS, ['phone_number' => '9876543210'])
                    ->json('data.id'),
            ),
            'api' => CrmLead::findOrFail(
                $this->withHeader('X-API-KEY', $this->apiKeyFor($t['account']))
                    ->postJson(self::V1_LEADS, ['phone_number' => '9876543210'])
                    ->json('data.id'),
            ),
            default => app(CaptureLeadLinker::class)->link($this->captureLead($t['account'], '9876543210', $path)),
        };

        $this->assertNotNull($lead->contact_id);
        $this->assertSame($t['account']->id, $lead->contact->account_id);
        $this->assertSame('919876543210', $lead->contact->phone_number);
    }

    /**
     * Every creation path goes through ContactResolver, so an existing
     * Contact is reused rather than duplicated — whichever door the lead
     * comes in through, and whatever format the phone arrives in.
     *
     * @dataProvider creationPathProvider
     */
    public function test_every_creation_path_reuses_an_existing_contact(string $path): void
    {
        $t = $this->tenant();
        $existing = Contact::factory()->forAccount($t['account'])->create([
            'phone_number' => '919876543210',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
        ]);

        $lead = match ($path) {
            'manual' => CrmLead::findOrFail(
                $this->actingAs($t['user'])
                    ->postJson(self::LEADS, ['phone_number' => '09876543210', 'name' => 'ada', 'email' => 'other@example.com'])
                    ->json('data.id'),
            ),
            'api' => CrmLead::findOrFail(
                $this->withHeader('X-API-KEY', $this->apiKeyFor($t['account']))
                    ->postJson(self::V1_LEADS, ['phone_number' => '+91 98765 43210', 'name' => 'ada', 'email' => 'other@example.com'])
                    ->json('data.id'),
            ),
            default => app(CaptureLeadLinker::class)->link(
                $this->captureLead($t['account'], '+91 98765 43210', $path, 'ada', 'other@example.com'),
            ),
        };

        $this->assertSame($existing->id, $lead->contact_id);
        $this->assertDatabaseCount('contacts', 1);

        // Curated values survive every path.
        $fresh = $existing->fresh();
        $this->assertSame('Ada Lovelace', $fresh->name);
        $this->assertSame('ada@example.com', $fresh->email);
    }

    public function test_a_blank_name_and_email_are_filled_but_never_overwritten(): void
    {
        $account = Account::factory()->create();
        $resolver = app(ContactResolver::class);

        $contact = $resolver->resolve($account->id, '9876543210');
        $this->assertNull($contact->name);
        $this->assertNull($contact->email);

        $resolver->resolve($account->id, '9876543210', 'Ada', 'ada@example.com');
        $this->assertSame('Ada', $contact->fresh()->name);
        $this->assertSame('ada@example.com', $contact->fresh()->email);

        $resolver->resolve($account->id, '9876543210', 'Someone Else', 'else@example.com');
        $this->assertSame('Ada', $contact->fresh()->name);
        $this->assertSame('ada@example.com', $contact->fresh()->email);
    }

    /**
     * The race-safe path: a concurrent creation loses the unique index
     * and must re-read the winner rather than throw. Simulated by
     * inserting the row behind the resolver's back between its lookup
     * and its insert — which is exactly the window the catch covers.
     */
    public function test_contact_creation_is_race_safe(): void
    {
        $account = Account::factory()->create();

        // The "other request" wins first.
        $winner = Contact::create(['account_id' => $account->id, 'phone_number' => '919876543210']);

        // A second resolve for the same number must return that row.
        $resolved = app(ContactResolver::class)->resolve($account->id, '+91 98765 43210', 'Ada');

        $this->assertSame($winner->id, $resolved->id);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertTrue(
            Schema::hasColumn('contacts', 'phone_number'),
            'The unique(account_id, phone_number) rule is what makes this safe; it must not be removed.',
        );
    }

    // =================================================================
    // 2. Explicit contact reassignment
    // =================================================================

    public function test_a_lead_can_be_reassigned_to_another_contact_of_the_same_account(): void
    {
        $t = $this->tenant();
        $from = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000001', 'name' => 'Wrong Person']);
        $to = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000002', 'name' => 'Right Person']);
        $lead = CrmLead::factory()->forContact($from)->create();

        $response = $this->actingAs($t['user'])
            ->patchJson(self::LEADS.'/'.$lead->id.'/contact', ['contact_id' => $to->id]);

        $response->assertOk();
        $response->assertJsonPath('data.contact.id', $to->id);
        $response->assertJsonPath('data.contact.name', 'Right Person');

        $this->assertSame($to->id, $lead->fresh()->contact_id);
        $this->assertSame(0, $from->fresh()->crmLeads()->count());
        $this->assertSame(1, $to->fresh()->crmLeads()->count());
    }

    public function test_reassignment_preserves_everything_except_the_contact(): void
    {
        $t = $this->tenant();
        $member = User::factory()->create(['account_id' => $t['account']->id, 'is_active' => true]);
        $member->givePermissionTo('manage-crm');

        $capture = $this->captureLead($t['account'], '919000000011', 'meta');
        $lead = app(CaptureLeadLinker::class)->link($capture);
        $lead->forceFill([
            'status' => CrmLead::STATUS_NOT_CONVERTED,
            'not_converted_at' => now(),
            'not_converted_reason' => 'Budget.',
            'assigned_user_id' => $member->id,
        ])->save();

        $before = $lead->fresh();
        $target = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000012']);

        $this->actingAs($t['user'])
            ->patchJson(self::LEADS.'/'.$lead->id.'/contact', ['contact_id' => $target->id])
            ->assertOk();

        $after = $lead->fresh();
        $this->assertSame($target->id, $after->contact_id);
        $this->assertSame($before->account_id, $after->account_id);
        $this->assertSame($before->capture_lead_id, $after->capture_lead_id);
        $this->assertSame($before->source, $after->source);
        $this->assertSame($before->status, $after->status);
        $this->assertSame($before->assigned_user_id, $after->assigned_user_id);
        $this->assertEquals($before->not_converted_at, $after->not_converted_at);
        $this->assertSame($before->not_converted_reason, $after->not_converted_reason);

        // No opportunity was duplicated, and no capture row was touched.
        $this->assertDatabaseCount('crm_leads', 1);
        $this->assertDatabaseCount('leads', 1);
    }

    public function test_the_capture_chain_still_reads_after_reassignment(): void
    {
        $t = $this->tenant();
        $capture = $this->captureLead($t['account'], '919000000021', 'meta');
        $lead = app(CaptureLeadLinker::class)->link($capture);
        $captureSnapshot = $capture->fresh()->toArray();

        $target = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000022']);

        $this->actingAs($t['user'])
            ->patchJson(self::LEADS.'/'.$lead->id.'/contact', ['contact_id' => $target->id])
            ->assertOk();

        // capture lead -> CRM lead -> new contact
        $this->assertSame($lead->id, $capture->fresh()->crmLead->id);
        $this->assertSame($target->id, $capture->fresh()->crmLead->contact->id);
        // and the capture row itself is byte-identical.
        $this->assertSame($captureSnapshot, $capture->fresh()->toArray());
    }

    public function test_reassignment_does_not_modify_either_contact(): void
    {
        $t = $this->tenant();
        $from = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000031', 'name' => 'A', 'email' => 'a@example.com']);
        $to = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000032', 'name' => 'B', 'email' => 'b@example.com']);
        $lead = CrmLead::factory()->forContact($from)->create();

        $fromBefore = $from->fresh()->toArray();
        $toBefore = $to->fresh()->toArray();

        $this->actingAs($t['user'])
            ->patchJson(self::LEADS.'/'.$lead->id.'/contact', ['contact_id' => $to->id])
            ->assertOk();

        $this->assertSame($fromBefore, $from->fresh()->toArray());
        $this->assertSame($toBefore, $to->fresh()->toArray());
    }

    public function test_reassignment_to_another_accounts_contact_is_not_found(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $lead = CrmLead::factory()->create(['account_id' => $t['account']->id]);
        $theirContact = Contact::factory()->forAccount($other['account'])->create();

        $this->actingAs($t['user'])
            ->patchJson(self::LEADS.'/'.$lead->id.'/contact', ['contact_id' => $theirContact->id])
            ->assertStatus(404);

        $this->assertNotSame($theirContact->id, $lead->fresh()->contact_id);
    }

    public function test_reassigning_another_accounts_lead_is_not_found(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $theirLead = CrmLead::factory()->create(['account_id' => $other['account']->id]);
        $myContact = Contact::factory()->forAccount($t['account'])->create();
        $originalContact = $theirLead->contact_id;

        $this->actingAs($t['user'])
            ->patchJson(self::LEADS.'/'.$theirLead->id.'/contact', ['contact_id' => $myContact->id])
            ->assertStatus(404);

        $this->assertSame($originalContact, $theirLead->fresh()->contact_id);
    }

    /** A foreign contact id and an id that exists nowhere must be indistinguishable. */
    public function test_a_foreign_contact_and_a_missing_contact_fail_identically(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $lead = CrmLead::factory()->create(['account_id' => $t['account']->id]);
        $theirContact = Contact::factory()->forAccount($other['account'])->create();

        $foreign = $this->actingAs($t['user'])->patchJson(self::LEADS.'/'.$lead->id.'/contact', ['contact_id' => $theirContact->id]);
        $missing = $this->actingAs($t['user'])->patchJson(self::LEADS.'/'.$lead->id.'/contact', ['contact_id' => 999999]);

        $this->assertSame($foreign->status(), $missing->status());
        $this->assertSame($foreign->json('message'), $missing->json('message'));
    }

    public function test_reassignment_ignores_a_request_supplied_account(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $target = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000041']);
        $lead = CrmLead::factory()->create(['account_id' => $t['account']->id]);

        $this->actingAs($t['user'])->patchJson(self::LEADS.'/'.$lead->id.'/contact', [
            'contact_id' => $target->id,
            'account_id' => $other['account']->id,
        ])->assertOk();

        $this->assertSame($t['account']->id, $lead->fresh()->account_id);
    }

    public function test_reassignment_requires_a_contact_id(): void
    {
        $t = $this->tenant();
        $lead = CrmLead::factory()->create(['account_id' => $t['account']->id]);

        $this->actingAs($t['user'])
            ->patchJson(self::LEADS.'/'.$lead->id.'/contact', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['contact_id']);
    }

    public function test_reassignment_requires_the_crm_capability(): void
    {
        $t = $this->tenant(withCrm: false);
        $lead = CrmLead::factory()->create(['account_id' => $t['account']->id]);
        $target = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000051']);

        $this->actingAs($t['user'])
            ->patchJson(self::LEADS.'/'.$lead->id.'/contact', ['contact_id' => $target->id])
            ->assertStatus(403);

        $this->assertNotSame($target->id, $lead->fresh()->contact_id);
    }

    public function test_reassignment_requires_the_lead_crm_module(): void
    {
        $t = $this->tenant();
        $t['account']->forceFill(['allowed_modules' => array_values(array_diff(Account::MODULES, ['lead_crm']))])->save();
        $lead = CrmLead::factory()->create(['account_id' => $t['account']->id]);
        $target = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000052']);

        $this->actingAs($t['user'])
            ->patchJson(self::LEADS.'/'.$lead->id.'/contact', ['contact_id' => $target->id])
            ->assertStatus(403);
    }

    /**
     * manage-social-leads is explicitly NOT a CRM permission. A role
     * holding only it must be refused, which is the separation Round 2
     * introduced and this endpoint must honour too.
     *
     * The role and its user are built BEFORE any actingAs() call in this
     * test: once a request has authenticated through Sanctum, Spatie
     * resolves the default guard as 'sanctum' while every permission row
     * in this project carries guard 'web', so a later givePermissionTo()
     * would throw. That is the pre-existing guard-configuration quirk
     * disclosed in the Round 2 report, not something this endpoint does.
     */
    public function test_manage_social_leads_alone_cannot_reassign(): void
    {
        $t = $this->tenant();
        $role = Role::create(['name' => 'no_crm_role']);
        $role->givePermissionTo('manage-social-leads');

        $user = User::factory()->create(['account_id' => $t['account']->id, 'is_active' => true]);
        $user->assignRole($role);

        $lead = CrmLead::factory()->create(['account_id' => $t['account']->id]);
        $target = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000053']);

        $this->actingAs($user)
            ->patchJson(self::LEADS.'/'.$lead->id.'/contact', ['contact_id' => $target->id])
            ->assertStatus(403);

        $this->assertNotSame($target->id, $lead->fresh()->contact_id);
    }

    /**
     * The reassignment writes through the model, which is what makes it
     * auditable: LogsActivity hooks Eloquent's `updated` event and
     * records actor, timestamp and the old/new contact_id.
     *
     * The ActivityLog ROW cannot be asserted here — recordActivity()
     * returns early under app()->runningInConsole(), which every PHPUnit
     * run is — so this asserts the event that drives it fires with
     * exactly the right change payload, which is the same technique the
     * Phase 5 reconciliation suite already uses.
     */
    public function test_reassignment_emits_the_audit_event_with_old_and_new_contact(): void
    {
        $t = $this->tenant();
        $from = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000061']);
        $to = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000062']);
        $lead = CrmLead::factory()->forContact($from)->create();

        $captured = null;
        CrmLead::updated(function (CrmLead $model) use (&$captured): void {
            $captured = ['changes' => $model->getChanges(), 'original' => $model->getOriginal()];
        });

        app(CrmLeadService::class)->reassignContact($lead, $to);

        $this->assertNotNull($captured, 'No updated event fired, so nothing would reach activity_logs.');
        $this->assertArrayHasKey('contact_id', $captured['changes']);
        $this->assertSame($to->id, (int) $captured['changes']['contact_id']);
        $this->assertSame($from->id, (int) $captured['original']['contact_id']);
    }

    public function test_the_database_refuses_a_cross_tenant_reassignment_even_below_the_model(): void
    {
        if (! $this->onMySql()) {
            $this->markTestSkipped('Composite foreign keys cannot be added through ALTER TABLE on SQLite; MySQL/MariaDB is authoritative.');
        }

        $a = Account::factory()->create();
        $b = Account::factory()->create();
        $lead = CrmLead::factory()->create(['account_id' => $a->id]);
        $foreignContact = Contact::factory()->forAccount($b)->create();

        $this->expectException(QueryException::class);

        DB::table('crm_leads')->where('id', $lead->id)->update(['contact_id' => $foreignContact->id]);
    }

    // =================================================================
    // 3. Contact -> Leads API
    // =================================================================

    public function test_a_contacts_leads_can_be_listed(): void
    {
        $t = $this->tenant();
        $contact = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000071']);
        $a = CrmLead::factory()->forContact($contact)->create();
        $b = CrmLead::factory()->forContact($contact)->create();

        $otherContact = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000072']);
        $unrelated = CrmLead::factory()->forContact($otherContact)->create();

        $response = $this->actingAs($t['user'])->getJson(self::CONTACTS.'/'.$contact->id.'/leads');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids);
        $this->assertNotContains($unrelated->id, $ids);

        // Paginated, like every other CRM list endpoint.
        $this->assertArrayHasKey('current_page', $response->json());
        $this->assertArrayHasKey('per_page', $response->json());
    }

    public function test_a_contacts_leads_can_be_filtered(): void
    {
        $t = $this->tenant();
        $contact = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000081']);
        $contacted = CrmLead::factory()->forContact($contact)->status(CrmLead::STATUS_CONTACTED)->create();
        CrmLead::factory()->forContact($contact)->source(CrmLead::SOURCE_META_AD)->create();

        $byStatus = $this->actingAs($t['user'])->getJson(self::CONTACTS.'/'.$contact->id.'/leads?status=contacted');
        $byStatus->assertOk();
        $this->assertSame([$contacted->id], collect($byStatus->json('data'))->pluck('id')->all());

        $this->actingAs($t['user'])
            ->getJson(self::CONTACTS.'/'.$contact->id.'/leads?status=won')
            ->assertStatus(422);
    }

    public function test_another_accounts_contact_has_no_listable_leads(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $theirContact = Contact::factory()->forAccount($other['account'])->create();
        CrmLead::factory()->forContact($theirContact)->create();

        $this->actingAs($t['user'])
            ->getJson(self::CONTACTS.'/'.$theirContact->id.'/leads')
            ->assertStatus(404);
    }

    public function test_the_contact_leads_endpoint_is_gated(): void
    {
        $t = $this->tenant(withCrm: false);
        $contact = Contact::factory()->forAccount($t['account'])->create();

        $this->actingAs($t['user'])
            ->getJson(self::CONTACTS.'/'.$contact->id.'/leads')
            ->assertStatus(403);
    }

    /** CRM leads only — a raw capture row is never served through this surface. */
    public function test_the_contact_leads_endpoint_returns_crm_leads_not_capture_rows(): void
    {
        $t = $this->tenant();
        $capture = $this->captureLead($t['account'], '919000000091', 'meta');
        $crmLead = app(CaptureLeadLinker::class)->link($capture);

        // A second capture for the same person that was never promoted.
        $unpromoted = $this->captureLead($t['account'], '919000000091', 'meta');
        $unpromoted->forceFill(['lead_phone' => null])->save();

        $response = $this->actingAs($t['user'])->getJson(self::CONTACTS.'/'.$crmLead->contact_id.'/leads');

        $response->assertOk();
        $this->assertSame([$crmLead->id], collect($response->json('data'))->pluck('id')->all());
        // The capture appears only as an identifier block, never as a row.
        $response->assertJsonPath('data.0.capture_lead.id', $capture->id);
        $this->assertStringNotContainsString('PROVIDER_PAYLOAD_XYZ', $response->getContent());
    }

    // =================================================================
    // 4. Lead -> Contact representation
    // =================================================================

    public function test_the_lead_detail_exposes_the_contact_the_frontend_needs(): void
    {
        $t = $this->tenant();
        $contact = Contact::factory()->forAccount($t['account'])->create([
            'phone_number' => '919876543210',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
        ]);
        $lead = CrmLead::factory()->forContact($contact)->create();

        $response = $this->actingAs($t['user'])->getJson(self::LEADS.'/'.$lead->id);

        $response->assertOk();
        $response->assertJsonPath('data.contact.id', $contact->id);
        $response->assertJsonPath('data.contact.name', 'Ada Lovelace');
        $response->assertJsonPath('data.contact.phone_number', '919876543210');
        $response->assertJsonPath('data.contact.email', 'ada@example.com');

        // Lightweight, not the whole Contact row.
        $this->assertSame(
            ['id', 'name', 'phone_number', 'email'],
            array_keys($response->json('data.contact')),
        );
    }

    public function test_an_email_less_contact_serializes_cleanly(): void
    {
        $t = $this->tenant();
        $contact = Contact::factory()->forAccount($t['account'])->create(['email' => null, 'phone_number' => '919000000101']);
        $lead = CrmLead::factory()->forContact($contact)->create();

        $this->actingAs($t['user'])
            ->getJson(self::LEADS.'/'.$lead->id)
            ->assertOk()
            ->assertJsonPath('data.contact.email', null);
    }

    // =================================================================
    // 5. Contact deletion safety
    // =================================================================

    public function test_a_contact_with_crm_leads_cannot_be_deleted(): void
    {
        $t = $this->tenant();
        $contact = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000111']);
        $lead = CrmLead::factory()->forContact($contact)->create();

        $this->actingAs($t['user'])
            ->deleteJson(self::CONTACTS.'/'.$contact->id)
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'CONTACT_HAS_DEPENDENTS')
            ->assertJsonPath('dependents.crm_leads', 1);

        $this->assertDatabaseHas('contacts', ['id' => $contact->id]);
        $this->assertDatabaseHas('crm_leads', ['id' => $lead->id, 'contact_id' => $contact->id]);
    }

    public function test_deletion_is_refused_for_group_memberships_too(): void
    {
        $t = $this->tenant();
        $contact = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000121']);
        $group = ContactGroup::create(['account_id' => $t['account']->id, 'name' => 'G', 'group_code' => 'GT3', 'is_default' => false]);
        ContactGroupMember::create([
            'group_id' => $group->id,
            'contact_id' => $contact->id,
            'phone_number' => '919000000121',
        ]);

        $this->actingAs($t['user'])
            ->deleteJson(self::CONTACTS.'/'.$contact->id)
            ->assertStatus(409)
            ->assertJsonPath('dependents.group_memberships', 1);
    }

    /**
     * The only way to remove a contact that has history is to remove the
     * history first — which never touches the capture record.
     */
    public function test_removing_the_leads_first_leaves_the_capture_row_intact(): void
    {
        $t = $this->tenant();
        $capture = $this->captureLead($t['account'], '919000000131', 'meta');
        $lead = app(CaptureLeadLinker::class)->link($capture);
        $contactId = $lead->contact_id;

        $this->actingAs($t['user'])->deleteJson(self::LEADS.'/'.$lead->id)->assertOk();
        $this->actingAs($t['user'])->deleteJson(self::CONTACTS.'/'.$contactId)->assertOk();

        $this->assertDatabaseMissing('contacts', ['id' => $contactId]);
        $this->assertDatabaseCount('crm_leads', 0);
        // The provider's original submission survives all of it.
        $this->assertDatabaseHas('leads', ['id' => $capture->id]);
    }

    public function test_no_crm_lead_is_ever_orphaned(): void
    {
        $t = $this->tenant();
        $contact = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000141']);
        CrmLead::factory()->forContact($contact)->create();

        $this->actingAs($t['user'])->deleteJson(self::CONTACTS.'/'.$contact->id)->assertStatus(409);

        $orphans = CrmLead::query()
            ->whereNotIn('contact_id', Contact::query()->select('id'))
            ->count();

        $this->assertSame(0, $orphans);
    }

    // =================================================================
    // 6. Merge interaction
    // =================================================================

    public function test_case_a_merge_brings_both_contacts_leads_onto_the_target(): void
    {
        $t = $this->tenant();
        $source = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000151']);
        $target = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000152']);
        $leadA = CrmLead::factory()->forContact($source)->create();
        $leadB = CrmLead::factory()->forContact($target)->create();

        $this->actingAs($t['user'])
            ->postJson(self::CONTACTS.'/'.$source->id.'/merge/'.$target->id, ['confirm_phone_discard' => true])
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            [$leadA->id, $leadB->id],
            $target->fresh()->crmLeads->pluck('id')->all(),
        );
        $this->assertDatabaseMissing('contacts', ['id' => $source->id]);
    }

    public function test_case_b_every_lead_survives_when_both_sides_have_several(): void
    {
        $t = $this->tenant();
        $source = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000161']);
        $target = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000162']);

        $expected = collect()
            ->merge(collect(range(1, 3))->map(fn () => CrmLead::factory()->forContact($source)->create()->id))
            ->merge(collect(range(1, 2))->map(fn () => CrmLead::factory()->forContact($target)->create()->id))
            ->all();

        $this->actingAs($t['user'])
            ->postJson(self::CONTACTS.'/'.$source->id.'/merge/'.$target->id, ['confirm_phone_discard' => true])
            ->assertOk();

        $this->assertEqualsCanonicalizing($expected, $target->fresh()->crmLeads->pluck('id')->all());
        $this->assertSame(5, CrmLead::count());
    }

    public function test_case_c_a_capture_relationship_survives_a_merge(): void
    {
        $t = $this->tenant();
        $capture = $this->captureLead($t['account'], '919000000171', 'meta');
        $lead = app(CaptureLeadLinker::class)->link($capture);
        $source = $lead->contact;
        $target = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000172']);

        $this->actingAs($t['user'])
            ->postJson(self::CONTACTS.'/'.$source->id.'/merge/'.$target->id, ['confirm_phone_discard' => true])
            ->assertOk();

        $this->assertSame($capture->id, $lead->fresh()->capture_lead_id);
        $this->assertSame($target->id, $capture->fresh()->crmLead->contact_id);
        $this->assertDatabaseHas('leads', ['id' => $capture->id]);
    }

    public function test_case_d_reassignment_after_a_merge_is_still_safe(): void
    {
        $t = $this->tenant();
        $source = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000181']);
        $target = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000182']);
        $third = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000183']);

        $capture = $this->captureLead($t['account'], '919000000181', 'meta');
        $lead = CrmLead::factory()->forContact($source)->create();
        $capture->crmLead()->save($lead);

        $this->actingAs($t['user'])
            ->postJson(self::CONTACTS.'/'.$source->id.'/merge/'.$target->id, ['confirm_phone_discard' => true])
            ->assertOk();

        $this->actingAs($t['user'])
            ->patchJson(self::LEADS.'/'.$lead->id.'/contact', ['contact_id' => $third->id])
            ->assertOk();

        $this->assertSame($third->id, $lead->fresh()->contact_id);
        $this->assertSame($capture->id, $lead->fresh()->capture_lead_id);
        $this->assertSame($third->id, $capture->fresh()->crmLead->contact_id);
        $this->assertDatabaseCount('crm_leads', 1);
        $this->assertDatabaseCount('leads', 1);
    }

    public function test_a_merge_across_tenants_changes_nothing(): void
    {
        $a = Account::factory()->create();
        $b = Account::factory()->create();
        $source = Contact::factory()->forAccount($a)->create(['phone_number' => '919000000191']);
        $target = Contact::factory()->forAccount($b)->create(['phone_number' => '919000000192']);
        $lead = CrmLead::factory()->forContact($source)->create();

        try {
            app(ContactService::class)->merge($source, $target, true);
            $this->fail('A cross-tenant merge was accepted.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame($source->id, $lead->fresh()->contact_id);
        $this->assertDatabaseHas('contacts', ['id' => $source->id]);
    }

    // =================================================================
    // 7. Duplicate handling — Contact uniqueness != Lead uniqueness
    // =================================================================

    public function test_one_contact_may_hold_many_leads_from_the_same_source_on_the_same_day(): void
    {
        $t = $this->tenant();

        $first = $this->actingAs($t['user'])->postJson(self::LEADS, ['phone_number' => '9876543210', 'source' => 'manual']);
        $second = $this->actingAs($t['user'])->postJson(self::LEADS, ['phone_number' => '9876543210', 'source' => 'manual']);

        $first->assertStatus(201);
        $second->assertStatus(201);
        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame($first->json('data.contact.id'), $second->json('data.contact.id'));

        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('crm_leads', 2);
    }

    /**
     * The only lead-level uniqueness the product has is capture
     * idempotency — unique(capture_lead_id) — and it must stay. There is
     * deliberately no unique(contact_id) or (contact_id, source, day).
     */
    public function test_capture_idempotency_is_the_only_lead_uniqueness_rule(): void
    {
        $t = $this->tenant();
        $capture = $this->captureLead($t['account'], '9876543210', 'meta');
        $linker = app(CaptureLeadLinker::class);

        $a = $linker->link($capture);
        $b = $linker->link($capture->fresh());

        $this->assertSame($a->id, $b->id);
        $this->assertDatabaseCount('crm_leads', 1);

        // But a SECOND capture of the same person is a second, legitimate
        // opportunity against the same contact.
        $secondCapture = $this->captureLead($t['account'], '9876543210', 'meta');
        $c = $linker->link($secondCapture);

        $this->assertNotSame($a->id, $c->id);
        $this->assertSame($a->contact_id, $c->contact_id);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('crm_leads', 2);
    }

    // =================================================================
    // 8. Contact updates never rewrite lead or capture data
    // =================================================================

    public function test_renaming_a_contact_does_not_touch_its_leads_or_captures(): void
    {
        $t = $this->tenant();
        $capture = $this->captureLead($t['account'], '919876543210', 'meta', 'Original Name', 'original@example.com');
        $lead = app(CaptureLeadLinker::class)->link($capture);

        $leadBefore = $lead->fresh()->toArray();
        $captureBefore = $capture->fresh()->toArray();

        $this->actingAs($t['user'])->patchJson(self::CONTACTS.'/'.$lead->contact_id, [
            'name' => 'Corrected Name',
            'email' => 'corrected@example.com',
        ])->assertOk();

        $this->assertSame('Corrected Name', $lead->fresh()->contact->name);
        // The opportunity and the original submission are untouched.
        $this->assertSame($leadBefore, $lead->fresh()->toArray());
        $this->assertSame($captureBefore, $capture->fresh()->toArray());
        $this->assertSame('Original Name', $capture->fresh()->lead_name);
        $this->assertSame('original@example.com', $capture->fresh()->lead_email);
    }

    public function test_changing_a_contacts_phone_does_not_rewrite_the_capture_payload(): void
    {
        $t = $this->tenant();
        $capture = $this->captureLead($t['account'], '919876543210', 'meta');
        $lead = app(CaptureLeadLinker::class)->link($capture);

        $this->actingAs($t['user'])
            ->patchJson(self::CONTACTS.'/'.$lead->contact_id, ['phone_number' => '9000000199'])
            ->assertOk();

        $this->assertSame('919000000199', $lead->fresh()->contact->phone_number);
        $this->assertSame('919876543210', $capture->fresh()->lead_phone);
        $this->assertSame(['verbatim' => 'PROVIDER_PAYLOAD_XYZ'], $capture->fresh()->raw_field_data);
    }

    // =================================================================
    // 9. Phone normalization across linking paths
    // =================================================================

    /** @return array<string, array{string}> */
    public static function equivalentPhoneProvider(): array
    {
        return [
            'country code' => ['919876543210'],
            'bare ten digits' => ['9876543210'],
            'formatted' => ['+91 98765 43210'],
        ];
    }

    /** @dataProvider equivalentPhoneProvider */
    public function test_equivalent_phone_formats_never_produce_a_second_contact(string $phone): void
    {
        $t = $this->tenant();
        $seed = Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919876543210']);

        $viaApi = $this->actingAs($t['user'])->postJson(self::LEADS, ['phone_number' => $phone]);
        $viaCapture = app(CaptureLeadLinker::class)->link($this->captureLead($t['account'], $phone, 'meta'));

        $this->assertSame($seed->id, $viaApi->json('data.contact.id'));
        $this->assertSame($seed->id, $viaCapture->contact_id);
        $this->assertDatabaseCount('contacts', 1);
    }

    // =================================================================
    // 10. Cross-tenant matrix
    // =================================================================

    public function test_an_api_key_cannot_reach_another_accounts_contact(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        // B already has this person.
        $theirContact = Contact::factory()->forAccount($b['account'])->create(['phone_number' => '919876543210']);

        $response = $this->withHeader('X-API-KEY', $this->apiKeyFor($a['account']))
            ->postJson(self::V1_LEADS, ['phone_number' => '9876543210']);

        $response->assertStatus(201);

        // A got its OWN new contact; B's is untouched and gained nothing.
        $this->assertNotSame($theirContact->id, $response->json('data.contact.id'));
        $this->assertSame($a['account']->id, Contact::findOrFail($response->json('data.contact.id'))->account_id);
        $this->assertSame(0, $theirContact->fresh()->crmLeads()->count());
    }

    public function test_a_user_cannot_read_another_accounts_contact_or_its_leads(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $theirContact = Contact::factory()->forAccount($other['account'])->create();
        CrmLead::factory()->forContact($theirContact)->create();

        $this->actingAs($t['user'])->getJson(self::CONTACTS.'/'.$theirContact->id)->assertStatus(404);
        $this->actingAs($t['user'])->getJson(self::CONTACTS.'/'.$theirContact->id.'/leads')->assertStatus(404);
        $this->actingAs($t['user'])->patchJson(self::CONTACTS.'/'.$theirContact->id, ['name' => 'X'])->assertStatus(404);
    }
}
