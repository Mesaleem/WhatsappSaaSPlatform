<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppTemplateJob;
use App\Models\Account;
use App\Models\ContactGroup;
use App\Models\MessageDispatchLog;
use App\Models\MessageTemplate;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Services\Templates\TemplateMessageDispatcher;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Message Log "View Message": GET /api/message-logs/{id}/template.
 *
 * SAFETY: phpunit.xml in this repo points at the real MySQL database
 * (the sqlite lines are commented out). These tests therefore:
 *   - SKIP unless the configured database name contains "test"
 *     (e.g. run with DB_DATABASE=wa_saas_test on a migrated scratch DB);
 *   - use DatabaseTransactions, never RefreshDatabase, so nothing is ever
 *     dropped or migrated by the test run itself;
 *   - fake every outbound HTTP call, so no WhatsApp message is sent.
 *
 * Run: DB_DATABASE=wa_saas_test php artisan test --filter=MessageLogTemplateDetailTest
 */
class MessageLogTemplateDetailTest extends TestCase
{
    use DatabaseTransactions;

    private const LONG_TAIL = ' Please keep this message for your records. Our team will contact you if anything changes with your delivery window, and you can reply here any time for help.';

    protected function setUp(): void
    {
        parent::setUp();

        $database = (string) config('database.connections.'.config('database.default').'.database');
        if (! str_contains(strtolower($database), 'test')) {
            $this->markTestSkipped("Refusing to run DB tests against '{$database}': point DB_DATABASE at a scratch database whose name contains 'test'.");
        }

        $this->seed(RolePermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Http::preventStrayRequests();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function account(string $type = 'client', ?int $agentId = null, ?array $modules = null): Account
    {
        $account = Account::create([
            'company_name' => 'Acme '.Str::random(6),
            'account_type' => $type,
            'agent_id' => $agentId,
            'status' => 'active',
            'allowed_modules' => $modules,
        ]);

        Subscription::create([
            'account_id' => $account->id,
            'engine_type' => 'qr',
            'billing_model' => 'unlimited',
            'used_messages' => 0,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addYear(),
            'status' => 'active',
        ]);

        WhatsAppSession::create(['account_id' => $account->id, 'status' => 'connected']);

        return $account->fresh();
    }

    private function user(string $role, ?Account $account): User
    {
        $user = User::create([
            'account_id' => $account?->id,
            'name' => ucfirst($role).' '.Str::random(4),
            'email' => Str::random(10).'@example.test',
            'password' => 'secret-password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function template(Account $account, string $body, array $extra = []): MessageTemplate
    {
        return MessageTemplate::create([
            'account_id' => $account->id,
            'title' => 'order_update',
            'template_body' => $body,
            'status' => 'approved',
            ...$extra,
        ]);
    }

    private function fakeQrEngine(bool $success = true, string $messageId = 'QR-MSG-1'): void
    {
        Http::fake([
            '*/api/message/send' => $success
                ? Http::response(['success' => true, 'message_id' => $messageId])
                : Http::response(['success' => false, 'error' => 'Engine rejected'], 500),
        ]);
    }

    /** Sends a real template message through TemplateMessageDispatcher (HTTP faked) and returns its log row. */
    private function sendTemplate(Account $account, MessageTemplate $template, array $variables): MessageDispatchLog
    {
        TemplateMessageDispatcher::dispatch($account->id, $template->id, '919876543210', $variables);

        return MessageDispatchLog::where('account_id', $account->id)->latest('id')->firstOrFail();
    }

    private function plainLog(Account $account, array $extra = []): MessageDispatchLog
    {
        return MessageDispatchLog::create([
            'account_id' => $account->id,
            'source' => 'web_ui',
            'recipient_phone' => '919800000000',
            'status' => 'sent',
            'message_preview' => 'Payment received, thank you.',
            ...$extra,
        ]);
    }

    private function detail(MessageDispatchLog|int $log, ?User $as, array $query = [])
    {
        if ($as) {
            Sanctum::actingAs($as);
        }
        $id = $log instanceof MessageDispatchLog ? $log->id : $log;
        $qs = $query ? '?'.http_build_query($query) : '';

        return $this->getJson("/api/message-logs/{$id}/template{$qs}");
    }

    // ---------------------------------------------------------------
    // Content & historical accuracy
    // ---------------------------------------------------------------

    public function test_detail_returns_exact_rendered_message_parameters_and_template_used(): void
    {
        $this->fakeQrEngine(messageId: 'QR-ABC');
        $account = $this->account();
        $body = 'Hello {{name}}, your order {{order}} is ready.'.self::LONG_TAIL;
        $template = $this->template($account, $body);

        $log = $this->sendTemplate($account, $template, ['name' => 'John', 'order' => '#12345']);
        $expected = 'Hello John, your order #12345 is ready.'.self::LONG_TAIL;

        $res = $this->detail($log, $this->user('admin', $account))->assertOk();

        $res->assertJsonPath('snapshot_available', true)
            ->assertJsonPath('snapshot_scope', 'individual')
            ->assertJsonPath('status', 'sent')
            ->assertJsonPath('recipient', '919876543210')
            ->assertJsonPath('provider', 'qr')
            ->assertJsonPath('provider_message_id', 'QR-ABC')
            ->assertJsonPath('rendered_content', $expected)
            ->assertJsonPath('template.id', $template->id)
            ->assertJsonPath('template.name', 'order_update')
            ->assertJsonPath('template.body', $body)
            ->assertJsonPath('parameters.0.key', 'name')
            ->assertJsonPath('parameters.0.value', 'John')
            ->assertJsonPath('parameters.1.key', 'order')
            ->assertJsonPath('parameters.1.value', '#12345')
            ->assertJsonPath('message_preview_possibly_truncated', true)
            ->assertJsonPath('account', null);

        // Full content is longer than the 160-char list preview.
        $this->assertGreaterThan(160, mb_strlen($res->json('rendered_content')));
        $this->assertSame(160, mb_strlen($res->json('message_preview')));
        $this->assertContains('buttons', $res->json('not_applicable'));

        // What the log shows is byte-for-byte what went to the engine.
        Http::assertSent(fn (HttpRequest $r) => $r['message'] === $expected);
    }

    public function test_detail_keeps_historical_content_after_template_is_edited(): void
    {
        $this->fakeQrEngine();
        $account = $this->account();
        $original = 'Hi {{name}}, v1 wording.';
        $template = $this->template($account, $original);

        $log = $this->sendTemplate($account, $template, ['name' => 'Asha']);

        $template->update(['template_body' => 'COMPLETELY NEW WORDING {{name}}', 'title' => 'renamed_later']);

        $this->detail($log, $this->user('admin', $account))
            ->assertOk()
            ->assertJsonPath('template.body', $original)
            ->assertJsonPath('template.name', 'order_update')
            ->assertJsonPath('rendered_content', 'Hi Asha, v1 wording.');
    }

    public function test_media_header_details_come_from_the_send(): void
    {
        $this->fakeQrEngine();
        $account = $this->account();
        $template = $this->template($account, 'Your bill, {{name}}.', [
            'header_type' => 'document',
            'header_media_url' => 'https://files.example.test/bills/bill-001.pdf',
        ]);

        $log = $this->sendTemplate($account, $template, ['name' => 'Ravi']);

        $this->detail($log, $this->user('admin', $account))
            ->assertOk()
            ->assertJsonPath('template.header_type', 'document')
            ->assertJsonPath('media.has_media', true)
            ->assertJsonPath('media.type', 'document')
            ->assertJsonPath('media.url', 'https://files.example.test/bills/bill-001.pdf')
            ->assertJsonPath('media.filename', 'bill-001.pdf');

        Http::assertSent(fn (HttpRequest $r) => ($r['media_url'] ?? null) === 'https://files.example.test/bills/bill-001.pdf');
    }

    public function test_failed_send_still_shows_what_was_attempted(): void
    {
        $this->fakeQrEngine(success: false);
        $account = $this->account();
        $template = $this->template($account, 'Hi {{name}}.');

        $log = $this->sendTemplate($account, $template, ['name' => 'Meena']);

        $this->detail($log, $this->user('admin', $account))
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('error_reason', 'Engine rejected')
            ->assertJsonPath('rendered_content', 'Hi Meena.')
            ->assertJsonPath('provider_message_id', null);
    }

    public function test_legacy_row_without_snapshot_never_substitutes_current_template(): void
    {
        $account = $this->account();
        $template = $this->template($account, 'CURRENT body that was NOT what was sent {{name}}');
        $log = $this->plainLog($account, [
            'source' => 'web_template',
            'reference_type' => 'template',
            'reference_id' => $template->id,
            'template_name' => 'order_update',
            'message_preview' => 'Hi Old, legacy preview',
        ]);

        $this->detail($log, $this->user('admin', $account))
            ->assertOk()
            ->assertJsonPath('snapshot_available', false)
            ->assertJsonPath('template.id', $template->id)
            ->assertJsonPath('template.name', 'order_update')
            ->assertJsonPath('template.body', null)
            ->assertJsonPath('rendered_content', null)
            ->assertJsonPath('parameters', [])
            ->assertJsonPath('message_preview', 'Hi Old, legacy preview');
    }

    public function test_group_row_reports_preview_not_a_single_rendered_message(): void
    {
        $account = $this->account();
        $template = $this->template($account, 'Hello {{name}}, sale on {{day}}.');
        $group = ContactGroup::create(['account_id' => $account->id, 'name' => 'VIP Customers']);
        $log = MessageDispatchLog::recordGroupDispatchQueued(
            $account->id, $group->id, 'VIP Customers', 25, $template->title, 'Hello {{name}}, sale on Friday.', 'web_template', null,
            templateSnapshot: TemplateMessageDispatcher::buildSnapshot($template, $account, ['day' => 'Friday'], null, scope: 'group', groupPreview: 'Hello {{name}}, sale on Friday.'),
        );

        $this->detail($log, $this->user('admin', $account))
            ->assertOk()
            ->assertJsonPath('snapshot_scope', 'group')
            ->assertJsonPath('recipient_type', 'group')
            ->assertJsonPath('group_name', 'VIP Customers')
            ->assertJsonPath('recipient_count', 25)
            ->assertJsonPath('rendered_content', null)
            ->assertJsonPath('group_preview', 'Hello {{name}}, sale on Friday.');
    }

    public function test_bulk_job_row_is_viewable_and_list_stays_valid(): void
    {
        $this->fakeQrEngine();
        $account = $this->account();
        $template = $this->template($account, 'Bulk hi {{name}}.');
        $admin = $this->user('admin', $account);

        (new SendWhatsAppTemplateJob($account->id, $template->id, '919811111111', ['name' => 'Kiran']))->handle();

        $log = MessageDispatchLog::where('account_id', $account->id)->latest('id')->firstOrFail();
        $this->assertSame('web_template_bulk', $log->source);

        Sanctum::actingAs($admin);
        $this->getJson('/api/message-logs')->assertOk()->assertJsonPath('data.0.source', 'web_template_bulk');
        $this->getJson('/api/message-logs?source=web_template_bulk')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/analytics/summary')->assertOk();

        $this->detail($log, $admin)->assertOk()->assertJsonPath('rendered_content', 'Bulk hi Kiran.');
    }

    // ---------------------------------------------------------------
    // Non-template / missing / auth
    // ---------------------------------------------------------------

    public function test_non_template_row_is_rejected_without_changing_list_behaviour(): void
    {
        $account = $this->account();
        $admin = $this->user('admin', $account);
        $log = $this->plainLog($account);

        $this->detail($log, $admin)->assertStatus(422)->assertJsonPath('error_code', 'NOT_A_TEMPLATE_MESSAGE');
        $this->getJson('/api/message-logs')->assertOk()->assertJsonPath('data.0.id', $log->id);
    }

    public function test_nonexistent_and_non_numeric_ids_are_404(): void
    {
        $admin = $this->user('admin', $this->account());
        $this->detail(999999999, $admin)->assertNotFound();
        $this->getJson('/api/message-logs/abc/template')->assertNotFound();
    }

    public function test_unauthenticated_is_401(): void
    {
        $log = $this->plainLog($this->account(), ['template_name' => 'x']);
        $this->detail($log, null)->assertUnauthorized();
    }

    // ---------------------------------------------------------------
    // Tenant isolation & roles
    // ---------------------------------------------------------------

    public function test_client_admin_cannot_read_another_tenants_message_even_with_account_id(): void
    {
        $this->fakeQrEngine();
        $a = $this->account();
        $b = $this->account();
        $logB = $this->sendTemplate($b, $this->template($b, 'Secret for B {{name}}'), ['name' => 'Zed']);
        $adminA = $this->user('admin', $a);

        $this->detail($logB, $adminA)->assertNotFound();
        // A plain tenant user's ?account_id= is ignored by TenantIsolationMiddleware.
        $this->detail($logB, $adminA, ['account_id' => $b->id])->assertNotFound();
    }

    public function test_super_admin_global_and_selected_client_context(): void
    {
        $this->fakeQrEngine();
        $a = $this->account();
        $b = $this->account();
        $logA = $this->sendTemplate($a, $this->template($a, 'A {{name}}'), ['name' => '1']);
        $logB = $this->sendTemplate($b, $this->template($b, 'B {{name}}'), ['name' => '2']);
        $super = $this->user('super_admin', null);

        $this->detail($logB, $super)->assertOk()->assertJsonPath('account.id', $b->id);
        $this->detail($logA, $super, ['account_id' => $a->id])->assertOk()->assertJsonPath('account', null);
        $this->detail($logB, $super, ['account_id' => $a->id])->assertNotFound();
    }

    public function test_agent_follows_existing_view_logs_permission_and_sub_client_scope(): void
    {
        $this->fakeQrEngine();
        $agentAcct = $this->account('agent');
        $sub = $this->account('client', $agentAcct->id);
        $otherAgent = $this->account('agent');
        $foreign = $this->account('client', $otherAgent->id);
        $logSub = $this->sendTemplate($sub, $this->template($sub, 'Sub {{name}}'), ['name' => 'S']);
        $logForeign = $this->sendTemplate($foreign, $this->template($foreign, 'F {{name}}'), ['name' => 'F']);

        // Seeded 'agent' role has no view-logs: same answer as the list endpoint.
        $agent = $this->user('agent', $agentAcct);
        Sanctum::actingAs($agent);
        $this->getJson('/api/message-logs')->assertForbidden();
        $this->detail($logSub, $agent, ['account_id' => $sub->id])->assertForbidden();

        // An Agent user who has been granted view-logs: own Sub-Client only.
        $agent->givePermissionTo('view-logs');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $agent = $agent->fresh();

        $this->detail($logSub, $agent, ['account_id' => $sub->id])->assertOk();
        $this->detail($logSub, $agent)->assertNotFound(); // agent's own account scope, not the sub-client's
        $this->detail($logForeign, $agent, ['account_id' => $foreign->id])->assertNotFound();
        $this->detail($logForeign, $agent, ['account_id' => $sub->id])->assertNotFound();
    }

    public function test_roles_without_view_logs_are_forbidden_same_as_list(): void
    {
        $account = $this->account();
        $log = $this->plainLog($account, ['template_name' => 'x']);

        foreach (['user', 'social_marketer'] as $role) {
            $u = $this->user($role, $account);
            Sanctum::actingAs($u);
            $this->getJson('/api/message-logs')->assertForbidden();
            $this->detail($log, $u)->assertForbidden();
        }
    }

    public function test_module_gate_applies_like_the_list(): void
    {
        $account = $this->account(modules: ['dashboard', 'analytics']);
        $admin = $this->user('admin', $account);
        $log = $this->plainLog($account, ['template_name' => 'x']);

        Sanctum::actingAs($admin);
        $this->getJson('/api/message-logs')->assertForbidden();
        $this->detail($log, $admin)->assertForbidden();
    }

    public function test_snapshot_write_is_skipped_when_column_missing_logic_is_sane(): void
    {
        // The column exists in this scratch DB, so writers must store it.
        $this->assertTrue(MessageDispatchLog::snapshotColumnExists());
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('message_dispatch_logs', 'template_snapshot'));
    }
}
