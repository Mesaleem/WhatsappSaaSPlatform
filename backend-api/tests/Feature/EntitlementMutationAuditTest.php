<?php

namespace Tests\Feature;

use App\Jobs\ReconcilePlanAccountsJob;
use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\ActivityLog;
use App\Models\Capability;
use App\Models\Invoice;
use App\Models\PaymentGatewaySetting;
use App\Models\Plan;
use App\Models\User;
use App\Services\Access\AccessControlService;
use App\Services\Access\PlanEntitlementReconciliationService;
use App\Support\EntitlementAuditContext;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * F-5.2 — every entitlement STATE MUTATION is audited, whoever performs it.
 *
 * LogsActivity only wrote rows for an authenticated HTTP user, so a queue
 * worker, an artisan command or a payment webhook changed authorization
 * silently. AccountEntitlement now records each mutation itself (one row
 * per logical mutation, same activity_logs table / 'AccountEntitlement'
 * module), with the actor and the state transition in new_values.audit.
 */
class EntitlementMutationAuditTest extends TestCase
{
    use RefreshDatabase;

    private const KEY_SECRET = 'rzp_test_secret_f52';

    private const WEBHOOK_SECRET = 'rzp_webhook_secret_f52';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        EntitlementAuditContext::reset();
    }

    protected function tearDown(): void
    {
        EntitlementAuditContext::reset();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ fixtures

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function cap(string $slug): Capability
    {
        return Capability::where('slug', $slug)->firstOrFail();
    }

    /** Pays for $planKey through the real payment-credit service; returns the account. */
    private function pay(Account $account, string $planKey): Invoice
    {
        $plan = \App\Support\PlanCatalog::find($planKey);

        $invoice = Invoice::create([
            'account_id' => $account->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => $planKey, 'plan_label' => $plan['label'],
            'amount' => $plan['price'], 'tax_amount' => 0, 'total_amount' => $plan['price'], 'currency' => 'INR',
            'payment_gateway' => 'razorpay', 'gateway_order_id' => 'order_'.uniqid(), 'gateway_payment_id' => null,
            'status' => 'pending', 'paid_at' => null, 'gateway_raw_response' => null,
        ]);

        app(\App\Services\Billing\InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());

        return $invoice;
    }

    /** @return \Illuminate\Support\Collection<int, ActivityLog> entitlement-mutation rows of one account, oldest first */
    private function rows(Account $account, ?string $capability = null): \Illuminate\Support\Collection
    {
        return ActivityLog::query()
            ->where('module_name', 'AccountEntitlement')
            ->where('account_id', $account->id)
            ->orderBy('id')
            ->get()
            ->filter(fn (ActivityLog $r) => $capability === null || ($r->new_values['audit']['capability'] ?? null) === $capability)
            ->values();
    }

    private function audit(ActivityLog $row): array
    {
        return $row->new_values['audit'];
    }

    private function holds(Account $account, string $slug): bool
    {
        return app(AccessControlService::class)->canTenant($account->fresh(), $slug);
    }

    // ------------------------------------------------------------------ 1. HTTP

    public function test_http_grant_revoke_and_regrant_each_write_exactly_one_attributed_row(): void
    {
        $account = Account::factory()->create();
        $admin = $this->superAdmin();

        $this->actingAs($admin)->postJson("/api/admin/accounts/{$account->id}/entitlements", ['capability' => 'crm'])->assertOk();

        $rows = $this->rows($account, 'crm');
        $this->assertCount(1, $rows, 'one logical grant = one audit row (no LogsActivity duplicate)');
        $a = $this->audit($rows[0]);
        $this->assertSame('entitlement.grant', $a['action']);
        $this->assertSame('http', $a['source']);
        $this->assertSame('user', $a['actor_type']);
        $this->assertSame('none', $a['previous_state']);
        $this->assertSame('held', $a['new_state']);
        $this->assertSame($account->id, $a['account_id']);
        $this->assertSame($this->cap('crm')->id, $a['capability_id']);
        $this->assertSame($admin->id, $rows[0]->user_id, 'the authenticated user is recorded because one genuinely exists');
        $this->assertSame('create', $rows[0]->action_type, 'LogsActivity row shape preserved');

        $this->actingAs($admin)->deleteJson("/api/admin/accounts/{$account->id}/entitlements/crm")->assertOk();

        $rows = $this->rows($account, 'crm');
        $this->assertCount(2, $rows);
        $a = $this->audit($rows[1]);
        $this->assertSame('entitlement.revoke', $a['action']);
        $this->assertSame('held', $a['previous_state']);
        $this->assertSame('revoked', $a['new_state']);
        $this->assertSame('manual', $a['reason']);
        $this->assertSame($admin->id, $rows[1]->user_id);
        $this->assertSame($account->id, $rows[1]->old_values['account_id'], 'update rows identify their entitlement');

        $this->actingAs($admin)->postJson("/api/admin/accounts/{$account->id}/entitlements", ['capability' => 'crm'])->assertOk();

        $rows = $this->rows($account, 'crm');
        $this->assertCount(3, $rows);
        $a = $this->audit($rows[2]);
        $this->assertSame('entitlement.restore', $a['action']);
        $this->assertSame('revoked', $a['previous_state']);
        $this->assertSame('held', $a['new_state']);
    }

    public function test_an_idempotent_regrant_changes_nothing_and_writes_nothing(): void
    {
        $account = Account::factory()->create();
        $admin = $this->superAdmin();

        $this->actingAs($admin)->postJson("/api/admin/accounts/{$account->id}/entitlements", ['capability' => 'crm'])->assertOk();
        $this->actingAs($admin)->postJson("/api/admin/accounts/{$account->id}/entitlements", ['capability' => 'crm'])->assertOk();

        $this->assertCount(1, $this->rows($account, 'crm'));
    }

    // ------------------------------------------------------------------ 2. queue reconciliation

    public function test_queue_reconciliation_writes_system_attributed_rows_naming_the_initiating_admin(): void
    {
        $account = Account::factory()->create();
        $this->pay($account, 'business');
        $admin = $this->superAdmin();

        $before = $this->rows($account)->count();
        $this->assertGreaterThan(0, $before, 'the payment-time grants are audited too');

        // Drop 'ads' from the bundle without going through HTTP, then run the job as a worker would: no auth.
        $plan = Plan::where('slug', 'business')->firstOrFail();
        $plan->capabilities()->detach($this->cap('ads')->id);
        Auth::forgetGuards();

        (new ReconcilePlanAccountsJob('business', $admin->id))->handle(app(PlanEntitlementReconciliationService::class));

        $new = $this->rows($account, 'ads')->last();
        $a = $this->audit($new);
        $this->assertSame('entitlement.revoke', $a['action']);
        $this->assertSame('queue:plan_reconciliation', $a['source']);
        $this->assertSame('system', $a['actor_type']);
        $this->assertNull($new->user_id, 'no authenticated user, none is invented');
        $this->assertSame($admin->id, $a['initiated_by_user_id']);
        $this->assertSame('plan_downgrade', $a['reason']);
        $this->assertSame('held', $a['previous_state']);
        $this->assertSame('revoked', $a['new_state']);
        $this->assertFalse($this->holds($account, 'ads'));

        // A second run changes nothing, so it audits nothing.
        $count = $this->rows($account)->count();
        (new ReconcilePlanAccountsJob('business', $admin->id))->handle(app(PlanEntitlementReconciliationService::class));
        $this->assertCount($count, $this->rows($account));

        // Restore is a distinct, audited action.
        $plan->capabilities()->attach($this->cap('ads')->id, ['usage_limit' => null]);
        (new ReconcilePlanAccountsJob('business', null))->handle(app(PlanEntitlementReconciliationService::class));
        $this->assertSame('entitlement.restore', $this->audit($this->rows($account, 'ads')->last())['action']);
    }

    public function test_a_database_queue_worker_run_is_audited_without_a_user(): void
    {
        config(['queue.default' => 'database']);
        $account = Account::factory()->create();
        $this->pay($account, 'business');
        $admin = $this->superAdmin();
        $keep = Plan::where('slug', 'business')->firstOrFail()->capabilities->pluck('slug')->reject(fn ($s) => $s === 'ads')->values()->all();

        $this->actingAs($admin)->putJson('/api/admin/plans-management/business', ['capabilities' => $keep])->assertOk();
        $this->assertTrue($this->holds($account, 'ads'), 'the HTTP request did not reconcile inline');

        Auth::forgetGuards();
        Artisan::call('queue:work', ['--queue' => ReconcilePlanAccountsJob::QUEUE, '--stop-when-empty' => true, '--memory' => 1024]);

        $row = $this->rows($account, 'ads')->last();
        $this->assertSame('entitlement.revoke', $this->audit($row)['action']);
        $this->assertSame('queue:plan_reconciliation', $this->audit($row)['source']);
        $this->assertNull($row->user_id);
        $this->assertSame($admin->id, $this->audit($row)['initiated_by_user_id']);
    }

    // ------------------------------------------------------------------ 3. commands

    public function test_the_reconcile_and_backfill_commands_are_audited_as_commands(): void
    {
        $account = Account::factory()->create();
        $this->pay($account, 'business');

        // reconcile: revoke 'ads' via the bundle, run the command.
        Plan::where('slug', 'business')->firstOrFail()->capabilities()->detach($this->cap('ads')->id);
        Artisan::call('entitlements:reconcile-plan', ['--account' => $account->id]);

        $row = $this->rows($account, 'ads')->last();
        $this->assertSame('entitlement.revoke', $this->audit($row)['action']);
        $this->assertSame('command:entitlements:reconcile-plan', $this->audit($row)['source']);
        $this->assertSame('system', $this->audit($row)['actor_type']);
        $this->assertNull($row->user_id);

        // backfill: a paid tenant missing a bundled entitlement gets it, audited.
        DB::table('account_entitlements')->where('account_id', $account->id)->where('capability_id', $this->cap('whatsapp_send')->id)->delete();
        Artisan::call('entitlements:backfill-plan', ['--account' => $account->id]);

        $row = $this->rows($account, 'whatsapp_send')->last();
        $this->assertSame('entitlement.grant', $this->audit($row)['action']);
        $this->assertSame('command:entitlements:backfill-plan', $this->audit($row)['source']);
        $this->assertNull($row->user_id);
    }

    public function test_a_dry_run_audits_nothing(): void
    {
        $account = Account::factory()->create();
        $this->pay($account, 'business');
        $count = $this->rows($account)->count();

        Plan::where('slug', 'business')->firstOrFail()->capabilities()->detach($this->cap('ads')->id);
        Artisan::call('entitlements:reconcile-plan', ['--account' => $account->id, '--dry-run' => true]);

        $this->assertCount($count, $this->rows($account));
        $this->assertTrue($this->holds($account, 'ads'));
    }

    // ------------------------------------------------------------------ 4. payment / webhook

    public function test_a_payment_webhook_grant_is_audited_as_payment_driven_with_no_user(): void
    {
        PaymentGatewaySetting::create([
            'gateway' => 'razorpay', 'mode' => 'test', 'is_enabled' => true,
            'test_key_id' => 'rzp_test_key_f52', 'test_key_secret' => self::KEY_SECRET, 'test_webhook_secret' => self::WEBHOOK_SECRET,
        ]);
        Http::fake(['api.razorpay.com/v1/orders' => fn () => Http::response(['id' => 'order_'.uniqid(), 'status' => 'created'], 200)]);

        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('admin');

        $orderId = $this->actingAs($user)->postJson('/api/billing/create-order', ['plan_key' => 'growth', 'gateway' => 'razorpay'])->assertCreated()->json('invoice_id');
        $invoice = Invoice::findOrFail($orderId);
        Auth::forgetGuards();

        $body = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'pay_f52', 'order_id' => $invoice->gateway_order_id, 'status' => 'captured']]]]);
        $this->call('POST', '/api/webhooks/razorpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, self::WEBHOOK_SECRET),
        ], $body)->assertOk();

        $rows = $this->rows($account);
        $this->assertNotEmpty($rows, 'the webhook granted entitlements and each grant is audited');
        $this->assertSame(
            $rows->count(),
            AccountEntitlement::where('account_id', $account->id)->count(),
            'exactly one audit row per entitlement mutation — no duplicates',
        );

        foreach ($rows as $row) {
            $a = $this->audit($row);
            $this->assertSame('entitlement.grant', $a['action']);
            $this->assertSame('payment_fulfilment', $a['source']);
            $this->assertSame('system', $a['actor_type']);
            $this->assertNull($row->user_id);
        }

        // A repeated webhook is a no-op for entitlements and for the audit trail.
        $this->call('POST', '/api/webhooks/razorpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, self::WEBHOOK_SECRET),
        ], $body)->assertOk();
        $this->assertCount($rows->count(), $this->rows($account));
    }

    public function test_system_actors_without_any_context_are_still_labelled(): void
    {
        $account = Account::factory()->create();

        AccountEntitlement::create(['account_id' => $account->id, 'capability_id' => $this->cap('crm')->id, 'source' => AccountEntitlement::SOURCE_MANUAL_GRANT]);

        $row = $this->rows($account, 'crm')->first();
        $this->assertSame('console', $this->audit($row)['source'], 'no context + no user + console run');
        $this->assertSame('system', $this->audit($row)['actor_type']);
        $this->assertNull($row->user_id);
    }

    // ------------------------------------------------------------------ 5. audit failure

    public function test_a_failing_audit_write_never_changes_who_is_authorized(): void
    {
        $account = Account::factory()->create();
        $admin = $this->superAdmin();

        $warnings = [];
        Log::listen(function ($e) use (&$warnings) {
            if ($e->level === 'warning') {
                $warnings[] = $e->message.' '.json_encode($e->context);
            }
        });
        ActivityLog::creating(function () {
            throw new RuntimeException('audit store is down');
        });

        // GRANT still takes effect...
        $this->actingAs($admin)->postJson("/api/admin/accounts/{$account->id}/entitlements", ['capability' => 'crm'])->assertOk();
        $this->assertTrue($this->holds($account, 'crm'), 'a failed audit does not deny the grant');
        $this->assertSame(0, $this->rows($account, 'crm')->count());

        // ...and REVOKE still takes effect (an audit outage must never leave access open).
        $this->actingAs($admin)->deleteJson("/api/admin/accounts/{$account->id}/entitlements/crm")->assertOk();
        $this->assertFalse($this->holds($account, 'crm'), 'a failed audit does not keep a revoked capability alive');

        $this->assertNotEmpty(array_filter($warnings, fn ($w) => str_contains($w, 'could not record an entitlement mutation')), 'the failure is reported, not swallowed silently');
        $this->assertStringNotContainsString('audit store is down', implode(' ', $warnings), 'no exception text or payload in the log line');
    }

    public function test_audit_rows_carry_only_allow_listed_non_secret_fields(): void
    {
        $account = Account::factory()->create();
        $this->actingAs($this->superAdmin())->postJson("/api/admin/accounts/{$account->id}/entitlements", ['capability' => 'crm'], ['Authorization' => 'Bearer SECRET_TOKEN_XYZ'])->assertOk();

        $row = $this->rows($account, 'crm')->first();
        $allowed = ['action', 'source', 'actor_type', 'initiated_by_user_id', 'resource_type', 'resource_id', 'capability', 'capability_id', 'previous_state', 'new_state', 'entitlement_source', 'reason', 'account_id'];
        $this->assertSame([], array_diff(array_keys($this->audit($row)), $allowed));
        $this->assertStringNotContainsString('SECRET_TOKEN_XYZ', json_encode(DB::table('activity_logs')->get()));
    }
}
