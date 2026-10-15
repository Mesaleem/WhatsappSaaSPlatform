<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\OrganicPost;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Ads\MetaAdsService;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Social\OrganicPublishService;
use App\Services\Social\Publishing\MetaPublisher;
use App\Services\Social\Publishing\PublishOutcome;
use App\Services\Social\Publishing\PublishRequest;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 2026-10-15 — Facebook Reels / Stories / multi-photo posts, Instagram Reels /
 * Stories / carousels, the DM reply after the 24-hour window, and the WhatsApp
 * Status ad placement. Graph responses are faked: the call sequence is the
 * documented contract ([Hypothesis] until verified on a live Page).
 */
class MetaPublishingFormatsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'page-token';

    /** @var list<array{method: string, url: string, data: array<string, mixed>, headers: array<string, mixed>}> */
    private array $calls = [];

    /** @var array<string, array<string, mixed>> url fragment => JSON answer (first match wins) */
    private array $answers = [];

    /** A test's own answer for every request (overrides $answers). */
    private ?\Closure $handler = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);

        Http::fake(function (HttpRequest $request) {
            $this->calls[] = ['method' => $request->method(), 'url' => $request->url(), 'data' => $request->data(), 'headers' => $request->headers()];
            if ($this->handler !== null) {
                return ($this->handler)($request);
            }
            foreach ($this->answers as $fragment => $answer) {
                if (str_contains($request->url(), $fragment)) {
                    return Http::response($answer[1], $answer[0]);
                }
            }

            return Http::response(['id' => 'ID'.count($this->calls)], 200);
        });
    }

    private function answer(string $urlFragment, array $json, int $status = 200): void
    {
        $this->answers[$urlFragment] = [$status, $json];
    }

    private function asset(string $type): SocialAccount
    {
        $account = Account::factory()->create(['allow_facebook' => true, 'allow_instagram' => true]);

        return SocialAccount::create([
            'account_id' => $account->id, 'provider' => 'meta', 'asset_type' => $type,
            'provider_id' => $type === 'instagram' ? 'IG1' : 'PG1', 'name' => 'Acme', 'access_token' => self::TOKEN,
            'health_status' => SocialAccount::HEALTH_CONNECTED,
        ]);
    }

    private function paths(): array
    {
        return array_map(fn ($c) => $c['method'].' '.preg_replace('#^https://[^/]+/v[0-9.]+#', '', $c['url']), $this->calls);
    }

    // ---- validation -------------------------------------------------------------------------

    public function test_each_format_states_what_it_needs(): void
    {
        $meta = app(MetaPublisher::class);
        $image = ['url' => 'https://cdn.example.com/a.jpg', 'type' => 'image'];
        $video = ['url' => 'https://cdn.example.com/a.mp4', 'type' => 'video'];

        $this->assertSame('A Reel needs a video.', $meta->validationError(new PublishRequest('facebook', 'x', 'https://cdn.example.com/a.jpg', 'image', 'reel')));
        $this->assertSame('A Story needs an image or a video.', $meta->validationError(new PublishRequest('instagram', 'x', null, null, 'story')));
        $this->assertSame('A carousel needs 2 to 10 images or videos.', $meta->validationError(new PublishRequest('instagram', 'x', null, null, 'carousel', [$image])));
        $this->assertStringContainsString('images only', (string) $meta->validationError(new PublishRequest('facebook', 'x', null, null, 'carousel', [$image, $video])));
        $this->assertNull($meta->validationError(new PublishRequest('instagram', 'x', null, null, 'carousel', [$image, $video])));
        $this->assertNull($meta->validationError(new PublishRequest('facebook', 'Text only')));
        $this->assertSame('Unknown post format.', $meta->validationError(new PublishRequest('facebook', 'x', null, null, 'live')));
    }

    // ---- Facebook ----------------------------------------------------------------------------

    public function test_a_facebook_reel_starts_an_upload_lets_meta_fetch_the_video_and_publishes(): void
    {
        $this->answer('/video_reels', ['video_id' => 'V9', 'success' => true]);
        $outcome = app(MetaPublisher::class)->publish($this->asset('facebook_page'), new PublishRequest('facebook', 'New reel', 'https://cdn.example.com/r.mp4', 'video', 'reel'));

        $this->assertSame([PublishOutcome::PUBLISHED, 'V9'], [$outcome->status, $outcome->externalId]);
        $this->assertSame(['POST /PG1/video_reels', 'POST /video-upload/v19.0/V9', 'POST /PG1/video_reels'], array_map(
            fn ($c) => $c['method'].' '.preg_replace('#^https://graph\.facebook\.com/v[0-9.]+|^https://rupload\.facebook\.com#', '', $c['url']),
            $this->calls,
        ));
        $this->assertSame('start', $this->calls[0]['data']['upload_phase']);
        $this->assertSame(['https://cdn.example.com/r.mp4'], $this->calls[1]['headers']['file_url']);
        $this->assertSame(['OAuth '.self::TOKEN], $this->calls[1]['headers']['Authorization']);
        $this->assertSame(['finish', 'V9', 'PUBLISHED', 'New reel'], [$this->calls[2]['data']['upload_phase'], $this->calls[2]['data']['video_id'], $this->calls[2]['data']['video_state'], $this->calls[2]['data']['description']]);
    }

    public function test_a_facebook_photo_story_and_video_story(): void
    {
        $this->answer('/photo_stories', ['post_id' => 'STORY1', 'success' => true]);
        $this->answer('/photos', ['id' => 'PH1']);
        $outcome = app(MetaPublisher::class)->publish($this->asset('facebook_page'), new PublishRequest('facebook', 'x', 'https://cdn.example.com/s.jpg', 'image', 'story'));
        $this->assertSame('STORY1', $outcome->externalId);
        $this->assertSame(['POST /PG1/photos', 'POST /PG1/photo_stories'], $this->paths());
        $this->assertSame('false', $this->calls[0]['data']['published']);
        $this->assertSame('PH1', $this->calls[1]['data']['photo_id']);

        $this->calls = [];
        $this->answers = ['/video_stories' => [200, ['video_id' => 'VS1', 'post_id' => 'STORY2']]];
        $outcome = app(MetaPublisher::class)->publish($this->asset('facebook_page'), new PublishRequest('facebook', 'x', 'https://cdn.example.com/s.mp4', 'video', 'story'));
        $this->assertSame('STORY2', $outcome->externalId);
        $this->assertCount(3, $this->calls); // start, hosted upload, finish
    }

    public function test_a_facebook_carousel_is_one_post_with_every_photo_attached(): void
    {
        $this->answer('/feed', ['id' => 'PG1_POST']);
        $items = [['url' => 'https://cdn.example.com/1.jpg', 'type' => 'image'], ['url' => 'https://cdn.example.com/2.jpg', 'type' => 'image']];
        $outcome = app(MetaPublisher::class)->publish($this->asset('facebook_page'), new PublishRequest('facebook', 'Album', null, null, 'carousel', $items));

        $this->assertSame('PG1_POST', $outcome->externalId);
        $this->assertSame(['POST /PG1/photos', 'POST /PG1/photos', 'POST /PG1/feed'], $this->paths());
        $this->assertSame([['media_fbid' => 'ID1'], ['media_fbid' => 'ID2']], json_decode($this->calls[2]['data']['attached_media'], true));
    }

    // ---- Instagram ---------------------------------------------------------------------------

    public function test_instagram_reel_and_story_containers(): void
    {
        $meta = app(MetaPublisher::class);

        $reel = $meta->publish($this->asset('instagram'), new PublishRequest('instagram', 'Reel!', 'https://cdn.example.com/r.mp4', 'video', 'reel'));
        $this->assertSame(PublishOutcome::PROCESSING, $reel->status);
        $this->assertSame(['REELS', 'true', 'Reel!'], [$this->calls[0]['data']['media_type'], $this->calls[0]['data']['share_to_feed'], $this->calls[0]['data']['caption']]);

        $this->calls = [];
        $story = $meta->publish($this->asset('instagram'), new PublishRequest('instagram', 'ignored', 'https://cdn.example.com/s.jpg', 'image', 'story'));
        $this->assertSame(PublishOutcome::PUBLISHED, $story->status);
        $this->assertSame('STORIES', $this->calls[0]['data']['media_type']);
        $this->assertArrayNotHasKey('caption', $this->calls[0]['data'], 'Instagram stories take no caption');
        $this->assertSame(['POST /IG1/media', 'POST /IG1/media_publish'], $this->paths());
    }

    public function test_an_image_carousel_is_published_at_once(): void
    {
        $items = [['url' => 'https://cdn.example.com/1.jpg', 'type' => 'image'], ['url' => 'https://cdn.example.com/2.jpg', 'type' => 'image']];
        $outcome = app(MetaPublisher::class)->publish($this->asset('instagram'), new PublishRequest('instagram', 'Two', null, null, 'carousel', $items));

        $this->assertSame(PublishOutcome::PUBLISHED, $outcome->status);
        $this->assertSame(['POST /IG1/media', 'POST /IG1/media', 'POST /IG1/media', 'POST /IG1/media_publish'], $this->paths());
        $this->assertSame('true', $this->calls[0]['data']['is_carousel_item']);
        $this->assertSame(['CAROUSEL', 'ID1,ID2', 'Two'], [$this->calls[2]['data']['media_type'], $this->calls[2]['data']['children'], $this->calls[2]['data']['caption']]);
    }

    public function test_a_carousel_with_a_video_waits_for_every_item_then_creates_and_publishes_the_carousel(): void
    {
        $ig = $this->asset('instagram');
        $meta = app(MetaPublisher::class);
        $items = [['url' => 'https://cdn.example.com/1.jpg', 'type' => 'image'], ['url' => 'https://cdn.example.com/2.mp4', 'type' => 'video']];
        $request = new PublishRequest('instagram', 'Mixed', null, null, 'carousel', $items);

        $first = $meta->publish($ig, $request);
        $this->assertSame([PublishOutcome::PROCESSING, 'carousel:ID1,ID2'], [$first->status, $first->containerId]);
        $this->assertSame('VIDEO', $this->calls[1]['data']['media_type']);

        // The video is still processing: nothing else happens.
        $this->calls = [];
        $this->answers = ['/ID2' => [200, ['status_code' => 'IN_PROGRESS']], '/ID1' => [200, ['status_code' => 'FINISHED']]];
        $this->assertSame('carousel:ID1,ID2', $meta->checkProcessing($ig, 'carousel:ID1,ID2', $request)->containerId);

        // Both ready: the carousel container is created with the caption, then checked like any container.
        $this->calls = [];
        $this->answers = ['/ID1' => [200, ['status_code' => 'FINISHED']], '/ID2' => [200, ['status_code' => 'FINISHED']], '/IG1/media' => [200, ['id' => 'CAR1']]];
        $next = $meta->checkProcessing($ig, 'carousel:ID1,ID2', $request);
        $this->assertSame([PublishOutcome::PROCESSING, 'CAR1'], [$next->status, $next->containerId]);
        $this->assertSame(['CAROUSEL', 'ID1,ID2', 'Mixed'], [$this->calls[2]['data']['media_type'], $this->calls[2]['data']['children'], $this->calls[2]['data']['caption']]);

        $this->answers = ['/CAR1' => [200, ['status_code' => 'FINISHED']], '/media_publish' => [200, ['id' => 'IGPOST']]];
        $this->assertSame([PublishOutcome::PUBLISHED, 'IGPOST'], [($done = $meta->checkProcessing($ig, 'CAR1', $request))->status, $done->externalId]);
    }

    // ---- through the API ---------------------------------------------------------------------

    public function test_a_carousel_is_stored_with_its_items_and_published_through_the_api(): void
    {
        $account = Account::factory()->create(['allow_facebook' => true, 'allow_instagram' => true]);
        $plan = PlanCatalog::find('growth');
        $invoice = Invoice::create([
            'account_id' => $account->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => 'growth', 'plan_label' => $plan['label'],
            'amount' => $plan['price'], 'tax_amount' => 0, 'total_amount' => $plan['price'], 'currency' => 'INR', 'payment_gateway' => 'razorpay', 'status' => 'pending',
        ]);
        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());
        SocialAccount::create([
            'account_id' => $account->id, 'provider' => 'meta', 'asset_type' => 'facebook_page', 'provider_id' => 'PG1',
            'name' => 'Acme', 'access_token' => self::TOKEN, 'health_status' => SocialAccount::HEALTH_CONNECTED,
        ]);
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('social_marketer');
        $this->answer('/feed', ['id' => 'PG1_ALBUM']);

        $this->actingAs($user)->postJson('/api/social/organic-posts', [
            'platform' => 'facebook', 'caption' => 'Album', 'post_format' => 'carousel',
            'media_items' => [['url' => 'https://cdn.example.com/1.jpg', 'type' => 'image'], ['url' => 'https://cdn.example.com/2.jpg', 'type' => 'image']],
        ])->assertCreated()->assertJsonPath('data.status', 'published')->assertJsonPath('data.post_format', 'carousel')
            ->assertJsonPath('data.external_post_id', 'PG1_ALBUM');
        $this->assertCount(2, OrganicPost::sole()->media_items);

        $this->actingAs($user)->postJson('/api/social/organic-posts', [
            'platform' => 'facebook', 'caption' => 'x', 'post_format' => 'reel', 'media_url' => 'https://cdn.example.com/a.jpg', 'media_type' => 'image',
        ])->assertUnprocessable()->assertJsonPath('message', 'A Reel needs a video.');
        $this->actingAs($user)->postJson('/api/social/organic-posts', [
            'platform' => 'linkedin', 'caption' => 'x', 'post_format' => 'story', 'media_url' => 'https://cdn.example.com/a.jpg', 'media_type' => 'image',
        ])->assertUnprocessable();
    }

    // ---- inbox -------------------------------------------------------------------------------

    public function test_a_dm_reply_after_24_hours_is_resent_with_the_human_agent_tag(): void
    {
        $page = $this->asset('facebook_page');
        $plan = PlanCatalog::find('business');
        $invoice = Invoice::create([
            'account_id' => $page->account_id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => 'business', 'plan_label' => $plan['label'],
            'amount' => $plan['price'], 'tax_amount' => 0, 'total_amount' => $plan['price'], 'currency' => 'INR', 'payment_gateway' => 'razorpay', 'status' => 'pending',
        ]);
        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());
        $user = User::factory()->create(['account_id' => $page->account_id, 'is_active' => true]);
        $user->assignRole('admin');
        $sends = 0;
        $this->handler = function (HttpRequest $request) use (&$sends) {
            if ($request->method() === 'GET') {
                return Http::response(['participants' => ['data' => [['id' => 'PG1'], ['id' => 'PSID9']]]]);
            }
            $sends++;

            return $sends === 1
                ? Http::response(['error' => ['code' => 10, 'error_subcode' => 2018278, 'message' => 'outside window']], 400)
                : Http::response(['message_id' => 'm1']);
        };

        $this->actingAs($user)->postJson('/api/social/inbox/send', ['thread_id' => "facebook:{$page->id}:C1", 'message' => 'Hello again'])
            ->assertOk();

        $posts = array_values(array_filter($this->calls, fn ($c) => $c['method'] === 'POST'));
        $this->assertSame('RESPONSE', $posts[0]['data']['messaging_type']);
        $this->assertSame(['MESSAGE_TAG', 'HUMAN_AGENT'], [$posts[1]['data']['messaging_type'], $posts[1]['data']['tag']]);
    }

    // ---- ads ---------------------------------------------------------------------------------

    public function test_whatsapp_status_travels_with_instagram_stories_and_targets_the_status_position(): void
    {
        $this->assertSame(['whatsapp_status', 'instagram_stories'], MetaAdsService::placementsFor(['whatsapp_status']));
        $this->assertSame(['instagram_stories', 'whatsapp_status'], MetaAdsService::placementsFor(['instagram_stories', 'whatsapp_status']));

        $targeting = (new \ReflectionMethod(MetaAdsService::class, 'placementTargeting'))->invoke(null, ['whatsapp_status', 'instagram_stories']);
        $this->assertSame(['whatsapp', 'instagram'], $targeting['publisher_platforms']);
        $this->assertSame(['status'], $targeting['whatsapp_positions']);
        $this->assertSame(['story'], $targeting['instagram_positions']);
    }
}
