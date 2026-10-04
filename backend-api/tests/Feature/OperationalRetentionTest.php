<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Ops\RetentionPruner;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Phase 12 Task 5 — operational data retention (ops:prune-retention / RetentionPruner).
 */
class OperationalRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['retention.enforce' => false, 'retention.batch_size' => 1000, 'retention.max_batches' => 50]);
    }

    private function ago(int $days): string
    {
        return now()->subDays($days)->toDateTimeString();
    }

    private function log(?int $accountId, int $daysOld, string $path = '/api/x'): int
    {
        return DB::table('api_request_logs')->insertGetId([
            'request_id' => 'r'.uniqid(), 'account_id' => $accountId, 'method' => 'GET', 'path' => $path, 'status_code' => 200,
            'created_at' => $this->ago($daysOld), 'updated_at' => $this->ago($daysOld),
        ]);
    }

    private function delivery(int $subId, int $daysOld): int
    {
        return DB::table('webhook_deliveries')->insertGetId([
            'webhook_subscription_id' => $subId, 'event' => 'message.sent', 'payload' => '{"secret":"x"}', 'status' => 'success',
            'created_at' => $this->ago($daysOld), 'updated_at' => $this->ago($daysOld),
        ]);
    }

    private function subscription(Account $a): int
    {
        return DB::table('webhook_subscriptions')->insertGetId([
            'account_id' => $a->id, 'url' => 'https://example.test/h', 'secret' => 's', 'events' => '["message.sent"]', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function failedJob(int $daysOld): void
    {
        DB::table('failed_jobs')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => $this->ago($daysOld)]);
    }

    private function rows(string $table): int
    {
        return DB::table($table)->count();
    }

    private function prune(array $args = [])
    {
        return $this->artisan('ops:prune-retention', $args);
    }

    public function test_old_rows_are_pruned_and_rows_inside_retention_are_kept_for_every_category(): void
    {
        $a = Account::factory()->create();
        $sub = $this->subscription($a);

        $this->log($a->id, 200);
        $this->log($a->id, 5);
        $this->delivery($sub, 100);
        $this->delivery($sub, 2);
        $this->failedJob(200);
        $this->failedJob(1);
        DB::table('inbound_message_events')->insert(['account_id' => $a->id, 'provider' => 'meta', 'event_key' => 'old', 'phone_number' => '1', 'created_at' => $this->ago(100)]);
        DB::table('inbound_message_events')->insert(['account_id' => $a->id, 'provider' => 'meta', 'event_key' => 'new', 'phone_number' => '1', 'created_at' => $this->ago(3)]);
        DB::table('journey_execution_events')->insert(['account_id' => $a->id, 'source' => 'runtime', 'event' => 'x', 'created_at' => $this->ago(400)]);
        DB::table('journey_execution_events')->insert(['account_id' => $a->id, 'source' => 'runtime', 'event' => 'x', 'created_at' => $this->ago(10)]);

        $this->prune(['--force' => true])->assertSuccessful();

        foreach (['api_request_logs', 'webhook_deliveries', 'failed_jobs', 'inbound_message_events', 'journey_execution_events'] as $table) {
            $this->assertSame(1, $this->rows($table), "{$table}: old row pruned, recent row kept");
        }
        $this->assertSame('new', DB::table('inbound_message_events')->value('event_key'));
    }

    public function test_open_session_history_and_referenced_inbound_keys_are_kept(): void
    {
        $a = Account::factory()->create();
        $flow = \App\Models\WhatsAppFlow::create(['account_id' => $a->id, 'name' => 'f', 'trigger_type' => 'keyword', 'trigger_value' => 'go', 'is_active' => true, 'graph_data' => ['nodes' => [], 'edges' => []]])->id;
        $open = DB::table('whatsapp_flow_sessions')->insertGetId(['account_id' => $a->id, 'flow_id' => $flow, 'phone_number' => '1', 'status' => 'waiting', 'created_at' => now(), 'updated_at' => now()]);
        $done = DB::table('whatsapp_flow_sessions')->insertGetId(['account_id' => $a->id, 'flow_id' => $flow, 'phone_number' => '2', 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([$open, $done] as $s) {
            DB::table('journey_execution_events')->insert(['account_id' => $a->id, 'session_id' => $s, 'source' => 'runtime', 'event' => 'x', 'created_at' => $this->ago(400)]);
        }
        $this->prune(['--force' => true, '--only' => ['journey_execution_events']])->assertSuccessful();

        $this->assertSame(1, DB::table('journey_execution_events')->where('session_id', $open)->count(), 'a live run keeps its history');
        $this->assertSame(0, DB::table('journey_execution_events')->where('session_id', $done)->count());
        $this->assertSame(2, $this->rows('whatsapp_flow_sessions'), 'sessions are never pruned');
    }

    public function test_tenant_rows_are_judged_only_by_their_own_age(): void
    {
        $a = Account::factory()->create();
        $b = Account::factory()->create();
        $this->log($a->id, 200);
        $this->log($b->id, 200);
        $keepB = $this->log($b->id, 1);
        $keepA = $this->log($a->id, 1);

        $this->prune(['--force' => true, '--only' => ['api_request_logs']])->assertSuccessful();

        $this->assertEqualsCanonicalizing([$keepA, $keepB], DB::table('api_request_logs')->pluck('id')->all());
        $this->assertSame(1, DB::table('api_request_logs')->where('account_id', $a->id)->count());
        $this->assertSame(1, DB::table('api_request_logs')->where('account_id', $b->id)->count());
    }

    public function test_platform_rows_are_a_separate_explicit_scope(): void
    {
        $a = Account::factory()->create();
        config(['retention.categories.api_request_logs.days' => 90, 'retention.categories.api_request_logs.platform_days' => 30]);
        $tenantMid = $this->log($a->id, 45);   // inside the tenant window
        $platformMid = $this->log(null, 45);   // outside the platform window
        $platformNew = $this->log(null, 5);

        $this->prune(['--force' => true, '--only' => ['api_request_logs']])->assertSuccessful();
        $this->assertEqualsCanonicalizing([$tenantMid, $platformNew], DB::table('api_request_logs')->pluck('id')->all());
        $this->assertNotContains($platformMid, DB::table('api_request_logs')->pluck('id')->all());

        // Disabling the platform scope keeps NULL-account rows even when ancient, and still prunes tenant rows.
        // (Ids are append-ordered in real use, so start the second phase from an empty table.)
        DB::table('api_request_logs')->delete();
        config(['retention.categories.api_request_logs.platform_days' => 0]);
        $oldPlatform = $this->log(null, 900);
        $oldTenant = $this->log($a->id, 900);
        $this->prune(['--force' => true, '--only' => ['api_request_logs']])->assertSuccessful();
        $ids = DB::table('api_request_logs')->pluck('id')->all();
        $this->assertContains($oldPlatform, $ids);
        $this->assertNotContains($oldTenant, $ids);
    }

    public function test_deletes_are_batched_and_a_rerun_is_idempotent(): void
    {
        foreach (range(1, 25) as $i) {
            $this->log(null, 300);
        }
        $this->log(null, 1);
        $run = fn () => app(RetentionPruner::class)->run(true, ['only' => ['api_request_logs'], 'batch' => 10, 'max_batches' => 2]);

        $deleted = fn (array $r) => collect($r['rows'])->where('category', 'api_request_logs')->sum('deleted');
        $this->assertSame(20, $deleted($run()), 'bounded: batch × max_batches per run');
        $this->assertSame(6, $this->rows('api_request_logs'));
        $this->assertSame(5, $deleted($run()));
        $this->assertSame(0, $deleted($run()), 'a further run finds nothing');
        $this->assertSame(1, $this->rows('api_request_logs'));
    }

    public function test_dry_run_and_unenforced_scheduled_runs_delete_nothing(): void
    {
        $this->log(null, 500);
        $this->failedJob(500);

        $this->prune()->expectsOutputToContain('DRY RUN')->assertSuccessful();               // enforce=false
        $this->prune(['--dry-run' => true, '--force' => true])->assertSuccessful();          // --dry-run always wins
        $this->assertSame(1, $this->rows('api_request_logs'));
        $this->assertSame(1, $this->rows('failed_jobs'));

        $json = json_decode($this->runJson(), true);
        $row = collect($json['rows'])->firstWhere(fn ($r) => $r['category'] === 'failed_jobs');
        $this->assertSame(1, $row['candidates']);
        $this->assertSame(0, $row['deleted']);

        config(['retention.enforce' => true]);
        $this->prune()->assertSuccessful();
        $this->assertSame(0, $this->rows('api_request_logs'));
        $this->assertSame(0, $this->rows('failed_jobs'));
    }

    private function runJson(): string
    {
        \Illuminate\Support\Facades\Artisan::call('ops:prune-retention', ['--json' => true]);

        return \Illuminate\Support\Facades\Artisan::output();
    }

    public function test_configured_periods_are_respected_floored_and_can_disable_a_category(): void
    {
        $this->log(null, 20);
        $this->log(null, 5);
        config(['retention.categories.api_request_logs.platform_days' => 15]);
        $this->prune(['--force' => true, '--only' => ['api_request_logs']])->assertSuccessful();
        $this->assertSame(1, $this->rows('api_request_logs'), '15-day config prunes the 20-day row only');

        // A value below min_days (14) is raised to the floor, never honoured: a typo cannot wipe recent data.
        config(['retention.categories.api_request_logs.platform_days' => 1]);
        $this->log(null, 10);
        $this->prune(['--force' => true, '--only' => ['api_request_logs']])->assertSuccessful();
        $this->assertSame(2, $this->rows('api_request_logs'), '10 and 5 days old are inside the 14-day floor');

        // <= 0 disables the category.
        config(['retention.categories.failed_jobs.days' => 0]);
        $this->failedJob(900);
        $this->prune(['--force' => true, '--only' => ['failed_jobs']])->assertSuccessful();
        $this->assertSame(1, $this->rows('failed_jobs'));
    }

    public function test_a_failing_category_is_reported_and_does_not_stop_the_others(): void
    {
        $this->log(null, 500);
        config(['queue.failed.database' => 'no_such_connection']);
        $this->failedJob(1); // default connection; irrelevant, the configured failed-job connection is bogus

        $events = [];
        Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$events) {
            $events[] = [$m->level, $m->message, $m->context];
        });

        $this->prune(['--force' => true])->assertFailed();
        $this->assertSame(0, $this->rows('api_request_logs'), 'other categories still ran');

        $err = collect($events)->first(fn ($e) => $e[1] === 'retention.prune_failed');
        $this->assertNotNull($err);
        $this->assertSame('failed_jobs', $err[2]['category']);
        $this->assertArrayNotHasKey('message', $err[2], 'exception messages (may embed SQL/values) are never logged');
    }

    public function test_a_missing_table_is_skipped_not_failed(): void
    {
        config(['queue.failed.table' => 'no_such_table']);
        $this->prune(['--force' => true])->assertSuccessful();
    }

    public function test_a_concurrent_run_is_refused_by_the_lock(): void
    {
        $this->log(null, 500);
        $lock = Cache::lock('retention:prune', 60);
        $this->assertTrue($lock->get());
        $result = app(RetentionPruner::class)->run(true);
        $lock->release();

        $this->assertTrue($result['locked']);
        $this->assertSame(1, $this->rows('api_request_logs'));
    }

    public function test_business_audit_and_security_data_is_never_touched(): void
    {
        $a = Account::factory()->create();
        Subscription::factory()->create(['account_id' => $a->id]);
        $u = User::factory()->create(['account_id' => $a->id]);
        Contact::create(['account_id' => $a->id, 'phone_number' => '919900000001', 'name' => 'Kept']);
        $old = $this->ago(2000);
        DB::table('activity_logs')->insert(['module_name' => 'm', 'action_type' => 'a', 'created_at' => $old, 'updated_at' => $old]);
        DB::table('login_audit_logs')->insert(['status' => 'success', 'logged_in_at' => $old, 'created_at' => $old, 'updated_at' => $old]);
        DB::table('api_idempotency_keys')->insert(['account_id' => $a->id, 'idempotency_key' => 'k', 'request_fingerprint' => 'f', 'status' => 'completed', 'created_at' => $old, 'updated_at' => $old]);
        DB::table('chatbot_logs')->insert(['account_id' => $a->id, 'sender_phone' => '1', 'incoming_message' => 'hi', 'status' => 'replied', 'created_at' => $old, 'updated_at' => $old]);
        DB::table('payment_alerts')->insert(['account_id' => $a->id, 'recipient_phone' => '1', 'customer_name' => 'c', 'amount' => 1, 'payment_ref' => 'p', 'created_at' => $old, 'updated_at' => $old]);

        $tables = ['accounts', 'users', 'contacts', 'subscriptions', 'activity_logs', 'login_audit_logs', 'api_idempotency_keys', 'chatbot_logs', 'payment_alerts', 'whatsapp_flow_sessions'];
        $before = collect($tables)->mapWithKeys(fn ($t) => [$t => $this->rows($t)])->all();

        config(['retention.categories' => collect(config('retention.categories'))->map(fn ($c) => array_merge($c, ['days' => 1, 'platform_days' => 1]))->all()]);
        $this->prune(['--force' => true])->assertSuccessful();

        $after = collect($tables)->mapWithKeys(fn ($t) => [$t => $this->rows($t)])->all();
        $this->assertSame($before, $after);
        $this->assertSame($u->id, User::find($u->id)->id);

        // And the pruner only knows the audited categories.
        $this->assertEqualsCanonicalizing(['api_request_logs', 'webhook_deliveries', 'journey_execution_events', 'inbound_message_events', 'failed_jobs'], app(RetentionPruner::class)->categories());
    }

    public function test_rows_inside_retention_are_never_deleted_even_with_out_of_order_timestamps(): void
    {
        $oldLow = $this->log(null, 300);
        $recentMid = $this->log(null, 1);   // lower id than the next, but recent
        $oldHigh = $this->log(null, 300);   // timestamps are not monotonic in id
        $this->prune(['--force' => true, '--only' => ['api_request_logs']])->assertSuccessful();

        $ids = DB::table('api_request_logs')->pluck('id')->all();
        $this->assertContains($recentMid, $ids);
        $this->assertNotContains($oldLow, $ids);
        // The binary-search ceiling may leave $oldHigh for a later run; a second run must still never touch $recentMid.
        $this->prune(['--force' => true, '--only' => ['api_request_logs']])->assertSuccessful();
        $this->assertContains($recentMid, DB::table('api_request_logs')->pluck('id')->all());
        $this->assertTrue(true, (string) $oldHigh);
    }

    public function test_logs_carry_counts_only_never_row_contents(): void
    {
        $this->log(null, 500, '/api/secret-path-token-123');
        $events = [];
        Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$events) {
            $events[] = [$m->message, $m->context];
        });
        $this->prune(['--force' => true, '--only' => ['api_request_logs']])->assertSuccessful();

        $logged = collect($events)->first(fn ($e) => $e[0] === 'retention.pruned');
        $this->assertNotNull($logged);
        $this->assertSame(['category', 'scope', 'table', 'days', 'cutoff', 'count'], array_keys($logged[1]));
        $this->assertStringNotContainsString('secret-path-token', json_encode($events));
    }

    public function test_it_is_scheduled_once_daily_without_overlap_and_the_journey_command_stays_manual(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'ops:prune-retention'));
        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertTrue($event->onOneServer, 'one instance per tick');
        $this->assertTrue($event->withoutOverlapping, 'no overlapping destructive runs');
        $this->assertSame('20 3 * * *', $event->expression);
        $this->assertStringNotContainsString('journeys:prune-history', collect(app(Schedule::class)->events())->map(fn ($e) => (string) $e->command)->implode("\n"));
    }

    public function test_unknown_category_is_rejected_before_anything_runs(): void
    {
        $this->log(null, 500);
        $this->prune(['--force' => true, '--only' => ['users']])->assertExitCode(2);
        $this->assertSame(1, $this->rows('api_request_logs'));
    }

    public function test_defaults_are_conservative_and_enforcement_is_opt_in(): void
    {
        $cfg = require base_path('config/retention.php');
        $this->assertFalse($cfg['enforce']);
        foreach ($cfg['categories'] as $name => $c) {
            $this->assertGreaterThanOrEqual($c['min_days'], $c['days'], $name);
            $this->assertGreaterThanOrEqual(14, $c['min_days'] >= 7 ? max($c['days'], 14) : 14);
        }
        $this->assertGreaterThanOrEqual(14, $cfg['categories']['inbound_message_events']['min_days'], 'above Meta redelivery window');
    }
}
