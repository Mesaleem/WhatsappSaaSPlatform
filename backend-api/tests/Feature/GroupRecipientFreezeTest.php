<?php

namespace Tests\Feature;

use App\Jobs\ProcessGroupDirectMessageJob;
use App\Jobs\ProcessGroupDispatchJob;
use App\Models\Account;
use App\Models\ContactGroup;
use App\Models\ContactGroupMember;
use App\Models\GroupDispatchRecipient;
use App\Models\Invoice;
use App\Models\MessageDispatchLog;
use App\Models\MessageTemplate;
use App\Models\Subscription;
use App\Models\WhatsAppSession;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Groups\GroupDirectMessageDispatcher;
use App\Services\Groups\GroupMessageDispatcher;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

// Shadows sleep() for App\Jobs in the test suite only (anti-ban pacing).
require_once __DIR__.'/../Support/disable_job_sleep.php';

/**
 * Phase 5 fix P5-1 — a group batch sends to EXACTLY the recipients its
 * quota reservation covered.
 *
 * The dispatcher reads the membership inside the reservation transaction,
 * reserves that count, and freezes the list (group_dispatch_recipients).
 * Both group jobs iterate the frozen list: a member added afterwards is
 * never sent; one removed afterwards is not sent, is recorded as a failed
 * recipient and is refunded. success + failure == recipient_count.
 *
 * Every scenario runs against BOTH group paths: template
 * (GroupMessageDispatcher / ProcessGroupDispatchJob) and direct
 * (GroupDirectMessageDispatcher / ProcessGroupDirectMessageJob).
 */
class GroupRecipientFreezeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(Phase1FoundationSeeder::class);
        Queue::fake();
    }

    // ------------------------------------------------------------------ fixtures

    private function qrAccount(): Account
    {
        $account = Account::factory()->create();
        $planKey = collect(PlanCatalog::all())->search(fn ($p) => $p['engine_type'] === 'qr');
        $plan = PlanCatalog::find($planKey);
        $invoice = Invoice::create([
            'account_id' => $account->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => $planKey,
            'plan_label' => $plan['label'], 'amount' => $plan['price'], 'tax_amount' => 0,
            'total_amount' => $plan['price'], 'currency' => 'INR', 'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.uniqid(), 'gateway_payment_id' => null, 'status' => 'pending',
            'paid_at' => null, 'gateway_raw_response' => null,
        ]);
        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());
        WhatsAppSession::create(['account_id' => $account->id, 'status' => 'connected']);

        return $account;
    }

    /** @param array<string, string> $members name => phone */
    private function group(Account $account, array $members): ContactGroup
    {
        $group = ContactGroup::create(['account_id' => $account->id, 'name' => 'Segment', 'group_type' => ContactGroup::GROUP_TYPE_INTERNAL]);
        foreach ($members as $name => $phone) {
            $this->addMember($group, $name, $phone);
        }

        return $group;
    }

    private function addMember(ContactGroup $group, string $name, string $phone): ContactGroupMember
    {
        return ContactGroupMember::create(['group_id' => $group->id, 'phone_number' => $phone, 'name' => $name]);
    }

    private function member(ContactGroup $group, string $name): ContactGroupMember
    {
        return ContactGroupMember::where('group_id', $group->id)->where('name', $name)->firstOrFail();
    }

    private function template(Account $account): MessageTemplate
    {
        return MessageTemplate::create([
            'account_id' => $account->id, 'template_code' => 'TPL_'.strtoupper(Str::random(6)),
            'title' => 'Blast', 'template_body' => 'Hello {{name}}.', 'status' => 'approved',
        ]);
    }

    private function used(Account $account): int
    {
        return (int) Subscription::where('account_id', $account->id)->value('used_messages');
    }

    private function allSendsSucceed(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'message_id' => 'QR_OK'], 200)]);
    }

    /** @return list<string> phone numbers actually handed to the WhatsApp engine */
    private function sentTo(): array
    {
        return collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn (HttpRequest $r) => str_ends_with($r->url(), '/api/message/send'))
            ->map(fn (HttpRequest $r) => Str::before((string) $r['to'], '@'))
            ->values()->all();
    }

    /**
     * Dispatch through one of the two group paths and return [dispatch_id, runJob].
     *
     * @return array{0: int, 1: \Closure}
     */
    private function dispatchVia(string $path, Account $account, ContactGroup $group): array
    {
        if ($path === 'template') {
            $template = $this->template($account);
            $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);
            $this->assertSame('queued', $result['status']);

            return [$result['dispatch_id'], fn () => (new ProcessGroupDispatchJob($result['dispatch_id'], $template->id, [], null))->handle()];
        }

        $result = GroupDirectMessageDispatcher::dispatch($account->id, $group->id, 'text', ['body' => 'Hi']);
        $this->assertSame('queued', $result['status']);

        return [$result['dispatch_id'], fn () => (new ProcessGroupDirectMessageJob($result['dispatch_id'], 'text', ['body' => 'Hi'], null))->handle()];
    }

    public static function paths(): array
    {
        return ['template path' => ['template'], 'direct path' => ['direct']];
    }

    // ------------------------------------------------------------------ growth

    /** @dataProvider paths */
    public function test_a_member_added_after_reservation_is_not_sent(string $path): void
    {
        $account = $this->qrAccount();
        $group = $this->group($account, ['A' => '919000000001', 'B' => '919000000002', 'C' => '919000000003']);
        [$dispatchId, $run] = $this->dispatchVia($path, $account, $group);

        $this->assertSame(3, $this->used($account), 'reserved = 3');
        $frozenBefore = GroupDispatchRecipient::where('parent_dispatch_id', $dispatchId)->orderBy('id')->pluck('phone_number')->all();

        $d = $this->addMember($group, 'D', '919000000004');

        $this->allSendsSucceed();
        $run();

        $this->assertSame(['919000000001', '919000000002', '919000000003'], $this->sentTo(), 'sent targets = A, B, C');
        $this->assertNotContains('919000000004', $this->sentTo(), 'D is not sent');
        $this->assertSame(0, MessageDispatchLog::where('parent_dispatch_id', $dispatchId)->where('reference_id', $d->id)->count());

        $this->assertSame($frozenBefore, GroupDispatchRecipient::where('parent_dispatch_id', $dispatchId)->orderBy('id')->pluck('phone_number')->all(), 'the reserved list is unchanged');
        $this->assertSame(['919000000001', '919000000002', '919000000003'], $frozenBefore);

        $log = MessageDispatchLog::findOrFail($dispatchId);
        $this->assertSame(3, (int) $log->recipient_count);
        $this->assertSame(3, (int) $log->success_count);
        $this->assertSame(0, (int) $log->failure_count);
        $this->assertSame(3, (int) $log->success_count + (int) $log->failure_count);
        $this->assertSame(3, $this->used($account), 'quota stays exactly the reservation — no second charge, no unpaid send');
        $this->assertSame(3, MessageDispatchLog::where('parent_dispatch_id', $dispatchId)->count());
    }

    // ------------------------------------------------------------------ shrink

    /** @dataProvider paths */
    public function test_a_member_removed_after_reservation_resolves_as_a_refunded_failure(string $path): void
    {
        $account = $this->qrAccount();
        $group = $this->group($account, ['A' => '919000000001', 'B' => '919000000002', 'C' => '919000000003']);
        [$dispatchId, $run] = $this->dispatchVia($path, $account, $group);
        $b = $this->member($group, 'B');

        $b->delete();
        // A newcomer must not take B's place.
        $this->addMember($group, 'D', '919000000004');

        $this->allSendsSucceed();
        $run();

        $this->assertSame(['919000000001', '919000000003'], $this->sentTo());

        $bRow = MessageDispatchLog::where('parent_dispatch_id', $dispatchId)->where('reference_id', $b->id)->sole();
        $this->assertSame('failed', $bRow->status);
        $this->assertSame('919000000002', $bRow->recipient_phone);
        $this->assertStringContainsString('Removed from the group', $bRow->error_reason);
        $this->assertNull($bRow->gateway_message_id);

        $log = MessageDispatchLog::findOrFail($dispatchId);
        $this->assertSame(3, (int) $log->recipient_count);
        $this->assertSame(2, (int) $log->success_count);
        $this->assertSame(1, (int) $log->failure_count);
        $this->assertSame('sent', $log->status);
        $this->assertSame(2, $this->used($account), '3 reserved, 1 refunded');
    }

    /** @dataProvider paths */
    public function test_a_fully_emptied_group_refunds_everything_and_still_balances(string $path): void
    {
        $account = $this->qrAccount();
        $group = $this->group($account, ['A' => '919000000001', 'B' => '919000000002']);
        [$dispatchId, $run] = $this->dispatchVia($path, $account, $group);

        ContactGroupMember::where('group_id', $group->id)->delete();
        $this->addMember($group, 'Z', '919000000009');

        $this->allSendsSucceed();
        $run();

        $this->assertSame([], $this->sentTo());
        $log = MessageDispatchLog::findOrFail($dispatchId);
        $this->assertSame([0, 2, 2], [(int) $log->success_count, (int) $log->failure_count, (int) $log->recipient_count]);
        $this->assertSame('failed', $log->status);
        $this->assertSame(0, $this->used($account));
    }

    // ------------------------------------------------------------------ idempotency

    /** @dataProvider paths */
    public function test_running_the_job_twice_sends_nothing_more_and_refunds_nothing_more(string $path): void
    {
        $account = $this->qrAccount();
        $group = $this->group($account, ['A' => '919000000001', 'B' => '919000000002', 'C' => '919000000003']);
        [$dispatchId, $run] = $this->dispatchVia($path, $account, $group);
        $this->member($group, 'C')->delete();

        $this->allSendsSucceed();
        $run();
        $sentAfterFirst = $this->sentTo();
        $usedAfterFirst = $this->used($account);

        $this->addMember($group, 'D', '919000000004');
        $run();

        $this->assertSame($sentAfterFirst, $this->sentTo(), 'the duplicate run sends nothing');
        $this->assertSame($usedAfterFirst, $this->used($account), 'and refunds nothing');
        $this->assertSame(2, $usedAfterFirst);
        $this->assertSame(3, MessageDispatchLog::where('parent_dispatch_id', $dispatchId)->count(), 'one child row per reserved recipient');
    }

    // ------------------------------------------------------------------ reservation integrity / tenancy

    /** @dataProvider paths */
    public function test_the_frozen_list_is_written_with_the_reservation_and_only_with_it(string $path): void
    {
        $account = $this->qrAccount();
        $group = $this->group($account, ['A' => '919000000001', 'B' => '919000000002']);
        [$dispatchId] = $this->dispatchVia($path, $account, $group);

        $rows = GroupDispatchRecipient::where('parent_dispatch_id', $dispatchId)->orderBy('id')->get();
        $this->assertSame([$account->id, $account->id], $rows->pluck('account_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(['A', 'B'], $rows->pluck('name')->all());
        $this->assertSame((int) MessageDispatchLog::findOrFail($dispatchId)->recipient_count, $rows->count());

        // A refused reservation writes no frozen list (and no parent row).
        $sub = Subscription::where('account_id', $account->id)->firstOrFail();
        $sub->forceFill(['used_messages' => $sub->total_allocated_messages - 1])->save();
        $refused = $path === 'template'
            ? GroupMessageDispatcher::dispatch($account->id, $group->id, $this->template($account)->id, [])
            : GroupDirectMessageDispatcher::dispatch($account->id, $group->id, 'text', ['body' => 'Hi']);
        $this->assertSame('insufficient_quota', $refused['status']);
        $this->assertSame(2, GroupDispatchRecipient::count());
    }

    /** @dataProvider paths */
    public function test_a_recipient_row_belonging_to_another_account_is_ignored(string $path): void
    {
        $account = $this->qrAccount();
        $other = $this->qrAccount();
        $group = $this->group($account, ['A' => '919000000001']);
        [$dispatchId, $run] = $this->dispatchVia($path, $account, $group);

        // A row pointed at this batch but carrying another tenant's account id.
        GroupDispatchRecipient::create([
            'parent_dispatch_id' => $dispatchId, 'account_id' => $other->id,
            'contact_group_member_id' => 999999, 'phone_number' => '919999999999', 'name' => 'Intruder',
        ]);

        $this->allSendsSucceed();
        $run();

        $this->assertSame(['919000000001'], $this->sentTo());
        $log = MessageDispatchLog::findOrFail($dispatchId);
        $this->assertSame([1, 0, 1], [(int) $log->success_count, (int) $log->failure_count, (int) $log->recipient_count]);
    }

    public function test_the_frozen_name_is_what_personalises_a_template_send(): void
    {
        $account = $this->qrAccount();
        $group = $this->group($account, ['Asha' => '919000000001']);
        [, $run] = $this->dispatchVia('template', $account, $group);

        $this->allSendsSucceed();
        $run();

        $body = collect(Http::recorded())->map(fn ($p) => $p[0])->first(fn (HttpRequest $r) => str_ends_with($r->url(), '/api/message/send'));
        $this->assertSame('Hello Asha.', $body['message']);
    }

    /** @dataProvider paths */
    public function test_a_batch_queued_before_the_fix_never_sends_more_than_it_reserved(string $path): void
    {
        $account = $this->qrAccount();
        $group = $this->group($account, ['A' => '919000000001', 'B' => '919000000002']);
        [$dispatchId, $run] = $this->dispatchVia($path, $account, $group);
        // A pre-fix batch has no frozen rows.
        GroupDispatchRecipient::where('parent_dispatch_id', $dispatchId)->delete();
        $this->addMember($group, 'C', '919000000003');

        $this->allSendsSucceed();
        $run();

        $this->assertSame(['919000000001', '919000000002'], $this->sentTo(), 'capped at the reserved count');
        $log = MessageDispatchLog::findOrFail($dispatchId);
        $this->assertSame([2, 0, 2], [(int) $log->success_count, (int) $log->failure_count, (int) $log->recipient_count]);
        $this->assertSame(2, $this->used($account));
    }
}
