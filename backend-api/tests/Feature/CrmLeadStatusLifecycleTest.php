<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\ApiKey;
use App\Models\Capability;
use App\Models\Contact;
use App\Models\CrmLead;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Access\PlanEntitlementReconciliationService;
use App\Services\Access\PlanManagementService;
use App\Services\Crm\CaptureLeadLinker;
use App\Services\Crm\CrmLeadService;
use App\Models\Lead;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 6 — CRM Task 5. Lead status and lifecycle.
 *
 * The four canonical statuses are new / contacted / converted /
 * not_converted and nothing else. Both write paths funnel through one
 * service step, every cell of the transition matrix is exercised
 * explicitly, and a no-op must leave no trace.
 */
class CrmLeadStatusLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const LEADS = '/api/crm/leads';

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

        if ($entitlement->revoked_at !== null) {
            $entitlement->forceFill(['revoked_at' => null, 'revoked_reason' => null])->save();
        }
    }

    /** A lead already sitting in $status, built through the service so its invariants hold. */
    private function leadAt(Account $account, string $status = CrmLead::STATUS_NEW): CrmLead
    {
        $contact = Contact::factory()->forAccount($account)->create();
        $lead = CrmLead::factory()->forContact($contact)->create();

        if ($status !== CrmLead::STATUS_NEW) {
            app(CrmLeadService::class)->changeStatus($lead, $status);
        }

        return $lead->fresh();
    }

    private function statusUrl(CrmLead $lead): string
    {
        return self::LEADS.'/'.$lead->id.'/status';
    }

    // =================================================================
    // Canonical vocabulary
    // =================================================================

    public function test_exactly_four_canonical_statuses_exist(): void
    {
        $this->assertSame(
            ['new', 'contacted', 'converted', 'not_converted'],
            CrmLead::STATUSES,
        );

        foreach (['qualified', 'proposal', 'negotiation', 'won', 'lost'] as $forbidden) {
            $this->assertNotContains($forbidden, CrmLead::STATUSES);
        }
    }

    public function test_a_new_lead_defaults_to_new(): void
    {
        $t = $this->tenant();

        $this->actingAs($t['user'])
            ->postJson(self::LEADS, ['phone_number' => '9876543210'])
            ->assertStatus(201)
            ->assertJsonPath('data.status', CrmLead::STATUS_NEW);
    }

    public function test_creation_accepts_a_canonical_status_and_rejects_anything_else(): void
    {
        $t = $this->tenant();

        $this->actingAs($t['user'])
            ->postJson(self::LEADS, ['phone_number' => '9876543210', 'status' => 'contacted'])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'contacted');

        foreach (['qualified', 'won', 'lost', 'foo'] as $invalid) {
            $this->actingAs($t['user'])
                ->postJson(self::LEADS, ['phone_number' => '9000000001', 'status' => $invalid])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['status']);
        }
    }

    /**
     * An empty string on CREATION is "no status supplied", not an
     * invalid one, so the deterministic default applies.
     *
     * That is Laravel's app-wide ConvertEmptyStringsToNull middleware,
     * not a CRM decision: '' and null are indistinguishable by the time
     * any request class sees them, throughout this application. On the
     * dedicated status endpoint — where a status is the entire point of
     * the call — the same conversion makes '' fail `required`, which is
     * the behaviour that actually matters and is asserted separately.
     */
    public function test_an_empty_status_on_creation_falls_back_to_the_default(): void
    {
        $t = $this->tenant();

        $this->actingAs($t['user'])
            ->postJson(self::LEADS, ['phone_number' => '9876543210', 'status' => ''])
            ->assertStatus(201)
            ->assertJsonPath('data.status', CrmLead::STATUS_NEW);
    }

    public function test_an_empty_status_is_rejected_by_the_status_endpoint(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account']);

        $this->actingAs($t['user'])
            ->patchJson($this->statusUrl($lead), ['status' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->assertSame(CrmLead::STATUS_NEW, $lead->fresh()->status);
    }

    // =================================================================
    // Dedicated status endpoint
    // =================================================================

    public function test_the_status_endpoint_updates_the_lifecycle(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account']);

        $response = $this->actingAs($t['user'])
            ->patchJson($this->statusUrl($lead), ['status' => 'contacted']);

        $response->assertOk();
        $response->assertJsonPath('message', 'Lead status updated.');
        $response->assertJsonPath('data.id', $lead->id);
        $response->assertJsonPath('data.status', 'contacted');

        $this->assertSame('contacted', $lead->fresh()->status);
    }

    public function test_the_status_endpoint_records_a_not_converted_reason(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account']);

        $this->actingAs($t['user'])
            ->patchJson($this->statusUrl($lead), [
                'status' => 'not_converted',
                'not_converted_reason' => 'Budget not approved.',
            ])
            ->assertOk()
            ->assertJsonPath('data.not_converted_reason', 'Budget not approved.');

        $fresh = $lead->fresh();
        $this->assertNotNull($fresh->not_converted_at);
        $this->assertNull($fresh->converted_at);
    }

    public function test_an_absent_status_is_rejected_by_the_status_endpoint(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account']);

        $this->actingAs($t['user'])
            ->patchJson($this->statusUrl($lead), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->assertSame(CrmLead::STATUS_NEW, $lead->fresh()->status);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidStatusProvider(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'unknown word' => ['foo'],
            'forbidden stage qualified' => ['qualified'],
            'forbidden stage won' => ['won'],
            'forbidden stage lost' => ['lost'],
            'numeric' => [1],
            'array' => [['contacted']],
            'object' => [['status' => 'contacted']],
            'wrong case' => ['Contacted'],
        ];
    }

    /**
     * Surrounding whitespace is trimmed before validation by Laravel's
     * app-wide TrimStrings middleware, so " contacted " is the same
     * input as "contacted" throughout this application. Asserted rather
     * than left implicit, because it is the one case that looks like it
     * should be rejected and is not.
     */
    public function test_surrounding_whitespace_is_trimmed_not_rejected(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account']);

        $this->actingAs($t['user'])
            ->patchJson($this->statusUrl($lead), ['status' => ' contacted '])
            ->assertOk()
            ->assertJsonPath('data.status', 'contacted');
    }

    /** @dataProvider invalidStatusProvider */
    public function test_invalid_status_input_is_rejected(mixed $status): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account']);

        $this->actingAs($t['user'])
            ->patchJson($this->statusUrl($lead), ['status' => $status])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->assertSame(CrmLead::STATUS_NEW, $lead->fresh()->status);
    }

    // =================================================================
    // Transition matrix — every cell, explicitly
    // =================================================================

    /** @return array<string, array{string, string}> */
    public static function transitionProvider(): array
    {
        $cases = [];

        foreach (CrmLead::STATUSES as $from) {
            foreach (CrmLead::STATUSES as $to) {
                if ($from !== $to) {
                    $cases["{$from} -> {$to}"] = [$from, $to];
                }
            }
        }

        return $cases;
    }

    /**
     * All twelve distinct moves. Every one is permitted by product
     * decision — a mistaken outcome must be correctable — and each must
     * leave the outcome columns consistent with the destination.
     *
     * @dataProvider transitionProvider
     */
    public function test_every_declared_transition_is_accepted(string $from, string $to): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account'], $from);
        $this->assertSame($from, $lead->status);

        $this->actingAs($t['user'])
            ->patchJson($this->statusUrl($lead), ['status' => $to])
            ->assertOk()
            ->assertJsonPath('data.status', $to);

        $fresh = $lead->fresh();
        $this->assertSame($to, $fresh->status);

        // The invariants the destination implies.
        match ($to) {
            CrmLead::STATUS_CONVERTED => (function () use ($fresh) {
                $this->assertNotNull($fresh->converted_at);
                $this->assertNull($fresh->not_converted_at);
                $this->assertNull($fresh->not_converted_reason);
            })(),
            CrmLead::STATUS_NOT_CONVERTED => (function () use ($fresh) {
                $this->assertNotNull($fresh->not_converted_at);
                $this->assertNull($fresh->converted_at);
            })(),
            default => (function () use ($fresh) {
                $this->assertNull($fresh->converted_at);
                $this->assertNull($fresh->not_converted_at);
                $this->assertNull($fresh->not_converted_reason);
            })(),
        };
    }

    public function test_the_transition_matrix_is_declared_and_complete(): void
    {
        $this->assertSame(CrmLead::STATUSES, array_keys(CrmLead::STATUS_TRANSITIONS));

        foreach (CrmLead::STATUSES as $from) {
            $this->assertSame(CrmLead::STATUSES, CrmLead::STATUS_TRANSITIONS[$from]);
            $this->assertTrue(CrmLead::canTransitionTo($from, $from), 'A status must be a legal no-op destination.');
        }

        // Fails closed on anything outside the vocabulary, either end.
        $this->assertFalse(CrmLead::canTransitionTo('new', 'won'));
        $this->assertFalse(CrmLead::canTransitionTo('won', 'new'));
        $this->assertFalse(CrmLead::canTransitionTo(null, 'new'));
    }

    /**
     * A correction out of a terminal state must not leave the previous
     * outcome behind — the row can never contradict itself.
     */
    public function test_correcting_a_terminal_outcome_clears_the_stale_one(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account']);

        $this->actingAs($t['user'])->patchJson($this->statusUrl($lead), [
            'status' => 'not_converted',
            'not_converted_reason' => 'Wrong call.',
        ])->assertOk();

        $this->actingAs($t['user'])->patchJson($this->statusUrl($lead), ['status' => 'converted'])->assertOk();

        $fresh = $lead->fresh();
        $this->assertNotNull($fresh->converted_at);
        $this->assertNull($fresh->not_converted_at);
        $this->assertNull($fresh->not_converted_reason, 'A converted lead cannot keep a not-converted reason.');

        $this->actingAs($t['user'])->patchJson($this->statusUrl($lead), ['status' => 'new'])->assertOk();

        $reopened = $lead->fresh();
        $this->assertNull($reopened->converted_at);
        $this->assertNull($reopened->not_converted_at);
    }

    // =================================================================
    // Only the lifecycle moves
    // =================================================================

    public function test_a_status_change_leaves_every_other_field_alone(): void
    {
        $t = $this->tenant();
        $member = User::factory()->create(['account_id' => $t['account']->id, 'is_active' => true]);
        $member->givePermissionTo('manage-crm');

        $capture = Lead::create([
            'account_id' => $t['account']->id,
            'provider' => 'meta',
            'provider_lead_id' => 'meta:'.uniqid(),
            'lead_phone' => '919876543210',
        ]);
        $lead = app(CaptureLeadLinker::class)->link($capture);
        $lead->forceFill(['assigned_user_id' => $member->id])->save();

        $before = $lead->fresh();

        $this->actingAs($t['user'])
            ->patchJson($this->statusUrl($lead), ['status' => 'contacted'])
            ->assertOk();

        $after = $lead->fresh();
        $this->assertSame('contacted', $after->status);
        $this->assertSame($before->account_id, $after->account_id);
        $this->assertSame($before->contact_id, $after->contact_id);
        $this->assertSame($before->capture_lead_id, $after->capture_lead_id);
        $this->assertSame($before->source, $after->source);
        $this->assertSame($before->assigned_user_id, $after->assigned_user_id);
        $this->assertEquals($before->created_at, $after->created_at);

        // The capture row is untouched by a lifecycle move.
        $this->assertDatabaseHas('leads', ['id' => $capture->id]);
    }

    public function test_a_no_op_status_change_writes_nothing(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account'], CrmLead::STATUS_CONVERTED);
        $before = $lead->fresh();

        $fired = 0;
        CrmLead::updated(function () use (&$fired): void {
            $fired++;
        });

        $this->actingAs($t['user'])
            ->patchJson($this->statusUrl($lead), ['status' => 'converted'])
            ->assertOk()
            ->assertJsonPath('data.status', 'converted');

        $this->assertSame(0, $fired, 'A no-op lifecycle move must not emit a status change.');

        $after = $lead->fresh();
        $this->assertEquals($before->updated_at, $after->updated_at);
        // Crucially: the terminal timestamp was NOT re-stamped.
        $this->assertEquals($before->converted_at, $after->converted_at);
    }

    // =================================================================
    // Both write paths behave identically
    // =================================================================

    public function test_the_general_update_route_still_changes_status(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account']);

        $this->actingAs($t['user'])
            ->patchJson(self::LEADS.'/'.$lead->id, ['status' => 'converted'])
            ->assertOk()
            ->assertJsonPath('data.status', 'converted');

        $this->assertNotNull($lead->fresh()->converted_at);
    }

    public function test_an_absent_status_on_the_general_route_leaves_the_lifecycle_alone(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account'], CrmLead::STATUS_CONTACTED);

        $this->actingAs($t['user'])
            ->patchJson(self::LEADS.'/'.$lead->id, ['name' => 'Renamed Contact'])
            ->assertOk();

        $this->assertSame('contacted', $lead->fresh()->status);
    }

    public function test_both_paths_reject_the_same_input_the_same_way(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account']);

        $viaGeneral = $this->actingAs($t['user'])->patchJson(self::LEADS.'/'.$lead->id, ['status' => 'won']);
        $viaDedicated = $this->actingAs($t['user'])->patchJson($this->statusUrl($lead), ['status' => 'won']);

        $this->assertSame($viaGeneral->status(), $viaDedicated->status());
        $this->assertSame($viaGeneral->json('errors'), $viaDedicated->json('errors'));
        $this->assertSame(CrmLead::STATUS_NEW, $lead->fresh()->status);
    }

    public function test_both_paths_produce_the_same_resulting_data(): void
    {
        $t = $this->tenant();
        $a = $this->leadAt($t['account']);
        $b = $this->leadAt($t['account']);

        $viaGeneral = $this->actingAs($t['user'])->patchJson(self::LEADS.'/'.$a->id, ['status' => 'converted']);
        $viaDedicated = $this->actingAs($t['user'])->patchJson($this->statusUrl($b), ['status' => 'converted']);

        $this->assertSame(
            array_keys($viaGeneral->json('data')),
            array_keys($viaDedicated->json('data')),
        );
        $this->assertSame('converted', $viaGeneral->json('data.status'));
        $this->assertSame('converted', $viaDedicated->json('data.status'));
        $this->assertNotNull($viaGeneral->json('data.converted_at'));
        $this->assertNotNull($viaDedicated->json('data.converted_at'));
    }

    public function test_neither_path_can_bypass_the_transition_check(): void
    {
        $t = $this->tenant();

        $viaService = $this->leadAt($t['account']);

        // The service is the single authority both routes call.
        try {
            app(CrmLeadService::class)->changeStatus($viaService, 'won');
            $this->fail('An unsupported status reached the database.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $this->assertSame(CrmLead::STATUS_NEW, $viaService->fresh()->status);
    }

    // =================================================================
    // Tenant isolation
    // =================================================================

    public function test_another_accounts_lead_cannot_be_moved(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $theirLead = $this->leadAt($other['account']);

        $this->actingAs($t['user'])
            ->patchJson($this->statusUrl($theirLead), ['status' => 'converted'])
            ->assertStatus(404);

        $this->assertSame(CrmLead::STATUS_NEW, $theirLead->fresh()->status);
    }

    public function test_a_foreign_lead_and_a_missing_lead_respond_identically(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $theirLead = $this->leadAt($other['account']);

        $foreign = $this->actingAs($t['user'])->patchJson($this->statusUrl($theirLead), ['status' => 'contacted']);
        $missing = $this->actingAs($t['user'])->patchJson(self::LEADS.'/999999/status', ['status' => 'contacted']);

        $this->assertSame($foreign->status(), $missing->status());
        $this->assertSame($foreign->json('message'), $missing->json('message'));
    }

    public function test_a_spoofed_tenant_in_the_body_is_ignored(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $lead = $this->leadAt($t['account']);

        $this->actingAs($t['user'])->patchJson($this->statusUrl($lead), [
            'status' => 'contacted',
            'account_id' => $other['account']->id,
            'agent_id' => $other['account']->id,
            'tenant_id' => $other['account']->id,
        ])->assertOk();

        $this->assertSame($t['account']->id, $lead->fresh()->account_id);
        $this->assertSame('contacted', $lead->fresh()->status);
    }

    // =================================================================
    // Authorization
    // =================================================================

    public function test_an_unauthenticated_caller_cannot_change_status(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account']);

        $this->patchJson($this->statusUrl($lead), ['status' => 'contacted'])->assertStatus(401);
    }

    public function test_the_crm_capability_is_required(): void
    {
        $t = $this->tenant(withCrm: false);
        $lead = $this->leadAt($t['account']);

        $this->actingAs($t['user'])
            ->patchJson($this->statusUrl($lead), ['status' => 'contacted'])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');

        $this->assertSame(CrmLead::STATUS_NEW, $lead->fresh()->status);
    }

    public function test_the_lead_crm_module_is_required(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account']);
        $t['account']->forceFill(['allowed_modules' => array_values(array_diff(Account::MODULES, ['lead_crm']))])->save();

        $this->actingAs($t['user'])
            ->patchJson($this->statusUrl($lead), ['status' => 'contacted'])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'MODULE_DISABLED');
    }

    /** Role built before the first actingAs() — see the Round 2 guard-configuration note. */
    public function test_a_caller_without_manage_crm_cannot_change_status(): void
    {
        $t = $this->tenant();
        $role = Role::create(['name' => 'status_denied']);
        $role->givePermissionTo('manage-social-leads');

        $caller = User::factory()->create(['account_id' => $t['account']->id, 'is_active' => true]);
        $caller->assignRole($role);

        $lead = $this->leadAt($t['account']);

        $this->actingAs($caller)
            ->patchJson($this->statusUrl($lead), ['status' => 'contacted'])
            ->assertStatus(403);

        $this->assertSame(CrmLead::STATUS_NEW, $lead->fresh()->status);
    }

    // =================================================================
    // Audit
    // =================================================================

    /**
     * The activity_logs ROW cannot be asserted under PHPUnit —
     * LogsActivity::recordActivity() returns early on
     * app()->runningInConsole(), which every test run is. This asserts
     * the `updated` event that drives it, including that an
     * authenticated actor is present when it fires (the other half of
     * that early-return condition, and the value written as user_id).
     * Same technique as the Task 4 assignment audit tests.
     */
    public function test_the_whole_lifecycle_is_auditable(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account']);

        $events = [];
        CrmLead::updated(function (CrmLead $model) use (&$events): void {
            $events[] = [
                'actor' => Auth::id(),
                'lead_id' => $model->getKey(),
                'old' => $model->getOriginal('status'),
                'new' => $model->getChanges()['status'] ?? null,
                'at' => $model->updated_at,
            ];
        });

        foreach (['contacted', 'converted', 'not_converted'] as $target) {
            $this->actingAs($t['user'])
                ->patchJson($this->statusUrl($lead), ['status' => $target])
                ->assertOk();
        }

        $this->assertCount(3, $events);
        $this->assertSame(['new', 'contacted', 'converted'], array_column($events, 'old'));
        $this->assertSame(['contacted', 'converted', 'not_converted'], array_column($events, 'new'));

        foreach ($events as $event) {
            $this->assertSame($t['user']->id, $event['actor'], 'LogsActivity writes Auth::id() as the actor.');
            $this->assertSame($lead->id, $event['lead_id']);
            $this->assertNotNull($event['at']);
        }
    }

    public function test_the_general_route_produces_the_same_audit_event(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account']);

        $captured = null;
        CrmLead::updated(function (CrmLead $model) use (&$captured): void {
            $captured = ['old' => $model->getOriginal('status'), 'new' => $model->getChanges()['status'] ?? null];
        });

        $this->actingAs($t['user'])
            ->patchJson(self::LEADS.'/'.$lead->id, ['status' => 'contacted'])
            ->assertOk();

        $this->assertSame(['old' => 'new', 'new' => 'contacted'], $captured);
    }

    public function test_the_service_layer_produces_the_same_audit_event(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account']);

        $captured = null;
        CrmLead::updated(function (CrmLead $model) use (&$captured): void {
            $captured = $model->getChanges();
        });

        app(CrmLeadService::class)->changeStatus($lead, 'contacted');

        $this->assertArrayHasKey('status', $captured);
        $this->assertSame('contacted', $captured['status']);
    }

    // =================================================================
    // Capability / plan behaviour
    // =================================================================

    public function test_revoking_crm_blocks_new_status_changes_but_preserves_the_stored_one(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account'], CrmLead::STATUS_CONTACTED);

        AccountEntitlement::query()
            ->where('account_id', $t['account']->id)
            ->get()
            ->each(fn (AccountEntitlement $e) => $e->forceFill([
                'revoked_at' => now(),
                'revoked_reason' => AccountEntitlement::REVOKED_PLAN_DOWNGRADE,
            ])->save());

        $this->actingAs($t['user'])
            ->patchJson($this->statusUrl($lead), ['status' => 'converted'])
            ->assertStatus(403);

        // The stored lifecycle state is untouched by a capability change.
        $this->assertSame('contacted', $lead->fresh()->status);

        $this->grantCrm($t['account']->fresh());

        $this->actingAs($t['user'])
            ->patchJson($this->statusUrl($lead), ['status' => 'converted'])
            ->assertOk();

        $this->assertSame('converted', $lead->fresh()->status);
    }

    public function test_plan_reconciliation_never_rewrites_statuses(): void
    {
        $t = $this->tenant();
        $converted = $this->leadAt($t['account'], CrmLead::STATUS_CONVERTED);
        $contacted = $this->leadAt($t['account'], CrmLead::STATUS_CONTACTED);

        app(PlanEntitlementReconciliationService::class)->reconcile($t['account']->fresh());

        $this->assertSame('converted', $converted->fresh()->status);
        $this->assertSame('contacted', $contacted->fresh()->status);
    }

    public function test_a_plan_can_carry_and_drop_the_crm_capability(): void
    {
        $service = app(PlanManagementService::class);

        $plan = $service->create('lifecycle-plan', ['label' => 'P', 'price' => 1, 'duration_days' => 30], ['crm']);
        $this->assertTrue($plan->capabilities->pluck('slug')->contains('crm'));

        $removed = $service->modify($plan->fresh(), [], []);
        $this->assertContains('crm', $removed['removed']);
    }

    // =================================================================
    // Filters
    // =================================================================

    /** @return array<string, array{string}> */
    public static function canonicalStatusProvider(): array
    {
        return [
            'new' => ['new'],
            'contacted' => ['contacted'],
            'converted' => ['converted'],
            'not_converted' => ['not_converted'],
        ];
    }

    /** @dataProvider canonicalStatusProvider */
    public function test_each_status_filter_returns_only_that_status(string $status): void
    {
        $t = $this->tenant();

        $wanted = [];
        foreach (CrmLead::STATUSES as $s) {
            $lead = $this->leadAt($t['account'], $s);
            if ($s === $status) {
                $wanted[] = $lead->id;
            }
        }

        $response = $this->actingAs($t['user'])->getJson(self::LEADS.'?status='.$status);

        $response->assertOk();
        $this->assertSame($wanted, collect($response->json('data'))->pluck('id')->all());
    }

    /** @return array<string, array{string}> */
    public static function invalidStatusFilterProvider(): array
    {
        return [
            'unknown' => ['foo'],
            'forbidden stage' => ['won'],
            'wrong case' => ['New'],
        ];
    }

    /** @dataProvider invalidStatusFilterProvider */
    public function test_an_invalid_status_filter_is_a_validation_error(string $status): void
    {
        $t = $this->tenant();

        $this->actingAs($t['user'])
            ->getJson(self::LEADS.'?status='.urlencode($status))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    /**
     * ?status= with no value means "no status filter", not an invalid
     * one — ConvertEmptyStringsToNull again, and the `nullable` rule
     * every CRM list filter already uses. It returns the unfiltered,
     * still account-scoped list rather than an error.
     */
    public function test_an_empty_status_filter_is_treated_as_absent(): void
    {
        $t = $this->tenant();
        $this->leadAt($t['account']);
        $this->leadAt($t['account'], CrmLead::STATUS_CONVERTED);

        $response = $this->actingAs($t['user'])->getJson(self::LEADS.'?status=');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_status_filtering_stays_account_scoped(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $mine = $this->leadAt($t['account'], CrmLead::STATUS_CONVERTED);
        $theirs = $this->leadAt($other['account'], CrmLead::STATUS_CONVERTED);

        $response = $this->actingAs($t['user'])->getJson(self::LEADS.'?status=converted');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$mine->id], $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    public function test_status_filtering_composes_with_source_ownership_and_pagination(): void
    {
        $t = $this->tenant();
        $member = User::factory()->create(['account_id' => $t['account']->id, 'is_active' => true]);
        $member->givePermissionTo('manage-crm');

        $wanted = $this->leadAt($t['account'], CrmLead::STATUS_CONTACTED);
        $wanted->forceFill(['source' => CrmLead::SOURCE_META_AD, 'assigned_user_id' => $member->id])->save();

        $wrongStatus = $this->leadAt($t['account']);
        $wrongStatus->forceFill(['source' => CrmLead::SOURCE_META_AD, 'assigned_user_id' => $member->id])->save();

        $wrongOwner = $this->leadAt($t['account'], CrmLead::STATUS_CONTACTED);
        $wrongOwner->forceFill(['source' => CrmLead::SOURCE_META_AD])->save();

        $response = $this->actingAs($t['user'])->getJson(
            self::LEADS.'?status=contacted&source=meta_ad&assigned_user_id='.$member->id.'&per_page=5',
        );

        $response->assertOk();
        $this->assertSame([$wanted->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertSame(5, $response->json('per_page'));
    }

    public function test_the_contact_leads_endpoint_filters_by_status_too(): void
    {
        $t = $this->tenant();
        $contact = Contact::factory()->forAccount($t['account'])->create();
        $converted = CrmLead::factory()->forContact($contact)->create();
        app(CrmLeadService::class)->changeStatus($converted, CrmLead::STATUS_CONVERTED);
        CrmLead::factory()->forContact($contact)->create();

        $response = $this->actingAs($t['user'])
            ->getJson('/api/crm/contacts/'.$contact->id.'/leads?status=converted');

        $response->assertOk();
        $this->assertSame([$converted->id], collect($response->json('data'))->pluck('id')->all());
    }

    // =================================================================
    // Serialization
    // =================================================================

    public function test_status_is_serialized_identically_everywhere(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account'], CrmLead::STATUS_CONVERTED);
        $contact = $lead->contact;

        $fromShow = $this->actingAs($t['user'])->getJson(self::LEADS.'/'.$lead->id)->json('data');
        $fromIndex = collect($this->actingAs($t['user'])->getJson(self::LEADS)->json('data'))->firstWhere('id', $lead->id);
        $fromContact = collect($this->actingAs($t['user'])->getJson('/api/crm/contacts/'.$contact->id.'/leads')->json('data'))->firstWhere('id', $lead->id);
        $fromStatusEndpoint = $this->actingAs($t['user'])->patchJson($this->statusUrl($lead), ['status' => 'contacted'])->json('data');

        $this->assertSame('converted', $fromShow['status']);
        $this->assertSame('converted', $fromIndex['status']);
        $this->assertSame('converted', $fromContact['status']);
        $this->assertSame('contacted', $fromStatusEndpoint['status']);

        // One serializer, so one shape.
        $this->assertSame(array_keys($fromShow), array_keys($fromIndex));
        $this->assertSame(array_keys($fromShow), array_keys($fromContact));
        $this->assertSame(array_keys($fromShow), array_keys($fromStatusEndpoint));
    }

    // =================================================================
    // Developer API contract is unchanged
    // =================================================================

    public function test_the_developer_api_still_accepts_only_canonical_statuses(): void
    {
        $t = $this->tenant();

        $plain = 'sk_test_'.bin2hex(random_bytes(12));
        ApiKey::create([
            'account_id' => $t['account']->id,
            'name' => 'Key',
            'key_prefix' => substr($plain, 0, 10),
            'key_hash' => ApiKey::hashKey($plain),
        ]);

        $this->withHeader('X-API-KEY', $plain)
            ->postJson(self::V1_LEADS, ['phone_number' => '9876543210', 'status' => 'contacted'])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'contacted');

        $this->withHeader('X-API-KEY', $plain)
            ->postJson(self::V1_LEADS, ['phone_number' => '9000000002', 'status' => 'won'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->withHeader('X-API-KEY', $plain)
            ->postJson(self::V1_LEADS, ['phone_number' => '9000000003'])
            ->assertStatus(201)
            ->assertJsonPath('data.status', CrmLead::STATUS_NEW);
    }

    /**
     * Task 5 asserted there was no Developer API status endpoint. Task 11
     * adds one deliberately; it must go through the same lifecycle step
     * (transition + outcome bookkeeping) as the tenant API.
     */
    public function test_the_developer_api_status_endpoint_uses_the_lifecycle_service(): void
    {
        $t = $this->tenant();
        $lead = $this->leadAt($t['account']);

        $plain = 'sk_test_'.bin2hex(random_bytes(12));
        ApiKey::create([
            'account_id' => $t['account']->id,
            'name' => 'Key',
            'key_prefix' => substr($plain, 0, 10),
            'key_hash' => ApiKey::hashKey($plain),
        ]);

        $this->withHeader('X-API-KEY', $plain)
            ->patchJson(self::V1_LEADS.'/'.$lead->id.'/status', ['status' => 'converted'])
            ->assertOk()
            ->assertJsonPath('data.status', CrmLead::STATUS_CONVERTED);

        $this->assertSame(CrmLead::STATUS_CONVERTED, $lead->fresh()->status);
        $this->assertNotNull($lead->fresh()->converted_at);
    }

    // =================================================================
    // Capture integrations keep their initial status
    // =================================================================

    /** @return array<string, array{string}> */
    public static function captureProviderProvider(): array
    {
        return [
            'meta' => ['meta'],
            'journey' => ['whatsapp_journey'],
            'click to whatsapp' => ['whatsapp_ctwa'],
        ];
    }

    /** @dataProvider captureProviderProvider */
    public function test_a_captured_lead_still_starts_at_new(string $provider): void
    {
        $t = $this->tenant();

        $capture = Lead::create([
            'account_id' => $t['account']->id,
            'provider' => $provider,
            'provider_lead_id' => $provider.':'.uniqid(),
            'lead_phone' => '919876543210',
        ]);

        $crmLead = app(CaptureLeadLinker::class)->link($capture);

        $this->assertSame(CrmLead::STATUS_NEW, $crmLead->status);
        $this->assertNull($crmLead->converted_at);
        $this->assertNull($crmLead->not_converted_at);
        $this->assertSame($capture->id, $crmLead->capture_lead_id);
    }

    public function test_moving_a_captured_lead_through_the_lifecycle_preserves_its_capture(): void
    {
        $t = $this->tenant();

        $capture = Lead::create([
            'account_id' => $t['account']->id,
            'provider' => 'meta',
            'provider_lead_id' => 'meta:'.uniqid(),
            'lead_phone' => '919876543210',
            'raw_field_data' => ['verbatim' => 'PROVIDER_PAYLOAD'],
        ]);
        $crmLead = app(CaptureLeadLinker::class)->link($capture);
        $captureBefore = $capture->fresh()->toArray();

        $this->actingAs($t['user'])
            ->patchJson($this->statusUrl($crmLead), ['status' => 'converted'])
            ->assertOk();

        $this->assertSame($capture->id, $crmLead->fresh()->capture_lead_id);
        $this->assertSame($captureBefore, $capture->fresh()->toArray());
    }
}
