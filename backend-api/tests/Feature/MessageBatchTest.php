<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\MessageBatch;
use App\Models\MessageBatchItem;
use App\Models\MessageTemplate;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppNumber;
use App\Services\Batches\MessageBatchService;
use App\Services\Batches\RecipientFileParser;
use Carbon\Carbon;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

/** Excel/CSV batch sends from the Send Notification page: parsing, sender rotation, spacing, schedule, pause, stop. */
class MessageBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function number(Account $account, string $phone, bool $default = false, string $status = WhatsAppNumber::STATUS_LINKED): WhatsAppNumber
    {
        return WhatsAppNumber::query()->create([
            'account_id' => $account->id,
            'phone_number' => $phone,
            'is_included' => true,
            'is_default' => $default,
            'status' => $status,
        ]);
    }

    /** A client admin with an active plan, one linked default number, and an approved template. */
    private function client(string $defaultPhone = '919800000001'): array
    {
        $account = Account::factory()->create();
        Subscription::factory()->for($account)->create(['engine_type' => 'qr', 'status' => 'active', 'expires_at' => now()->addMonth()]);

        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');

        $template = MessageTemplate::create([
            'account_id' => $account->id, 'template_code' => 'BATCH_'.strtoupper(Str::random(5)), 'title' => 'Payment reminder',
            'template_body' => 'Hello {{name}}', 'status' => 'approved', 'language' => 'en_US', 'category' => 'UTILITY',
            'meta_template_name' => 'payment_reminder', 'meta_template_status' => 'APPROVED',
        ]);

        $default = $this->number($account, $defaultPhone, true);

        return [$account, $user, $template, $default];
    }

    private function csv(string $content, string $name = 'customers.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    /** @param  list<int>|null  $senders  sending numbers in turn order; null = the account's default number */
    private function store($user, $template, UploadedFile $file, array $extra = [], ?array $senders = null)
    {
        $default = WhatsAppNumber::query()->where('account_id', $user->account_id)->where('is_default', true)->value('id');

        return $this->actingAs($user)->postJson('/api/alerts/message-batches', array_merge([
            'file' => $file,
            'template_id' => $template->id,
            'variables' => ['name' => 'Asha'],
            'batch_size' => 10,
            'interval_minutes' => 5,
            'sender_number_ids' => $senders ?? [$default],
        ], $extra));
    }

    public function test_the_csv_phone_column_is_read_with_duplicates_and_bad_rows_handled(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'batch');
        file_put_contents($path, "name,phone\nAsha,+91 98765 43210\nRavi,919876543211\nDup,919876543210\nBad,12\n");

        $parsed = app(RecipientFileParser::class)->parse($path, 'csv');

        $this->assertSame(['919876543210', '919876543211'], $parsed['phones']);
        $this->assertSame(1, $parsed['invalid']);
        unlink($path);
    }

    public function test_an_xlsx_list_is_read_from_its_first_sheet(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'batch').'.xlsx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst><si><t>Mobile</t></si><si><t>Name</t></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet><sheetData>'
            .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
            .'<row r="2"><c r="A2"><v>919876543210</v></c><c r="B2" t="inlineStr"><is><t>Asha</t></is></c></row>'
            .'<row r="3"><c r="A3"><v>919876543211</v></c></row>'
            .'</sheetData></worksheet>');
        $zip->close();

        $parsed = app(RecipientFileParser::class)->parse($path, 'xlsx');

        $this->assertSame(['919876543210', '919876543211'], $parsed['phones']);
        unlink($path);
    }

    public function test_an_immediate_batch_is_stored_with_one_row_per_number(): void
    {
        [, $user, $template] = $this->client();

        $this->store($user, $template, $this->csv("phone\n919876543210\n919876543211\n919876543212\n"))
            ->assertCreated()
            ->assertJsonPath('data.status', MessageBatch::RUNNING)
            ->assertJsonPath('data.total', 3);

        $batch = MessageBatch::query()->firstOrFail();
        $this->assertSame(3, $batch->items()->count());
        $this->assertSame(MessageBatchItem::PENDING, $batch->items()->orderBy('sequence')->value('status'));
    }

    public function test_a_batch_without_a_template_sends_the_typed_text(): void
    {
        [$account, $user, , $default] = $this->client();

        $this->actingAs($user)->postJson('/api/alerts/message-batches', [
            'file' => $this->csv("phone
919876543210
"),
            'message_text' => 'Stock is running low, please reorder.',
            'batch_size' => 10,
            'interval_minutes' => 5,
            'sender_number_ids' => [$default->id],
        ])->assertCreated();

        $batch = MessageBatch::query()->firstOrFail();
        $this->assertNull($batch->template_id);
        $this->assertSame('Stock is running low, please reorder.', $batch->message_text);
    }

    public function test_a_batch_without_a_template_and_without_text_or_media_is_refused(): void
    {
        [, $user, , $default] = $this->client();

        $this->actingAs($user)->postJson('/api/alerts/message-batches', [
            'file' => $this->csv("phone
919876543210
"),
            'batch_size' => 10,
            'interval_minutes' => 5,
            'sender_number_ids' => [$default->id],
        ])->assertStatus(422)->assertJsonPath('error_code', 'TEMPLATE_OR_TEXT_REQUIRED');
    }

    public function test_the_senders_take_turns_in_order_and_each_number_keeps_its_gap(): void
    {
        [$account, $user, $template, $default] = $this->client();
        Carbon::setTestNow(Carbon::parse('2026-10-08 10:00:00', 'UTC'));

        $numbers = [$default->id];
        foreach (['919800000002', '919800000003', '919800000004', '919800000005'] as $phone) {
            $numbers[] = $this->number($account, $phone)->id;
        }

        // Twelve numbers from the file, five senders: message 1 from the 1st sender, message 6 from the 1st again.
        $rows = "phone\n".implode("\n", array_map(fn ($i) => '9199'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), range(1, 12)))."\n";
        $this->store($user, $template, $this->csv($rows), [], $numbers)->assertCreated();

        $items = MessageBatchItem::query()->orderBy('sequence')->get();
        $expected = array_slice(array_merge($numbers, $numbers, $numbers), 0, 12);
        $this->assertSame($expected, $items->pluck('sender_number_id')->all());

        // With five senders one step is 5 seconds, so one sender's own messages are 25 seconds apart.
        $this->assertSame(5, (int) $items[0]->send_at->diffInSeconds($items[1]->send_at));
        $this->assertSame(25, (int) $items[0]->send_at->diffInSeconds($items[5]->send_at));
    }

    public function test_one_number_sends_every_25_seconds_and_ten_numbers_every_3_seconds(): void
    {
        $service = app(MessageBatchService::class);

        $this->assertSame(25, $service->stepSeconds(1));
        $this->assertSame(5, $service->stepSeconds(5));
        $this->assertSame(3, $service->stepSeconds(10));
        $this->assertSame(13, $service->stepSeconds(2)); // 13 s apart, so each number waits 26 s between its own messages
    }

    public function test_batches_run_back_to_back_with_no_rest_between_them(): void
    {
        [, $user, $template] = $this->client();

        $phones = implode("
", array_map(fn ($i) => '9199'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), range(1, 11)));
        $this->store($user, $template, $this->csv("phone
{$phones}
"), ['batch_size' => 10])->assertCreated();

        $batch = MessageBatch::query()->firstOrFail();
        $this->assertSame(0, $batch->interval_minutes);

        $items = $batch->items()->orderBy('sequence')->get();
        // Item 11 starts a new chunk (batch_size 10), right after item 10 — exactly one step later, no extra rest.
        $this->assertSame(25, (int) $items[9]->send_at->diffInSeconds($items[10]->send_at));
    }

    public function test_a_scheduled_batch_waits_for_its_time_then_starts(): void
    {
        [, $user, $template] = $this->client();
        Carbon::setTestNow(Carbon::parse('2026-10-08 10:00:00', 'Asia/Kolkata'));

        $this->store($user, $template, $this->csv("phone\n919876543210\n"), ['scheduled_at' => '2026-10-08 11:00:00'])
            ->assertCreated()
            ->assertJsonPath('data.status', MessageBatch::SCHEDULED);

        $this->assertSame(0, app(MessageBatchService::class)->dispatchDue(Carbon::parse('2026-10-08 10:30:00', 'Asia/Kolkata')));
        $this->assertSame(MessageBatch::SCHEDULED, MessageBatch::query()->value('status'));

        app(MessageBatchService::class)->dispatchDue(Carbon::parse('2026-10-08 11:00:30', 'Asia/Kolkata'));
        $this->assertNotSame(MessageBatch::SCHEDULED, MessageBatch::query()->value('status'), 'the batch must start once its time is due');
    }

    public function test_stopping_a_batch_cancels_its_unsent_numbers_and_nothing_more_is_sent(): void
    {
        [, $user, $template] = $this->client();
        $this->store($user, $template, $this->csv("phone\n919876543210\n919876543211\n"));
        $batch = MessageBatch::query()->firstOrFail();

        $this->actingAs($user)->postJson("/api/alerts/message-batches/{$batch->id}/stop")
            ->assertOk()
            ->assertJsonPath('data.status', MessageBatch::STOPPED);

        $this->assertSame(2, $batch->items()->where('status', MessageBatchItem::CANCELLED)->count());
        $this->assertSame(0, app(MessageBatchService::class)->dispatchDue(now()->addHour()));
    }

    public function test_a_paused_batch_waits_and_resumes_on_request(): void
    {
        [, $user, $template] = $this->client();
        $this->store($user, $template, $this->csv("phone\n919876543210\n"));
        $batch = MessageBatch::query()->firstOrFail();

        $this->actingAs($user)->postJson("/api/alerts/message-batches/{$batch->id}/pause")
            ->assertOk()->assertJsonPath('data.status', MessageBatch::PAUSED);
        $this->assertSame(0, app(MessageBatchService::class)->dispatchDue(now()->addHour()));

        $this->actingAs($user)->postJson("/api/alerts/message-batches/{$batch->id}/resume")
            ->assertOk()->assertJsonPath('data.status', MessageBatch::RUNNING);
    }

    public function test_a_number_that_is_not_linked_or_not_this_accounts_is_refused(): void
    {
        [$account, $user, $template] = $this->client();
        $unlinked = $this->number($account, '919800000009', false, WhatsAppNumber::STATUS_UNLINKED);

        $this->store($user, $template, $this->csv("phone\n919876543210\n"), [], [$unlinked->id])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'sender_not_active');

        [$otherAccount] = $this->client('919800000011');
        $foreign = WhatsAppNumber::query()->where('account_id', $otherAccount->id)->value('id');
        $this->store($user, $template, $this->csv("phone\n919876543210\n"), [], [$foreign])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'sender_not_found');
    }

    public function test_another_account_cannot_stop_or_see_the_batch(): void
    {
        [, $owner, $template] = $this->client();
        $this->store($owner, $template, $this->csv("phone\n919876543210\n"));
        $batch = MessageBatch::query()->firstOrFail();

        [, $other] = $this->client('919800000012');
        $this->actingAs($other)->postJson("/api/alerts/message-batches/{$batch->id}/stop")->assertNotFound();
        $this->actingAs($other)->getJson('/api/alerts/message-batches')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_file_with_no_valid_numbers_is_refused(): void
    {
        [, $user, $template] = $this->client();

        $this->store($user, $template, $this->csv("phone\nabc\n12\n"))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'no_numbers');
    }

    public function test_the_sender_list_shows_only_linked_numbers_default_first(): void
    {
        [$account, $user] = $this->client();
        $this->number($account, '919800000002', false, WhatsAppNumber::STATUS_PAUSED);
        $this->number($account, '919800000003');

        $phones = $this->actingAs($user)->getJson('/api/alerts/sender-numbers')->assertOk()->json('data');

        $this->assertSame(['919800000001', '919800000003'], array_column($phones, 'phone_number'));
        $this->assertTrue($phones[0]['is_default']);
    }

    public function test_the_gap_is_remembered_per_sending_number_after_a_send_attempt(): void
    {
        [$account, $user, $template, $default] = $this->client();
        Carbon::setTestNow(Carbon::parse('2026-10-08 10:00:00', 'UTC'));
        $this->store($user, $template, $this->csv("phone\n919876543210\n"));

        app(MessageBatchService::class)->dispatchDue(Carbon::parse('2026-10-08 10:00:00', 'UTC'));

        $last = Cache::get('message_batch_last_send:'.$account->id.':'.$default->id);
        $this->assertNotNull($last);
        $this->assertSame('10:00:00', $last->format('H:i:s'));
    }

    public function test_batches_are_not_part_of_the_developer_api(): void
    {
        $v1 = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/'))
            ->filter(fn ($route) => str_contains($route->uri(), 'batch'));

        $this->assertCount(0, $v1, 'batch sends are for the Send Notification page only, never the Developer API');
    }
}
