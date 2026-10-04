<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\MessageDispatchLog;
use App\Models\MessageTemplate;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Message Logs "View" action backend: GET /api/message-logs/{id}. */
class MessageLogDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    private function tenant(array $attrs = []): Account
    {
        $a = Account::factory()->create($attrs);
        Subscription::factory()->create(['account_id' => $a->id]);

        return $a;
    }

    private function userOf(Account $a, array|string $roles = 'admin'): User
    {
        $u = User::factory()->create(['account_id' => $a->id, 'is_active' => true]);
        $u->assignRole($roles);

        return $u;
    }

    private function fetch(User $u, string $uri)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u)->getJson($uri);
    }

    private function template(Account $a, string $title): MessageTemplate
    {
        return MessageTemplate::create([
            'account_id' => $a->id,
            'template_code' => MessageTemplate::generateTemplateCode($title),
            'title' => $title,
            'template_body' => 'Hello {{name}}.',
            'status' => 'approved',
        ]);
    }

    private function log(Account $a): MessageDispatchLog
    {
        return MessageDispatchLog::record(
            $a->id, 'api', '919967671647', true,
            templateName: 'Low Quantity Product',
            messagePreview: "⚠️ *Low Stock Alert*\nHello Arvind, the following products are running low.\nProduct A — 2 remaining",
            gatewayMessageId: 'PROV-1',
        );
    }

    public function test_it_returns_the_complete_resolved_message_not_the_preview(): void
    {
        $a = $this->tenant();
        $long = "Hello Arvind,\n".str_repeat('Product line — 2 remaining. ', 40)."\nTHE-END-MARKER";
        $log = MessageDispatchLog::record($a->id, 'api', '919967671647', true, templateName: 'Low Quantity Product', messagePreview: $long, gatewayMessageId: 'PROV-9');

        $res = $this->fetch($this->userOf($a), '/api/message-logs/'.$log->id)->assertOk();

        $this->assertSame(trim($long), $res->json('data.message_body'));
        $this->assertStringContainsString('THE-END-MARKER', $res->json('data.message_body'));
        $this->assertTrue($res->json('data.message_body_is_complete'));
        $this->assertSame('Low Quantity Product', $res->json('data.template_name'));
        $this->assertSame('PROV-9', $res->json('data.gateway_message_id'));
        $this->assertSame('api', $res->json('data.source'));
        $this->assertSame('sent', $res->json('data.status'));
    }

    public function test_the_list_payload_does_not_gain_the_full_body(): void
    {
        $a = $this->tenant();
        $this->log($a);

        $row = $this->fetch($this->userOf($a), '/api/message-logs')->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('message_body', $row);
        $this->assertArrayHasKey('message_preview', $row);
    }

    public function test_the_template_code_is_reported_for_a_template_send(): void
    {
        $a = $this->tenant();
        $tpl = $this->template($a, 'Low Quantity Product');
        $log = MessageDispatchLog::record($a->id, 'api', '919967671647', true, referenceType: 'template', referenceId: $tpl->id, templateName: $tpl->title, messagePreview: 'Hi');

        $res = $this->fetch($this->userOf($a), '/api/message-logs/'.$log->id)->assertOk();

        $this->assertNotNull($tpl->template_code);
        $this->assertSame($tpl->template_code, $res->json('data.template_code'));
    }

    public function test_a_template_from_another_tenant_is_never_reported_as_the_code(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        $foreign = $this->template($b, 'Other');
        $log = MessageDispatchLog::record($a->id, 'api', '9199', true, referenceType: 'template', referenceId: $foreign->id, messagePreview: 'Hi');

        $this->assertNull($this->fetch($this->userOf($a), '/api/message-logs/'.$log->id)->assertOk()->json('data.template_code'));
    }

    public function test_media_is_described_without_leaking_a_storage_path(): void
    {
        $a = $this->tenant();
        $u = $this->userOf($a);
        $ok = MessageDispatchLog::record($a->id, 'api', '9199', true, messagePreview: 'Doc', hasMedia: true, mediaUrl: 'https://cdn.example.com/files/stock%20report.pdf?sig=abc');
        $internal = MessageDispatchLog::record($a->id, 'api', '9198', true, messagePreview: 'Img', hasMedia: true, mediaUrl: '/var/www/storage/app/private/secret/photo.png');
        $none = MessageDispatchLog::record($a->id, 'api', '9197', true, messagePreview: 'Text');

        $m = $this->fetch($u, '/api/message-logs/'.$ok->id)->json('data.media');
        $this->assertSame(['has_media' => true, 'name' => 'stock report.pdf', 'type' => 'document', 'url' => 'https://cdn.example.com/files/stock%20report.pdf?sig=abc'], $m);

        $m2 = $this->fetch($u, '/api/message-logs/'.$internal->id)->json('data.media');
        $this->assertSame('image', $m2['type']);
        $this->assertNull($m2['url'], 'a filesystem path must never reach the browser');
        $this->assertStringNotContainsString('/var/www', json_encode($this->fetch($u, '/api/message-logs/'.$internal->id)->json('data.media')) ?: '');

        $this->assertFalse($this->fetch($u, '/api/message-logs/'.$none->id)->json('data.media.has_media'));
    }

    public function test_a_failed_message_shows_its_failure_reason(): void
    {
        $a = $this->tenant();
        $log = MessageDispatchLog::record($a->id, 'api', '9199', false, errorReason: 'WhatsApp account is disconnected.', messagePreview: 'Hello');

        $res = $this->fetch($this->userOf($a), '/api/message-logs/'.$log->id)->assertOk();

        $this->assertSame('failed', $res->json('data.status'));
        $this->assertSame('WhatsApp account is disconnected.', $res->json('data.error_reason'));
    }

    public function test_a_legacy_row_without_a_body_falls_back_to_its_preview_and_says_so(): void
    {
        $a = $this->tenant();
        $log = MessageDispatchLog::record($a->id, 'api', '9199', true, messagePreview: str_repeat('x', 200));
        MessageDispatchLog::whereKey($log->id)->update(['message_body' => null]);

        $res = $this->fetch($this->userOf($a), '/api/message-logs/'.$log->id)->assertOk();

        $this->assertSame(160, mb_strlen($res->json('data.message_body')));
        $this->assertFalse($res->json('data.message_body_is_complete'));
    }

    public function test_a_tenant_cannot_open_another_tenants_log_by_changing_the_id(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        $mine = $this->log($a);
        $theirs = $this->log($b);
        $u = $this->userOf($a);

        $this->fetch($u, '/api/message-logs/'.$mine->id)->assertOk();
        $this->fetch($u, '/api/message-logs/'.$theirs->id)->assertNotFound();
        // Walking the id space never yields a foreign row, and a missing id looks identical.
        $missing = $this->fetch($u, '/api/message-logs/999999')->assertNotFound();
        $this->assertSame($missing->json('exception'), $this->fetch($u, '/api/message-logs/'.$theirs->id)->json('exception'));
        // An account_id query param is ignored for a plain tenant user.
        $this->fetch($u, '/api/message-logs/'.$theirs->id.'?account_id='.$b->id)->assertNotFound();
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $a = $this->tenant();
        $this->getJson('/api/message-logs/'.$this->log($a)->id)->assertUnauthorized();
    }

    public function test_an_agent_reaches_only_its_own_subclients_logs(): void
    {
        $agentAcc = Account::factory()->agent()->create();
        Subscription::factory()->create(['account_id' => $agentAcc->id]);
        $child = Account::factory()->client($agentAcc)->create();
        Subscription::factory()->create(['account_id' => $child->id]);
        $otherAgent = Account::factory()->agent()->create();
        $strangerChild = Account::factory()->client($otherAgent)->create();
        $agent = $this->userOf($agentAcc, ['admin', 'agent']);

        $childLog = $this->log($child);
        $strangerLog = $this->log($strangerChild);

        $this->fetch($agent, "/api/message-logs/{$childLog->id}?account_id={$child->id}")->assertOk()->assertJsonPath('data.id', $childLog->id);
        // Another agent's client: the target-account check refuses, and the id alone cannot bypass the scope.
        $this->fetch($agent, "/api/message-logs/{$strangerLog->id}?account_id={$strangerChild->id}")->assertNotFound();
        $this->fetch($agent, "/api/message-logs/{$strangerLog->id}?account_id={$child->id}")->assertNotFound();
        $this->fetch($agent, "/api/message-logs/{$strangerLog->id}")->assertNotFound();
    }

    public function test_super_admin_global_view_and_client_view_are_scoped_correctly(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        $la = $this->log($a);
        $lb = $this->log($b);
        $root = Account::factory()->create();
        $sa = $this->userOf($root, 'super_admin');

        // Global view: any tenant's row, labelled with its client.
        $g = $this->fetch($sa, '/api/message-logs/'.$lb->id)->assertOk();
        $this->assertSame('global', $g->json('scope'));
        $this->assertSame($b->company_name, $g->json('data.account.company_name'));

        // With a client selected the same id outside that client is a 404.
        $this->fetch($sa, "/api/message-logs/{$la->id}?account_id={$a->id}")->assertOk()->assertJsonPath('scope', 'account');
        $this->fetch($sa, "/api/message-logs/{$lb->id}?account_id={$a->id}")->assertNotFound();
    }

    public function test_the_existing_list_filters_and_pagination_are_unchanged(): void
    {
        $a = $this->tenant();
        $u = $this->userOf($a);
        for ($i = 0; $i < 3; $i++) {
            $this->log($a);
        }
        MessageDispatchLog::record($a->id, 'chatbot', '9100', false, errorReason: 'x', messagePreview: 'c');

        $all = $this->fetch($u, '/api/message-logs?per_page=2')->assertOk();
        $this->assertSame(4, $all->json('total'));
        $this->assertCount(2, $all->json('data'));
        $this->assertSame('account', $all->json('scope'));
        $this->assertSame(1, $this->fetch($u, '/api/message-logs?status=failed')->json('total'));
        $this->assertSame(3, $this->fetch($u, '/api/message-logs?source=api')->json('total'));
        $this->assertSame(3, $this->fetch($u, '/api/message-logs?search=Low%20Quantity')->json('total'));
    }

    public function test_record_still_truncates_the_preview_and_keeps_the_body(): void
    {
        $a = $this->tenant();
        $log = MessageDispatchLog::record($a->id, 'api', '9199', true, messagePreview: str_repeat('y', 500));

        $fresh = MessageDispatchLog::find($log->id);
        $this->assertSame(160, mb_strlen($fresh->message_preview));
        $this->assertSame(500, mb_strlen((string) $fresh->getRawOriginal('message_body')));
    }
}
