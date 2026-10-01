<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\AccountIndustry;
use App\Models\Capability;
use App\Models\Collections\CollectionChargeAssignment;
use App\Models\Collections\CollectionChargeItem;
use App\Models\Collections\CollectionPayment;
use App\Models\Contact;
use App\Models\Education\EducationStudent;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Access\AccessControlService;
use App\Services\Collections\BillingCollectionService;
use App\Services\Education\EducationFeeService;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 11 Task 4 — Education fees: the Education ADAPTER over the generic collections core, through the real
 * routes and the real gate (industry_education + the shared billing_collections capability).
 */
class EducationFeesTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/industry/education';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    // ------------------------------------------------------------------ fixtures

    private function grant(Account $account, string $slug): void
    {
        AccountEntitlement::firstOrCreate(
            ['account_id' => $account->id, 'capability_id' => Capability::where('slug', $slug)->firstOrFail()->id],
            ['source' => 'manual_grant'],
        );
    }

    /** An Education account that ALSO holds the generic billing capability (unless told otherwise). */
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

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function student(Account $account, string $name = 'Asha'): EducationStudent
    {
        static $n = 0;
        $n++;
        $contact = Contact::create(['account_id' => $account->id, 'phone_number' => '9188'.str_pad((string) $n, 8, '0', STR_PAD_LEFT), 'name' => $name]);

        return EducationStudent::create(['account_id' => $account->id, 'contact_id' => $contact->id]);
    }

    /** @return array{0: Account, 1: User} */
    private function ready(): array
    {
        $account = $this->school();

        return [$account, $this->userOf($account)];
    }

    private function createItem(User $user, string $name = 'Tuition', string $amount = '5000.00', array $extra = [])
    {
        return $this->actingAs($user)->postJson(self::BASE.'/fee-items', ['name' => $name, 'amount' => $amount] + $extra);
    }

    private function assign(User $user, EducationStudent $student, int $itemId, array $extra = [])
    {
        return $this->actingAs($user)->postJson(self::BASE."/students/{$student->id}/fees", ['charge_item_id' => $itemId] + $extra);
    }

    private function payUrl(EducationStudent $student, int $feeId): string
    {
        return self::BASE."/students/{$student->id}/fees/{$feeId}/payments";
    }

    // ================================================================== the manual workflow

    public function test_create_a_fee_assign_it_to_a_student_and_collect_it_in_instalments(): void
    {
        [$account, $admin] = $this->ready();
        $student = $this->student($account);

        $item = $this->createItem($admin, 'Tuition Term 1', '5000', ['frequency' => 'quarterly', 'description' => 'Term 1'])
            ->assertCreated()->assertJsonPath('data.amount', '5000.00')->assertJsonPath('data.status', 'active')->json('data.id');
        $this->actingAs($admin)->patchJson(self::BASE."/fee-items/{$item}", ['name' => 'Tuition T1', 'amount' => '5500.50'])->assertOk()->assertJsonPath('data.amount', '5500.50');
        $this->actingAs($admin)->getJson(self::BASE."/fee-items/{$item}")->assertOk()->assertJsonPath('data.name', 'Tuition T1');
        $this->actingAs($admin)->getJson(self::BASE.'/fee-items?search=T1')->assertOk()->assertJsonPath('total', 1);

        $fee = $this->assign($admin, $student, $item, ['due_date' => now()->addDays(20)->toDateString()])
            ->assertCreated()->assertJsonPath('data.amount_due', '5500.50')->assertJsonPath('data.status', 'open')->json('data.id');

        $this->actingAs($admin)->postJson($this->payUrl($student, $fee), ['amount' => '2000.25', 'payment_method' => 'upi', 'reference' => 'UPI-1'])
            ->assertCreated()->assertJsonPath('data.amount_paid', '2000.25')->assertJsonPath('data.outstanding', '3500.25')->assertJsonPath('data.status', 'partially_paid');
        $ledger = $this->actingAs($admin)->getJson(self::BASE."/students/{$student->id}/fees")->assertOk();
        $ledger->assertJsonPath('summary.total_due', '5500.50')->assertJsonPath('summary.total_paid', '2000.25')->assertJsonPath('summary.outstanding', '3500.25');
        $this->assertSame('Asha', $ledger->json('student.name'));

        $this->actingAs($admin)->postJson($this->payUrl($student, $fee), ['amount' => '3500.25'])->assertCreated()->assertJsonPath('data.status', 'paid');
        $this->actingAs($admin)->postJson($this->payUrl($student, $fee), ['amount' => '0.01'])->assertStatus(422)->assertJsonValidationErrors('amount');

        $history = $this->actingAs($admin)->getJson(self::BASE."/students/{$student->id}/fee-payments")->assertOk();
        $this->assertSame(['3500.25', '2000.25'], array_column($history->json('data'), 'amount'));
        $this->assertSame('Tuition T1', $history->json('data.0.charge_name'));
        $this->assertSame('Asha', $history->json('data.0.contact_name'));
        $this->actingAs($admin)->getJson(self::BASE.'/fee-payments?student_id='.$student->id.'&payment_method=upi')->assertOk()->assertJsonPath('total', 1);
        $this->assertSame(2, CollectionPayment::count());
    }

    public function test_the_server_ignores_frontend_supplied_balances_and_statuses(): void
    {
        [$account, $admin] = $this->ready();
        $student = $this->student($account);
        $item = $this->createItem($admin, 'Fee', '100.00')->json('data.id');
        $fee = $this->assign($admin, $student, $item, ['amount_paid' => '100.00', 'status' => 'paid', 'outstanding' => '0.00'])->assertCreated()->json('data');
        $this->assertSame(['0.00', '100.00', 'open'], [$fee['amount_paid'], $fee['outstanding'], $fee['status']]);

        $this->actingAs($admin)->postJson($this->payUrl($student, $fee['id']), ['amount' => '10.00', 'outstanding' => '0.00', 'status' => 'paid', 'amount_paid' => '100.00'])
            ->assertCreated()->assertJsonPath('data.outstanding', '90.00')->assertJsonPath('data.status', 'partially_paid');
    }

    public function test_retries_overpayment_and_bad_amounts_over_http(): void
    {
        [$account, $admin] = $this->ready();
        $student = $this->student($account);
        $fee = $this->assign($admin, $student, $this->createItem($admin, 'Fee', '100.00')->json('data.id'))->json('data.id');
        $url = $this->payUrl($student, $fee);

        $this->actingAs($admin)->postJson($url, ['amount' => '100.01'])->assertStatus(422)->assertJsonValidationErrors('amount');
        foreach (['0', '-1', '1.234', 'abc', '1e2'] as $bad) {
            $this->actingAs($admin)->postJson($url, ['amount' => $bad])->assertStatus(422)->assertJsonValidationErrors('amount');
        }
        $this->actingAs($admin)->postJson($url, [])->assertStatus(422);
        $this->assertSame(0, CollectionPayment::count());

        $body = ['amount' => '40.00', 'idempotency_key' => 'form-submit-0001'];
        $this->actingAs($admin)->postJson($url, $body)->assertCreated()->assertJsonPath('idempotent', false);
        $this->actingAs($admin)->postJson($url, $body)->assertOk()->assertJsonPath('idempotent', true)->assertJsonPath('data.outstanding', '60.00');
        $this->actingAs($admin)->postJson($url, ['amount' => '41.00'] + $body)->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
        $this->assertSame(1, CollectionPayment::count());
    }

    public function test_assignment_adjustment_archived_charges_and_duplicates(): void
    {
        [$account, $admin] = $this->ready();
        $student = $this->student($account);
        $item = $this->createItem($admin, 'Fee', '100.00')->json('data.id');
        $fee = $this->assign($admin, $student, $item, ['due_date' => '2026-12-01'])->assertCreated()->json('data.id');

        $this->assign($admin, $student, $item, ['due_date' => '2026-12-01'])->assertStatus(422)->assertJsonValidationErrors('charge_item_id');
        $this->actingAs($admin)->putJson(self::BASE."/students/{$student->id}/fees/{$fee}", ['amount_due' => '80.00', 'due_date' => '2027-01-15'])
            ->assertOk()->assertJsonPath('data.amount_due', '80.00')->assertJsonPath('data.due_date', '2027-01-15');
        $this->actingAs($admin)->postJson($this->payUrl($student, $fee), ['amount' => '50.00'])->assertCreated();
        $this->actingAs($admin)->putJson(self::BASE."/students/{$student->id}/fees/{$fee}", ['amount_due' => '49.99'])->assertStatus(422)->assertJsonValidationErrors('amount_due');

        $this->actingAs($admin)->patchJson(self::BASE."/fee-items/{$item}", ['status' => 'archived'])->assertOk();
        $this->assign($admin, $this->student($account, 'Bala'), $item)->assertStatus(422)->assertJsonValidationErrors('charge_item_id');
        $this->actingAs($admin)->postJson($this->payUrl($student, $fee), ['amount' => '30.00'])->assertCreated()->assertJsonPath('data.status', 'paid');
        $this->actingAs($admin)->getJson(self::BASE.'/fee-items?status=archived')->assertOk()->assertJsonPath('total', 1);
        $this->createItem($admin, 'Fee')->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_the_assignment_update_endpoint_keeps_paid_within_the_amount_due(): void
    {
        [$account, $admin] = $this->ready();
        $student = $this->student($account);
        $item = $this->createItem($admin, 'Fee', '100.00')->json('data.id');
        $other = $this->createItem($admin, 'Other', '10.00')->json('data.id');
        $fee = $this->assign($admin, $student, $item, ['due_date' => '2026-12-01'])->json('data.id');
        $url = self::BASE."/students/{$student->id}/fees/{$fee}";

        // before any payment: free to change
        $this->actingAs($admin)->putJson($url, ['amount_due' => '80.00'])->assertOk()->assertJsonPath('data.amount_due', '80.00');
        $this->actingAs($admin)->postJson($this->payUrl($student, $fee), ['amount' => '50.00'])->assertCreated();

        // after a partial payment: up is fine, below the paid total is a 422, exactly the paid total closes it
        $this->actingAs($admin)->putJson($url, ['amount_due' => '120.00'])->assertOk()->assertJsonPath('data.outstanding', '70.00');
        $this->actingAs($admin)->putJson($url, ['amount_due' => '49.99', 'due_date' => '2031-01-01'])->assertStatus(422)->assertJsonValidationErrors('amount_due');
        $this->actingAs($admin)->getJson(self::BASE."/students/{$student->id}/fees")->assertJsonPath('data.0.amount_due', '120.00')->assertJsonPath('data.0.due_date', '2026-12-01');
        $this->actingAs($admin)->putJson($url, ['amount_due' => '50.00'])->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.outstanding', '0.00');

        // the fee reference, status and balances are never writable — before or after payment
        foreach ([['charge_item_id' => $other], ['status' => 'open'], ['amount_paid' => '0.00'], ['outstanding' => '99.00']] as $extra) {
            $this->actingAs($admin)->putJson($url, ['amount_due' => '60.00'] + $extra)->assertStatus(422)->assertJsonValidationErrors(array_key_first($extra));
        }
        $this->actingAs($admin)->getJson(self::BASE."/students/{$student->id}/fees")->assertJsonPath('data.0.amount_due', '50.00')->assertJsonPath('data.0.charge_item_id', $item);
        $this->assertSame(1, CollectionPayment::count());
    }

    public function test_no_route_can_edit_or_delete_a_payment_or_an_item(): void
    {
        [$account, $admin] = $this->ready();
        $student = $this->student($account);
        $item = $this->createItem($admin, 'Fee', '100.00')->json('data.id');
        $fee = $this->assign($admin, $student, $item)->json('data.id');
        $this->actingAs($admin)->postJson($this->payUrl($student, $fee), ['amount' => '10.00'])->assertCreated();
        $paymentId = CollectionPayment::value('id');

        foreach ([
            ['deleteJson', self::BASE."/fee-items/{$item}"],
            ['deleteJson', self::BASE."/students/{$student->id}/fees/{$fee}"],
            ['deleteJson', self::BASE."/fee-payments/{$paymentId}"],
            ['putJson', self::BASE."/fee-payments/{$paymentId}"],
            ['patchJson', self::BASE."/fee-payments/{$paymentId}"],
        ] as [$method, $url]) {
            $this->assertContains($this->actingAs($admin)->{$method}($url, ['amount' => '1.00'])->status(), [404, 405], "{$method} {$url}");
        }
        $this->assertSame('10.00', number_format((float) CollectionPayment::find($paymentId)->amount, 2, '.', ''));
    }

    // ================================================================== authorization + entitlement

    public function test_every_gate_applies_including_the_shared_billing_capability(): void
    {
        [$account, $admin] = $this->ready();
        $student = $this->student($account);
        $this->actingAs($admin)->getJson(self::BASE.'/fee-items')->assertOk();

        // Education alone is not enough: the generic billing capability is entitled separately.
        $noBilling = $this->school([], false);
        $adminNoBilling = $this->userOf($noBilling);
        foreach ([['getJson', '/fee-items'], ['postJson', '/fee-items'], ['getJson', "/students/{$this->student($noBilling)->id}/fees"]] as [$method, $path]) {
            $this->actingAs($adminNoBilling)->{$method}(self::BASE.$path, ['name' => 'x', 'amount' => '1'])->assertForbidden()->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
        }
        $this->assertSame(0, CollectionChargeItem::where('account_id', $noBilling->id)->count());
        $this->assertNotContains('education.fees', $this->actingAs($adminNoBilling)->getJson('/api/auth/me')->json('user.industry_modules'));

        // …and billing alone does not unlock Education.
        $billingOnly = Account::factory()->create();
        Subscription::factory()->create(['account_id' => $billingOnly->id]);
        $this->grant($billingOnly, 'billing_collections');
        AccountIndustry::create(['account_id' => $billingOnly->id, 'industry' => 'education', 'subtype' => 'school']);
        $this->actingAs($this->userOf($billingOnly))->getJson(self::BASE.'/fee-items')->assertForbidden()->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');

        // Education industry not assigned.
        AccountIndustry::where('account_id', $account->id)->delete();
        $this->actingAs($admin)->getJson(self::BASE.'/fee-items')->assertForbidden()->assertJsonPath('error_code', 'INDUSTRY_NOT_ASSIGNED');
        AccountIndustry::create(['account_id' => $account->id, 'industry' => 'education', 'subtype' => 'school']);

        // The account module switch.
        $account->forceFill(['status' => 'suspended'])->save();
        $this->actingAs($admin)->getJson(self::BASE.'/fee-items')->assertForbidden();
        $this->assertSame($student->account_id, $account->id);
    }

    public function test_view_education_reads_and_manage_education_writes(): void
    {
        [$account, $admin] = $this->ready();
        $student = $this->student($account);
        $item = $this->createItem($admin, 'Fee', '100.00')->json('data.id');
        $fee = $this->assign($admin, $student, $item)->json('data.id');

        Role::firstOrCreate(['name' => 'fees_viewer', 'guard_name' => 'web'])->syncPermissions(['view-education']);
        Role::firstOrCreate(['name' => 'fees_none', 'guard_name' => 'web'])->syncPermissions(['view-analytics']);
        $viewer = $this->userOf($account, 'fees_viewer');
        $none = $this->userOf($account, 'fees_none');

        $this->actingAs($viewer)->getJson(self::BASE.'/fee-items')->assertOk();
        $this->actingAs($viewer)->getJson(self::BASE."/students/{$student->id}/fees")->assertOk();
        $this->actingAs($viewer)->getJson(self::BASE."/students/{$student->id}/fee-payments")->assertOk();
        $this->createItem($viewer, 'New')->assertForbidden()->assertJsonPath('error_code', 'PERMISSION_DENIED');
        $this->actingAs($viewer)->patchJson(self::BASE."/fee-items/{$item}", ['name' => 'Renamed'])->assertForbidden();
        $this->assign($viewer, $student, $item, ['due_date' => '2027-01-01'])->assertForbidden();
        $this->actingAs($viewer)->postJson($this->payUrl($student, $fee), ['amount' => '10.00'])->assertForbidden();
        $this->actingAs($none)->getJson(self::BASE.'/fee-items')->assertForbidden();

        $this->assertSame(1, CollectionChargeItem::count());
        $this->assertSame(0, CollectionPayment::count());
        $this->assertTrue(\Spatie\Permission\Models\Permission::where('name', 'like', '%fee%')->doesntExist(), 'no separate fees permission exists');
    }

    public function test_an_expired_subscription_keeps_reads_and_blocks_every_write(): void
    {
        [$account, $admin] = $this->ready();
        $student = $this->student($account);
        $item = $this->createItem($admin, 'Fee', '100.00')->json('data.id');
        $fee = $this->assign($admin, $student, $item)->json('data.id');
        $this->actingAs($admin)->postJson($this->payUrl($student, $fee), ['amount' => '10.00'])->assertCreated();
        Subscription::where('account_id', $account->id)->update(['expires_at' => now()->subDay()]);

        $this->actingAs($admin)->getJson(self::BASE.'/fee-items')->assertOk();
        $this->actingAs($admin)->getJson(self::BASE."/students/{$student->id}/fees")->assertOk()->assertJsonPath('summary.outstanding', '90.00');
        $this->actingAs($admin)->getJson(self::BASE."/students/{$student->id}/fee-payments")->assertOk()->assertJsonPath('total', 1);

        $this->createItem($admin, 'Other')->assertForbidden();
        $this->actingAs($admin)->patchJson(self::BASE."/fee-items/{$item}", ['name' => 'X'])->assertForbidden();
        $this->assign($admin, $student, $item, ['due_date' => '2027-02-01'])->assertForbidden();
        $this->actingAs($admin)->putJson(self::BASE."/students/{$student->id}/fees/{$fee}", ['amount_due' => '50.00'])->assertForbidden();
        $this->actingAs($admin)->postJson($this->payUrl($student, $fee), ['amount' => '10.00'])->assertForbidden();
        $this->assertSame([1, 1, 1], [CollectionChargeItem::count(), CollectionChargeAssignment::count(), CollectionPayment::count()]);
    }

    public function test_billing_is_sold_through_plan_management_and_is_in_no_seeded_plan(): void
    {
        $this->assertTrue(Capability::where('slug', 'billing_collections')->exists());
        $this->assertSame(0, Plan::query()->whereHas('capabilities', fn ($q) => $q->where('slug', 'billing_collections'))->count(), 'no seeded plan bundles billing');
        $this->assertSame(0, Plan::query()->whereHas('capabilities', fn ($q) => $q->where('slug', 'industry_education'))->count());

        $super = $this->superAdmin();
        $this->actingAs($super)->postJson('/api/admin/plans-management', [
            'slug' => 'school_fees_pack', 'label' => 'School Fees Pack', 'price' => 1999, 'duration_days' => 30, 'engine_type' => 'meta', 'billing_model' => 'flat_quota',
            'total_allocated_messages' => 1000, 'capabilities' => ['whatsapp_send', 'industry_education', 'billing_collections'],
        ])->assertSuccessful();
        $this->assertEqualsCanonicalizing(['whatsapp_send', 'industry_education', 'billing_collections'], Plan::where('slug', 'school_fees_pack')->first()->capabilities->pluck('slug')->all());

        // …or removed again: an account entitlement grant / revoke.
        $account = Account::factory()->create();
        $this->actingAs($super)->postJson("/api/admin/accounts/{$account->id}/entitlements", ['capability' => 'billing_collections'])->assertSuccessful();
        $this->assertTrue(app(AccessControlService::class)->canTenant($account, 'billing_collections'));
        $this->assertFalse(app(AccessControlService::class)->canTenant($account, 'industry_education'), 'billing does not imply Education');
        AccountEntitlement::where('account_id', $account->id)->update(['revoked_at' => now()]);
        $this->assertFalse(app(AccessControlService::class)->canTenant($account->fresh(), 'billing_collections'));
    }

    public function test_the_frontend_navigation_key_uses_the_same_entitlement_source(): void
    {
        [$account, $admin] = $this->ready();
        $this->assertContains('education.fees', $this->actingAs($admin)->getJson('/api/auth/me')->json('user.industry_modules'));

        $context = $this->actingAs($admin)->getJson('/api/industry/context')->assertOk()->json('data.0');
        $this->assertTrue(array_column($context['modules'], 'allowed', 'key')['fees']);

        AccountEntitlement::where('account_id', $account->id)->whereHas('capability', fn ($q) => $q->where('slug', 'billing_collections'))->update(['revoked_at' => now()]);
        $this->assertNotContains('education.fees', $this->actingAs($admin->fresh())->getJson('/api/auth/me')->json('user.industry_modules'));
        $this->assertContains('education.students', $this->actingAs($admin->fresh())->getJson('/api/auth/me')->json('user.industry_modules'), 'the rest of Education is unaffected');
        $this->assertFalse(array_column($this->actingAs($admin)->getJson('/api/industry/context')->json('data.0.modules'), 'allowed', 'key')['fees']);
    }

    // ================================================================== tenant isolation

    public function test_tenant_isolation_between_accounts(): void
    {
        [$a, $adminA] = $this->ready();
        [$b, $adminB] = $this->ready();
        $studentA = $this->student($a);
        $studentB = $this->student($b);
        $itemA = $this->createItem($adminA, 'A fee', '100.00')->json('data.id');
        $itemB = $this->createItem($adminB, 'B fee', '100.00')->json('data.id');
        $feeA = $this->assign($adminA, $studentA, $itemA)->json('data.id');
        $this->actingAs($adminA)->postJson($this->payUrl($studentA, $feeA), ['amount' => '10.00'])->assertCreated();

        $this->actingAs($adminB)->getJson(self::BASE."/fee-items/{$itemA}")->assertNotFound();
        $this->actingAs($adminB)->patchJson(self::BASE."/fee-items/{$itemA}", ['name' => 'hijack'])->assertNotFound();
        $this->actingAs($adminB)->getJson(self::BASE."/students/{$studentA->id}/fees")->assertNotFound();
        $this->actingAs($adminB)->getJson(self::BASE."/students/{$studentA->id}/fee-payments")->assertNotFound();
        $this->assign($adminB, $studentA, $itemB)->assertNotFound();
        $this->assign($adminB, $studentB, $itemA)->assertStatus(422)->assertJsonValidationErrors('charge_item_id'); // A's charge
        $this->actingAs($adminB)->postJson($this->payUrl($studentA, $feeA), ['amount' => '5.00'])->assertNotFound();
        $this->actingAs($adminB)->postJson($this->payUrl($studentB, $feeA), ['amount' => '5.00'])->assertNotFound(); // A's assignment under B's student
        $this->actingAs($adminB)->putJson(self::BASE."/students/{$studentB->id}/fees/{$feeA}", ['amount_due' => '1.00'])->assertNotFound();
        $this->actingAs($adminB)->getJson(self::BASE.'/fee-payments?student_id='.$studentA->id)->assertNotFound();
        $this->assertSame(0, $this->actingAs($adminB)->getJson(self::BASE.'/fee-payments')->assertOk()->json('total'));

        // A forged account id never retargets
        $this->actingAs($adminB)->postJson(self::BASE."/fee-items?account_id={$a->id}", ['name' => 'forged', 'amount' => '1', 'account_id' => $a->id])->assertCreated();
        $this->assertSame(0, CollectionChargeItem::where('account_id', $a->id)->where('name', 'forged')->count());
        $this->assertSame(1, CollectionChargeItem::where('account_id', $b->id)->where('name', 'forged')->count());
        $this->assertSame(1, CollectionPayment::count());
    }

    public function test_a_student_only_sees_education_scope_charges_even_for_a_shared_contact(): void
    {
        [$account, $admin] = $this->ready();
        $student = $this->student($account);
        $item = $this->createItem($admin, 'Tuition', '100.00')->json('data.id');
        $this->assign($admin, $student, $item)->assertCreated();

        // Another module's charge on the same CRM contact, written through the generic core.
        $core = app(BillingCollectionService::class);
        $foreign = $core->assign($account, 'some_other_module', $student->contact_id, ['charge_item_id' => $core->createItem($account, 'some_other_module', ['name' => 'Other', 'amount' => '999.00'])->id]);

        $this->assertSame(['Tuition'], array_column($this->actingAs($admin)->getJson(self::BASE."/students/{$student->id}/fees")->json('data'), 'charge_name'));
        $this->actingAs($admin)->postJson($this->payUrl($student, $foreign->id), ['amount' => '1.00'])->assertNotFound();
        $this->assertSame(1, CollectionChargeItem::where('account_id', $account->id)->where('scope', 'education')->count());
        $this->assertSame(0, CollectionPayment::count());
    }

    public function test_an_agent_reaches_only_its_own_clients(): void
    {
        $agent = $this->school(['account_type' => 'agent']);
        $actor = $this->userOf($agent);
        $own = $this->school();
        $own->forceFill(['agent_id' => $agent->id])->save();
        $ownStudent = $this->student($own);
        $otherAgent = $this->school(['account_type' => 'agent']);
        $stranger = $this->school();
        $stranger->forceFill(['agent_id' => $otherAgent->id])->save();
        $strangerStudent = $this->student($stranger);
        $strangerAdmin = $this->userOf($stranger);
        $strangerItem = $this->createItem($strangerAdmin, 'S fee', '10.00')->json('data.id');

        $item = $this->actingAs($actor)->postJson(self::BASE."/fee-items?account_id={$own->id}", ['name' => 'Own fee', 'amount' => '100.00'])->assertCreated()->json('data.id');
        $fee = $this->actingAs($actor)->postJson(self::BASE."/students/{$ownStudent->id}/fees?account_id={$own->id}", ['charge_item_id' => $item])->assertCreated()->json('data.id');
        $this->actingAs($actor)->postJson(self::BASE."/students/{$ownStudent->id}/fees/{$fee}/payments?account_id={$own->id}", ['amount' => '25.00'])->assertCreated();
        $this->assertSame([$own->id], CollectionPayment::pluck('account_id')->all());

        $this->actingAs($actor)->getJson(self::BASE."/fee-items?account_id={$stranger->id}")->assertNotFound();
        $this->actingAs($actor)->postJson(self::BASE."/students/{$strangerStudent->id}/fees?account_id={$stranger->id}", ['charge_item_id' => $strangerItem])->assertNotFound();
        // Acting as itself, a child's student id is not reachable
        $this->actingAs($actor)->getJson(self::BASE."/students/{$ownStudent->id}/fees")->assertNotFound();
        $this->assertSame(0, CollectionChargeAssignment::where('account_id', $stranger->id)->count());
    }

    public function test_super_admin_must_pick_the_client_and_never_gets_the_platform_account(): void
    {
        $client = $this->school();
        $clientStudent = $this->student($client);
        $platform = Account::factory()->create(['account_type' => 'super_admin', 'company_name' => 'Platform (Super Admin)']);
        Subscription::factory()->create(['account_id' => $platform->id]);
        $this->grant($platform, 'industry_education');
        $this->grant($platform, 'billing_collections');
        AccountIndustry::create(['account_id' => $platform->id, 'industry' => 'education', 'subtype' => 'school']);
        $platformStudent = $this->student($platform);
        $admin = $this->superAdmin();

        $this->actingAs($admin)->getJson(self::BASE.'/fee-items')->assertStatus(422);
        $this->actingAs($admin)->postJson(self::BASE.'/fee-items', ['name' => 'x', 'amount' => '1'])->assertStatus(422);
        $this->actingAs($admin)->getJson(self::BASE."/students/{$clientStudent->id}/fees")->assertStatus(422);
        $this->actingAs($admin)->postJson(self::BASE."/students/{$platformStudent->id}/fees", ['charge_item_id' => 1])->assertStatus(422);
        $this->assertSame(0, CollectionChargeItem::count());

        $item = $this->actingAs($admin)->postJson(self::BASE."/fee-items?account_id={$client->id}", ['name' => 'Fee', 'amount' => '100.00'])->assertCreated()->json('data.id');
        $fee = $this->actingAs($admin)->postJson(self::BASE."/students/{$clientStudent->id}/fees?account_id={$client->id}", ['charge_item_id' => $item])->assertCreated()->json('data.id');
        $this->actingAs($admin)->postJson(self::BASE."/students/{$clientStudent->id}/fees/{$fee}/payments?account_id={$client->id}", ['amount' => '10.00'])->assertCreated();
        $this->assertSame([$client->id], CollectionPayment::pluck('account_id')->all());
        // The client's id with the Platform's student is a plain 404.
        $this->actingAs($admin)->getJson(self::BASE."/students/{$platformStudent->id}/fees?account_id={$client->id}")->assertNotFound();
    }

    // ================================================================== adapter + service

    public function test_the_education_adapter_works_without_http_and_only_maps_student_to_contact(): void
    {
        [$account] = $this->ready();
        $student = $this->student($account);
        $service = app(EducationFeeService::class);

        $item = $service->createItem($account, ['name' => 'Bus', 'amount' => '300.00', 'frequency' => 'monthly']);
        $assignment = $service->assign($account, $student, ['charge_item_id' => $item->id, 'due_date' => '2026-12-05']);
        $this->assertSame($student->contact_id, $assignment->contact_id, 'the core row points at the CRM contact, not the student');
        $service->recordPayment($account, $student, $assignment->id, ['amount' => '100.00', 'idempotency_key' => 'service-call-0001']);
        $again = $service->recordPayment($account, $student, $assignment->id, ['amount' => '100.00', 'idempotency_key' => 'service-call-0001']);

        $this->assertTrue($again['idempotent']);
        $this->assertSame('200.00', $service->ledger($account, $student)['summary']['outstanding']);
        $this->assertSame(1, $service->studentPayments($account, $student)->total());

        // Another account's student is rejected by the adapter itself.
        $foreign = $this->student($this->school());
        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        $service->ledger($account, $foreign);
    }

    public function test_attendance_and_the_other_education_modules_are_unaffected(): void
    {
        [$account, $admin] = $this->ready();
        $this->actingAs($admin)->getJson(self::BASE.'/students')->assertOk();
        $this->actingAs($admin)->getJson(self::BASE.'/groups')->assertOk();
        $keys = $this->actingAs($admin)->getJson('/api/auth/me')->json('user.industry_modules');
        $this->assertEqualsCanonicalizing(['education.students', 'education.batches', 'education.attendance', 'education.fees'], $keys);
    }
}
