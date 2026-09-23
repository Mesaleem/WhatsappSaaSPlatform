<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\Contact;
use App\Models\CrmLead;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 6 — CRM, Task 2. HTTP coverage for /api/crm/leads.
 *
 * What this suite is actually for: proving the three route gates
 * (permission, module, capability) and the tenant boundary hold at the
 * API, not just in the domain. Domain invariants themselves belong to
 * CrmLeadDomainTest (Task 1) and CrmContactResolutionTest; this file
 * deliberately does not re-litigate them.
 *
 * Fixtures grant the `crm` capability as an explicit Super-Admin grant
 * (the same AccountEntitlement row AccountController::grantEntitlement()
 * writes) rather than by buying a plan, because the subject here is "is
 * the capability enforced", which needs a tenant that provably does not
 * hold it. The plan -> entitlement mapping has its own suite
 * (PlanCapabilityMatrixAndBackfillTest).
 */
class CrmLeadApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/crm/leads';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    /**
     * A tenant admin with an active subscription, optionally entitled to
     * CRM.
     *
     * @return array{account: Account, user: User}
     */
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
    // Capability enforcement
    // =================================================================

    public function test_an_account_without_the_crm_capability_is_refused(): void
    {
        $t = $this->tenant(withCrm: false);

        $response = $this->actingAs($t['user'])->getJson(self::ENDPOINT);

        $response->assertStatus(403);
        $response->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
    }

    public function test_an_account_without_the_crm_capability_cannot_create_either(): void
    {
        $t = $this->tenant(withCrm: false);

        $this->actingAs($t['user'])
            ->postJson(self::ENDPOINT, ['phone_number' => '9876543210'])
            ->assertStatus(403);

        $this->assertDatabaseCount('crm_leads', 0);
    }

    public function test_an_account_with_the_crm_capability_is_allowed(): void
    {
        $t = $this->tenant();

        $this->actingAs($t['user'])->getJson(self::ENDPOINT)->assertOk();
    }

    public function test_a_revoked_entitlement_stops_granting_access(): void
    {
        $t = $this->tenant();
        AccountEntitlement::where('account_id', $t['account']->id)
            ->get()
            ->each(fn (AccountEntitlement $e) => $e->forceFill(['revoked_at' => now(), 'revoked_reason' => 'manual'])->save());

        $this->actingAs($t['user'])->getJson(self::ENDPOINT)->assertStatus(403);
    }

    public function test_the_module_toggle_still_applies_on_top_of_the_capability(): void
    {
        $t = $this->tenant();
        // Every module EXCEPT lead_crm.
        $t['account']->forceFill([
            'allowed_modules' => array_values(array_diff(Account::MODULES, ['lead_crm'])),
        ])->save();

        $response = $this->actingAs($t['user'])->getJson(self::ENDPOINT);

        $response->assertStatus(403);
        $response->assertJsonPath('error_code', 'MODULE_DISABLED');
    }

    public function test_an_unauthenticated_caller_is_refused(): void
    {
        $this->getJson(self::ENDPOINT)->assertStatus(401);
    }

    // =================================================================
    // Create
    // =================================================================

    public function test_the_create_endpoint_creates_a_lead_and_its_contact(): void
    {
        $t = $this->tenant();

        $response = $this->actingAs($t['user'])->postJson(self::ENDPOINT, [
            'phone_number' => '+91 98765 43210',
            'name' => 'Ada',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'new');
        $response->assertJsonPath('data.source', 'manual');
        $response->assertJsonPath('data.contact.phone_number', '919876543210');
        $response->assertJsonPath('data.contact.name', 'Ada');
        $response->assertJsonPath('data.assigned_user', null);

        $this->assertDatabaseHas('crm_leads', [
            'id' => $response->json('data.id'),
            'account_id' => $t['account']->id,
        ]);
    }

    public function test_creating_a_second_lead_for_the_same_phone_reuses_the_contact(): void
    {
        $t = $this->tenant();

        $first = $this->actingAs($t['user'])->postJson(self::ENDPOINT, ['phone_number' => '9876543210']);
        $second = $this->actingAs($t['user'])->postJson(self::ENDPOINT, ['phone_number' => '09876543210']);

        $this->assertSame($first->json('data.contact.id'), $second->json('data.contact.id'));
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('crm_leads', 2);
    }

    public function test_a_lead_can_be_created_with_an_explicit_status_and_assignee(): void
    {
        $t = $this->tenant();
        $member = $this->member($t['account']);

        $response = $this->actingAs($t['user'])->postJson(self::ENDPOINT, [
            'phone_number' => '9876543210',
            'source' => 'manual',
            'status' => 'contacted',
            'assigned_user_id' => $member->id,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.source', 'manual');
        $response->assertJsonPath('data.status', 'contacted');
        $response->assertJsonPath('data.assigned_user.id', $member->id);
    }

    public function test_manual_creation_always_stores_source_manual(): void
    {
        $t = $this->tenant();

        $omitted = $this->actingAs($t['user'])->postJson(self::ENDPOINT, ['phone_number' => '9876543210']);
        $omitted->assertStatus(201)->assertJsonPath('data.source', CrmLead::SOURCE_MANUAL);

        $explicit = $this->actingAs($t['user'])->postJson(self::ENDPOINT, ['phone_number' => '9876543211', 'source' => 'manual']);
        $explicit->assertStatus(201)->assertJsonPath('data.source', CrmLead::SOURCE_MANUAL);

        $this->assertSame(
            [CrmLead::SOURCE_MANUAL],
            CrmLead::query()->where('account_id', $t['account']->id)->distinct()->pluck('source')->all(),
        );
    }

    public function test_manual_creation_cannot_spoof_an_automated_source(): void
    {
        $t = $this->tenant();

        foreach ([CrmLead::SOURCE_META_AD, CrmLead::SOURCE_JOURNEY, CrmLead::SOURCE_API, CrmLead::SOURCE_WHATSAPP] as $spoof) {
            $this->actingAs($t['user'])
                ->postJson(self::ENDPOINT, ['phone_number' => '9876543210', 'source' => $spoof])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['source']);
        }

        $this->assertDatabaseCount('crm_leads', 0);
        $this->assertDatabaseCount('contacts', 0);
    }

    // =================================================================
    // Validation
    // =================================================================

    public function test_a_missing_phone_number_is_a_validation_error(): void
    {
        $t = $this->tenant();

        $this->actingAs($t['user'])
            ->postJson(self::ENDPOINT, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone_number']);
    }

    public function test_an_unsupported_source_is_a_validation_error(): void
    {
        $t = $this->tenant();

        $this->actingAs($t['user'])
            ->postJson(self::ENDPOINT, ['phone_number' => '9876543210', 'source' => 'carrier_pigeon'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['source']);
    }

    public function test_an_unsupported_status_is_a_validation_error(): void
    {
        $t = $this->tenant();

        $this->actingAs($t['user'])
            ->postJson(self::ENDPOINT, ['phone_number' => '9876543210', 'status' => 'won'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_a_digitless_phone_number_is_a_validation_error(): void
    {
        $t = $this->tenant();

        $this->actingAs($t['user'])
            ->postJson(self::ENDPOINT, ['phone_number' => 'not a number'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone_number']);
    }

    public function test_an_unknown_list_filter_is_a_validation_error(): void
    {
        $t = $this->tenant();

        $this->actingAs($t['user'])
            ->getJson(self::ENDPOINT.'?status=won')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    // =================================================================
    // List / show
    // =================================================================

    public function test_the_list_endpoint_returns_only_this_accounts_leads(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();

        $mine = CrmLead::factory()->create(['account_id' => $t['account']->id]);
        $theirs = CrmLead::factory()->create(['account_id' => $other['account']->id]);

        $response = $this->actingAs($t['user'])->getJson(self::ENDPOINT);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertContains($mine->id, $ids->all());
        $this->assertNotContains($theirs->id, $ids->all());
    }

    public function test_the_list_endpoint_filters_by_status_and_assignment(): void
    {
        $t = $this->tenant();
        $member = $this->member($t['account']);

        $contacted = CrmLead::factory()->create([
            'account_id' => $t['account']->id,
            'status' => CrmLead::STATUS_CONTACTED,
            'assigned_user_id' => $member->id,
        ]);
        CrmLead::factory()->create(['account_id' => $t['account']->id]);

        $byStatus = $this->actingAs($t['user'])->getJson(self::ENDPOINT.'?status=contacted');
        $byStatus->assertOk();
        $this->assertSame([$contacted->id], collect($byStatus->json('data'))->pluck('id')->all());

        $unassigned = $this->actingAs($t['user'])->getJson(self::ENDPOINT.'?assigned_user_id=none');
        $unassigned->assertOk();
        $this->assertNotContains($contacted->id, collect($unassigned->json('data'))->pluck('id')->all());
    }

    public function test_the_show_endpoint_returns_this_accounts_lead(): void
    {
        $t = $this->tenant();
        $lead = CrmLead::factory()->create(['account_id' => $t['account']->id]);

        $this->actingAs($t['user'])
            ->getJson(self::ENDPOINT.'/'.$lead->id)
            ->assertOk()
            ->assertJsonPath('data.id', $lead->id);
    }

    // =================================================================
    // Tenant isolation over HTTP
    // =================================================================

    public function test_one_account_cannot_read_anothers_lead(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $theirs = CrmLead::factory()->create(['account_id' => $other['account']->id]);

        $this->actingAs($t['user'])
            ->getJson(self::ENDPOINT.'/'.$theirs->id)
            ->assertStatus(404);
    }

    public function test_one_account_cannot_update_anothers_lead(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $theirs = CrmLead::factory()->create(['account_id' => $other['account']->id]);

        $this->actingAs($t['user'])
            ->patchJson(self::ENDPOINT.'/'.$theirs->id, ['status' => 'contacted'])
            ->assertStatus(404);

        $this->assertSame(CrmLead::STATUS_NEW, $theirs->fresh()->status);
    }

    /**
     * A foreign id and an id that exists nowhere must be indistinguishable,
     * or the endpoint is an enumeration oracle for another tenant's rows.
     */
    public function test_a_foreign_id_and_a_missing_id_respond_identically(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $theirs = CrmLead::factory()->create(['account_id' => $other['account']->id]);

        $foreign = $this->actingAs($t['user'])->getJson(self::ENDPOINT.'/'.$theirs->id);
        $missing = $this->actingAs($t['user'])->getJson(self::ENDPOINT.'/999999');

        $this->assertSame($foreign->status(), $missing->status());
        // `message` is the whole production body for a 404 here. The
        // comparison is deliberately on that key rather than the full
        // payload: with APP_DEBUG=true the test environment also renders
        // `exception`/`file`/`line`/`trace`, whose line numbers differ
        // between the two calls in this very test file — an artifact of
        // the debug renderer, not of the endpoint.
        $this->assertSame($foreign->json('message'), $missing->json('message'));
        $this->assertNull($foreign->json('data'));
        $this->assertNull($missing->json('data'));
    }

    /**
     * The API never accepts a contact id, so "using another account's
     * contact" can only be attempted by posting their phone number. The
     * correct outcome is a NEW contact under the caller's own account —
     * never a reference to the other tenant's row.
     */
    public function test_posting_another_accounts_phone_number_creates_an_own_contact(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $theirContact = Contact::factory()->forAccount($other['account'])->create(['phone_number' => '919876543210']);

        $response = $this->actingAs($t['user'])->postJson(self::ENDPOINT, ['phone_number' => '9876543210']);

        $response->assertStatus(201);
        $this->assertNotSame($theirContact->id, $response->json('data.contact.id'));
        $this->assertSame($t['account']->id, Contact::find($response->json('data.contact.id'))->account_id);
        $this->assertSame(0, $theirContact->fresh()->crmLeads()->count());
    }

    public function test_a_lead_cannot_be_assigned_to_another_accounts_user(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();

        $this->actingAs($t['user'])
            ->postJson(self::ENDPOINT, [
                'phone_number' => '9876543210',
                'assigned_user_id' => $other['user']->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['assigned_user_id']);

        $this->assertDatabaseCount('crm_leads', 0);
    }

    public function test_an_existing_lead_cannot_be_reassigned_to_another_accounts_user(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $lead = CrmLead::factory()->create(['account_id' => $t['account']->id]);

        $this->actingAs($t['user'])
            ->patchJson(self::ENDPOINT.'/'.$lead->id, ['assigned_user_id' => $other['user']->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['assigned_user_id']);

        $this->assertNull($lead->fresh()->assigned_user_id);
    }

    // =================================================================
    // Update
    // =================================================================

    public function test_the_update_endpoint_changes_status_and_assignment(): void
    {
        $t = $this->tenant();
        $member = $this->member($t['account']);
        $lead = CrmLead::factory()->create(['account_id' => $t['account']->id]);

        $response = $this->actingAs($t['user'])->patchJson(self::ENDPOINT.'/'.$lead->id, [
            'status' => 'converted',
            'assigned_user_id' => $member->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'converted');
        $response->assertJsonPath('data.assigned_user.id', $member->id);
        $this->assertNotNull($response->json('data.converted_at'));
    }

    public function test_a_lead_can_be_explicitly_unassigned(): void
    {
        $t = $this->tenant();
        $member = $this->member($t['account']);
        $lead = CrmLead::factory()->assignedTo($member)->create(['account_id' => $t['account']->id]);

        $this->actingAs($t['user'])
            ->patchJson(self::ENDPOINT.'/'.$lead->id, ['assigned_user_id' => null])
            ->assertOk()
            ->assertJsonPath('data.assigned_user', null);
    }

    public function test_the_update_endpoint_can_correct_the_contact_name(): void
    {
        $t = $this->tenant();
        $contact = Contact::factory()->forAccount($t['account'])->create(['name' => 'Typo']);
        $lead = CrmLead::factory()->forContact($contact)->create();

        $this->actingAs($t['user'])
            ->patchJson(self::ENDPOINT.'/'.$lead->id, ['name' => 'Ada Lovelace'])
            ->assertOk()
            ->assertJsonPath('data.contact.name', 'Ada Lovelace');
    }

    public function test_put_and_patch_behave_identically(): void
    {
        $t = $this->tenant();
        $lead = CrmLead::factory()->create(['account_id' => $t['account']->id]);

        $this->actingAs($t['user'])
            ->putJson(self::ENDPOINT.'/'.$lead->id, ['status' => 'contacted'])
            ->assertOk()
            ->assertJsonPath('data.status', 'contacted');
    }

    /**
     * account_id and contact_id are not in the update rules, so they are
     * never in validated() and can never be written — whatever a client
     * sends.
     */
    public function test_the_update_endpoint_ignores_attempts_to_move_a_lead(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $theirContact = Contact::factory()->forAccount($other['account'])->create();
        $lead = CrmLead::factory()->create(['account_id' => $t['account']->id]);

        $this->actingAs($t['user'])
            ->patchJson(self::ENDPOINT.'/'.$lead->id, [
                'status' => 'contacted',
                'account_id' => $other['account']->id,
                'contact_id' => $theirContact->id,
            ])
            ->assertOk();

        $fresh = $lead->fresh();
        $this->assertSame($t['account']->id, $fresh->account_id);
        $this->assertSame($lead->contact_id, $fresh->contact_id);
    }

    public function test_an_unsupported_status_is_rejected_on_update(): void
    {
        $t = $this->tenant();
        $lead = CrmLead::factory()->create(['account_id' => $t['account']->id]);

        $this->actingAs($t['user'])
            ->patchJson(self::ENDPOINT.'/'.$lead->id, ['status' => 'negotiation'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    // =================================================================
    // The existing leads domain is untouched
    // =================================================================

    public function test_the_existing_social_leads_endpoint_still_works(): void
    {
        $t = $this->tenant();

        $this->actingAs($t['user'])->getJson('/api/social/leads')->assertOk();
    }
}
