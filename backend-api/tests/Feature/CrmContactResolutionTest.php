<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\CrmLead;
use App\Models\User;
use App\Services\Crm\ContactResolver;
use App\Services\Crm\CrmLeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Phase 6 — CRM, Task 2. Service-level coverage for ContactResolver and
 * CrmLeadService, exercised directly rather than over HTTP: these are
 * the classes Task 3+'s webhook, journey and public-API sources will
 * call with no request in scope at all, so their behaviour has to be
 * pinned independently of the controller.
 *
 * The HTTP surface (routes, capability gating, tenant isolation through
 * the API, validation shape) is covered by CrmLeadApiTest.
 */
class CrmContactResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): ContactResolver
    {
        return app(ContactResolver::class);
    }

    private function service(): CrmLeadService
    {
        return app(CrmLeadService::class);
    }

    // =================================================================
    // Contact resolution
    // =================================================================

    public function test_an_unseen_phone_number_creates_a_contact(): void
    {
        $account = Account::factory()->create();

        $contact = $this->resolver()->resolve($account->id, '9876543210', 'Ada');

        $this->assertDatabaseCount('contacts', 1);
        $this->assertSame($account->id, $contact->account_id);
        $this->assertSame('919876543210', $contact->phone_number);
        $this->assertSame('Ada', $contact->name);
    }

    public function test_the_same_phone_number_reuses_the_existing_contact(): void
    {
        $account = Account::factory()->create();

        $first = $this->resolver()->resolve($account->id, '9876543210', 'Ada');
        $second = $this->resolver()->resolve($account->id, '9876543210', 'Ada');

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('contacts', 1);
    }

    /**
     * Every one of these strings is the same human being. If any of them
     * produced a second row, the unique index would be decorative.
     *
     * @return array<string, array{string}>
     */
    public static function equivalentPhoneProvider(): array
    {
        return [
            'bare ten digits' => ['9876543210'],
            'trunk prefix' => ['09876543210'],
            'country code' => ['919876543210'],
            'plus and spaces' => ['+91 98765 43210'],
            'dashes' => ['+91-98765-43210'],
        ];
    }

    /**
     * @dataProvider equivalentPhoneProvider
     */
    public function test_equivalent_phone_formats_resolve_to_one_contact(string $input): void
    {
        $account = Account::factory()->create();
        $seed = $this->resolver()->resolve($account->id, '9876543210');

        $resolved = $this->resolver()->resolve($account->id, $input);

        $this->assertSame($seed->id, $resolved->id);
        $this->assertDatabaseCount('contacts', 1);
    }

    public function test_two_accounts_may_each_hold_the_same_phone_number(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();

        $a = $this->resolver()->resolve($accountA->id, '9876543210');
        $b = $this->resolver()->resolve($accountB->id, '9876543210');

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame($accountA->id, $a->account_id);
        $this->assertSame($accountB->id, $b->account_id);
        $this->assertDatabaseCount('contacts', 2);
    }

    public function test_an_existing_name_is_never_overwritten(): void
    {
        $account = Account::factory()->create();
        $contact = Contact::factory()->forAccount($account)->create(['name' => 'Ada Lovelace']);

        $resolved = $this->resolver()->resolve($account->id, $contact->phone_number, 'ada l');

        $this->assertSame($contact->id, $resolved->id);
        $this->assertSame('Ada Lovelace', $resolved->fresh()->name);
    }

    public function test_a_missing_name_is_filled_in_when_one_arrives(): void
    {
        $account = Account::factory()->create();
        $contact = Contact::factory()->forAccount($account)->nameless()->create();

        $resolved = $this->resolver()->resolve($account->id, $contact->phone_number, 'Ada');

        $this->assertSame('Ada', $resolved->fresh()->name);
    }

    public function test_a_whitespace_only_name_does_not_count_as_a_name(): void
    {
        $account = Account::factory()->create();

        $contact = $this->resolver()->resolve($account->id, '9876543210', '   ');

        $this->assertNull($contact->fresh()->name);
    }

    public function test_a_phone_number_with_no_digits_is_rejected(): void
    {
        $account = Account::factory()->create();

        try {
            $this->resolver()->resolve($account->id, 'not a number');
            $this->fail('A digitless phone number was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('phone_number', $e->errors());
        }

        $this->assertDatabaseCount('contacts', 0);
    }

    // =================================================================
    // Lead creation
    // =================================================================

    public function test_a_lead_is_created_against_the_resolved_contact(): void
    {
        $account = Account::factory()->create();

        $lead = $this->service()->create($account, ['phone_number' => '9876543210', 'name' => 'Ada']);

        $this->assertSame($account->id, $lead->account_id);
        $this->assertSame('919876543210', $lead->contact->phone_number);
        $this->assertDatabaseCount('contacts', 1);
    }

    public function test_a_second_lead_for_the_same_person_reuses_the_contact(): void
    {
        $account = Account::factory()->create();

        $first = $this->service()->create($account, ['phone_number' => '9876543210']);
        $second = $this->service()->create($account, ['phone_number' => '+91 98765 43210']);

        $this->assertSame($first->contact_id, $second->contact_id);
        $this->assertNotSame($first->id, $second->id);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('crm_leads', 2);
    }

    public function test_the_defaults_are_new_and_manual(): void
    {
        $account = Account::factory()->create();

        $lead = $this->service()->create($account, ['phone_number' => '9876543210']);

        $this->assertSame(CrmLead::STATUS_NEW, $lead->status);
        $this->assertSame(CrmLead::SOURCE_MANUAL, $lead->source);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function supportedSourceProvider(): array
    {
        return [
            'manual' => ['manual'],
            'whatsapp' => ['whatsapp'],
            'meta_ad' => ['meta_ad'],
            'api' => ['api'],
            'journey' => ['journey'],
        ];
    }

    /**
     * @dataProvider supportedSourceProvider
     */
    public function test_every_supported_source_is_accepted_by_the_service(string $source): void
    {
        $account = Account::factory()->create();

        $lead = $this->service()->create($account, ['phone_number' => '9876543210', 'source' => $source]);

        $this->assertSame($source, $lead->fresh()->source);
    }

    public function test_an_unsupported_source_is_rejected_by_the_service(): void
    {
        $account = Account::factory()->create();

        $this->expectException(ValidationException::class);

        $this->service()->create($account, ['phone_number' => '9876543210', 'source' => 'carrier_pigeon']);
    }

    public function test_an_unsupported_status_is_rejected_by_the_service(): void
    {
        $account = Account::factory()->create();

        $this->expectException(ValidationException::class);

        $this->service()->create($account, ['phone_number' => '9876543210', 'status' => 'won']);
    }

    /**
     * The reason create() is wrapped in a transaction: a rejected lead
     * must not leave the contact it just created behind.
     */
    public function test_a_rejected_lead_does_not_leave_an_orphan_contact(): void
    {
        $account = Account::factory()->create();
        $foreignUser = User::factory()->create(['account_id' => Account::factory()->create()->id]);

        try {
            $this->service()->create($account, [
                'phone_number' => '9876543210',
                'assigned_user_id' => $foreignUser->id,
            ]);
            $this->fail('A cross-tenant assignment was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('assigned_user_id', $e->errors());
        }

        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('crm_leads', 0);
    }

    public function test_an_existing_contact_survives_a_rejected_lead(): void
    {
        $account = Account::factory()->create();
        $contact = Contact::factory()->forAccount($account)->create(['phone_number' => '919876543210']);
        $foreignUser = User::factory()->create(['account_id' => Account::factory()->create()->id]);

        try {
            $this->service()->create($account, [
                'phone_number' => '9876543210',
                'assigned_user_id' => $foreignUser->id,
            ]);
        } catch (ValidationException) {
            // expected
        }

        $this->assertDatabaseHas('contacts', ['id' => $contact->id]);
        $this->assertDatabaseCount('crm_leads', 0);
    }

    // =================================================================
    // Terminal outcome bookkeeping
    // =================================================================

    public function test_converting_a_lead_stamps_converted_at(): void
    {
        $account = Account::factory()->create();
        $lead = $this->service()->create($account, ['phone_number' => '9876543210']);

        $updated = $this->service()->update($lead, ['status' => CrmLead::STATUS_CONVERTED]);

        $this->assertNotNull($updated->converted_at);
        $this->assertNull($updated->not_converted_at);
    }

    public function test_not_converting_a_lead_stamps_the_time_and_reason(): void
    {
        $account = Account::factory()->create();
        $lead = $this->service()->create($account, ['phone_number' => '9876543210']);

        $updated = $this->service()->update($lead, [
            'status' => CrmLead::STATUS_NOT_CONVERTED,
            'not_converted_reason' => 'Budget not approved.',
        ]);

        $this->assertNotNull($updated->not_converted_at);
        $this->assertSame('Budget not approved.', $updated->not_converted_reason);
        $this->assertNull($updated->converted_at);
    }

    public function test_reopening_a_lead_clears_the_stale_outcome(): void
    {
        $account = Account::factory()->create();
        $lead = $this->service()->create($account, ['phone_number' => '9876543210']);
        $this->service()->update($lead, ['status' => CrmLead::STATUS_CONVERTED]);

        $reopened = $this->service()->update($lead->fresh(), ['status' => CrmLead::STATUS_CONTACTED]);

        $this->assertNull($reopened->converted_at);
        $this->assertNull($reopened->not_converted_at);
        $this->assertSame(CrmLead::STATUS_CONTACTED, $reopened->status);
    }

    // =================================================================
    // Factories behave
    // =================================================================

    public function test_the_lead_factory_keeps_contact_and_account_in_the_same_tenant(): void
    {
        $lead = CrmLead::factory()->create();

        $this->assertSame($lead->account_id, $lead->contact->account_id);
    }

    public function test_the_lead_factory_can_be_pinned_to_an_existing_contact(): void
    {
        $contact = Contact::factory()->create();

        $lead = CrmLead::factory()->forContact($contact)->create();

        $this->assertSame($contact->id, $lead->contact_id);
        $this->assertSame($contact->account_id, $lead->account_id);
    }
}
