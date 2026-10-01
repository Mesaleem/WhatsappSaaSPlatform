<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\ActivityLog;
use App\Models\Capability;
use App\Models\CommentAutomationEvent;
use App\Models\CommentAutomationRule;
use App\Models\CrmLead;
use App\Models\Lead;
use App\Models\SocialAccount;
use App\Models\SocialProviderConfig;
use App\Models\Subscription;
use App\Services\SocialAuth\WebhookAssetResolver;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 9 Task 2.2 — Meta webhooks are routed to a tenant only when exactly
 * one connection holds the asset. The same Page / Instagram account may be
 * connected by several tenants (social_accounts is unique per account), and
 * Meta's signed payload carries no tenant, so a shared asset is NOT
 * processed — never handed to "the first row" — and is audited for manual
 * resolution. The webhook still answers 200.
 */
class SocialWebhookTenantRoutingTest extends TestCase
{
    use RefreshDatabase;

    private const APP_SECRET = 'task-2-2-app-secret';

    private const PAGE_ID = '555000111';

    private const IG_ID = '178400000001';

    /** @var array{0: int, 1: array<string, mixed>} */
    private array $graph = [200, []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);

        SocialProviderConfig::create(['provider' => 'meta', 'client_id' => 'app-id', 'client_secret' => self::APP_SECRET, 'is_active' => true]);

        $this->graph = [200, ['field_data' => [
            ['name' => 'full_name', 'values' => ['Ada Lovelace']],
            ['name' => 'phone_number', 'values' => ['+91 98765 43210']],
        ], 'id' => 'reply-1']];

        Http::fake(fn (HttpRequest $r) => Http::response($this->graph[1], $this->graph[0]));
    }

    // ------------------------------------------------------------------ fixtures

    private function tenant(): Account
    {
        $account = Account::factory()->create();
        Subscription::factory()->create(['account_id' => $account->id]);
        AccountEntitlement::firstOrCreate(
            ['account_id' => $account->id, 'capability_id' => Capability::where('slug', 'crm')->value('id')],
            ['source' => 'manual_grant', 'granted_by_account_id' => null],
        );

        return $account->fresh();
    }

    private function connect(Account $account, string $assetType = 'facebook_page', string $providerId = self::PAGE_ID): SocialAccount
    {
        return SocialAccount::create([
            'account_id' => $account->id, 'provider' => 'meta', 'asset_type' => $assetType,
            'provider_id' => $providerId, 'name' => 'Shared asset', 'access_token' => 'EAA-token-'.$account->id,
            'health_status' => SocialAccount::HEALTH_CONNECTED,
        ]);
    }

    private function rule(Account $account): void
    {
        CommentAutomationRule::create([
            'account_id' => $account->id, 'keyword' => 'price',
            'public_reply_template' => 'See DM', 'private_dm_template' => 'Price list', 'is_active' => true,
        ]);
    }

    private function signedPost(array $payload)
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/social/webhook/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::APP_SECRET),
        ], $body);
    }

    private function leadgen(string $leadgenId = 'LG-1', string $pageId = self::PAGE_ID)
    {
        return $this->signedPost(['object' => 'page', 'entry' => [[
            'id' => $pageId,
            'changes' => [['field' => 'leadgen', 'value' => ['leadgen_id' => $leadgenId, 'page_id' => $pageId, 'form_id' => 'F1']]],
        ]]]);
    }

    private function pageComment(string $commentId = 'c-1', string $pageId = self::PAGE_ID)
    {
        return $this->signedPost(['object' => 'page', 'entry' => [[
            'id' => $pageId,
            'changes' => [['field' => 'feed', 'value' => ['item' => 'comment', 'verb' => 'add', 'comment_id' => $commentId, 'post_id' => 'p-1', 'message' => 'price?', 'from' => ['id' => 'u-1']]]],
        ]]]);
    }

    private function instagramComment(string $commentId = 'ig-c-1', string $igId = self::IG_ID)
    {
        return $this->signedPost(['object' => 'instagram', 'entry' => [[
            'id' => $igId,
            'changes' => [['field' => 'comments', 'value' => ['id' => $commentId, 'text' => 'price?', 'media' => ['id' => 'm-1'], 'from' => ['id' => 'u-1']]]],
        ]]]);
    }

    private function ambiguityAudits(): \Illuminate\Support\Collection
    {
        return ActivityLog::where('module_name', WebhookAssetResolver::MODULE)->get();
    }

    // ------------------------------------------------------------------ Lead Ads

    public function test_a_page_with_one_owner_routes_the_lead_to_that_tenant(): void
    {
        $owner = $this->tenant();
        $this->connect($owner);

        $this->leadgen()->assertOk();

        $lead = Lead::sole();
        $this->assertSame($owner->id, $lead->account_id);
        $this->assertSame($owner->id, CrmLead::sole()->account_id);
        $this->assertCount(0, $this->ambiguityAudits());
    }

    public function test_a_page_shared_by_two_tenants_is_not_assigned_to_either(): void
    {
        $first = $this->tenant();
        $second = $this->tenant();
        $firstPage = $this->connect($first);   // the row an arbitrary ->first() would have picked
        $secondPage = $this->connect($second);

        $this->leadgen()->assertOk(); // Meta still gets 200: no redelivery loop

        $this->assertSame(0, Lead::count(), 'not processed against the first row (or any row)');
        $this->assertSame(0, CrmLead::count());
        Http::assertNothingSent(); // not even the field-data fetch with one tenant's token

        $audit = $this->ambiguityAudits()->sole();
        $this->assertNull($audit->account_id, 'platform-level record, never shown to one of the tenants');
        $this->assertSame('denied', $audit->action_type);
        $this->assertSame('ambiguous_owner', $audit->new_values['category']);
        $this->assertSame('leadgen', $audit->new_values['event']);
        $this->assertSame(self::PAGE_ID, $audit->new_values['provider_id']);
        $this->assertSame(['leadgen_id' => 'LG-1'], $audit->new_values['reference']);
        $this->assertEqualsCanonicalizing([$firstPage->id, $secondPage->id], $audit->new_values['social_account_ids']);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $audit->new_values['account_ids']);
        $this->assertStringNotContainsString('EAA-token', json_encode($audit->new_values));
    }

    public function test_ambiguity_does_not_write_connection_health_on_either_tenant(): void
    {
        $a = $this->connect($this->tenant());
        $b = $this->connect($this->tenant());
        $this->graph = [400, ['error' => ['code' => 190]]];

        $this->leadgen()->assertOk();

        $this->assertSame(SocialAccount::HEALTH_CONNECTED, $a->fresh()->health_status);
        $this->assertSame(SocialAccount::HEALTH_CONNECTED, $b->fresh()->health_status);
    }

    public function test_health_write_back_still_applies_to_the_single_resolved_owner(): void
    {
        $owner = $this->tenant();
        $page = $this->connect($owner);
        $otherTenantsPage = $this->connect($this->tenant(), 'facebook_page', '555000999');
        $this->graph = [400, ['error' => ['code' => 190, 'error_subcode' => 463]]];

        $this->leadgen()->assertOk();

        $this->assertSame('expired', $page->fresh()->connectionStatus());
        $this->assertSame(SocialAccount::HEALTH_CONNECTED, $otherTenantsPage->fresh()->health_status);
    }

    public function test_the_same_id_on_a_different_asset_type_is_not_ambiguous(): void
    {
        $owner = $this->tenant();
        $this->connect($owner);
        $this->connect($this->tenant(), 'instagram', self::PAGE_ID); // same numeric id, different asset

        $this->leadgen()->assertOk();

        $this->assertSame($owner->id, Lead::sole()->account_id);
        $this->assertCount(0, $this->ambiguityAudits());
    }

    public function test_an_unknown_page_is_still_dropped_quietly(): void
    {
        $this->leadgen()->assertOk();

        $this->assertSame(0, Lead::count());
        $this->assertCount(0, $this->ambiguityAudits(), 'no owner is not the same as two owners');
    }

    public function test_once_the_duplicate_connection_is_removed_routing_resumes(): void
    {
        $owner = $this->tenant();
        $this->connect($owner);
        $duplicate = $this->connect($this->tenant());

        $this->leadgen('LG-1')->assertOk();
        $this->assertSame(0, Lead::count());

        $duplicate->delete(); // manual resolution
        $this->leadgen('LG-2')->assertOk();

        $this->assertSame($owner->id, Lead::sole()->account_id);
    }

    // ------------------------------------------------------------------ comments

    public function test_a_page_comment_with_one_owner_is_actioned_for_that_tenant(): void
    {
        $owner = $this->tenant();
        $this->connect($owner);
        $this->rule($owner);

        $this->pageComment()->assertOk();

        $event = CommentAutomationEvent::sole();
        $this->assertSame($owner->id, $event->account_id);
        $this->assertNotNull($event->public_replied_at);
    }

    public function test_a_comment_on_a_shared_page_is_not_actioned_for_anyone(): void
    {
        $first = $this->tenant();
        $second = $this->tenant();
        $this->connect($first);
        $this->connect($second);
        $this->rule($first);
        $this->rule($second);

        $this->pageComment()->assertOk();

        $this->assertSame(0, CommentAutomationEvent::count());
        Http::assertNothingSent(); // no reply posted with either tenant's token
        $this->assertSame('comment', $this->ambiguityAudits()->sole()->new_values['event']);
    }

    public function test_an_instagram_comment_follows_the_same_rule(): void
    {
        $owner = $this->tenant();
        $this->connect($owner, 'instagram', self::IG_ID);
        $this->rule($owner);

        $this->instagramComment('ig-c-1')->assertOk();
        $this->assertSame($owner->id, CommentAutomationEvent::sole()->account_id);

        $this->connect($this->tenant(), 'instagram', self::IG_ID); // now shared
        Http::fake(); // record from here
        $this->instagramComment('ig-c-2')->assertOk();

        $this->assertSame(1, CommentAutomationEvent::count(), 'the shared-account comment is not actioned');
        Http::assertNothingSent();
        $this->assertSame('instagram_comment', $this->ambiguityAudits()->sole()->new_values['event']);
    }

    // ------------------------------------------------------------------ guardrail

    public function test_no_webhook_path_resolves_a_social_account_by_provider_id_outside_the_resolver(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php' || $file->getFilename() === 'WebhookAssetResolver.php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            // A SocialAccount query keyed on the provider's asset id — which is
            // not unique across tenants — must go through WebhookAssetResolver.
            preg_match_all("/SocialAccount::(query\(\)|where)[^;]*provider_id['\"]\s*,[^;]*;/s", $source, $matches);

            foreach ($matches[0] as $statement) {
                if (! str_contains($statement, 'forAccount(')) {
                    $offenders[] = str_replace(app_path().'/', '', $file->getPathname());
                }
            }
        }

        $this->assertSame([], $offenders, 'resolve provider assets from webhooks with WebhookAssetResolver, never ->first()');

        $resolver = file_get_contents(app_path('Services/SocialAuth/WebhookAssetResolver.php'));
        $this->assertStringContainsString('->limit(2)', $resolver);
    }
}
