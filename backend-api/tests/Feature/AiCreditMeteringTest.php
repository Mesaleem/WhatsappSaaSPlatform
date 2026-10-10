<?php

namespace Tests\Feature;

use App\Events\AiOperationPerformed;
use App\Models\Account;
use App\Models\AiOperation;
use App\Models\CreditAccount;
use App\Models\CreditLedgerEntry;
use App\Models\CreditReservation;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;
use App\Services\Access\PlanManagementService;
use App\Services\Ai\AiAuthorization;
use App\Services\Ai\AiAuthorizer;
use App\Services\Ai\AiException;
use App\Services\Ai\AiManager;
use App\Services\Ai\Billing\AiCreditPricing;
use App\Services\Ai\Billing\MeteredAiService;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiResponse;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Credits\CreditService;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 8 Task 5 — AI usage metering + credit consumption.
 *
 * AI operations are billed through the EXISTING credit system only:
 * reserve an upper-bound estimate (CreditConsumptionService, gated by
 * CreditEntitlementService, under the credit-account lock) → provider call
 * (AiService) → consume the actual cost from the hold (rest released) or
 * release it on failure. ai_operations correlates each operation with its
 * reservation and consumption entry. The real multi-process race is
 * tests/Probes/ai_credit_concurrency_probe.php.
 *
 * Pricing used here: 1000 tokens per credit, minimum 1.
 */
class AiCreditMeteringTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{input: ?int, output: ?int, fail: ?AiException, calls: int, throwOnCall?: bool} */
    private array $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        Http::fake(); // nothing may reach a real vendor

        config([
            'ai.default' => 'fake', 'ai.enabled' => ['fake'], 'ai.providers.fake' => ['model' => 'fake-model'],
            'ai.credits.tokens_per_credit' => 1000, 'ai.credits.minimum_per_operation' => 1,
            'ai.credits.tokens_per_credit_overrides' => [], 'ai.credits.missing_usage' => 'minimum',
        ]);

        $this->fake = ['input' => 1500, 'output' => 500, 'fail' => null, 'calls' => 0];
        $test = $this;
        app(AiManager::class)->extend('fake', fn () => new class($test) implements AiProvider {
            public function __construct(private readonly AiCreditMeteringTest $test) {}
            public function name(): string { return 'fake'; }
            public function generateText(AiRequest $request): AiResponse { return $this->test->fakeAnswer(); }
            public function generateStructured(AiRequest $request): AiResponse { return $this->test->fakeAnswer()->withData(['ok' => true]); }
        });
    }

    /** @internal called by the fake provider */
    public function fakeAnswer(): AiResponse
    {
        $this->fake['calls']++;

        if ($this->fake['fail'] !== null) {
            throw $this->fake['fail'];
        }

        return new AiResponse('fake', 'fake-model', 'an answer', null, $this->fake['input'], $this->fake['output'], 'stop');
    }

    // ------------------------------------------------------------------ fixtures

    private function tenant(string $planKey = 'growth', array $attributes = []): Account
    {
        $account = Account::factory()->create($attributes);
        $plan = PlanCatalog::find($planKey);
        $invoice = Invoice::create([
            'account_id' => $account->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => $planKey,
            'plan_label' => $plan['label'], 'amount' => $plan['price'], 'tax_amount' => 0,
            'total_amount' => $plan['price'], 'currency' => 'INR', 'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.uniqid(), 'gateway_payment_id' => null, 'status' => 'pending',
            'paid_at' => null, 'gateway_raw_response' => null,
        ]);
        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());

        return $account->fresh();
    }

    private function funded(int $credits, string $planKey = 'growth', array $attributes = []): Account
    {
        $account = $this->tenant($planKey, $attributes);
        if ($credits > 0) {
            app(CreditService::class)->grant($account, $credits, 'test:grant:'.uniqid());
        }

        return $account;
    }

    private function auth(Account $account, string $source = 'automation'): AiAuthorization
    {
        return app(AiAuthorizer::class)->forAccount($account, 'chatbot', $source);
    }

    private function metered(): MeteredAiService
    {
        return app(MeteredAiService::class);
    }

    /** maxTokens 4000 → estimate ceil((bytes + 64 + 4000) / 1000) = 5 credits for a short prompt */
    private function request(array $overrides = []): AiRequest
    {
        return new AiRequest(...array_merge(['prompt' => 'Summarize this lead.', 'maxTokens' => 4000], $overrides));
    }

    private function state(Account $account): array
    {
        $b = app(CreditService::class)->balance($account);

        return [$b['balance'], $b['reserved'], $b['available']];
    }

    private function assertAiError(string $code, callable $fn): AiException
    {
        try {
            $fn();
        } catch (AiException $e) {
            $this->assertSame($code, $e->errorCode, $e->getMessage());

            return $e;
        }

        $this->fail("Expected AiException {$code}.");
    }

    /** The credit system's own invariants: the ledger replays to the stored state; never negative. */
    private function assertLedgerConsistent(Account $account): void
    {
        $credit = CreditAccount::where('account_id', $account->id)->firstOrFail();
        $balance = $reserved = 0;

        foreach (CreditLedgerEntry::where('account_id', $account->id)->orderBy('id')->get() as $entry) {
            $balance += $entry->balance_delta;
            $reserved += $entry->reserved_delta;
            $this->assertSame([$balance, $reserved], [$entry->balance_after, $entry->reserved_after], "entry #{$entry->id}");
            $this->assertGreaterThanOrEqual(0, $reserved);
            $this->assertLessThanOrEqual($balance, $reserved);
        }

        $this->assertSame([$balance, $reserved], [(int) $credit->balance, (int) $credit->reserved]);
        $this->assertGreaterThanOrEqual(0, $balance);
    }

    private function consumptions(Account $account): int
    {
        return (int) CreditLedgerEntry::where('account_id', $account->id)->where('type', 'consumption')->sum('amount');
    }

    // ==================================================================
    // Successful usage
    // ==================================================================

    public function test_a_successful_operation_holds_the_estimate_and_charges_the_actual_usage(): void
    {
        Event::fake([AiOperationPerformed::class]);
        $account = $this->funded(100);

        $result = $this->metered()->generateText($this->auth($account), $this->request());

        // 1500 + 500 tokens = 2 credits; hold was 5; 3 released in the same step.
        $this->assertSame([2, true, 'an answer'], [$result->creditsCharged, $result->settled, $result->response->text]);
        $this->assertSame([98, 0, 98], $this->state($account));

        $op = AiOperation::sole();
        $this->assertSame(
            [$account->id, AiOperation::STATUS_SETTLED, 'fake', 'fake-model', 'text', 'automation', 1500, 500, true, 5, 2, 0],
            [$op->account_id, $op->status, $op->provider, $op->model, $op->operation, $op->source, $op->input_tokens, $op->output_tokens, $op->usage_reported, $op->credits_reserved, $op->credits_charged, $op->credits_uncharged],
        );
        $reservation = CreditReservation::findOrFail($op->reservation_id);
        $this->assertSame(['consumed', 5, 2, 'ai_operation', (string) $op->id], [$reservation->status, (int) $reservation->amount, (int) $reservation->consumed_amount, $reservation->reference_type, $reservation->reference_id]);
        $consumption = CreditLedgerEntry::findOrFail($op->consumption_entry_id);
        $this->assertSame(['consumption', 2, $reservation->id, 'ai'], [$consumption->type, (int) $consumption->amount, (int) $consumption->reservation_id, $consumption->source]);
        $this->assertSame(['reservation', 'consumption', 'reservation_release'], CreditLedgerEntry::where('reservation_id', $reservation->id)->orderBy('id')->pluck('type')->all());
        $this->assertNotNull($op->settled_at);
        $this->assertLedgerConsistent($account);

        Event::assertDispatched(AiOperationPerformed::class, fn ($e) => $e->success && $e->operationId === (string) $op->id && $e->inputTokens === 1500);
    }

    public function test_no_prompt_or_answer_is_stored_with_the_operation_or_the_ledger(): void
    {
        $account = $this->funded(100);
        $this->metered()->generateText($this->auth($account), $this->request(['prompt' => 'SECRET-PROMPT-XYZ', 'system' => 'SECRET-SYSTEM']));

        $dump = json_encode([AiOperation::all()->toArray(), CreditLedgerEntry::all()->toArray(), CreditReservation::all()->toArray()]);
        $this->assertStringNotContainsString('SECRET', $dump);
        $this->assertStringNotContainsString('an answer', $dump);
    }

    public function test_structured_operations_are_billed_the_same_way(): void
    {
        $account = $this->funded(100);

        $result = $this->metered()->generateStructured($this->auth($account), $this->request());

        $this->assertSame(['ok' => true], $result->response->data);
        $this->assertSame([2, 'structured'], [$result->creditsCharged, AiOperation::sole()->operation]);
        $this->assertSame([98, 0, 98], $this->state($account));
    }

    // ==================================================================
    // Token usage → credits (centralized)
    // ==================================================================

    public static function usage(): array
    {
        return [
            'exactly one credit' => [600, 400, 1],
            'just over' => [600, 401, 2],
            'tiny usage → minimum' => [3, 2, 1],
            'three credits' => [2000, 999, 3],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('usage')]
    public function test_normalized_token_usage_decides_the_charge(int $input, int $output, int $expected): void
    {
        $this->fake['input'] = $input;
        $this->fake['output'] = $output;
        $account = $this->funded(100);

        $this->assertSame($expected, $this->metered()->generateText($this->auth($account), $this->request())->creditsCharged);
        $this->assertSame(100 - $expected, $this->state($account)[0]);
    }

    public function test_a_per_model_price_override_is_applied(): void
    {
        config(['ai.credits.tokens_per_credit_overrides' => ['fake:fake-model' => 500]]);
        $account = $this->funded(100);

        // 2000 tokens / 500 = 4 credits (estimate: ceil(4084/500) = 9).
        $this->assertSame(4, $this->metered()->generateText($this->auth($account), $this->request())->creditsCharged);
        $this->assertSame(9, AiOperation::sole()->credits_reserved);
    }

    public function test_missing_usage_follows_the_configured_rule_and_is_flagged(): void
    {
        Log::spy();
        $this->fake['input'] = null;
        $this->fake['output'] = null;
        $account = $this->funded(100);

        $this->assertSame(1, $this->metered()->generateText($this->auth($account), $this->request(['prompt' => 'a']))->creditsCharged, "'minimum' rule");
        $this->assertFalse(AiOperation::latest('id')->first()->usage_reported);
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'reported no token usage'))->once();

        config(['ai.credits.missing_usage' => 'reservation']);
        $this->assertSame(5, $this->metered()->generateText($this->auth($account), $this->request(['prompt' => 'b']))->creditsCharged, "'reservation' rule");
        $this->assertSame(100 - 1 - 5, $this->state($account)[0]);
    }

    public function test_usage_above_the_hold_is_capped_and_recorded(): void
    {
        $this->fake['input'] = 9000;
        $this->fake['output'] = 1000; // 10 credits, hold is 5
        $account = $this->funded(100);

        $this->assertSame(5, $this->metered()->generateText($this->auth($account), $this->request())->creditsCharged);
        $op = AiOperation::sole();
        $this->assertSame([5, 5], [$op->credits_charged, $op->credits_uncharged]);
        $this->assertSame(95, $this->state($account)[0], 'never more than the hold the account agreed to');
    }

    public function test_the_estimate_is_an_upper_bound_of_the_request(): void
    {
        $pricing = app(AiCreditPricing::class);

        $this->assertSame(2, $pricing->estimate(new AiRequest('x', maxTokens: 1000), 'fake', 'fake-model')); // 1 + 64 + 1000 bytes/tokens
        $this->assertSame(1, $pricing->estimate(new AiRequest('x', maxTokens: 900), 'fake', 'fake-model'));
        $this->assertSame(3, $pricing->estimate(new AiRequest(str_repeat('é', 600), maxTokens: 900), 'fake', 'fake-model'), 'multi-byte text counts by bytes');
    }

    // ==================================================================
    // Insufficient credits
    // ==================================================================

    public function test_insufficient_credits_block_the_operation_before_the_provider_and_write_nothing(): void
    {
        $account = $this->funded(4); // estimate is 5
        $ledgerBefore = CreditLedgerEntry::count();

        $e = $this->assertAiError(AiException::INSUFFICIENT_CREDITS, fn () => $this->metered()->generateText($this->auth($account), $this->request()));

        $this->assertSame(422, $e->httpStatus);
        $this->assertSame('INSUFFICIENT_CREDITS', json_decode($e->render()->getContent(), true)['error_code'], 'the credit system\'s own code');
        $this->assertSame(0, $this->fake['calls'], 'the provider was never called');
        $this->assertSame($ledgerBefore, CreditLedgerEntry::count());
        $this->assertSame([4, 0, 4], $this->state($account));
        $this->assertSame([AiOperation::STATUS_FAILED, 'INSUFFICIENT_CREDITS', null], [AiOperation::sole()->status, AiOperation::sole()->error_code, AiOperation::sole()->reservation_id]);
    }

    public function test_an_account_with_no_credits_cannot_use_ai_even_when_entitled(): void
    {
        $account = $this->tenant('growth'); // plan includes 0 credits (owner decision)

        $this->assertAiError(AiException::INSUFFICIENT_CREDITS, fn () => $this->metered()->generateText($this->auth($account), $this->request()));
        $this->assertSame(0, $this->fake['calls']);
    }

    public function test_reserved_credits_are_not_available_to_a_second_operation(): void
    {
        $account = $this->funded(7);
        // A foreign hold of 3 (another in-flight operation) leaves 4 < 5.
        app(CreditService::class)->reserve($account, 3, 'other:hold');

        $this->assertAiError(AiException::INSUFFICIENT_CREDITS, fn () => $this->metered()->generateText($this->auth($account), $this->request()));
        $this->assertSame([7, 3, 4], $this->state($account));
    }

    // ==================================================================
    // Provider failure
    // ==================================================================

    public static function failures(): array
    {
        return [
            'provider failed' => [AiException::providerFailed('fake')],
            'timeout' => [AiException::timeout('fake')],
            'malformed' => [AiException::malformedResponse('fake')],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function test_a_provider_failure_leaves_no_charge(AiException $failure): void
    {
        $this->fake['fail'] = $failure;
        $account = $this->funded(100);

        $this->assertAiError($failure->errorCode, fn () => $this->metered()->generateText($this->auth($account), $this->request()));

        $this->assertSame([100, 0, 100], $this->state($account));
        $this->assertSame(0, $this->consumptions($account));
        $op = AiOperation::sole();
        $this->assertSame([AiOperation::STATUS_FAILED, $failure->errorCode, 'released'], [$op->status, $op->error_code, CreditReservation::findOrFail($op->reservation_id)->status]);
        $this->assertLedgerConsistent($account);
    }

    public function test_an_unexpected_exception_is_normalized_and_releases_the_hold(): void
    {
        $account = $this->funded(100);
        app(AiManager::class)->extend('fake', fn () => new class implements AiProvider {
            public function name(): string { return 'fake'; }
            public function generateText(AiRequest $request): AiResponse { throw new RuntimeException('vendor SDK exploded: sk-live-123'); }
            public function generateStructured(AiRequest $request): AiResponse { throw new RuntimeException('x'); }
        });

        $e = $this->assertAiError(AiException::PROVIDER_FAILED, fn () => $this->metered()->generateText($this->auth($account), $this->request()));
        $this->assertStringNotContainsString('sk-live', $e->getMessage());
        $this->assertSame([100, 0, 100], $this->state($account));
    }

    public function test_an_unavailable_provider_fails_before_anything_is_held(): void
    {
        config(['ai.enabled' => []]);
        $account = $this->funded(100);

        $this->assertAiError(AiException::PROVIDER_UNAVAILABLE, fn () => $this->metered()->generateText($this->auth($account), $this->request()));
        $this->assertSame(0, AiOperation::count());
        $this->assertSame(0, CreditReservation::count());
    }

    // ==================================================================
    // Retry / idempotency
    // ==================================================================

    public function test_the_same_operation_key_can_never_be_charged_twice(): void
    {
        $account = $this->funded(100);

        $this->metered()->generateText($this->auth($account), $this->request(), 'journey:session:9:node:ai');
        foreach (range(1, 3) as $_) {
            $this->assertAiError(AiException::OPERATION_DUPLICATE, fn () => $this->metered()->generateText($this->auth($account), $this->request(), 'journey:session:9:node:ai'));
        }

        $this->assertSame(1, $this->fake['calls']);
        $this->assertSame(2, $this->consumptions($account));
        $this->assertSame(1, AiOperation::count());
    }

    public function test_a_failed_operation_key_may_be_retried_and_is_charged_once(): void
    {
        $account = $this->funded(100);
        $this->fake['fail'] = AiException::timeout('fake');
        $this->assertAiError(AiException::PROVIDER_TIMEOUT, fn () => $this->metered()->generateText($this->auth($account), $this->request(), 'job:42'));

        $this->fake['fail'] = null;
        $result = $this->metered()->generateText($this->auth($account), $this->request(), 'job:42');

        $this->assertSame([2, 2], [$result->creditsCharged, $result->operation->attempt]);
        $this->assertSame(2, $this->consumptions($account));
        $this->assertSame(2, CreditReservation::where('account_id', $account->id)->count(), 'one hold per attempt');
        $this->assertSame([98, 0, 98], $this->state($account));
        $this->assertAiError(AiException::OPERATION_DUPLICATE, fn () => $this->metered()->generateText($this->auth($account), $this->request(), 'job:42'));
    }

    public function test_a_charge_that_could_not_be_written_is_kept_and_settled_exactly_once_later(): void
    {
        $account = $this->funded(100);
        $failNext = true;
        DB::listen(function ($query) use (&$failNext) {
            if ($failNext && str_starts_with(strtolower($query->sql), 'insert into') && str_contains($query->sql, 'credit_ledger_entries') && in_array('consumption', $query->bindings, true)) {
                $failNext = false;
                throw new RuntimeException('simulated ledger outage');
            }
        });

        $result = $this->metered()->generateText($this->auth($account), $this->request());

        // The answer is delivered; the usage and its price are durable; nothing is lost or doubled.
        $this->assertSame(['an answer', false, 2], [$result->response->text, $result->settled, $result->creditsCharged]);
        $op = AiOperation::sole();
        $this->assertSame([AiOperation::STATUS_SUCCEEDED, 2], [$op->status, $op->credits_charged]);
        $this->assertSame([100, 5, 95], $this->state($account), 'the hold stays until settled');

        Artisan::call('ai:settle-operations');
        Artisan::call('ai:settle-operations'); // replayed: no effect
        $this->metered()->settle($op);          // called again directly: no effect

        $this->assertSame(AiOperation::STATUS_SETTLED, $op->fresh()->status);
        $this->assertSame([98, 0, 98], $this->state($account));
        $this->assertSame(1, CreditLedgerEntry::where('account_id', $account->id)->where('type', 'consumption')->count());
        $this->assertSame($op->fresh()->consumption_entry_id, CreditLedgerEntry::where('type', 'consumption')->value('id'));
        $this->assertLedgerConsistent($account);
    }

    public function test_an_operation_that_never_reported_back_is_abandoned_uncharged(): void
    {
        $account = $this->funded(100);
        // A run that died mid-call: claimed and held, never finished.
        $op = AiOperation::create(['account_id' => $account->id, 'operation_key' => 'dead', 'source' => 'automation', 'operation' => 'text', 'status' => AiOperation::STATUS_RUNNING]);
        $hold = app(CreditService::class)->reserve($account, 5, "ai:{$op->id}:a1");
        $op->update(['reservation_id' => $hold->reservation->id, 'credits_reserved' => 5]);

        Artisan::call('ai:settle-operations');
        $this->assertSame(AiOperation::STATUS_RUNNING, $op->fresh()->status, 'not before the stale threshold');

        $this->travel(config('ai.credits.stale_after_seconds') + 1)->seconds();
        Artisan::call('ai:settle-operations');

        $this->assertSame(AiOperation::STATUS_ABANDONED, $op->fresh()->status);
        $this->assertSame([100, 0, 100], $this->state($account));
        $this->assertSame(0, $this->consumptions($account));

        // The key may be retried as a new attempt.
        $this->assertSame(2, $this->metered()->generateText($this->auth($account), $this->request(), 'dead')->operation->attempt);
    }

    public function test_a_failed_operation_whose_release_failed_is_released_by_the_command(): void
    {
        $account = $this->funded(100);
        $op = AiOperation::create(['account_id' => $account->id, 'operation_key' => 'f', 'source' => 'automation', 'operation' => 'text', 'status' => AiOperation::STATUS_FAILED]);
        $hold = app(CreditService::class)->reserve($account, 5, "ai:{$op->id}:a1");
        $op->update(['reservation_id' => $hold->reservation->id]);

        Artisan::call('ai:settle-operations');
        Artisan::call('ai:settle-operations');

        $this->assertSame('released', $hold->reservation->fresh()->status);
        $this->assertSame([100, 0, 100], $this->state($account));
    }

    // ==================================================================
    // Concurrency (serialized here; the real race is the MariaDB probe)
    // ==================================================================

    public function test_repeated_operations_cannot_overspend_the_account(): void
    {
        $account = $this->funded(12); // holds of 5 each: 2 fit at a time; each settles at 2
        $outcomes = [];

        foreach (range(1, 10) as $i) {
            try {
                $this->metered()->generateText($this->auth($account), $this->request(), "op:{$i}");
                $outcomes[] = 'ok';
            } catch (AiException $e) {
                $outcomes[] = $e->errorCode;
            }
            $this->assertGreaterThanOrEqual(0, $this->state($account)[2]);
        }

        // 12 → 10 → 8 → 6 → 4 (< 5: refused from here on)
        $this->assertSame(['ok', 'ok', 'ok', 'ok', ...array_fill(0, 6, 'INSUFFICIENT_CREDITS')], $outcomes);
        $this->assertSame([4, 0, 4], $this->state($account));
        $this->assertLedgerConsistent($account);
    }

    // ==================================================================
    // Tenant isolation / target account
    // ==================================================================

    public function test_the_target_account_is_charged_and_no_other(): void
    {
        $customer = $this->funded(50);
        $other = $this->funded(50);
        $superAdmin = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $superAdmin->assignRole('super_admin');

        $auth = app(AiAuthorizer::class)->forUser($superAdmin->fresh(), $customer->id, 'chatbot', 'manage-chatbot');
        $this->metered()->generateText($auth, $this->request());

        $this->assertSame(48, $this->state($customer)[0]);
        $this->assertSame([50, 0, 50], $this->state($other));
        $this->assertSame($customer->id, AiOperation::sole()->account_id);
        $this->assertSame($superAdmin->id, AiOperation::sole()->actor_user_id);
    }

    public function test_an_agent_acting_for_a_sub_client_bills_the_sub_client(): void
    {
        $agent = $this->funded(50, 'growth', ['account_type' => 'agent']);
        $sub = $this->funded(50, 'growth', ['agent_id' => $agent->id]);
        $agentUser = User::factory()->create(['account_id' => $agent->id, 'is_active' => true]);
        $agentUser->assignRole('admin');

        $this->metered()->generateText(app(AiAuthorizer::class)->forUser($agentUser->fresh(), $sub->id, 'chatbot', 'manage-chatbot'), $this->request());

        $this->assertSame([48, 50], [$this->state($sub)[0], $this->state($agent)[0]]);
    }

    public function test_operation_keys_are_scoped_to_the_billed_account(): void
    {
        $a = $this->funded(50);
        $b = $this->funded(50);

        $this->metered()->generateText($this->auth($a), $this->request(), 'shared-key');
        $this->metered()->generateText($this->auth($b), $this->request(), 'shared-key'); // not a duplicate: other tenant

        $this->assertSame([48, 48], [$this->state($a)[0], $this->state($b)[0]]);
    }

    // ==================================================================
    // Manual + automated paths share one mechanism
    // ==================================================================

    public function test_every_origin_is_billed_identically(): void
    {
        $results = [];
        foreach (['manual', 'api', 'journey', 'crm', 'ads'] as $source) {
            $account = $this->funded(100);
            if ($source === 'manual') {
                $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
                $user->assignRole('admin');
                $auth = app(AiAuthorizer::class)->forUser($user->fresh(), null, 'chatbot', 'manage-chatbot');
            } else {
                $auth = $this->auth($account, $source);
            }

            $result = $this->metered()->generateText($auth, $this->request());
            $results[$source] = [$result->creditsCharged, $this->state($account)[0], $result->operation->source];
        }

        $this->assertSame([
            'manual' => [2, 98, 'manual'], 'api' => [2, 98, 'api'], 'journey' => [2, 98, 'journey'],
            'crm' => [2, 98, 'crm'], 'ads' => [2, 98, 'ads'],
        ], $results);
    }

    public function test_an_unentitled_account_is_refused_by_the_authorizer_whatever_its_credits(): void
    {
        $starter = $this->funded(100, 'starter'); // credits granted, but Starter does not sell `ai`

        $this->assertAiError(AiException::CAPABILITY_UNAVAILABLE, fn () => $this->auth($starter, 'journey'));
        $this->assertSame([100, 0, 100], $this->state($starter));
    }

    // ==================================================================
    // Plan credits (existing plan → credit allocation)
    // ==================================================================

    public function test_plan_included_credits_are_what_ai_consumes(): void
    {
        $plan = app(PlanManagementService::class)->create('ai-pro', [
            'label' => 'AI Pro', 'price' => 999, 'duration_days' => 30, 'engine_type' => 'qr',
            'billing_model' => 'flat_quota', 'total_allocated_messages' => 100, 'included_credits' => 7, 'is_active' => true,
        ], ['whatsapp_send', 'ai']);
        $account = Account::factory()->create();
        $invoice = new Invoice([
            'account_id' => $account->id, 'invoice_number' => 'INV-AI-'.uniqid(), 'plan_key' => 'ai-pro', 'plan_label' => 'AI Pro',
            'amount' => 999, 'tax_amount' => 0, 'total_amount' => 999, 'currency' => 'INR', 'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.uniqid(), 'status' => 'pending',
        ]);
        $invoice->capturePlanTerms($plan)->save();
        $this->assertTrue(app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid()));
        $account = $account->fresh();

        $this->assertSame([7, 0, 7], $this->state($account), 'allocated by the existing plan_allocation path');
        $this->metered()->generateText($this->auth($account), $this->request()); // 7 → 5
        $this->assertSame([5, 0, 5], $this->state($account));
        $this->assertSame(1, CreditLedgerEntry::where('account_id', $account->id)->where('type', 'plan_allocation')->count());

        // 5 left, the next hold needs 5: allowed once more, then refused.
        $this->metered()->generateText($this->auth($account), $this->request());
        $this->assertAiError(AiException::INSUFFICIENT_CREDITS, fn () => $this->metered()->generateText($this->auth($account), $this->request()));
        $this->assertLedgerConsistent($account);
    }

    // ==================================================================
    // Boundaries (static)
    // ==================================================================

    public function test_providers_stay_billing_agnostic_and_only_the_metered_service_calls_the_ai_service(): void
    {
        foreach (glob(app_path('Services/Ai/Providers/*.php')) as $file) {
            $this->assertDoesNotMatchRegularExpression('/Credit|Billing|AiOperation/', file_get_contents($file), basename($file));
        }

        // Phase 8 Task 6 — precise form: every file that references the unmetered
        // AiService class (import, type or container key), except itself and its binding.
        $callers = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && ! str_ends_with($file->getPathname(), 'Services/Ai/AiService.php')
                && preg_match('/\\\\Ai\\\\AiService\b|\bAiService::class|\bAiService\s+\$/', file_get_contents($file->getPathname()))) {
                $callers[] = basename($file->getPathname());
            }
        }
        $this->assertSame(['MeteredAiService.php'], $callers, 'billable AI goes through MeteredAiService');
    }

    public function test_the_credit_price_lives_in_one_place(): void
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && ! str_ends_with($file->getPathname(), 'AiCreditPricing.php')
                // Match the bare pricing key/formula only — NOT the
                // legitimate, separately-documented per-model
                // 'tokens_per_credit_override(s)' column/config name
                // that AiProviderSettings/AiGatewayController/AiManager
                // read and write (AiCreditPricing.php alone still folds
                // that override into the one pricing formula).
                && preg_match('/tokens_per_credit(?!_overrides?)/', file_get_contents($file->getPathname()))) {
                $offenders[] = basename($file->getPathname());
            }
        }

        $this->assertSame([], $offenders);
    }
}
