<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\MessageDispatchLog;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Models\WhatsAppSession;
use App\Rules\PublicOutboundUrl;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Social\Publishing\LinkedInPublisher;
use App\Services\Templates\TemplateMessageDispatcher;
use App\Services\Webhooks\WebhookSender;
use App\Services\WhatsApp\DirectMessageDispatcher;
use App\Support\PlanCatalog;
use App\Support\Security\HostResolver;
use App\Support\WhatsAppMediaPayloadBuilder;
use App\Support\Security\UnsafeOutboundUrlException;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use ReflectionMethod;
use Tests\Support\FakeHostResolver;
use Tests\TestCase;

/**
 * Phase 12 Task 2 (H7) — where the OutboundUrlGuard is wired in: webhook registration, WebhookSender at send time
 * (incl. redirects and DNS rebinding), media_url in front of the QR engine, the LinkedIn media download and the
 * organic-post media_url rule. Trusted configuration URLs (the QR engine's own base URL) are NOT guarded — proved
 * below by a send that still reaches the engine's loopback address.
 */
class OutboundUrlIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private FakeHostResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->dns = new FakeHostResolver();
        $this->app->instance(HostResolver::class, $this->dns);
        config(['services.qr_engine.url' => 'http://127.0.0.1:3001', 'services.qr_engine.internal_secret' => 's3cret']);
    }

    // ------------------------------------------------------------ fixtures

    private function subscription(string $url = 'https://hooks.example.com/in'): WebhookSubscription
    {
        $account = Account::factory()->create();

        return WebhookSubscription::create(['account_id' => $account->id, 'url' => $url, 'secret' => 'a-long-secret', 'events' => ['message.sent'], 'is_active' => true]);
    }

    private function qrAccount(): Account
    {
        $account = Account::factory()->create();
        $planKey = collect(PlanCatalog::all())->filter(fn ($p) => $p['engine_type'] === 'qr')->keys()->first();
        $plan = PlanCatalog::find($planKey);
        Plan::updateOrCreate(['slug' => $planKey], [
            'label' => $plan['label'], 'price' => $plan['price'], 'duration_days' => $plan['duration_days'], 'description' => $plan['description'],
            'engine_type' => 'qr', 'billing_model' => $plan['billing_model'], 'rate_per_message' => $plan['rate_per_message'], 'total_allocated_messages' => $plan['total_allocated_messages'],
        ]);
        $invoice = Invoice::create([
            'account_id' => $account->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => $planKey, 'plan_label' => $plan['label'],
            'amount' => $plan['price'], 'tax_amount' => 0, 'total_amount' => $plan['price'], 'currency' => 'INR',
            'payment_gateway' => 'razorpay', 'gateway_order_id' => 'order_'.uniqid(), 'status' => 'pending',
        ]);
        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());
        WhatsAppSession::create(['account_id' => $account->id, 'status' => 'connected']);

        return $account->fresh();
    }

    private function admin(): User
    {
        $user = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $user->assignRole('super_admin');

        return $user;
    }

    /**
     * The shape LinkedInPublisher::uploadAsset() actually reads. NOTE (pre-existing, out of scope for this task): it
     * addresses LinkedIn's dotted key with `\\.` escapes, which Laravel's data_get() does not honor, so against the real
     * API (a literal "com.linkedin.…" key) the upload URL is never found. The response here is shaped for the code as
     * written so the guarded download step can be reached.
     */
    private function linkedInRegisterResponse(): array
    {
        return ['value' => ['asset' => 'urn:li:digitalmediaAsset:1', 'uploadMechanism' => ['com\\' => ['linkedin\\' => ['digitalmedia\\' => ['uploading\\' => ['MediaUploadHttpRequest' => ['uploadUrl' => 'https://upload.linkedin.test/u']]]]]]]];
    }

    // ------------------------------------------------------------ webhook registration

    public function test_registering_a_webhook_for_a_non_public_address_is_rejected(): void
    {
        $account = Account::factory()->create();
        $admin = $this->admin();
        $this->dns->map['internal.example.com'] = ['10.0.0.9'];

        foreach (['http://127.0.0.1/hook', 'http://[::1]/hook', 'http://169.254.169.254/latest', 'http://192.168.1.10/h', 'https://internal.example.com/h', 'ftp://example.com/h'] as $url) {
            $this->actingAs($admin)
                ->postJson("/api/developer/webhooks?account_id={$account->id}", ['url' => $url, 'events' => ['message.sent']])
                ->assertStatus(422)
                ->assertJsonValidationErrors('url');
        }

        $this->assertSame(0, WebhookSubscription::count());
    }

    public function test_registering_a_webhook_for_a_public_url_still_works(): void
    {
        $account = Account::factory()->create();

        $this->actingAs($this->admin())
            ->postJson("/api/developer/webhooks?account_id={$account->id}", ['url' => 'https://hooks.example.com/in', 'events' => ['message.sent']])
            ->assertCreated();

        $this->assertSame(1, WebhookSubscription::count());
    }

    // ------------------------------------------------------------ WebhookSender

    public function test_a_public_webhook_is_delivered_signed_exactly_as_before(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response(['ok' => true], 200)]);
        $subscription = $this->subscription();

        $result = WebhookSender::send($subscription, 'message.sent', ['id' => 1]);

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['status_code']);
        Http::assertSent(function ($request) {
            $body = $request->body();

            return $request->method() === 'POST'
                && $request->url() === 'https://hooks.example.com/in'
                && $request->hasHeader('X-WASAAS-Event', 'message.sent')
                && $request->header('X-WASAAS-Signature')[0] === hash_hmac('sha256', $body, 'a-long-secret');
        });
        $this->assertSame('success', WebhookDelivery::first()->status);
    }

    public function test_a_stored_non_public_url_is_refused_at_send_time_without_any_request(): void
    {
        Http::fake();
        // registered before the guard existed (or edited in the database)
        $subscription = $this->subscription('http://169.254.169.254/latest/meta-data/');

        $result = WebhookSender::send($subscription, 'message.sent', ['id' => 1]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('refused', (string) $result['error']);
        Http::assertNothingSent();
        $this->assertSame('failed', WebhookDelivery::first()->status);
    }

    public function test_dns_rebinding_after_registration_is_caught_at_send_time(): void
    {
        Http::fake();
        $subscription = $this->subscription('https://rebind.example.com/in');
        $this->dns->sequence['rebind.example.com'] = [['127.0.0.1']]; // public when registered, internal now

        $result = WebhookSender::send($subscription, 'message.sent', []);

        $this->assertFalse($result['success']);
        Http::assertNothingSent();
    }

    public function test_a_webhook_url_that_redirects_into_an_internal_address_is_not_followed(): void
    {
        Http::fake([
            'hooks.example.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/iam']),
            '169.254.169.254/*' => Http::response('credentials', 200),
        ]);
        $subscription = $this->subscription();

        $result = WebhookSender::send($subscription, 'message.sent', []);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('refused', (string) $result['error']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '169.254.169.254'));
        Http::assertSentCount(1);
    }

    public function test_a_webhook_redirect_to_another_public_url_is_still_followed(): void
    {
        Http::fake([
            'hooks.example.com/*' => Http::response('', 307, ['Location' => 'https://hooks2.example.org/in']),
            'hooks2.example.org/*' => Http::response('', 200),
        ]);

        $result = WebhookSender::send($this->subscription(), 'message.sent', []);

        $this->assertTrue($result['success']);
        Http::assertSent(fn ($request) => $request->url() === 'https://hooks2.example.org/in' && $request->method() === 'POST' && $request->body() !== '');
    }

    public function test_the_test_webhook_endpoint_reports_a_refused_url_as_a_failure(): void
    {
        Http::fake();
        $subscription = $this->subscription('http://10.1.1.1/hook');

        $this->actingAs($this->admin())
            ->postJson("/api/developer/webhooks/{$subscription->id}/test?account_id={$subscription->account_id}")
            ->assertStatus(502)
            ->assertJsonPath('success', false);

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------ media_url in front of the QR engine

    public function test_the_qr_media_payload_refuses_a_non_public_media_url_and_keeps_public_ones(): void
    {
        foreach (['http://127.0.0.1:8080/x.png', 'http://169.254.169.254/x', 'http://10.0.0.1/x.pdf', 'http://[::1]/x'] as $url) {
            try {
                WhatsAppMediaPayloadBuilder::build('qr', ['media_type' => 'image', 'url' => $url]);
                $this->fail("{$url} must be refused");
            } catch (UnsafeOutboundUrlException) {
                $this->assertTrue(true);
            }
        }

        [, $meta] = WhatsAppMediaPayloadBuilder::build('qr', ['media_type' => 'image', 'url' => 'https://cdn.example.com/a.png', 'caption' => 'hi']);
        $this->assertSame('https://cdn.example.com/a.png', $meta['media_url']);
        $this->assertSame('image', $meta['media_type']);
    }

    public function test_the_meta_media_payload_is_unchanged_because_meta_fetches_the_link_itself(): void
    {
        [, $meta] = WhatsAppMediaPayloadBuilder::build('meta', ['media_type' => 'image', 'url' => 'https://cdn.example.com/a.png']);

        $this->assertSame(['type' => 'image', 'image' => ['link' => 'https://cdn.example.com/a.png']], $meta);
    }

    public function test_a_template_media_override_pointing_inward_degrades_to_plain_text_like_any_unusable_url(): void
    {
        $account = $this->qrAccount();

        $this->assertSame([], TemplateMessageDispatcher::resolveMediaMetaData(new \App\Models\MessageTemplate(), $account, 'http://169.254.169.254/latest/meta-data/'));
        $this->assertSame([], TemplateMessageDispatcher::resolveMediaMetaData(new \App\Models\MessageTemplate(), $account, 'http://127.0.0.1/x.png'));

        $ok = TemplateMessageDispatcher::resolveMediaMetaData(new \App\Models\MessageTemplate(), $account, 'https://cdn.example.com/a.png');
        $this->assertSame('https://cdn.example.com/a.png', $ok['media_url']);
    }

    public function test_a_direct_media_send_with_an_internal_url_never_reaches_the_engine_and_is_logged_as_failed(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'message_id' => 'QR_OK'], 200)]);
        $account = $this->qrAccount();

        $result = DirectMessageDispatcher::dispatch($account->id, '919999999999', 'media', ['media_type' => 'image', 'url' => 'http://169.254.169.254/latest/meta-data/']);

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('media URL was refused', $result['message']);
        Http::assertNothingSent();
        $log = MessageDispatchLog::where('account_id', $account->id)->latest('id')->first();
        $this->assertFalse((bool) $log->success);
        $this->assertSame(0, (int) \App\Models\Subscription::where('account_id', $account->id)->value('used_messages'));
    }

    public function test_a_direct_media_send_with_a_public_url_and_the_engines_own_loopback_address_still_works(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'message_id' => 'QR_OK'], 200)]);
        $account = $this->qrAccount();

        $result = DirectMessageDispatcher::dispatch($account->id, '919999999999', 'media', ['media_type' => 'image', 'url' => 'https://cdn.example.com/a.png']);

        $this->assertSame('sent', $result['status']);
        // the trusted, configured engine base URL (loopback) is deliberately NOT subject to the guard
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'http://127.0.0.1:3001/api/message/send') && $request['media_url'] === 'https://cdn.example.com/a.png');
    }

    // ------------------------------------------------------------ organic post media_url + LinkedIn download

    public function test_the_organic_post_media_url_rule_accepts_public_and_rejects_internal_urls(): void
    {
        $check = fn (?string $url) => Validator::make(['media_url' => $url], ['media_url' => ['nullable', 'string', new PublicOutboundUrl()]])->passes();

        $this->assertTrue($check('https://cdn.example.com/p.jpg'));
        $this->assertTrue($check(null));
        $this->assertFalse($check('http://127.0.0.1/p.jpg'));
        $this->assertFalse($check('http://169.254.169.254/'));
        $this->assertFalse($check('file:///etc/passwd'));

        $this->assertStringContainsString('PublicOutboundUrl', file_get_contents(app_path('Http/Controllers/Api/OrganicPostController.php')));
    }

    public function test_the_linkedin_media_download_cannot_be_pointed_at_an_internal_address(): void
    {
        Http::fake([
            'api.linkedin.com/*' => Http::response($this->linkedInRegisterResponse(), 200),
            '169.254.169.254/*' => Http::response('credentials', 200),
            'upload.linkedin.test/*' => Http::response('', 201),
        ]);
        $publisher = app(LinkedInPublisher::class);
        $upload = new ReflectionMethod($publisher, 'uploadAsset');
        $upload->setAccessible(true);

        try {
            $upload->invoke($publisher, 'token', 'urn:li:person:1', 'http://169.254.169.254/latest/meta-data/iam');
            $this->fail('the internal media URL must be refused');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\App\Services\Social\Publishing\PublishUnreachable::class, $e);
            $this->assertInstanceOf(UnsafeOutboundUrlException::class, $e->getPrevious());
        }

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '169.254.169.254'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'upload.linkedin.test'));
    }

    public function test_the_linkedin_media_download_of_a_public_url_still_works(): void
    {
        Http::fake([
            'api.linkedin.com/*' => Http::response($this->linkedInRegisterResponse(), 200),
            'cdn.example.com/*' => Http::response('BINARY', 200),
            'upload.linkedin.test/*' => Http::response('', 201),
        ]);
        $publisher = app(LinkedInPublisher::class);
        $upload = new ReflectionMethod($publisher, 'uploadAsset');
        $upload->setAccessible(true);

        $this->assertSame('urn:li:digitalmediaAsset:1', $upload->invoke($publisher, 'token', 'urn:li:person:1', 'https://cdn.example.com/p.jpg'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'upload.linkedin.test') && $request->body() === 'BINARY');
    }
}
