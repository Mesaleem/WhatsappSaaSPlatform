<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CommentAutomationEvent;
use App\Models\CommentAutomationRule;
use App\Models\Lead;
use App\Models\SocialAccount;
use App\Services\Comments\CommentAutomationService;
use App\Services\Leads\MetaLeadWebhookHandler;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 9 Task 2.1 — the two remaining Meta calls made with a stored
 * connection now take part in connection health: the Lead Ads form-data
 * fetch (MetaLeadWebhookHandler) and the comment auto-replies
 * (CommentAutomationService). The connection is always the one resolved
 * from the webhook entry's own Page / Instagram id — never a client value.
 * Both run inside Meta's webhook request, so a dead connection is
 * persisted and logged (the webhook still answers 200); nothing is
 * returned to a user.
 */
class SocialConnectionWriteBackTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{0: int, 1: array<string, mixed>}|'down' */
    private array|string $graph = [200, []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);

        Http::fake(function (HttpRequest $request) {
            if ($this->graph === 'down') {
                throw new ConnectionException('timeout');
            }

            return Http::response($this->graph[1], $this->graph[0]);
        });
    }

    private function page(array $attributes = []): SocialAccount
    {
        $account = Account::factory()->create();

        return SocialAccount::create($attributes + [
            'account_id' => $account->id,
            'provider' => 'meta',
            'asset_type' => 'facebook_page',
            'provider_id' => 'page-'.random_int(1000, 9999),
            'name' => 'Acme Page',
            'access_token' => 'EAA-page-token',
            'health_status' => SocialAccount::HEALTH_CONNECTED,
        ]);
    }

    private function graphError(int $code, int $subcode = 0, int $http = 400): array
    {
        return [$http, ['error' => ['message' => 'Raw Meta detail fbtrace_id=XYZ', 'code' => $code, 'error_subcode' => $subcode ?: null]]];
    }

    private function deliverLead(SocialAccount $page, string $leadgenId = 'LG-1'): void
    {
        app(MetaLeadWebhookHandler::class)->handle(['changes' => [[
            'field' => 'leadgen',
            'value' => ['leadgen_id' => $leadgenId, 'page_id' => $page->provider_id, 'form_id' => 'F1'],
        ]]]);
    }

    private function deliverComment(SocialAccount $page, string $commentId = 'c-1'): void
    {
        app(CommentAutomationService::class)->handle(['id' => $page->provider_id, 'changes' => [[
            'field' => 'feed',
            'value' => ['item' => 'comment', 'verb' => 'add', 'comment_id' => $commentId, 'post_id' => 'p-1', 'message' => 'price?', 'from' => ['id' => 'u-1']],
        ]]]);
    }

    private function rule(SocialAccount $page): void
    {
        CommentAutomationRule::create([
            'account_id' => $page->account_id, 'keyword' => 'price',
            'public_reply_template' => 'Check your DMs!', 'private_dm_template' => 'Here is the price.', 'is_active' => true,
        ]);
    }

    // ------------------------------------------------------------------ Lead Ads form-data fetch

    public function test_lead_fetch_with_an_expired_token_persists_expired(): void
    {
        $page = $this->page();
        $this->graph = $this->graphError(190, 463);

        $this->deliverLead($page);

        $fresh = $page->fresh();
        $this->assertSame('expired', $fresh->connectionStatus());
        $this->assertStringContainsString('expired', $fresh->status_reason);
        $this->assertStringNotContainsString('fbtrace', $fresh->status_reason);
        $this->assertNotNull($fresh->status_checked_at);
        $this->assertSame(0, Lead::count(), 'no lead can be captured without its form data (unchanged)');
    }

    public function test_lead_fetch_with_a_revoked_permission_persists_revoked(): void
    {
        $page = $this->page();
        $this->graph = $this->graphError(200);

        $this->deliverLead($page);

        $this->assertSame('revoked', $page->fresh()->connectionStatus());
        $this->assertNotNull($page->fresh()->status_reason);
    }

    public function test_lead_fetch_temporary_failures_do_not_mark_the_connection_dead(): void
    {
        foreach (['down', $this->graphError(4), $this->graphError(2, 0, 503), [500, []]] as $i => $answer) {
            $page = $this->page();
            $this->graph = $answer;

            $this->deliverLead($page, "LG-T{$i}");

            $this->assertSame(SocialAccount::HEALTH_CONNECTED, $page->fresh()->health_status);
            $this->assertNull($page->fresh()->status_checked_at);
        }
    }

    public function test_lead_fetch_is_not_attempted_for_a_connection_known_to_be_dead(): void
    {
        $page = $this->page(['health_status' => SocialAccount::HEALTH_REAUTH_REQUIRED]);
        $expired = $this->page(['token_expires_at' => now()->subDay()]);

        $this->deliverLead($page, 'LG-A');
        $this->deliverLead($expired, 'LG-B');

        Http::assertNothingSent();
        $this->assertSame(SocialAccount::HEALTH_TOKEN_EXPIRED, $expired->fresh()->health_status, 'known expiry persisted without calling Meta');
    }

    public function test_a_healthy_lead_fetch_is_unchanged(): void
    {
        $page = $this->page();
        $this->graph = [200, ['field_data' => [['name' => 'full_name', 'values' => ['Ada']], ['name' => 'phone_number', 'values' => ['+91 98765 43210']]]]];

        $this->deliverLead($page);

        $this->assertSame(1, Lead::where('account_id', $page->account_id)->where('provider_lead_id', 'LG-1')->count());
        $this->assertSame(SocialAccount::HEALTH_CONNECTED, $page->fresh()->health_status);
    }

    // ------------------------------------------------------------------ comment auto-replies

    public function test_a_comment_reply_rejected_for_an_expired_token_persists_it_and_stops(): void
    {
        $page = $this->page();
        $this->rule($page);
        $this->graph = $this->graphError(190, 463);

        $this->deliverComment($page);

        $this->assertSame('expired', $page->fresh()->connectionStatus());
        $event = CommentAutomationEvent::sole();
        $this->assertStringContainsString('Reconnect it in Social Accounts', $event->public_reply_error);
        $this->assertStringContainsString('Reconnect it in Social Accounts', $event->private_message_error);
        $this->assertStringNotContainsString('fbtrace', $event->public_reply_error);
        Http::assertSentCount(1); // the private reply is not attempted with the dead token
    }

    public function test_a_comment_reply_rejected_for_a_revoked_permission_persists_revoked(): void
    {
        $page = $this->page();
        $this->rule($page);
        $this->graph = $this->graphError(10);

        $this->deliverComment($page);

        $this->assertSame('revoked', $page->fresh()->connectionStatus());
    }

    public function test_other_comment_reply_failures_keep_the_previous_behaviour(): void
    {
        $page = $this->page();
        $this->rule($page);
        $this->graph = $this->graphError(100);

        $this->deliverComment($page);

        $event = CommentAutomationEvent::sole();
        $this->assertSame('Raw Meta detail fbtrace_id=XYZ', $event->public_reply_error, 'unchanged: Meta message kept for non-connection failures');
        $this->assertSame('Raw Meta detail fbtrace_id=XYZ', $event->private_message_error);
        $this->assertSame(SocialAccount::HEALTH_CONNECTED, $page->fresh()->health_status);
        Http::assertSentCount(2);
    }

    public function test_a_dead_connection_is_not_used_for_comment_replies(): void
    {
        $page = $this->page(['health_status' => SocialAccount::HEALTH_TOKEN_EXPIRED]);
        $this->rule($page);

        $this->deliverComment($page);

        Http::assertNothingSent();
        $this->assertSame(0, CommentAutomationEvent::count(), 'same as a missing token: not actioned');
    }

    public function test_successful_comment_replies_are_unchanged(): void
    {
        $page = $this->page();
        $this->rule($page);
        $this->graph = [200, ['id' => 'reply-1']];

        $this->deliverComment($page);

        $event = CommentAutomationEvent::sole();
        $this->assertNotNull($event->public_replied_at);
        $this->assertNotNull($event->private_message_sent_at);
        $this->assertSame(SocialAccount::HEALTH_CONNECTED, $page->fresh()->health_status);
    }

    public function test_the_connection_comes_from_the_webhook_entry_not_another_tenant(): void
    {
        $page = $this->page();
        $other = $this->page();
        $this->rule($page);
        $this->graph = $this->graphError(190);

        $this->deliverComment($page);

        $this->assertSame('revoked', $page->fresh()->connectionStatus());
        $this->assertSame(SocialAccount::HEALTH_CONNECTED, $other->fresh()->health_status);
        $this->assertSame($page->account_id, CommentAutomationEvent::sole()->account_id);
    }
}
