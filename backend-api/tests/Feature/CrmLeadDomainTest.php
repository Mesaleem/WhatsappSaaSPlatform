<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\CrmLead;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase 6 — CRM, Task 1. Domain-level coverage for the Contact/CrmLead
 * foundation: creation, the closed status/source vocabularies, nullable
 * assignment, the three relationships, and tenant isolation at BOTH
 * levels it is enforced (the model guard and the composite foreign key).
 *
 * No HTTP coverage here on purpose — Task 1 ships no controller, and
 * inventing one to test would violate the brief's "no API/UI yet".
 */
class CrmLeadDomainTest extends TestCase
{
    use RefreshDatabase;

    private function contactFor(Account $account, string $phone = '9876543210', ?string $name = 'Ada'): Contact
    {
        return Contact::create([
            'account_id' => $account->id,
            'phone_number' => $phone,
            'name' => $name,
        ]);
    }

    /**
     * [Round 2 amendment] An ELIGIBLE CRM assignee. CrmLead now requires
     * an assignee to be active and to hold `manage-crm` (Round 2,
     * Limitation 8 — "do not only check account membership"), so this
     * fixture grants it directly. The assertions in this suite are
     * unchanged; only the fixture is, because a bare account member is
     * no longer a valid assignee by design.
     */
    private function memberOf(Account $account): User
    {
        Permission::findOrCreate('manage-crm');

        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->givePermissionTo('manage-crm');

        return $user;
    }

    // ---------------------------------------------------------------
    // 1. Lead creation
    // ---------------------------------------------------------------

    public function test_a_lead_persists_with_account_contact_status_source_and_assignment(): void
    {
        $account = Account::factory()->create();
        $contact = $this->contactFor($account);
        $member = $this->memberOf($account);

        $lead = CrmLead::create([
            'account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => CrmLead::STATUS_CONTACTED,
            'source' => CrmLead::SOURCE_WHATSAPP,
            'assigned_user_id' => $member->id,
        ]);

        $this->assertDatabaseHas('crm_leads', [
            'id' => $lead->id,
            'account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'contacted',
            'source' => 'whatsapp',
            'assigned_user_id' => $member->id,
        ]);

        $this->assertNotNull($lead->created_at);
        $this->assertNotNull($lead->updated_at);
        $this->assertNull($lead->converted_at);
        $this->assertNull($lead->not_converted_at);
        $this->assertNull($lead->not_converted_reason);
    }

    public function test_a_new_lead_defaults_to_the_new_status_and_manual_source(): void
    {
        $account = Account::factory()->create();

        $lead = CrmLead::create([
            'account_id' => $account->id,
            'contact_id' => $this->contactFor($account)->id,
        ]);

        $this->assertSame(CrmLead::STATUS_NEW, $lead->fresh()->status);
        $this->assertSame(CrmLead::SOURCE_MANUAL, $lead->fresh()->source);
    }

    public function test_terminal_outcome_columns_record_when_and_why(): void
    {
        $account = Account::factory()->create();
        $lead = CrmLead::create([
            'account_id' => $account->id,
            'contact_id' => $this->contactFor($account)->id,
        ]);

        $lead->update([
            'status' => CrmLead::STATUS_NOT_CONVERTED,
            'not_converted_at' => now(),
            'not_converted_reason' => 'Budget not approved.',
        ]);

        $fresh = $lead->fresh();
        $this->assertSame(CrmLead::STATUS_NOT_CONVERTED, $fresh->status);
        $this->assertNotNull($fresh->not_converted_at);
        $this->assertSame('Budget not approved.', $fresh->not_converted_reason);
        $this->assertNull($fresh->converted_at);
    }

    // ---------------------------------------------------------------
    // 2. Tenant isolation — lead may not reference a foreign contact
    // ---------------------------------------------------------------

    public function test_a_lead_cannot_reference_a_contact_from_another_account(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();
        $foreignContact = $this->contactFor($accountB, '9000000001');

        try {
            CrmLead::create([
                'account_id' => $accountA->id,
                'contact_id' => $foreignContact->id,
            ]);
            $this->fail('A cross-tenant contact reference was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('contact_id', $e->errors());
        }

        $this->assertDatabaseCount('crm_leads', 0);
    }

    /**
     * The error must not distinguish "belongs to another tenant" from
     * "does not exist" — a distinguishable message is an existence
     * oracle for another account's IDs, which the brief forbids.
     */
    public function test_a_foreign_contact_and_a_missing_contact_fail_identically(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();
        $foreignContact = $this->contactFor($accountB, '9000000002');

        $foreignError = null;
        try {
            CrmLead::create(['account_id' => $accountA->id, 'contact_id' => $foreignContact->id]);
        } catch (ValidationException $e) {
            $foreignError = $e->errors();
        }

        $missingError = null;
        try {
            CrmLead::create(['account_id' => $accountA->id, 'contact_id' => 999999]);
        } catch (ValidationException $e) {
            $missingError = $e->errors();
        }

        $this->assertNotNull($foreignError);
        $this->assertSame($foreignError, $missingError);
    }

    /**
     * The model guard is convenience; the composite foreign key is the
     * guarantee. Writing straight through the query builder bypasses
     * every Eloquent event, and the database must still refuse.
     */
    public function test_the_database_itself_rejects_a_cross_tenant_contact_reference(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();
        $foreignContact = $this->contactFor($accountB, '9000000003');

        $this->expectException(QueryException::class);

        DB::table('crm_leads')->insert([
            'account_id' => $accountA->id,
            'contact_id' => $foreignContact->id,
            'status' => CrmLead::STATUS_NEW,
            'source' => CrmLead::SOURCE_MANUAL,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // 3. Tenant isolation on lead ownership
    // ---------------------------------------------------------------

    public function test_an_existing_lead_cannot_be_moved_to_another_account(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();
        $lead = CrmLead::create([
            'account_id' => $accountA->id,
            'contact_id' => $this->contactFor($accountA, '9000000004')->id,
        ]);

        try {
            $lead->update(['account_id' => $accountB->id]);
            $this->fail('A lead was reassigned to another account while keeping its contact.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('contact_id', $e->errors());
        }

        $this->assertSame($accountA->id, $lead->fresh()->account_id);
    }

    public function test_a_lead_cannot_be_assigned_to_a_user_from_another_account(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();
        $foreignMember = $this->memberOf($accountB);

        try {
            CrmLead::create([
                'account_id' => $accountA->id,
                'contact_id' => $this->contactFor($accountA, '9000000005')->id,
                'assigned_user_id' => $foreignMember->id,
            ]);
            $this->fail('A cross-tenant assignment was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('assigned_user_id', $e->errors());
        }

        $this->assertDatabaseCount('crm_leads', 0);
    }

    public function test_scope_for_account_never_returns_another_accounts_leads(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();

        CrmLead::create(['account_id' => $accountA->id, 'contact_id' => $this->contactFor($accountA, '9000000006')->id]);
        $bLead = CrmLead::create(['account_id' => $accountB->id, 'contact_id' => $this->contactFor($accountB, '9000000007')->id]);

        $visible = CrmLead::query()->forAccount($accountA->id)->pluck('id');

        $this->assertCount(1, $visible);
        $this->assertNotContains($bLead->id, $visible->all());
    }

    // ---------------------------------------------------------------
    // 4. Nullable assignment
    // ---------------------------------------------------------------

    public function test_a_lead_can_exist_without_an_assigned_user(): void
    {
        $account = Account::factory()->create();

        $lead = CrmLead::create([
            'account_id' => $account->id,
            'contact_id' => $this->contactFor($account, '9000000008')->id,
        ]);

        $this->assertNull($lead->fresh()->assigned_user_id);
        $this->assertNull($lead->fresh()->assignedUser);
    }

    public function test_deleting_the_assigned_member_unassigns_the_lead_instead_of_deleting_it(): void
    {
        $account = Account::factory()->create();
        $member = $this->memberOf($account);

        $lead = CrmLead::create([
            'account_id' => $account->id,
            'contact_id' => $this->contactFor($account, '9000000009')->id,
            'assigned_user_id' => $member->id,
        ]);

        $member->delete();

        $this->assertDatabaseHas('crm_leads', ['id' => $lead->id, 'assigned_user_id' => null]);
    }

    // ---------------------------------------------------------------
    // 5 & 6. Status and source vocabularies
    // ---------------------------------------------------------------

    public function test_every_supported_status_is_accepted(): void
    {
        $account = Account::factory()->create();
        $contact = $this->contactFor($account, '9000000010');

        $this->assertSame(['new', 'contacted', 'converted', 'not_converted'], CrmLead::STATUSES);

        foreach (CrmLead::STATUSES as $status) {
            /*
             * [Round 2 amendment] The status/timestamp invariants
             * (Limitation 10) make `converted` with no converted_at, and
             * `not_converted` with no not_converted_at, impossible
             * states rather than merely odd ones. The assertion is
             * unchanged — every supported status is still accepted — but
             * the row now has to be constructed in a legal shape, which
             * is what CrmLeadService::stampTerminalOutcome() produces
             * for every real caller.
             */
            $lead = CrmLead::create([
                'account_id' => $account->id,
                'contact_id' => $contact->id,
                'status' => $status,
                'converted_at' => $status === CrmLead::STATUS_CONVERTED ? now() : null,
                'not_converted_at' => $status === CrmLead::STATUS_NOT_CONVERTED ? now() : null,
            ]);

            $this->assertSame($status, $lead->fresh()->status);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rejectedStatusProvider(): array
    {
        return [
            // The pipeline statuses the brief explicitly forbids.
            'qualified' => ['qualified'],
            'proposal' => ['proposal'],
            'negotiation' => ['negotiation'],
            'won' => ['won'],
            'lost' => ['lost'],
            'empty' => [''],
            'casing' => ['New'],
        ];
    }

    /**
     * @dataProvider rejectedStatusProvider
     */
    public function test_unsupported_statuses_are_rejected(string $status): void
    {
        $account = Account::factory()->create();

        $this->expectException(ValidationException::class);

        CrmLead::create([
            'account_id' => $account->id,
            'contact_id' => $this->contactFor($account, '9000000011')->id,
            'status' => $status,
        ]);
    }

    public function test_every_supported_source_is_accepted(): void
    {
        $account = Account::factory()->create();
        $contact = $this->contactFor($account, '9000000012');

        $this->assertSame(['manual', 'whatsapp', 'meta_ad', 'api', 'journey'], CrmLead::SOURCES);

        foreach (CrmLead::SOURCES as $source) {
            $lead = CrmLead::create([
                'account_id' => $account->id,
                'contact_id' => $contact->id,
                'source' => $source,
            ]);

            $this->assertSame($source, $lead->fresh()->source);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rejectedSourceProvider(): array
    {
        return [
            'unknown' => ['facebook_ad'],
            'empty' => [''],
            'casing' => ['Manual'],
        ];
    }

    /**
     * @dataProvider rejectedSourceProvider
     */
    public function test_unsupported_sources_are_rejected(string $source): void
    {
        $account = Account::factory()->create();

        $this->expectException(ValidationException::class);

        CrmLead::create([
            'account_id' => $account->id,
            'contact_id' => $this->contactFor($account, '9000000013')->id,
            'source' => $source,
        ]);
    }

    public function test_an_unsupported_status_cannot_be_introduced_by_an_update_either(): void
    {
        $account = Account::factory()->create();
        $lead = CrmLead::create([
            'account_id' => $account->id,
            'contact_id' => $this->contactFor($account, '9000000014')->id,
        ]);

        try {
            $lead->update(['status' => 'won']);
            $this->fail('An unsupported status was accepted on update.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $this->assertSame(CrmLead::STATUS_NEW, $lead->fresh()->status);
    }

    // ---------------------------------------------------------------
    // 7. Relationships
    // ---------------------------------------------------------------

    public function test_lead_relationships_resolve_to_the_existing_models(): void
    {
        $account = Account::factory()->create();
        $contact = $this->contactFor($account, '9000000015');
        $member = $this->memberOf($account);

        $lead = CrmLead::create([
            'account_id' => $account->id,
            'contact_id' => $contact->id,
            'assigned_user_id' => $member->id,
        ])->fresh();

        $this->assertInstanceOf(Account::class, $lead->account);
        $this->assertSame($account->id, $lead->account->id);

        $this->assertInstanceOf(Contact::class, $lead->contact);
        $this->assertSame($contact->id, $lead->contact->id);

        $this->assertInstanceOf(User::class, $lead->assignedUser);
        $this->assertSame($member->id, $lead->assignedUser->id);
    }

    public function test_the_ownership_chain_reads_account_to_contacts_to_crm_leads(): void
    {
        $account = Account::factory()->create();
        $contact = $this->contactFor($account, '9000000016');

        CrmLead::create(['account_id' => $account->id, 'contact_id' => $contact->id]);
        CrmLead::create(['account_id' => $account->id, 'contact_id' => $contact->id, 'source' => CrmLead::SOURCE_META_AD]);

        $this->assertCount(1, $account->fresh()->contacts);
        $this->assertCount(2, $contact->fresh()->crmLeads);
        $this->assertSame($account->id, $contact->fresh()->account->id);
    }

    // ---------------------------------------------------------------
    // Contact identity and cascade behaviour
    // ---------------------------------------------------------------

    public function test_a_contact_phone_number_is_normalized_so_the_unique_index_actually_dedupes(): void
    {
        $account = Account::factory()->create();

        $contact = $this->contactFor($account, '+91 98765 43210');
        $this->assertSame('919876543210', $contact->fresh()->phone_number);

        $this->expectException(QueryException::class);
        $this->contactFor($account, '09876543210');
    }

    public function test_the_same_phone_number_may_exist_once_per_account(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();

        $this->contactFor($accountA, '9876543210');
        $this->contactFor($accountB, '9876543210');

        $this->assertDatabaseCount('contacts', 2);
    }

    public function test_deleting_a_contact_removes_its_leads(): void
    {
        $account = Account::factory()->create();
        $contact = $this->contactFor($account, '9000000017');
        CrmLead::create(['account_id' => $account->id, 'contact_id' => $contact->id]);

        $contact->delete();

        $this->assertDatabaseCount('crm_leads', 0);
    }

    public function test_deleting_an_account_removes_its_contacts_and_leads(): void
    {
        $account = Account::factory()->create();
        $contact = $this->contactFor($account, '9000000018');
        CrmLead::create(['account_id' => $account->id, 'contact_id' => $contact->id]);

        $account->delete();

        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('crm_leads', 0);
    }
}
