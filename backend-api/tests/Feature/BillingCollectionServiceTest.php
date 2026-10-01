<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Collections\CollectionChargeAssignment;
use App\Models\Collections\CollectionChargeItem;
use App\Models\Collections\CollectionPayment;
use App\Models\Contact;
use App\Models\Subscription;
use App\Services\Collections\BillingCollectionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Phase 11 Task 4 — the GENERIC Billing & Collections core, called directly (no HTTP, no Education, no
 * industry): charge items, assignments to a CRM contact, payments, balances, scope separation, tenant
 * safety, money precision, idempotency.
 */
class BillingCollectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private const SCOPE = 'acme_module'; // an arbitrary tag: the core has no list of valid scopes

    private BillingCollectionService $core;

    protected function setUp(): void
    {
        parent::setUp();
        $this->core = app(BillingCollectionService::class);
    }

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

    private function item(Account $account, string $name = 'Consultation', string $amount = '1000.00', string $scope = self::SCOPE): CollectionChargeItem
    {
        return $this->core->createItem($account, $scope, ['name' => $name, 'amount' => $amount]);
    }

    /** @return array{0: Account, 1: Contact, 2: CollectionChargeAssignment} an assignment of 1000.00 */
    private function billed(string $amount = '1000.00', ?string $due = null): array
    {
        $account = $this->account();
        $contact = $this->contact($account);
        $assignment = $this->core->assign($account, self::SCOPE, $contact->id, ['charge_item_id' => $this->item($account, 'Fee', $amount)->id, 'due_date' => $due]);

        return [$account, $contact, $assignment];
    }

    private function pay(Account $account, CollectionChargeAssignment $a, mixed $amount, array $extra = []): array
    {
        return $this->core->recordPayment($account, self::SCOPE, $a, ['amount' => $amount] + $extra);
    }

    private function counts(): array
    {
        return [CollectionChargeItem::count(), CollectionChargeAssignment::count(), CollectionPayment::count()];
    }

    // ------------------------------------------------------------------ the core needs nothing industry-specific

    public function test_the_core_runs_without_http_education_or_any_industry(): void
    {
        [$account, $contact, $a] = $this->billed('1500.00');

        $this->assertEquals('1500.00', $a->amount_due);
        $result = $this->pay($account, $a, '600.00', ['payment_method' => 'cash', 'reference' => 'R-1']);
        $this->assertFalse($result['idempotent']);
        $this->assertSame(['paid' => '600.00', 'out' => '900.00', 'st' => 'partially_paid'], ['paid' => $result['assignment']['amount_paid'], 'out' => $result['assignment']['outstanding'], 'st' => $result['assignment']['status']]);

        $ledger = $this->core->ledger($account, self::SCOPE, $contact->id);
        $this->assertSame(['total_due' => '1500.00', 'total_paid' => '600.00', 'outstanding' => '900.00', 'overdue' => '0.00', 'count' => 1], $ledger['summary']);

        $this->pay($account, $a, '900.00');
        $this->assertSame('paid', $this->core->ledger($account, self::SCOPE, $contact->id)['data'][0]['status']);
        $this->assertSame('0.00', $this->core->ledger($account, self::SCOPE, $contact->id)['summary']['outstanding']);
        $this->assertSame(2, $this->core->paymentHistory($account, self::SCOPE, ['contact_id' => $contact->id])->total());

        foreach (['student', 'patient', 'school', 'industry'] as $word) {
            foreach (['collection_charge_items', 'collection_charge_assignments', 'collection_payments'] as $table) {
                foreach (Schema::getColumnListing($table) as $column) {
                    $this->assertStringNotContainsString($word, $column, "{$table}.{$column} must stay industry-neutral");
                }
            }
        }
        foreach (['name', 'phone_number', 'email', 'customer_name'] as $column) {
            $this->assertFalse(Schema::hasColumn('collection_charge_assignments', $column), "no {$column} copied");
        }
    }

    // ------------------------------------------------------------------ money

    public function test_money_is_exact_in_minor_units_never_float(): void
    {
        $this->assertSame(10, $this->core->toMinor('0.10'));
        $this->assertSame(30, $this->core->toMinor('0.1') + $this->core->toMinor('0.2'), '0.1 + 0.2 is exactly 0.30');
        $this->assertSame(1999, $this->core->toMinor(19.99));
        $this->assertSame(10100, $this->core->toMinor('101'));
        $this->assertSame('0.05', $this->core->format(5));
        $this->assertSame('1234567.89', $this->core->format(123456789));

        [$account, , $a] = $this->billed('99.99');
        foreach (['33.33', '33.33', '33.33'] as $part) {
            $this->pay($account, $a, $part);
        }
        $this->assertSame('99.99', CollectionChargeAssignment::find($a->id)->payments->sum(fn ($p) => $this->core->toMinor(number_format((float) $p->amount, 2, '.', ''))) === 9999 ? '99.99' : 'x');
        $this->assertSame('paid', $this->core->present($account, $a->fresh('item'))['status']);
        $this->assertSame('0.00', $this->core->present($account, $a->fresh('item'))['outstanding']);
    }

    public function test_zero_negative_malformed_and_oversized_amounts_are_rejected_and_nothing_is_written(): void
    {
        [$account, , $a] = $this->billed();
        $before = $this->counts();

        foreach (['0', '0.00', '-5', '-0.01', '1.234', '1e3', 'abc', '', '12,50', '₹10', [10], null, '999999999999.00'] as $bad) {
            try {
                $this->pay($account, $a, $bad);
                $this->fail('accepted '.json_encode($bad));
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('amount', $e->errors());
            }
        }
        foreach (['0', '-1', '2.999'] as $bad) {
            try {
                $this->core->createItem($account, self::SCOPE, ['name' => 'X'.$bad, 'amount' => $bad]);
                $this->fail('accepted item amount '.$bad);
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame($before, $this->counts());
    }

    // ------------------------------------------------------------------ payments

    public function test_overpayment_is_rejected_and_a_fully_paid_charge_accepts_nothing_more(): void
    {
        [$account, , $a] = $this->billed('100.00');
        $this->pay($account, $a, '60.00');

        try {
            $this->pay($account, $a, '40.01');
            $this->fail('overpayment accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('40.00', $e->errors()['amount'][0]);
        }
        $this->assertSame(1, CollectionPayment::count(), 'the rejected payment left nothing behind');

        $this->pay($account, $a, '40.00');
        try {
            $this->pay($account, $a, '0.01');
            $this->fail('payment on a paid charge accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('fully paid', $e->errors()['amount'][0]);
        }
        $this->assertSame(2, CollectionPayment::count());
    }

    public function test_a_retried_payment_with_the_same_key_is_recorded_once(): void
    {
        [$account, , $a] = $this->billed('100.00');
        $key = 'pay-'.uniqid();

        $first = $this->pay($account, $a, '30.00', ['idempotency_key' => $key, 'payment_date' => '2026-09-01']);
        $again = $this->pay($account, $a, '30.00', ['idempotency_key' => $key, 'payment_date' => '2026-09-01']);

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($again['idempotent']);
        $this->assertSame($first['payment']->id, $again['payment']->id);
        $this->assertSame(1, CollectionPayment::count());
        $this->assertSame('70.00', $again['assignment']['outstanding']);

        // The same key for a DIFFERENT payment is refused, never silently merged or duplicated.
        try {
            $this->pay($account, $a, '31.00', ['idempotency_key' => $key, 'payment_date' => '2026-09-01']);
            $this->fail('key reuse accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('idempotency_key', $e->errors());
        }
        $this->assertSame(1, CollectionPayment::count());

        // No key → two equal payments are two payments (a real second instalment).
        $this->pay($account, $a, '10.00');
        $this->pay($account, $a, '10.00');
        $this->assertSame(3, CollectionPayment::count());
    }

    public function test_a_key_is_scoped_to_its_account(): void
    {
        [$accountA, , $a] = $this->billed('100.00');
        [$accountB, , $b] = $this->billed('100.00');
        $key = 'shared-key-123';

        $this->pay($accountA, $a, '10.00', ['idempotency_key' => $key]);
        $this->assertFalse($this->pay($accountB, $b, '10.00', ['idempotency_key' => $key])['idempotent'], "A's key never matches B's payment");
        $this->assertSame(2, CollectionPayment::count());
    }

    public function test_an_invalid_payment_leaves_no_partial_write(): void
    {
        [$account, , $a] = $this->billed('100.00');
        $before = $this->counts();

        foreach ([['payment_method' => 'bitcoin'], ['payment_date' => '2026-02-30'], ['payment_date' => '2099-01-01'], ['reference' => str_repeat('x', 121)], ['idempotency_key' => 'short']] as $extra) {
            try {
                $this->pay($account, $a, '10.00', $extra);
                $this->fail('accepted '.json_encode($extra));
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame($before, $this->counts());
        $this->assertSame('100.00', $this->core->present($account, $a->fresh('item'))['outstanding']);
    }

    public function test_payments_are_never_rewritten_when_the_charge_changes(): void
    {
        [$account, , $a] = $this->billed('100.00');
        $payment = $this->pay($account, $a, '40.00', ['reference' => 'ORIG'])['payment'];
        $snapshot = CollectionPayment::find($payment->id)->only(['amount', 'payment_date', 'reference', 'created_at']);

        $this->core->updateItem($account, self::SCOPE, $a->item, ['amount' => '500.00', 'status' => 'archived']);
        $this->core->updateAssignment($account, self::SCOPE, $a, ['amount_due' => '120.00', 'due_date' => '2027-01-01']);

        $this->assertEquals($snapshot, CollectionPayment::find($payment->id)->only(['amount', 'payment_date', 'reference', 'created_at']));
        $this->assertSame('80.00', $this->core->present($account, $a->fresh('item'))['outstanding']);

        try {
            $this->core->updateAssignment($account, self::SCOPE, $a, ['amount_due' => '39.99']);
            $this->fail('amount below paid accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount_due', $e->errors());
        }
        $this->assertEquals('120.00', CollectionChargeAssignment::find($a->id)->amount_due);
    }

    public function test_status_is_derived_from_records_open_partial_paid_overdue(): void
    {
        $account = $this->account();
        $contact = $this->contact($account);
        $make = fn (string $name, ?string $due) => $this->core->assign($account, self::SCOPE, $contact->id, ['charge_item_id' => $this->item($account, $name, '100.00')->id, 'due_date' => $due]);
        $open = $make('open', now()->addDays(10)->toDateString());
        $partial = $make('partial', now()->addDays(10)->toDateString());
        $paid = $make('paid', null);
        $overdue = $make('overdue', now()->subDays(3)->toDateString());
        $overduePartial = $make('overdue-partial', now()->subDays(3)->toDateString());
        $this->pay($account, $partial, '10.00');
        $this->pay($account, $paid, '100.00');
        $this->pay($account, $overduePartial, '25.00');

        $by = array_column($this->core->ledger($account, self::SCOPE, $contact->id)['data'], 'status', 'charge_name');
        ksort($by);
        $this->assertSame(['open' => 'open', 'overdue' => 'overdue', 'overdue-partial' => 'overdue', 'paid' => 'paid', 'partial' => 'partially_paid'], $by);
        $summary = $this->core->ledger($account, self::SCOPE, $contact->id)['summary'];
        $this->assertSame('500.00', $summary['total_due']);
        $this->assertSame('135.00', $summary['total_paid']);
        $this->assertSame('365.00', $summary['outstanding']);
        $this->assertSame('175.00', $summary['overdue'], 'only the outstanding part of overdue charges');
        $this->assertCount(2, $this->core->ledger($account, self::SCOPE, $contact->id, 'overdue')['data']);
    }

    // ------------------------------------------------------------------ charge items + assignments

    public function test_charge_items_validate_and_names_are_unique_per_account_and_scope(): void
    {
        $account = $this->account();
        $item = $this->item($account, 'Tuition');

        try {
            $this->item($account, ' Tuition ');
            $this->fail('duplicate name accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->errors());
        }
        $this->item($account, 'Tuition', '10.00', 'another_module'); // same name, another scope: fine
        $this->item($this->account(), 'Tuition'); // same name, another account: fine
        $this->assertSame(3, CollectionChargeItem::count());

        $updated = $this->core->updateItem($account, self::SCOPE, $item, ['name' => 'Tuition Plus', 'amount' => '1200.5', 'frequency' => 'monthly', 'description' => '  ']);
        $this->assertEquals(['Tuition Plus', '1200.50', 'monthly', null], [$updated->name, $updated->amount, $updated->frequency, $updated->description]);
    }

    public function test_an_archived_charge_cannot_be_assigned_but_existing_assignments_stay_payable(): void
    {
        [$account, $contact, $a] = $this->billed('100.00');
        $this->core->updateItem($account, self::SCOPE, $a->item, ['status' => 'archived']);

        try {
            $this->core->assign($account, self::SCOPE, $contact->id, ['charge_item_id' => $a->charge_item_id, 'due_date' => '2027-01-01']);
            $this->fail('archived charge assigned');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('archived', $e->errors()['charge_item_id'][0]);
        }
        $this->assertSame(1, CollectionChargeAssignment::count());

        $this->pay($account, $a, '100.00');
        $this->assertSame('paid', $this->core->present($account, $a->fresh('item'))['status']);

        $this->core->updateItem($account, self::SCOPE, $a->item, ['status' => 'active']);
        $this->core->assign($account, self::SCOPE, $contact->id, ['charge_item_id' => $a->charge_item_id, 'due_date' => '2027-01-01']);
        $this->assertSame(2, CollectionChargeAssignment::count());
    }

    public function test_assignment_defaults_to_the_item_amount_and_refuses_an_exact_duplicate(): void
    {
        $account = $this->account();
        $contact = $this->contact($account);
        $item = $this->item($account, 'Fee', '250.00');

        $a = $this->core->assign($account, self::SCOPE, $contact->id, ['charge_item_id' => $item->id, 'due_date' => '2026-12-01']);
        $this->assertEquals('250.00', $a->amount_due);
        $custom = $this->core->assign($account, self::SCOPE, $contact->id, ['charge_item_id' => $item->id, 'due_date' => '2027-01-01', 'amount_due' => '199.5']);
        $this->assertEquals('199.50', $custom->amount_due);

        foreach ([['due_date' => '2026-12-01'], ['due_date' => '2026-02-31'], ['due_date' => '2027-01-01', 'amount_due' => '0']] as $bad) {
            try {
                $this->core->assign($account, self::SCOPE, $contact->id, ['charge_item_id' => $item->id] + $bad);
                $this->fail('accepted '.json_encode($bad));
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(2, CollectionChargeAssignment::count());
    }

    // ------------------------------------------------------------------ assignment updates keep paid <= amount due

    /** @return array<string, mixed> everything a rejected update must leave untouched */
    private function snapshot(CollectionChargeAssignment $a): array
    {
        return [
            'assignment' => CollectionChargeAssignment::find($a->id)->getAttributes(),
            'payments' => CollectionPayment::where('charge_assignment_id', $a->id)->orderBy('id')->get()->map->getAttributes()->all(),
        ];
    }

    public function test_the_amount_can_change_freely_before_any_payment(): void
    {
        [$account, , $a] = $this->billed('100.00');

        $up = $this->core->updateAssignment($account, self::SCOPE, $a, ['amount_due' => '150.50', 'due_date' => '2027-02-01']);
        $this->assertEquals('150.50', $up->amount_due);
        $this->assertSame('2027-02-01', $up->due_date->toDateString());
        $down = $this->core->updateAssignment($account, self::SCOPE, $a, ['amount_due' => '0.01']);
        $this->assertEquals('0.01', $down->amount_due);
        $this->assertSame('0.01', $this->core->present($account, $down)['outstanding']);
        $this->assertSame('2027-02-01', $down->due_date->toDateString(), 'an update without due_date leaves it alone');
        $cleared = $this->core->updateAssignment($account, self::SCOPE, $a, ['due_date' => null]);
        $this->assertNull($cleared->due_date);
    }

    public function test_the_amount_can_be_increased_after_a_partial_payment(): void
    {
        [$account, , $a] = $this->billed('100.00');
        $this->pay($account, $a, '60.00');

        $updated = $this->core->updateAssignment($account, self::SCOPE, $a, ['amount_due' => '250.00']);

        $row = $this->core->present($account, $updated);
        $this->assertSame(['250.00', '60.00', '190.00', 'partially_paid'], [$row['amount_due'], $row['amount_paid'], $row['outstanding'], $row['status']]);
        $this->pay($account, $a, '190.00'); // the extra room is real
        $this->assertSame('paid', $this->core->present($account, $a->fresh('item'))['status']);
    }

    public function test_the_amount_cannot_be_reduced_below_what_was_paid_and_nothing_changes(): void
    {
        [$account, , $a] = $this->billed('100.00');
        $this->pay($account, $a, '60.00');
        $this->pay($account, $a, '15.50');
        $before = $this->snapshot($a);

        foreach (['75.49', '60.00', '0.01'] as $tooLow) {
            try {
                // a valid due_date in the same request must not be applied when the amount is refused
                $this->core->updateAssignment($account, self::SCOPE, $a, ['amount_due' => $tooLow, 'due_date' => '2031-01-01']);
                $this->fail("amount_due {$tooLow} accepted below the paid total 75.50");
            } catch (ValidationException $e) {
                $this->assertSame(['amount_due'], array_keys($e->errors()));
                $this->assertStringContainsString('already been paid', $e->errors()['amount_due'][0]);
            }
        }

        $this->assertSame($before, $this->snapshot($a), 'the assignment and every payment row are exactly as they were');
        $row = $this->core->present($account, $a->fresh('item'));
        $this->assertSame(['100.00', '75.50', '24.50'], [$row['amount_due'], $row['amount_paid'], $row['outstanding']], 'no negative outstanding');
    }

    public function test_reducing_exactly_to_the_paid_total_is_allowed_and_closes_the_charge(): void
    {
        [$account, , $a] = $this->billed('100.00');
        $this->pay($account, $a, '60.00');

        $updated = $this->core->updateAssignment($account, self::SCOPE, $a, ['amount_due' => '60.00']);

        $row = $this->core->present($account, $updated);
        $this->assertSame(['60.00', '60.00', '0.00', 'paid'], [$row['amount_due'], $row['amount_paid'], $row['outstanding'], $row['status']]);
        try {
            $this->pay($account, $a, '0.01');
            $this->fail('a paid charge accepted another payment');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('fully paid', $e->errors()['amount'][0]);
        }
        $this->assertSame(1, CollectionPayment::count());
        try {
            $this->core->updateAssignment($account, self::SCOPE, $a, ['amount_due' => '59.99']);
            $this->fail('one paisa below the paid total accepted');
        } catch (ValidationException) {
            $this->assertEquals('60.00', CollectionChargeAssignment::find($a->id)->amount_due);
        }
    }

    public function test_the_charge_item_and_other_financial_fields_cannot_be_changed_by_an_update(): void
    {
        [$account, $contact, $a] = $this->billed('100.00');
        $other = $this->item($account, 'Other fee', '10.00');
        $otherContact = $this->contact($account, '919877770007');
        $withoutPayment = $this->snapshot($a);

        $forbidden = [
            ['charge_item_id' => $other->id],
            ['status' => 'paid'],
            ['contact_id' => $otherContact->id],
            ['account_id' => $this->account()->id],
            ['amount_paid' => '100.00'],
            ['outstanding' => '0.00'],
        ];
        foreach ([false, true] as $afterPayment) {
            if ($afterPayment) {
                $this->pay($account, $a, '40.00');
            }
            $before = $this->snapshot($a);
            foreach ($forbidden as $extra) {
                try {
                    $this->core->updateAssignment($account, self::SCOPE, $a, ['amount_due' => '120.00'] + $extra);
                    $this->fail('accepted '.json_encode($extra).' (after payment: '.json_encode($afterPayment).')');
                } catch (ValidationException $e) {
                    $this->assertSame([array_key_first($extra)], array_keys($e->errors()));
                }
            }
            $this->assertSame($before, $this->snapshot($a), 'a refused update — even one that also carried a valid amount — changes nothing');
        }
        $this->assertNotSame($withoutPayment['payments'], $this->snapshot($a)['payments']);
        $this->assertSame($other->id === $a->fresh()->charge_item_id, false);
        $this->assertSame($contact->id, $a->fresh()->contact_id);
    }

    public function test_an_update_cannot_move_an_assignment_across_accounts_or_scopes(): void
    {
        [$account, , $a] = $this->billed('100.00');
        $this->pay($account, $a, '10.00');
        $before = $this->snapshot($a);

        foreach ([fn () => $this->core->updateAssignment($this->account(), self::SCOPE, $a, ['amount_due' => '500.00']), fn () => $this->core->updateAssignment($account, 'second_module', $a, ['amount_due' => '500.00'])] as $attempt) {
            try {
                $attempt();
                $this->fail('an update crossed an account or scope boundary');
            } catch (HttpException) {
                $this->assertSame($before, $this->snapshot($a));
            }
        }
    }

    // ------------------------------------------------------------------ scope + tenant safety

    public function test_scopes_never_see_or_pay_each_others_records_for_the_same_contact(): void
    {
        [$account, $contact, $a] = $this->billed('100.00');
        $other = $this->core->assign($account, 'second_module', $contact->id, ['charge_item_id' => $this->item($account, 'Other', '50.00', 'second_module')->id]);

        $this->assertSame(['Fee'], array_column($this->core->ledger($account, self::SCOPE, $contact->id)['data'], 'charge_name'));
        $this->assertSame(['Other'], array_column($this->core->ledger($account, 'second_module', $contact->id)['data'], 'charge_name'));

        $this->expectException(HttpException::class);
        try {
            $this->core->recordPayment($account, self::SCOPE, $other, ['amount' => '10.00']);
        } finally {
            $this->assertSame(0, CollectionPayment::count());
        }
    }

    public function test_another_accounts_records_are_never_reachable(): void
    {
        [$accountA, $contactA, $a] = $this->billed('100.00');
        $accountB = $this->account();
        $contactB = $this->contact($accountB, '919822220002');
        $itemB = $this->item($accountB, 'B fee');

        foreach ([
            fn () => $this->core->assign($accountB, self::SCOPE, $contactA->id, ['charge_item_id' => $itemB->id]),  // A's customer
            fn () => $this->core->assign($accountA, self::SCOPE, $contactA->id, ['charge_item_id' => $itemB->id]),  // B's charge
            fn () => $this->pay($accountB, $a, '10.00'),                                                              // A's assignment
            fn () => $this->core->updateAssignment($accountB, self::SCOPE, $a, ['amount_due' => '5.00']),
            fn () => $this->core->updateItem($accountB, self::SCOPE, $a->item, ['name' => 'hijack']),
            fn () => $this->core->item($accountB, self::SCOPE, $a->charge_item_id),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('a cross-account reference was accepted');
            } catch (HttpException|ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame([], $this->core->ledger($accountB, self::SCOPE, $contactA->id)['data']);
        $this->assertSame(0, $this->core->paymentHistory($accountB, self::SCOPE)->total());
        $this->assertSame(1, CollectionChargeAssignment::count());
        $this->assertSame(0, CollectionPayment::count());
        $this->assertSame('hijack' === CollectionChargeItem::find($a->charge_item_id)->name, false);
        $this->assertSame($contactB->account_id, $accountB->id);
    }

    public function test_the_database_itself_refuses_cross_account_references_and_protects_history(): void
    {
        [$accountA, $contactA, $a] = $this->billed('100.00');
        $accountB = $this->account();
        $contactB = $this->contact($accountB, '919833330003');
        $itemB = $this->item($accountB, 'B fee');
        $now = now();
        $assignment = fn (array $o) => DB::table('collection_charge_assignments')->insert($o + ['amount_due' => '1.00', 'created_at' => $now, 'updated_at' => $now]);

        // contact of A with item of B / account of B, and the mirror
        foreach ([
            ['account_id' => $accountB->id, 'contact_id' => $contactA->id, 'charge_item_id' => $itemB->id],
            ['account_id' => $accountA->id, 'contact_id' => $contactA->id, 'charge_item_id' => $itemB->id],
            ['account_id' => $accountA->id, 'contact_id' => $contactB->id, 'charge_item_id' => $a->charge_item_id],
        ] as $row) {
            try {
                $assignment($row);
                $this->fail('the database accepted a cross-account assignment');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
        try {
            DB::table('collection_payments')->insert(['account_id' => $accountB->id, 'charge_assignment_id' => $a->id, 'amount' => '1.00', 'payment_date' => '2026-09-01', 'created_at' => $now, 'updated_at' => $now]);
            $this->fail('the database accepted a payment on another account\'s assignment');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        // A contact with a financial record cannot be deleted from under it.
        try {
            $contactA->delete();
            $this->fail('a contact with charges was deleted');
        } catch (QueryException) {
            $this->assertNotNull(Contact::find($contactA->id));
        }

        // Two payments can never share an account + key at the database.
        $this->pay($accountA, $a, '1.00', ['idempotency_key' => 'dupe-key-1']);
        try {
            DB::table('collection_payments')->insert(['account_id' => $accountA->id, 'charge_assignment_id' => $a->id, 'amount' => '1.00', 'payment_date' => '2026-09-01', 'idempotency_key' => 'dupe-key-1', 'created_at' => $now, 'updated_at' => $now]);
            $this->fail('duplicate idempotency key accepted');
        } catch (QueryException) {
            $this->assertSame(1, CollectionPayment::count());
        }
    }

    public function test_payment_history_filters_and_is_newest_first(): void
    {
        [$account, $contact, $a] = $this->billed('100.00');
        $this->pay($account, $a, '10.00', ['payment_date' => '2026-08-01', 'payment_method' => 'cash']);
        $this->pay($account, $a, '20.00', ['payment_date' => '2026-09-01', 'payment_method' => 'upi']);
        $this->pay($account, $a, '30.00', ['payment_date' => '2026-09-15', 'payment_method' => 'upi']);

        $amounts = fn (array $f) => array_map(fn ($p) => $this->core->presentPayment($p)['amount'], $this->core->paymentHistory($account, self::SCOPE, $f)->items());
        $this->assertSame(['30.00', '20.00', '10.00'], $amounts(['contact_id' => $contact->id]));
        $this->assertSame(['30.00', '20.00'], $amounts(['payment_method' => 'upi']));
        $this->assertSame(['20.00'], $amounts(['from' => '2026-08-15', 'to' => '2026-09-10']));
        $this->assertSame([], $amounts(['contact_id' => $contact->id + 999]));
    }

    // ------------------------------------------------------------------ real concurrency

    /**
     * Real row-lock concurrency cannot run inside PHPUnit (RefreshDatabase's transaction is invisible to other
     * processes), so this runs tests/Probes/collections_payment_concurrency_probe.php — forked OS processes with
     * their own connections driving the real service — against the throwaway database
     * `wa_throwaway_collections`, whenever the suite runs on MariaDB.
     */
    public function test_concurrent_payments_cannot_exceed_the_balance_on_real_mariadb(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Real row-lock concurrency needs MariaDB; SQLite serializes all writers. Run the suite on MariaDB (authoritative) or the probe directly.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The concurrency probe needs the pcntl extension.');
        }

        $current = (string) DB::connection()->getDatabaseName();
        $this->assertStringStartsWith('wa_throwaway_', $current, 'refusing to create a probe database next to a non-throwaway one');
        DB::statement('CREATE DATABASE IF NOT EXISTS `wa_throwaway_collections`');

        $config = config('database.connections.'.config('database.default'));
        $process = new Process([PHP_BINARY, base_path('tests/Probes/collections_payment_concurrency_probe.php')], base_path(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => config('database.default'), 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
            'DB_DATABASE' => 'wa_throwaway_collections', 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => (string) $config['password'],
        ]);
        $process->setTimeout(600)->run();
        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertSame(0, $process->getExitCode(), $output);
        $this->assertStringNotContainsString('FAIL', $output, $output);
        foreach (['8 full payments at once', '10 × 30.00 on 100.00', 'one idempotency key from 8 processes', '8 identical assignments at once', 'independent assignments do not interfere', 'payments racing amount_due reductions', 'payments racing cancel', 'cancel/reinstate/pay interleaved'] as $case) {
            $this->assertMatchesRegularExpression('/^PASS .*'.preg_quote($case, '/').'/m', $output, $case);
        }
    }
}
