<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\AccountIndustry;
use App\Models\Capability;
use App\Models\Collections\CollectionChargeAssignment;
use App\Models\Collections\CollectionPayment;
use App\Models\Contact;
use App\Models\Education\EducationStudent;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Collections\BillingCollectionService;
use App\Services\Education\EducationFeeService;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Phase 11 Task 5 — the generic collection lifecycle (pending → partially paid → paid, or cancelled) on the
 * generic core, and its Education adapter surface. Financial invariants from Task 4 are re-asserted at the edges
 * a cancel could weaken them.
 */
class CollectionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const SCOPE = 'acme_module';

    private const BASE = '/api/industry/education';

    private BillingCollectionService $core;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        $this->core = app(BillingCollectionService::class);
    }

    // ------------------------------------------------------------------ fixtures

    private function account(): Account
    {
        $account = Account::factory()->create();
        Subscription::factory()->create(['account_id' => $account->id]);

        return $account;
    }

    private function contact(Account $account, string $phone = '919811110001'): Contact
    {
        return Contact::create(['account_id' => $account->id, 'phone_number' => $phone, 'name' => 'Customer '.$phone]);
    }

    /** @return array{0: Account, 1: Contact, 2: CollectionChargeAssignment} */
    private function billed(string $amount = '100.00', ?string $due = null): array
    {
        $account = $this->account();
        $contact = $this->contact($account);
        $item = $this->core->createItem($account, self::SCOPE, ['name' => 'Fee', 'amount' => $amount]);

        return [$account, $contact, $this->core->assign($account, self::SCOPE, $contact->id, ['charge_item_id' => $item->id, 'due_date' => $due])];
    }

    private function pay(Account $account, CollectionChargeAssignment $a, string $amount): array
    {
        return $this->core->recordPayment($account, self::SCOPE, $a, ['amount' => $amount]);
    }

    private function lifeState(Account $account, CollectionChargeAssignment $a): string
    {
        return $this->core->present($account, $a->fresh('item'))['status'];
    }

    /** @return array<string, mixed> */
    private function snapshot(CollectionChargeAssignment $a): array
    {
        return [
            'assignment' => CollectionChargeAssignment::find($a->id)->getAttributes(),
            'payments' => CollectionPayment::where('charge_assignment_id', $a->id)->orderBy('id')->get()->map->getAttributes()->all(),
        ];
    }

    private function refused(callable $call, string $key): ValidationException
    {
        try {
            $call();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());

            return $e;
        }
        $this->fail("expected a validation error on '{$key}'");
    }

    // ------------------------------------------------------------------ the generic lifecycle

    public function test_the_lifecycle_is_pending_then_partially_paid_then_paid(): void
    {
        [$account, , $a] = $this->billed('100.00');
        $this->assertSame('open', $this->lifeState($account, $a));

        $this->pay($account, $a, '40.00');
        $this->assertSame('partially_paid', $this->lifeState($account, $a));
        $this->pay($account, $a, '59.99');
        $this->assertSame('partially_paid', $this->lifeState($account, $a), 'one paisa short is still partial');
        $this->pay($account, $a, '0.01');
        $this->assertSame('paid', $this->lifeState($account, $a));
        $this->assertSame('0.00', $this->core->present($account, $a->fresh('item'))['outstanding']);
    }

    public function test_overdue_comes_from_the_due_date_and_does_not_hide_a_partial_payment(): void
    {
        [$account, , $a] = $this->billed('100.00', now()->subDays(2)->toDateString());
        $this->assertSame('overdue', $this->lifeState($account, $a));
        $this->pay($account, $a, '10.00');
        $this->assertSame('overdue', $this->lifeState($account, $a));
        $this->pay($account, $a, '90.00');
        $this->assertSame('paid', $this->lifeState($account, $a));
    }

    public function test_an_unpaid_charge_can_be_cancelled_and_nothing_is_owed_on_it(): void
    {
        [$account, $contact, $a] = $this->billed('100.00');
        $user = User::factory()->create(['account_id' => $account->id]);

        $cancelled = $this->core->cancelAssignment($account, self::SCOPE, $a, '  entered by mistake  ', $user->id);

        $row = $this->core->present($account, $cancelled);
        $this->assertSame(['cancelled', '100.00', '0.00', '0.00', 'entered by mistake'], [$row['status'], $row['amount_due'], $row['amount_paid'], $row['outstanding'], $row['cancellation_reason']]);
        $this->assertNotNull($row['cancelled_at']);
        $this->assertSame($user->id, CollectionChargeAssignment::find($a->id)->cancelled_by_user_id);

        // listed, but counted nowhere
        $ledger = $this->core->ledger($account, self::SCOPE, $contact->id);
        $this->assertSame(['cancelled'], array_column($ledger['data'], 'status'));
        $this->assertSame(['total_due' => '0.00', 'total_paid' => '0.00', 'outstanding' => '0.00', 'overdue' => '0.00', 'count' => 0], $ledger['summary']);
        $this->assertCount(1, $this->core->ledger($account, self::SCOPE, $contact->id, 'cancelled')['data']);
        $this->assertCount(0, $this->core->ledger($account, self::SCOPE, $contact->id, 'open')['data']);
    }

    public function test_cancelled_charges_never_enter_the_decimal_totals(): void
    {
        $account = $this->account();
        $contact = $this->contact($account);
        $make = fn (string $name, string $amount) => $this->core->assign($account, self::SCOPE, $contact->id, ['charge_item_id' => $this->core->createItem($account, self::SCOPE, ['name' => $name, 'amount' => $amount])->id, 'due_date' => now()->subDay()->toDateString()]);
        $a = $make('a', '0.10');
        $b = $make('b', '0.20');
        $void = $make('void', '33.33');
        $this->core->cancelAssignment($account, self::SCOPE, $void);

        $summary = $this->core->ledger($account, self::SCOPE, $contact->id)['summary'];
        $this->assertSame(['0.30', '0.00', '0.30', '0.30', 2], [$summary['total_due'], $summary['total_paid'], $summary['outstanding'], $summary['overdue'], $summary['count']]);
        $this->assertSame($a->contact_id, $b->contact_id);
    }

    public function test_invalid_transitions_are_422_and_change_nothing(): void
    {
        // partially paid / paid cannot be cancelled (no refunds)
        [$account, , $partial] = $this->billed('100.00');
        $this->pay($account, $partial, '30.00');
        $before = $this->snapshot($partial);
        $e = $this->refused(fn () => $this->core->cancelAssignment($account, self::SCOPE, $partial, 'x'), 'status');
        $this->assertStringContainsString('payments', $e->errors()['status'][0]);
        $this->assertSame($before, $this->snapshot($partial));

        $this->pay($account, $partial, '70.00');
        $before = $this->snapshot($partial);
        $this->refused(fn () => $this->core->cancelAssignment($account, self::SCOPE, $partial), 'status');
        $this->assertSame($before, $this->snapshot($partial));
        $this->assertSame('paid', $this->lifeState($account, $partial));

        // only a cancelled charge can be reinstated; a charge cannot be cancelled twice
        [$account2, , $open] = $this->billed('100.00');
        $before = $this->snapshot($open);
        $this->refused(fn () => $this->core->reinstateAssignment($account2, self::SCOPE, $open), 'status');
        $this->assertSame($before, $this->snapshot($open));
        $this->core->cancelAssignment($account2, self::SCOPE, $open);
        $before = $this->snapshot($open);
        $this->refused(fn () => $this->core->cancelAssignment($account2, self::SCOPE, $open, 'again'), 'status');
        $this->assertSame($before, $this->snapshot($open), 'the first cancellation (time, reason) is not overwritten');

        // a cancelled charge cannot be paid or edited
        $this->refused(fn () => $this->pay($account2, $open, '10.00'), 'amount');
        $this->refused(fn () => $this->core->updateAssignment($account2, self::SCOPE, $open, ['amount_due' => '200.00']), 'status');
        $this->refused(fn () => $this->core->updateAssignment($account2, self::SCOPE, $open, ['due_date' => '2030-01-01']), 'status');
        $this->assertSame($before, $this->snapshot($open));
        $this->assertSame(0, CollectionPayment::where('charge_assignment_id', $open->id)->count());
    }

    public function test_a_reinstated_charge_is_payable_again_and_keeps_every_invariant(): void
    {
        [$account, , $a] = $this->billed('100.00');
        $this->core->cancelAssignment($account, self::SCOPE, $a, 'oops');

        $back = $this->core->reinstateAssignment($account, self::SCOPE, $a);

        $row = $this->core->present($account, $back);
        $this->assertSame(['open', '100.00', null, null], [$row['status'], $row['outstanding'], $row['cancelled_at'], $row['cancellation_reason']]);
        $this->pay($account, $a, '100.00');
        $this->refused(fn () => $this->pay($account, $a, '0.01'), 'amount'); // overpayment protection survives
        $this->refused(fn () => $this->core->updateAssignment($account, self::SCOPE, $a, ['amount_due' => '99.99']), 'amount_due');
        $this->assertSame('paid', $this->lifeState($account, $a));
    }

    public function test_a_cancelled_charge_can_be_assigned_again_but_not_reinstated_into_a_duplicate(): void
    {
        [$account, $contact, $a] = $this->billed('100.00', '2026-12-01');
        $this->core->cancelAssignment($account, self::SCOPE, $a);

        // the voided assignment no longer blocks the same charge + due date…
        $again = $this->core->assign($account, self::SCOPE, $contact->id, ['charge_item_id' => $a->charge_item_id, 'due_date' => '2026-12-01']);
        $this->assertNotSame($a->id, $again->id);
        // …but an ACTIVE duplicate still does
        $this->refused(fn () => $this->core->assign($account, self::SCOPE, $contact->id, ['charge_item_id' => $a->charge_item_id, 'due_date' => '2026-12-01']), 'charge_item_id');
        // and reinstating the old one would create exactly that duplicate
        $e = $this->refused(fn () => $this->core->reinstateAssignment($account, self::SCOPE, $a), 'status');
        $this->assertStringContainsString('already assigned', $e->errors()['status'][0]);
        $this->assertNotNull(CollectionChargeAssignment::find($a->id)->cancelled_at);
        $this->assertSame(1, CollectionChargeAssignment::whereNull('cancelled_at')->count());
    }

    public function test_lifecycle_fields_and_the_charge_item_are_immutable_through_an_update(): void
    {
        [$account, , $a] = $this->billed('100.00');
        $other = $this->core->createItem($account, self::SCOPE, ['name' => 'Other', 'amount' => '5.00']);
        $before = $this->snapshot($a);

        foreach ([['charge_item_id' => $other->id], ['status' => 'cancelled'], ['cancelled_at' => now()->toDateTimeString()], ['cancelled_by_user_id' => 1], ['cancellation_reason' => 'x']] as $extra) {
            $this->refused(fn () => $this->core->updateAssignment($account, self::SCOPE, $a, ['amount_due' => '120.00'] + $extra), array_key_first($extra));
        }
        $this->assertSame($before, $this->snapshot($a));
        $this->assertSame($a->charge_item_id, CollectionChargeAssignment::find($a->id)->charge_item_id);
    }

    public function test_cancel_and_reinstate_never_cross_accounts_or_scopes(): void
    {
        [$account, , $a] = $this->billed('100.00');
        $before = $this->snapshot($a);
        $stranger = $this->account();

        foreach ([
            fn () => $this->core->cancelAssignment($stranger, self::SCOPE, $a),
            fn () => $this->core->cancelAssignment($account, 'second_module', $a),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('a lifecycle change crossed a boundary');
            } catch (HttpException $e) {
                $this->assertSame(404, $e->getStatusCode());
            }
        }
        $this->core->cancelAssignment($account, self::SCOPE, $a);
        foreach ([fn () => $this->core->reinstateAssignment($stranger, self::SCOPE, $a), fn () => $this->core->reinstateAssignment($account, 'second_module', $a)] as $attempt) {
            try {
                $attempt();
                $this->fail('a reinstatement crossed a boundary');
            } catch (HttpException $e) {
                $this->assertSame(404, $e->getStatusCode());
            }
        }
        $this->assertNotNull(CollectionChargeAssignment::find($a->id)->cancelled_at);
        $this->assertNotSame($before, $this->snapshot($a));
    }

    public function test_the_lifecycle_migration_adds_only_the_cancellation_facts(): void
    {
        foreach (['cancelled_at', 'cancelled_by_user_id', 'cancellation_reason'] as $column) {
            $this->assertTrue(Schema::hasColumn('collection_charge_assignments', $column));
        }
        $this->assertFalse(Schema::hasColumn('collection_charge_assignments', 'status'), 'status stays derived, never stored');
        $this->assertFalse(Schema::hasColumn('collection_charge_assignments', 'amount_paid'));
        $this->assertFalse(Schema::hasColumn('collection_payments', 'cancelled_at'), 'payments stay immutable and uncancellable');
    }

    // ------------------------------------------------------------------ Education adapter + HTTP

    private function grant(Account $account, string $slug): void
    {
        AccountEntitlement::firstOrCreate(
            ['account_id' => $account->id, 'capability_id' => Capability::where('slug', $slug)->firstOrFail()->id],
            ['source' => 'manual_grant'],
        );
    }

    private function school(array $attributes = [], bool $billing = true): Account
    {
        $account = Account::factory()->create($attributes);
        Subscription::factory()->create(['account_id' => $account->id]);
        $this->grant($account, 'industry_education');
        if ($billing) {
            $this->grant($account, 'billing_collections');
        }
        AccountIndustry::create(['account_id' => $account->id, 'industry' => 'education', 'subtype' => 'school']);

        return $account->fresh();
    }

    private function userOf(Account $account, string $role = 'admin'): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function student(Account $account, string $name = 'Asha'): EducationStudent
    {
        static $n = 0;
        $n++;
        $contact = Contact::create(['account_id' => $account->id, 'phone_number' => '9166'.str_pad((string) $n, 8, '0', STR_PAD_LEFT), 'name' => $name]);

        return EducationStudent::create(['account_id' => $account->id, 'contact_id' => $contact->id]);
    }

    /** @return array{0: Account, 1: User, 2: EducationStudent, 3: int} an account, its admin, a student and an open 100.00 fee */
    private function enrolled(): array
    {
        $account = $this->school();
        $admin = $this->userOf($account);
        $student = $this->student($account);
        $item = $this->actingAs($admin)->postJson(self::BASE.'/fee-items', ['name' => 'Tuition', 'amount' => '100.00'])->json('data.id');
        $fee = $this->actingAs($admin)->postJson(self::BASE."/students/{$student->id}/fees", ['charge_item_id' => $item])->json('data.id');

        return [$account, $admin, $student, $fee];
    }

    private function cancelUrl(EducationStudent $s, int $fee, string $action = 'cancel'): string
    {
        return self::BASE."/students/{$s->id}/fees/{$fee}/{$action}";
    }

    public function test_cancel_and_reinstate_over_http_and_the_ledger_reflects_them(): void
    {
        [$account, $admin, $student, $fee] = $this->enrolled();

        $this->actingAs($admin)->postJson($this->cancelUrl($student, $fee), ['reason' => 'duplicate entry'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.outstanding', '0.00')->assertJsonPath('data.cancellation_reason', 'duplicate entry');
        $ledger = $this->actingAs($admin)->getJson(self::BASE."/students/{$student->id}/fees")->assertOk();
        $ledger->assertJsonPath('summary.outstanding', '0.00')->assertJsonPath('summary.total_due', '0.00')->assertJsonPath('summary.count', 0)->assertJsonPath('data.0.status', 'cancelled');
        $this->actingAs($admin)->getJson(self::BASE."/students/{$student->id}/fees?status=cancelled")->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson(self::BASE."/students/{$student->id}/fees?status=bogus")->assertStatus(422);

        $this->actingAs($admin)->postJson($this->cancelUrl($student, $fee, 'reinstate'))->assertOk()->assertJsonPath('data.status', 'open')->assertJsonPath('data.outstanding', '100.00');
        $this->actingAs($admin)->postJson(self::BASE."/students/{$student->id}/fees/{$fee}/payments", ['amount' => '100.00'])->assertCreated()->assertJsonPath('data.status', 'paid');
        $this->actingAs($admin)->postJson($this->cancelUrl($student, $fee))->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_a_cancelled_charge_refuses_payment_and_edit_over_http(): void
    {
        [$account, $admin, $student, $fee] = $this->enrolled();
        $this->actingAs($admin)->postJson($this->cancelUrl($student, $fee))->assertOk();

        $this->actingAs($admin)->postJson(self::BASE."/students/{$student->id}/fees/{$fee}/payments", ['amount' => '10.00'])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->actingAs($admin)->putJson(self::BASE."/students/{$student->id}/fees/{$fee}", ['amount_due' => '50.00'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->actingAs($admin)->postJson($this->cancelUrl($student, $fee, 'reinstate'), [])->assertOk();
        $this->assertSame(0, CollectionPayment::count());
        $this->assertEquals('100.00', CollectionChargeAssignment::first()->amount_due);
    }

    public function test_unsupported_fields_are_a_422_not_silently_ignored(): void
    {
        [$account, $admin, $student, $fee] = $this->enrolled();
        $before = CollectionChargeAssignment::first()->getAttributes();

        foreach (['status' => 'paid', 'outstanding' => '0.00', 'amount_paid' => '100.00', 'amount_due' => '1.00', 'charge_item_id' => 1, 'account_id' => 999, 'cancelled_at' => '2026-01-01', 'anything_else' => 'x'] as $field => $value) {
            $this->actingAs($admin)->postJson($this->cancelUrl($student, $fee), ['reason' => 'ok', $field => $value])->assertStatus(422)->assertJsonValidationErrors($field);
            $this->actingAs($admin)->postJson($this->cancelUrl($student, $fee, 'reinstate'), [$field => $value])->assertStatus(422)->assertJsonValidationErrors($field);
        }
        $this->actingAs($admin)->postJson($this->cancelUrl($student, $fee), ['reason' => str_repeat('x', 256)])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->actingAs($admin)->putJson(self::BASE."/students/{$student->id}/fees/{$fee}", ['amount_due' => '90.00', 'cancelled_at' => '2026-01-01'])->assertStatus(422)->assertJsonValidationErrors('cancelled_at');
        $this->actingAs($admin)->putJson(self::BASE."/students/{$student->id}/fees/{$fee}", ['amount_due' => '90.00', 'cancellation_reason' => 'x'])->assertStatus(422)->assertJsonValidationErrors('cancellation_reason');

        $this->assertSame($before, CollectionChargeAssignment::first()->getAttributes(), 'no refused request changed anything');
    }

    public function test_every_gate_applies_to_the_lifecycle_endpoints(): void
    {
        [$account, $admin, $student, $fee] = $this->enrolled();

        // permission: view-education may read the cancelled state but not change it
        Role::firstOrCreate(['name' => 'life_viewer', 'guard_name' => 'web'])->syncPermissions(['view-education']);
        Role::firstOrCreate(['name' => 'life_none', 'guard_name' => 'web'])->syncPermissions(['view-analytics']);
        foreach (['life_viewer', 'life_none'] as $role) {
            $actor = $this->userOf($account, $role);
            foreach (['cancel', 'reinstate'] as $action) {
                $this->actingAs($actor)->postJson($this->cancelUrl($student, $fee, $action))->assertForbidden();
            }
        }

        // capability: Education alone does not unlock collections
        $noBilling = $this->school([], false);
        $noBillingAdmin = $this->userOf($noBilling);
        $noBillingStudent = $this->student($noBilling);
        $this->actingAs($noBillingAdmin)->postJson($this->cancelUrl($noBillingStudent, 1))->assertForbidden()->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');

        // industry not assigned / suspended
        AccountIndustry::where('account_id', $account->id)->delete();
        $this->actingAs($admin)->postJson($this->cancelUrl($student, $fee))->assertForbidden()->assertJsonPath('error_code', 'INDUSTRY_NOT_ASSIGNED');
        AccountIndustry::create(['account_id' => $account->id, 'industry' => 'education', 'subtype' => 'school']);

        // expired subscription: reads stay, lifecycle writes are blocked
        Subscription::where('account_id', $account->id)->update(['expires_at' => now()->subDay()]);
        $this->actingAs($admin)->getJson(self::BASE."/students/{$student->id}/fees")->assertOk();
        $this->actingAs($admin)->postJson($this->cancelUrl($student, $fee))->assertForbidden();
        $this->assertNull(CollectionChargeAssignment::first()->cancelled_at);
        Subscription::where('account_id', $account->id)->update(['expires_at' => now()->addMonth()]);
        $this->actingAs($admin)->postJson($this->cancelUrl($student, $fee))->assertOk();
        Subscription::where('account_id', $account->id)->update(['expires_at' => now()->subDay()]);
        $this->actingAs($admin)->postJson($this->cancelUrl($student, $fee, 'reinstate'))->assertForbidden();
        $this->actingAs($admin)->getJson(self::BASE."/students/{$student->id}/fees?status=cancelled")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_tenant_isolation_agent_and_super_admin_rules_hold_for_the_lifecycle(): void
    {
        [$a, $adminA, $studentA, $feeA] = $this->enrolled();
        [$b, $adminB, $studentB, $feeB] = $this->enrolled();

        // another tenant: every id is a 404, a forged account id never retargets
        $this->actingAs($adminB)->postJson($this->cancelUrl($studentA, $feeA))->assertNotFound();
        $this->actingAs($adminB)->postJson($this->cancelUrl($studentB, $feeA))->assertNotFound(); // A's assignment under B's student
        $this->actingAs($adminB)->postJson($this->cancelUrl($studentA, $feeA)."?account_id={$a->id}")->assertNotFound();
        $this->assertSame(0, CollectionChargeAssignment::whereNotNull('cancelled_at')->count());

        // Agent: own client yes, a stranger's client no, itself no (children's ids are not its own)
        $agent = $this->school(['account_type' => 'agent']);
        $actor = $this->userOf($agent);
        $own = $this->school();
        $own->forceFill(['agent_id' => $agent->id])->save();
        $ownAdmin = $this->userOf($own);
        $ownStudent = $this->student($own);
        $ownItem = $this->actingAs($ownAdmin)->postJson(self::BASE.'/fee-items', ['name' => 'F', 'amount' => '10.00'])->json('data.id');
        $ownFee = $this->actingAs($ownAdmin)->postJson(self::BASE."/students/{$ownStudent->id}/fees", ['charge_item_id' => $ownItem])->json('data.id');
        $this->actingAs($actor)->postJson($this->cancelUrl($ownStudent, $ownFee)."?account_id={$own->id}")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->actingAs($actor)->postJson($this->cancelUrl($ownStudent, $ownFee, 'reinstate'))->assertNotFound();
        $this->actingAs($actor)->postJson($this->cancelUrl($studentA, $feeA)."?account_id={$a->id}")->assertNotFound();

        // Super Admin: explicit account_id required, never the Platform account
        $super = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $super->assignRole('super_admin');
        $this->actingAs($super)->postJson($this->cancelUrl($studentA, $feeA))->assertStatus(422);
        $this->actingAs($super)->postJson($this->cancelUrl($studentA, $feeA)."?account_id={$a->id}")->assertOk();
        $this->assertNull(CollectionChargeAssignment::find($feeB)->cancelled_at);
    }

    public function test_the_education_adapter_stays_an_adapter_over_the_core(): void
    {
        [$account, $admin, $student, $fee] = $this->enrolled();
        $service = app(EducationFeeService::class);

        // the adapter only resolves student → contact and pins the scope; the rules are the core's
        $cancelled = $service->cancelFee($account, $student, $fee, 'adapter call', $admin->id);
        $this->assertSame('cancelled', $service->core()->present($account, $cancelled)['status']);
        $this->assertSame($student->contact_id, $cancelled->contact_id);
        $this->assertSame('open', $service->core()->present($account, $service->reinstateFee($account, $student, $fee))['status']);

        // executable code only: comments may explain who the adapters are, but no code may know them
        $code = '';
        foreach (token_get_all(file_get_contents(base_path('app/Services/Collections/BillingCollectionService.php'))) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        foreach (['education', 'student', 'school'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $code, "the generic core must not mention '{$word}'");
        }
    }
}
