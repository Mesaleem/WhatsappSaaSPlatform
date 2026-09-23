<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\ApiKey;
use App\Models\Capability;
use App\Models\Contact;
use App\Models\CrmLead;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Access\PlanEntitlementReconciliationService;
use App\Services\Access\PlanManagementService;
use App\Services\Crm\CrmLeadService;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 6 — CRM Task 4. Lead assignment and ownership.
 *
 * `crm_leads.assigned_user_id` is the only ownership field and this
 * suite treats it as such: assign, reassign and unassign are the same
 * write with a different argument, both API paths funnel through one
 * service method, and eligibility for a NEW assignment is a different
 * question from whether a HISTORICAL assignment stays put.
 */
class CrmLeadAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private const LEADS = '/api/crm/leads';

    private const ASSIGNEES = '/api/crm/assignees';

    private const V1_LEADS = '/api/v1/crm/leads';

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

        // A revoked row still exists (that is the point of revoked_at —
        // a deliberate revocation is never silently reversed by a
        // backfill), so re-granting has to clear it rather than rely on
        // firstOrCreate.
        if ($entitlement->revoked_at !== null) {
            $entitlement->forceFill(['revoked_at' => null, 'revoked_reason' => null])->save();
        }
    }

    /** An eligible CRM assignee: same account, active, holds manage-crm. */
    private function member(Account $account, bool $active = true, bool $crm = true): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => $active]);

        if ($crm) {
            $user->givePermissionTo('manage-crm');
        }

        return $user;
    }

    private function leadFor(Account $account, ?User $assignee = null): CrmLead
    {
        $contact = Contact::factory()->forAccount($account)->create();

        $factory = CrmLead::factory()->forContact($contact);

        return $assignee ? $factory->assignedTo($assignee)->create() : $factory->create();
    }

    private function assigneeUrl(CrmLead $lead): string
    {
        return self::LEADS.'/'.$lead->id.'/assignee';
    }

    // =================================================================
    // Assign / reassign / unassign
    // =================================================================

    public function test_an_unassigned_lead_can_be_assigned(): void
    {
        $t = $this->tenant();
        $member = $this->member($t['account']);
        $lead = $this->leadFor($t['account']);

        $response = $this->actingAs($t['user'])
            ->patchJson($this->assigneeUrl($lead), ['assigned_user_id' => $member->id]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Lead assigned.');
        $response->assertJsonPath('data.assigned_user_id', $member->id);
        $response->assertJsonPath('data.assigned_user.id', $member->id);
        $response->assertJsonPath('data.assigned_user.name', $member->name);

        $this->assertSame($member->id, $lead->fresh()->assigned_user_id);
    }

    public function test_an_assigned_lead_can_be_reassigned(): void
    {
        $t = $this->tenant();
        $from = $this->member($t['account']);
        $to = $this->member($t['account']);
        $lead = $this->leadFor($t['account'], $from);

        $this->actingAs($t['user'])
            ->patchJson($this->assigneeUrl($lead), ['assigned_user_id' => $to->id])
            ->assertOk()
            ->assertJsonPath('data.assigned_user_id', $to->id);

        $this->assertSame($to->id, $lead->fresh()->assigned_user_id);
    }

    public function test_a_lead_can_be_explicitly_unassigned(): void
    {
        $t = $this->tenant();
        $member = $this->member($t['account']);
        $lead = $this->leadFor($t['account'], $member);

        $response = $this->actingAs($t['user'])
            ->patchJson($this->assigneeUrl($lead), ['assigned_user_id' => null]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Lead unassigned.');
        $response->assertJsonPath('data.assigned_user_id', null);
        $response->assertJsonPath('data.assigned_user', null);

        $this->assertNull($lead->fresh()->assigned_user_id);
        // Unassigning is not deleting.
        $this->assertDatabaseHas('crm_leads', ['id' => $lead->id]);
    }

    /** An ownership endpoint called with no ownership field is a mistake, not a no-op. */
    public function test_an_absent_field_is_rejected_by_the_assignee_endpoint(): void
    {
        $t = $this->tenant();
        $member = $this->member($t['account']);
        $lead = $this->leadFor($t['account'], $member);

        $this->actingAs($t['user'])
            ->patchJson($this->assigneeUrl($lead), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['assigned_user_id']);

        $this->assertSame($member->id, $lead->fresh()->assigned_user_id);
    }

    /** @return array<string, array{string}> */
    public static function ownershipOperationProvider(): array
    {
        return ['assign' => ['assign'], 'reassign' => ['reassign'], 'unassign' => ['unassign']];
    }

    /**
     * Only ownership moves. Everything that makes the lead what it is —
     * tenant, contact, capture origin, source, status, outcome
     * timestamps — must be byte-identical afterwards.
     *
     * @dataProvider ownershipOperationProvider
     */
    public function test_only_the_ownership_field_changes(string $operation): void
    {
        $t = $this->tenant();
        $first = $this->member($t['account']);
        $second = $this->member($t['account']);

        $lead = $this->leadFor($t['account'], $operation === 'assign' ? null : $first);
        $lead->forceFill([
            'status' => CrmLead::STATUS_NOT_CONVERTED,
            'not_converted_at' => now()->subDay(),
            'not_converted_reason' => 'Budget.',
            'source' => CrmLead::SOURCE_META_AD,
        ])->save();

        $before = $lead->fresh();

        $payload = match ($operation) {
            'assign' => $first->id,
            'reassign' => $second->id,
            'unassign' => null,
        };

        $this->actingAs($t['user'])
            ->patchJson($this->assigneeUrl($lead), ['assigned_user_id' => $payload])
            ->assertOk();

        $after = $lead->fresh();
        $this->assertSame($payload, $after->assigned_user_id);
        $this->assertSame($before->account_id, $after->account_id);
        $this->assertSame($before->contact_id, $after->contact_id);
        $this->assertSame($before->capture_lead_id, $after->capture_lead_id);
        $this->assertSame($before->source, $after->source);
        $this->assertSame($before->status, $after->status);
        $this->assertEquals($before->not_converted_at, $after->not_converted_at);
        $this->assertSame($before->not_converted_reason, $after->not_converted_reason);
        $this->assertEquals($before->created_at, $after->created_at);
    }

    /**
     * Reassigning to the user who already owns the lead must not write
     * phantom ownership history. Eloquent's dirty checking means no
     * `updated` event fires, so LogsActivity records nothing.
     */
    public function test_reassigning_to_the_current_owner_writes_no_audit_event(): void
    {
        $t = $this->tenant();
        $member = $this->member($t['account']);
        $lead = $this->leadFor($t['account'], $member);
        $updatedAtBefore = $lead->fresh()->updated_at;

        $fired = 0;
        CrmLead::updated(function () use (&$fired): void {
            $fired++;
        });

        $this->actingAs($t['user'])
            ->patchJson($this->assigneeUrl($lead), ['assigned_user_id' => $member->id])
            ->assertOk()
            ->assertJsonPath('data.assigned_user_id', $member->id);

        $this->assertSame(0, $fired, 'A no-op reassignment must not emit an ownership change.');
        $this->assertEquals($updatedAtBefore, $lead->fresh()->updated_at);
    }

    // =================================================================
    // Eligibility — fails closed, one message for every reason
    // =================================================================

    /** @return array<string, array{string}> */
    public static function ineligibleAssigneeProvider(): array
    {
        return [
            'foreign account' => ['foreign'],
            'nonexistent' => ['missing'],
            'inactive' => ['inactive'],
            'no crm permission' => ['no_crm'],
        ];
    }

    /** @dataProvider ineligibleAssigneeProvider */
    public function test_an_ineligible_assignee_is_rejected(string $kind): void
    {
        $t = $this->tenant();
        $lead = $this->leadFor($t['account']);

        $targetId = match ($kind) {
            'foreign' => $this->member($this->tenant()['account'])->id,
            'missing' => 999999,
            'inactive' => $this->member($t['account'], active: false)->id,
            'no_crm' => $this->member($t['account'], crm: false)->id,
        };

        $this->actingAs($t['user'])
            ->patchJson($this->assigneeUrl($lead), ['assigned_user_id' => $targetId])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['assigned_user_id']);

        $this->assertNull($lead->fresh()->assigned_user_id);
    }

    /**
     * All four rejection reasons must be indistinguishable, or the
     * endpoint becomes a way to probe another account's user ids and to
     * learn who holds which permission.
     */
    public function test_every_rejection_reason_looks_identical(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $lead = $this->leadFor($t['account']);

        $bodies = [];

        foreach ([
            $this->member($other['account'])->id,
            999999,
            $this->member($t['account'], active: false)->id,
            $this->member($t['account'], crm: false)->id,
        ] as $targetId) {
            $response = $this->actingAs($t['user'])
                ->patchJson($this->assigneeUrl($lead), ['assigned_user_id' => $targetId]);

            $bodies[] = [$response->status(), $response->json('errors')];
        }

        $this->assertCount(1, collect($bodies)->unique(fn ($b) => json_encode($b)));
    }

    // =================================================================
    // Tenant isolation
    // =================================================================

    public function test_another_accounts_lead_cannot_be_assigned(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $theirLead = $this->leadFor($other['account']);
        $myMember = $this->member($t['account']);

        $this->actingAs($t['user'])
            ->patchJson($this->assigneeUrl($theirLead), ['assigned_user_id' => $myMember->id])
            ->assertStatus(404);

        $this->assertNull($theirLead->fresh()->assigned_user_id);
    }

    public function test_another_accounts_lead_cannot_be_unassigned(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $theirMember = $this->member($other['account']);
        $theirLead = $this->leadFor($other['account'], $theirMember);

        $this->actingAs($t['user'])
            ->patchJson($this->assigneeUrl($theirLead), ['assigned_user_id' => null])
            ->assertStatus(404);

        $this->assertSame($theirMember->id, $theirLead->fresh()->assigned_user_id);
    }

    public function test_a_foreign_lead_and_a_missing_lead_respond_identically(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $theirLead = $this->leadFor($other['account']);
        $member = $this->member($t['account']);

        $foreign = $this->actingAs($t['user'])->patchJson($this->assigneeUrl($theirLead), ['assigned_user_id' => $member->id]);
        $missing = $this->actingAs($t['user'])->patchJson(self::LEADS.'/999999/assignee', ['assigned_user_id' => $member->id]);

        $this->assertSame($foreign->status(), $missing->status());
        $this->assertSame($foreign->json('message'), $missing->json('message'));
    }

    public function test_a_request_supplied_account_cannot_escape_the_tenant_scope(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $lead = $this->leadFor($t['account']);
        $member = $this->member($t['account']);

        $this->actingAs($t['user'])->patchJson($this->assigneeUrl($lead), [
            'assigned_user_id' => $member->id,
            'account_id' => $other['account']->id,
            'agent_id' => $other['account']->id,
            'tenant_id' => $other['account']->id,
        ])->assertOk();

        $this->assertSame($t['account']->id, $lead->fresh()->account_id);
        $this->assertSame($member->id, $lead->fresh()->assigned_user_id);
    }

    // =================================================================
    // Authorization — permission, module, capability
    // =================================================================

    public function test_assignment_requires_the_crm_capability(): void
    {
        $t = $this->tenant(withCrm: false);
        $member = $this->member($t['account']);
        $lead = $this->leadFor($t['account']);

        $this->actingAs($t['user'])
            ->patchJson($this->assigneeUrl($lead), ['assigned_user_id' => $member->id])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');

        $this->assertNull($lead->fresh()->assigned_user_id);
    }

    public function test_assignment_requires_the_lead_crm_module(): void
    {
        $t = $this->tenant();
        $t['account']->forceFill(['allowed_modules' => array_values(array_diff(Account::MODULES, ['lead_crm']))])->save();
        $member = $this->member($t['account']);
        $lead = $this->leadFor($t['account']);

        $this->actingAs($t['user'])
            ->patchJson($this->assigneeUrl($lead), ['assigned_user_id' => $member->id])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'MODULE_DISABLED');
    }

    /**
     * Role and user are built BEFORE the first actingAs(): once a
     * request has authenticated through Sanctum, Spatie resolves the
     * default guard as 'sanctum' while every permission row carries
     * 'web', so a later givePermissionTo() would throw. Pre-existing
     * guard-configuration quirk, disclosed in the Round 2 report.
     */
    public function test_a_caller_without_manage_crm_cannot_assign(): void
    {
        $t = $this->tenant();
        $role = Role::create(['name' => 'assignment_denied']);
        $role->givePermissionTo('manage-social-leads');

        $caller = User::factory()->create(['account_id' => $t['account']->id, 'is_active' => true]);
        $caller->assignRole($role);

        $member = $this->member($t['account']);
        $lead = $this->leadFor($t['account']);

        $this->actingAs($caller)
            ->patchJson($this->assigneeUrl($lead), ['assigned_user_id' => $member->id])
            ->assertStatus(403);

        $this->assertNull($lead->fresh()->assigned_user_id);
    }

    public function test_an_unauthenticated_caller_cannot_assign(): void
    {
        $t = $this->tenant();
        $lead = $this->leadFor($t['account']);

        $this->patchJson($this->assigneeUrl($lead), ['assigned_user_id' => $this->member($t['account'])->id])
            ->assertStatus(401);
    }

    // =================================================================
    // Both API paths share one implementation
    // =================================================================

    public function test_the_general_update_route_still_assigns(): void
    {
        $t = $this->tenant();
        $member = $this->member($t['account']);
        $lead = $this->leadFor($t['account']);

        $this->actingAs($t['user'])
            ->patchJson(self::LEADS.'/'.$lead->id, ['assigned_user_id' => $member->id])
            ->assertOk()
            ->assertJsonPath('data.assigned_user_id', $member->id);

        $this->actingAs($t['user'])
            ->patchJson(self::LEADS.'/'.$lead->id, ['assigned_user_id' => null])
            ->assertOk()
            ->assertJsonPath('data.assigned_user_id', null);
    }

    public function test_an_absent_field_on_the_general_route_leaves_ownership_alone(): void
    {
        $t = $this->tenant();
        $member = $this->member($t['account']);
        $lead = $this->leadFor($t['account'], $member);

        $this->actingAs($t['user'])
            ->patchJson(self::LEADS.'/'.$lead->id, ['status' => 'contacted'])
            ->assertOk();

        $this->assertSame($member->id, $lead->fresh()->assigned_user_id);
        $this->assertSame('contacted', $lead->fresh()->status);
    }

    /** Neither path may become a way around the other's rules. */
    public function test_both_paths_reject_the_same_assignees_the_same_way(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $foreign = $this->member($other['account']);
        $lead = $this->leadFor($t['account']);

        $viaGeneral = $this->actingAs($t['user'])->patchJson(self::LEADS.'/'.$lead->id, ['assigned_user_id' => $foreign->id]);
        $viaDedicated = $this->actingAs($t['user'])->patchJson($this->assigneeUrl($lead), ['assigned_user_id' => $foreign->id]);

        $this->assertSame($viaGeneral->status(), $viaDedicated->status());
        $this->assertSame($viaGeneral->json('errors'), $viaDedicated->json('errors'));
        $this->assertNull($lead->fresh()->assigned_user_id);
    }

    // =================================================================
    // Audit
    // =================================================================

    /**
     * The full ownership lifecycle NULL -> A -> B -> NULL, asserting the
     * actor, the lead, and the old/new value at each step.
     *
     * The activity_logs ROW cannot be asserted here — LogsActivity's
     * recordActivity() returns early under app()->runningInConsole(),
     * which every PHPUnit run is. So this asserts the `updated` event
     * that drives it, including that an authenticated actor is present
     * at the moment it fires (the other half of that early-return
     * condition), which is what LogsActivity writes as user_id.
     */
    public function test_the_whole_ownership_lifecycle_is_auditable(): void
    {
        $t = $this->tenant();
        $a = $this->member($t['account']);
        $b = $this->member($t['account']);
        $lead = $this->leadFor($t['account']);

        $events = [];
        CrmLead::updated(function (CrmLead $model) use (&$events): void {
            $events[] = [
                'actor' => Auth::id(),
                'lead_id' => $model->getKey(),
                'old' => $model->getOriginal('assigned_user_id'),
                'new' => $model->getChanges()['assigned_user_id'] ?? null,
                'at' => $model->updated_at,
            ];
        });

        foreach ([$a->id, $b->id, null] as $target) {
            $this->actingAs($t['user'])
                ->patchJson($this->assigneeUrl($lead), ['assigned_user_id' => $target])
                ->assertOk();
        }

        $this->assertCount(3, $events);

        $this->assertSame([null, $a->id, $b->id], array_map(fn ($e) => $e['old'] === null ? null : (int) $e['old'], $events));
        $this->assertSame([$a->id, $b->id, null], array_map(fn ($e) => $e['new'] === null ? null : (int) $e['new'], $events));

        foreach ($events as $event) {
            $this->assertSame($t['user']->id, $event['actor'], 'LogsActivity writes Auth::id() as the actor.');
            $this->assertSame($lead->id, $event['lead_id']);
            $this->assertNotNull($event['at']);
        }
    }

    public function test_the_general_route_produces_the_same_audit_event(): void
    {
        $t = $this->tenant();
        $member = $this->member($t['account']);
        $lead = $this->leadFor($t['account']);

        $captured = null;
        CrmLead::updated(function (CrmLead $model) use (&$captured): void {
            $captured = $model->getChanges();
        });

        $this->actingAs($t['user'])
            ->patchJson(self::LEADS.'/'.$lead->id, ['assigned_user_id' => $member->id])
            ->assertOk();

        $this->assertArrayHasKey('assigned_user_id', $captured);
        $this->assertSame($member->id, (int) $captured['assigned_user_id']);
    }

    public function test_the_service_layer_produces_the_same_audit_event(): void
    {
        $t = $this->tenant();
        $member = $this->member($t['account']);
        $lead = $this->leadFor($t['account']);

        $captured = null;
        CrmLead::updated(function (CrmLead $model) use (&$captured): void {
            $captured = $model->getChanges();
        });

        app(CrmLeadService::class)->changeAssignee($lead, $member->id);

        $this->assertArrayHasKey('assigned_user_id', $captured);
    }

    // =================================================================
    // Historical assignment preservation
    // =================================================================

    /**
     * The nine-step scenario the brief specifies, in order.
     */
    public function test_a_historical_assignment_survives_the_owner_becoming_ineligible(): void
    {
        $t = $this->tenant();
        $member = $this->member($t['account']);
        $replacement = $this->member($t['account']);
        $lead = $this->leadFor($t['account']);

        // 1. Assign to an active CRM user.
        $this->actingAs($t['user'])
            ->patchJson($this->assigneeUrl($lead), ['assigned_user_id' => $member->id])
            ->assertOk();

        // 2. Deactivate them.
        $member->forceFill(['is_active' => false])->save();

        // 3. The historical assignment stands.
        $this->assertSame($member->id, $lead->fresh()->assigned_user_id);

        // 4. They are no longer an eligible candidate.
        $candidates = collect($this->actingAs($t['user'])->getJson(self::ASSIGNEES)->json('data'))->pluck('id');
        $this->assertNotContains($member->id, $candidates->all());

        // 5. A new assignment to them is refused.
        $other = $this->leadFor($t['account']);
        $this->actingAs($t['user'])
            ->patchJson($this->assigneeUrl($other), ['assigned_user_id' => $member->id])
            ->assertStatus(422);

        // 6-7. Remove CRM capability from them; the assignment still stands.
        $member->forceFill(['is_active' => true])->save();
        $member->revokePermissionTo('manage-crm');
        $this->assertSame($member->id, $lead->fresh()->assigned_user_id);

        // 8. A new assignment to them is still refused.
        $this->actingAs($t['user'])
            ->patchJson($this->assigneeUrl($other), ['assigned_user_id' => $member->id])
            ->assertStatus(422);

        // 9. An explicit reassignment to an eligible user succeeds.
        $this->actingAs($t['user'])
            ->patchJson($this->assigneeUrl($lead), ['assigned_user_id' => $replacement->id])
            ->assertOk();

        $this->assertSame($replacement->id, $lead->fresh()->assigned_user_id);
    }

    public function test_an_unrelated_edit_never_drops_a_now_ineligible_owner(): void
    {
        $t = $this->tenant();
        $member = $this->member($t['account']);
        $lead = $this->leadFor($t['account'], $member);

        $member->forceFill(['is_active' => false])->save();

        $this->actingAs($t['user'])
            ->patchJson(self::LEADS.'/'.$lead->id, ['status' => 'contacted'])
            ->assertOk();

        $this->assertSame($member->id, $lead->fresh()->assigned_user_id);
    }

    /** The FK is ON DELETE SET NULL: deleting a member unassigns, never deletes the lead. */
    public function test_deleting_the_owner_unassigns_rather_than_deleting_the_lead(): void
    {
        $t = $this->tenant();
        $member = $this->member($t['account']);
        $lead = $this->leadFor($t['account'], $member);

        $member->delete();

        $this->assertDatabaseHas('crm_leads', ['id' => $lead->id, 'assigned_user_id' => null]);
    }

    // =================================================================
    // Capability / plan behaviour
    // =================================================================

    public function test_revoking_crm_blocks_new_assignment_but_preserves_the_existing_one(): void
    {
        $t = $this->tenant();
        $member = $this->member($t['account']);
        $replacement = $this->member($t['account']);
        $lead = $this->leadFor($t['account'], $member);

        AccountEntitlement::query()
            ->where('account_id', $t['account']->id)
            ->get()
            ->each(fn (AccountEntitlement $e) => $e->forceFill([
                'revoked_at' => now(),
                'revoked_reason' => AccountEntitlement::REVOKED_PLAN_DOWNGRADE,
            ])->save());

        $this->actingAs($t['user'])
            ->patchJson($this->assigneeUrl($lead), ['assigned_user_id' => $replacement->id])
            ->assertStatus(403);

        // Ownership history is untouched by a capability change.
        $this->assertSame($member->id, $lead->fresh()->assigned_user_id);

        // Re-enable: normal rules apply again.
        $this->grantCrm($t['account']->fresh());

        $this->actingAs($t['user'])
            ->patchJson($this->assigneeUrl($lead), ['assigned_user_id' => $replacement->id])
            ->assertOk();

        $this->assertSame($replacement->id, $lead->fresh()->assigned_user_id);
    }

    public function test_plan_reconciliation_never_rewrites_ownership(): void
    {
        $t = $this->tenant();
        $member = $this->member($t['account']);
        $lead = $this->leadFor($t['account'], $member);

        app(PlanEntitlementReconciliationService::class)->reconcile($t['account']->fresh());

        $this->assertSame($member->id, $lead->fresh()->assigned_user_id);
    }

    public function test_a_plan_can_carry_and_drop_the_crm_capability(): void
    {
        $service = app(PlanManagementService::class);

        $plan = $service->create('assignment-plan', ['label' => 'P', 'price' => 1, 'duration_days' => 30], ['crm']);
        $this->assertTrue($plan->capabilities->pluck('slug')->contains('crm'));

        $removed = $service->modify($plan->fresh(), [], []);
        $this->assertContains('crm', $removed['removed']);
        $this->assertTrue(Plan::query()->where('slug', 'assignment-plan')->exists());
    }

    // =================================================================
    // Ownership filters
    // =================================================================

    public function test_the_ownership_filter_returns_only_that_users_leads(): void
    {
        $t = $this->tenant();
        $a = $this->member($t['account']);
        $b = $this->member($t['account']);
        $mine = $this->leadFor($t['account'], $a);
        $theirs = $this->leadFor($t['account'], $b);
        $unassigned = $this->leadFor($t['account']);

        $response = $this->actingAs($t['user'])->getJson(self::LEADS.'?assigned_user_id='.$a->id);

        $response->assertOk();
        $this->assertSame([$mine->id], collect($response->json('data'))->pluck('id')->all());
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($theirs->id, $ids);
        $this->assertNotContains($unassigned->id, $ids);
    }

    public function test_the_none_filter_returns_only_unassigned_leads(): void
    {
        $t = $this->tenant();
        $member = $this->member($t['account']);
        $assigned = $this->leadFor($t['account'], $member);
        $unassigned = $this->leadFor($t['account']);

        $response = $this->actingAs($t['user'])->getJson(self::LEADS.'?assigned_user_id=none');

        $response->assertOk();
        $this->assertSame([$unassigned->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertNotContains($assigned->id, collect($response->json('data'))->pluck('id')->all());
    }

    /** @return array<string, array{string}> */
    public static function invalidOwnershipFilterProvider(): array
    {
        return [
            'letters' => ['abc'],
            'zero' => ['0'],
            'negative' => ['-1'],
            'mixed' => ['12abc'],
            'null literal' => ['null'],
        ];
    }

    /** @dataProvider invalidOwnershipFilterProvider */
    public function test_an_invalid_ownership_filter_is_a_validation_error(string $value): void
    {
        $t = $this->tenant();

        $this->actingAs($t['user'])
            ->getJson(self::LEADS.'?assigned_user_id='.urlencode($value))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['assigned_user_id']);
    }

    public function test_a_foreign_user_id_in_the_filter_reveals_nothing(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $theirMember = $this->member($other['account']);
        $this->leadFor($other['account'], $theirMember);

        $response = $this->actingAs($t['user'])->getJson(self::LEADS.'?assigned_user_id='.$theirMember->id);

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
    }

    public function test_ownership_filtering_composes_with_the_existing_filters(): void
    {
        $t = $this->tenant();
        $member = $this->member($t['account']);

        $wanted = $this->leadFor($t['account'], $member);
        $wanted->forceFill(['status' => CrmLead::STATUS_CONTACTED, 'source' => CrmLead::SOURCE_META_AD])->save();

        $wrongStatus = $this->leadFor($t['account'], $member);
        $wrongOwner = $this->leadFor($t['account']);
        $wrongOwner->forceFill(['status' => CrmLead::STATUS_CONTACTED, 'source' => CrmLead::SOURCE_META_AD])->save();

        $response = $this->actingAs($t['user'])->getJson(
            self::LEADS.'?assigned_user_id='.$member->id.'&status=contacted&source=meta_ad&per_page=10',
        );

        $response->assertOk();
        $this->assertSame([$wanted->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertSame(10, $response->json('per_page'));
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($wrongStatus->id, $ids);
        $this->assertNotContains($wrongOwner->id, $ids);
    }

    // =================================================================
    // Assignee listing
    // =================================================================

    public function test_the_assignee_list_returns_only_eligible_users(): void
    {
        $t = $this->tenant();
        $eligible = $this->member($t['account']);
        $inactive = $this->member($t['account'], active: false);
        $noCrm = $this->member($t['account'], crm: false);
        $foreign = $this->member($this->tenant()['account']);

        $response = $this->actingAs($t['user'])->getJson(self::ASSIGNEES);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($eligible->id, $ids);
        // The acting admin holds manage-crm through its role, so it is a
        // valid assignee too.
        $this->assertContains($t['user']->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
        $this->assertNotContains($noCrm->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
    }

    public function test_the_assignee_list_exposes_nothing_beyond_id_and_name(): void
    {
        $t = $this->tenant();
        $member = $this->member($t['account']);

        $response = $this->actingAs($t['user'])->getJson(self::ASSIGNEES);

        $response->assertOk();
        foreach ($response->json('data') as $row) {
            $this->assertSame(['id', 'name'], array_keys($row));
        }

        $body = $response->getContent();
        $this->assertStringNotContainsString($member->email, $body);
        $this->assertStringNotContainsString('password', $body);
        $this->assertStringNotContainsString('remember_token', $body);
    }

    public function test_the_assignee_list_cannot_be_pointed_at_another_account(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $foreign = $this->member($other['account']);

        $response = $this->actingAs($t['user'])
            ->getJson(self::ASSIGNEES.'?account_id='.$other['account']->id.'&search='.urlencode($foreign->name));

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
    }

    public function test_the_assignee_list_requires_the_crm_capability(): void
    {
        $noCap = $this->tenant(withCrm: false);

        $this->actingAs($noCap['user'])
            ->getJson(self::ASSIGNEES)
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
    }

    public function test_the_assignee_list_requires_authentication(): void
    {
        // No actingAs() anywhere in this test: actingAs() persists for
        // the rest of the test case, so an unauthenticated assertion has
        // to stand on its own.
        $this->getJson(self::ASSIGNEES)->assertStatus(401);
    }

    public function test_everyone_the_assignee_list_offers_can_actually_be_assigned(): void
    {
        $t = $this->tenant();
        $this->member($t['account']);
        $this->member($t['account'], active: false);
        $this->member($t['account'], crm: false);
        $lead = $this->leadFor($t['account']);

        foreach ($this->actingAs($t['user'])->getJson(self::ASSIGNEES)->json('data') as $row) {
            $this->actingAs($t['user'])
                ->patchJson($this->assigneeUrl($lead), ['assigned_user_id' => $row['id']])
                ->assertOk();
        }
    }

    // =================================================================
    // Developer API stays out of assignment
    // =================================================================

    public function test_the_developer_api_still_ignores_a_supplied_assignee(): void
    {
        $t = $this->tenant();
        $member = $this->member($t['account']);

        $plain = 'sk_test_'.bin2hex(random_bytes(12));
        ApiKey::create([
            'account_id' => $t['account']->id,
            'name' => 'Key',
            'key_prefix' => substr($plain, 0, 10),
            'key_hash' => ApiKey::hashKey($plain),
        ]);

        $response = $this->withHeader('X-API-KEY', $plain)->postJson(self::V1_LEADS, [
            'phone_number' => '9876543210',
            'assigned_user_id' => $member->id,
        ]);

        $response->assertStatus(201);
        $this->assertNull(CrmLead::findOrFail($response->json('data.id'))->assigned_user_id);
    }

    // =================================================================
    // Concurrency
    // =================================================================

    /**
     * Two requests racing to assign the same lead. Each loaded the row
     * before either wrote, so both saves run against the same original
     * state — the interleaving a lock would prevent.
     *
     * The acceptable outcome, per the brief, is that the final value is
     * ONE valid assignee and no invalid state exists. Both writes are
     * individually validated by the model guard, so last-write-wins is
     * safe here and no lock is needed.
     */
    public function test_racing_assignments_leave_one_valid_owner(): void
    {
        $t = $this->tenant();
        $a = $this->member($t['account']);
        $b = $this->member($t['account']);
        $lead = $this->leadFor($t['account']);

        $first = CrmLead::findOrFail($lead->id);
        $second = CrmLead::findOrFail($lead->id);

        $service = app(CrmLeadService::class);
        $service->changeAssignee($first, $a->id);
        $service->changeAssignee($second, $b->id);

        $final = $lead->fresh()->assigned_user_id;
        $this->assertContains($final, [$a->id, $b->id]);
        $this->assertTrue(CrmLead::assigneeIsEligible(User::find($final), (int) $t['account']->id));
        $this->assertDatabaseCount('crm_leads', 1);
    }

    /** A racing write can never land an ineligible owner, whatever the interleaving. */
    public function test_a_racing_write_cannot_land_a_foreign_assignee(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $valid = $this->member($t['account']);
        $foreign = $this->member($other['account']);
        $lead = $this->leadFor($t['account']);

        $first = CrmLead::findOrFail($lead->id);
        $second = CrmLead::findOrFail($lead->id);

        $service = app(CrmLeadService::class);
        $service->changeAssignee($first, $valid->id);

        try {
            $service->changeAssignee($second, $foreign->id);
            $this->fail('A cross-tenant assignment was accepted.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame($valid->id, $lead->fresh()->assigned_user_id);
    }
}
