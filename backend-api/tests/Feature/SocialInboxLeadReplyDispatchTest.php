<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\SocialInboxController;
use App\Models\Account;
use App\Models\Lead;
use App\Models\MessageDispatchLog;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppSession;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 5 P5-2 -- a Social Inbox reply to a `lead:` thread goes out over
 * WhatsApp through DirectMessageDispatcher (the unified individual-recipient
 * path), so it is quota-gated, consumes exactly one credit through
 * MessageQuotaService on a confirmed send, and writes the normal
 * MessageDispatchLog row. Before P5-2 SocialInboxController::sendToLead()
 * called the engine driver itself: no credit, no log.
 *
 * Driven through the real HTTP route (auth:sanctum -> tenant.isolation ->
 * subscription.guard -> permission:manage-social-leads ->
 * module.guard:social_inbox) with the provider faked at the HTTP boundary,
 * the same convention as UnifiedQuotaAndCapabilityTest.
 */
class SocialInboxLeadReplyDispatchTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/social/inbox/send';

    private const LEAD_PHONE_RAW = '+91 98765 43210';

    private const LEAD_PHONE_NORMALIZED = '919876543210';

    private const META_TOKEN = 'EAAG_social_inbox_p52_token_0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------
    private function qrTenant(array $subscription = []): Account
    {
        $account = Account::factory()->create();
        Subscription::factory()->create(['account_id' => $account->id, 'engine_type' => 'qr'] + $subscription);
        WhatsAppSession::create(['account_id' => $account->id, 'status' => 'connected']);

        return $account->fresh();
    }

    private function metaTenant(array $subscription = []): Account
    {
        $account = Account::factory()->create();
        Subscription::factory()->create(['account_id' => $account->id, 'engine_type' => 'meta'] + $subscription);
        WhatsAppSession::create([
            'account_id' => $account->id,
            'status' => 'connected',
            'meta_phone_number_id' => '1098'.random_int(100000000, 999999999),
            'meta_waba_id' => '123456789012345',
            'meta_access_token' => self::META_TOKEN,
        ]);

        return $account->fresh();
    }

    private function user(Account $account): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('admin');

        return $user;
    }

    private function lead(Account $account, ?string $phone = self::LEAD_PHONE_RAW): Lead
    {
        return Lead::create([
            'account_id' => $account->id,
            'provider' => 'meta',
            'provider_lead_id' => 'lg_'.uniqid('', true),
            'lead_name' => 'Ada Lovelace',
            'lead_phone' => $phone,
        ]);
    }

    private function reply(User $user, Lead $lead, string $message = 'Thanks for your interest!', array $extra = [])
    {
        return $this->actingAs($user)->postJson(self::ENDPOINT, [
            'thread_id' => "lead:{$lead->id}",
            'message' => $message,
        ] + $extra);
    }

    private function fakeProvidersOk(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messaging_product' => 'whatsapp',
                'messages' => [['id' => 'wamid.SOCIAL_INBOX_OK']],
            ], 200),
            '*' => Http::response(['success' => true, 'message_id' => 'QR_SOCIAL_INBOX_OK'], 200),
        ]);
    }

    private function used(Account $account): int
    {
        return (int) Subscription::where('account_id', $account->id)->firstOrFail()->used_messages;
    }

    private function logs(Account $account)
    {
        return MessageDispatchLog::where('account_id', $account->id)->get();
    }

    // ------------------------------------------------------------------
    // 1 + 5 -- successful QR reply
    // ------------------------------------------------------------------
    public function test_a_qr_lead_reply_sends_consumes_one_credit_and_is_logged(): void
    {
        $this->fakeProvidersOk();
        $account = $this->qrTenant();
        $lead = $this->lead($account);

        $this->reply($this->user($account), $lead, 'Hello from the inbox')
            ->assertOk()
            ->assertExactJson(['message' => 'Message sent.']);

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $request) use ($account) {
            return str_ends_with($request->url(), '/api/message/send')
                && $request['account_id'] === $account->id
                && $request['to'] === self::LEAD_PHONE_NORMALIZED.'@s.whatsapp.net'
                && $request['message'] === 'Hello from the inbox';
        });

        $this->assertSame(1, $this->used($account));

        $logs = $this->logs($account);
        $this->assertCount(1, $logs);
        $log = $logs->first();
        $this->assertSame('sent', $log->status);
        $this->assertSame(SocialInboxController::DISPATCH_SOURCE, $log->source);
        $this->assertSame('social_inbox', $log->source);
        $this->assertSame(self::LEAD_PHONE_NORMALIZED, $log->recipient_phone);
        $this->assertSame('text', $log->reference_type);
        $this->assertSame('Hello from the inbox', $log->message_preview);
        $this->assertSame('QR_SOCIAL_INBOX_OK', $log->gateway_message_id);
        $this->assertNull($log->api_key_id);
        $this->assertNull($log->error_reason);
        $this->assertNotNull($log->sent_at);
    }

    // ------------------------------------------------------------------
    // 6 -- Meta provider through the same dispatcher
    // ------------------------------------------------------------------
    public function test_a_meta_lead_reply_goes_through_the_cloud_api_and_records_the_wamid(): void
    {
        $this->fakeProvidersOk();
        $account = $this->metaTenant();
        $lead = $this->lead($account);

        $this->reply($this->user($account), $lead, 'Meta hello')
            ->assertOk()
            ->assertExactJson(['message' => 'Message sent.']);

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $request) {
            return str_contains($request->url(), 'graph.facebook.com')
                && $request['to'] === self::LEAD_PHONE_NORMALIZED
                && $request['type'] === 'text'
                && $request['text']['body'] === 'Meta hello';
        });

        $this->assertSame(1, $this->used($account));

        $log = $this->logs($account)->sole();
        $this->assertSame('sent', $log->status);
        $this->assertSame('social_inbox', $log->source);
        $this->assertSame(self::LEAD_PHONE_NORMALIZED, $log->recipient_phone);
        $this->assertSame('wamid.SOCIAL_INBOX_OK', $log->gateway_message_id);
    }

    // ------------------------------------------------------------------
    // 2 -- insufficient quota
    // ------------------------------------------------------------------
    public function test_an_exhausted_tenant_is_refused_by_the_existing_subscription_guard(): void
    {
        // An exhausted subscription is not "active", so for a tenant user
        // the pre-existing subscription.guard answers first (403
        // SUBSCRIPTION_EXPIRED) -- unchanged by P5-2.
        $this->fakeProvidersOk();
        $account = $this->qrTenant(['total_allocated_messages' => 5, 'used_messages' => 5]);
        $lead = $this->lead($account);

        $this->reply($this->user($account), $lead)
            ->assertForbidden()
            ->assertJsonPath('error_code', 'SUBSCRIPTION_EXPIRED');

        Http::assertNothingSent();
        $this->assertSame(5, $this->used($account));
        $this->assertSame(0, $this->logs($account)->where('status', 'sent')->count());
    }

    public function test_the_dispatcher_quota_gate_refuses_when_the_route_guard_is_bypassed(): void
    {
        // A Super Admin bypasses subscription.guard and module.guard by
        // design, so for them the dispatcher's own quota gate is the only
        // barrier. Before P5-2 this path had its own hasActiveSubscription()
        // check; it must still refuse, now through DirectMessageDispatcher.
        $this->fakeProvidersOk();
        $account = $this->qrTenant(['total_allocated_messages' => 5, 'used_messages' => 5]);
        $lead = $this->lead($account);
        $superAdmin = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $superAdmin->assignRole('super_admin');

        $this->actingAs($superAdmin)
            ->postJson(self::ENDPOINT.'?account_id='.$account->id, ['thread_id' => "lead:{$lead->id}", 'message' => 'hi'])
            ->assertStatus(422)
            ->assertExactJson(['message' => $account->fresh()->quotaExhaustedMessage()]);

        Http::assertNothingSent();
        $this->assertSame(5, $this->used($account));

        // The dispatcher's own refusal log -- never a 'sent' row.
        $log = $this->logs($account)->sole();
        $this->assertSame('failed', $log->status);
        $this->assertSame('social_inbox', $log->source);
        $this->assertNull($log->gateway_message_id);
        $this->assertNull($log->sent_at);
    }

    public function test_a_super_admin_reply_for_a_selected_client_is_charged_to_that_client(): void
    {
        $this->fakeProvidersOk();
        $account = $this->qrTenant();
        $lead = $this->lead($account);
        $superAdmin = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $superAdmin->assignRole('super_admin');

        $this->actingAs($superAdmin)
            ->postJson(self::ENDPOINT.'?account_id='.$account->id, ['thread_id' => "lead:{$lead->id}", 'message' => 'hi'])
            ->assertOk();

        $this->assertSame(1, $this->used($account));
        $this->assertSame('sent', $this->logs($account)->sole()->status);
    }

    // ------------------------------------------------------------------
    // 3 -- provider failure
    // ------------------------------------------------------------------
    public function test_a_qr_provider_rejection_is_not_a_success_and_consumes_nothing(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'error' => 'Engine said no'], 200)]);
        $account = $this->qrTenant(['used_messages' => 7]);
        $lead = $this->lead($account);

        $this->reply($this->user($account), $lead)
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Engine said no']);

        Http::assertSentCount(1);
        $this->assertSame(7, $this->used($account));

        $log = $this->logs($account)->sole();
        $this->assertSame('failed', $log->status);
        $this->assertSame('Engine said no', $log->error_reason);
        $this->assertNull($log->gateway_message_id);
        $this->assertNull($log->sent_at);
    }

    public function test_a_meta_provider_error_is_not_a_success_and_consumes_nothing(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Recipient not on WhatsApp']], 400)]);
        $account = $this->metaTenant();
        $lead = $this->lead($account);

        $response = $this->reply($this->user($account), $lead)->assertStatus(422);

        $this->assertNotSame('Message sent.', $response->json('message'));
        $this->assertSame(0, $this->used($account));
        $this->assertSame(0, $this->logs($account)->where('status', 'sent')->count());
        $this->assertSame(1, $this->logs($account)->where('status', 'failed')->count());
    }

    public function test_an_unreachable_qr_engine_is_a_failure_and_consumes_nothing(): void
    {
        Http::fake(['*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('connection refused')]);
        $account = $this->qrTenant();
        $lead = $this->lead($account);

        $response = $this->reply($this->user($account), $lead)->assertStatus(422);

        $this->assertStringContainsString('unreachable', $response->json('message'));
        $this->assertSame(0, $this->used($account));
        $this->assertSame('failed', $this->logs($account)->sole()->status);
    }

    public function test_a_disconnected_qr_session_is_refused_before_any_provider_call(): void
    {
        $this->fakeProvidersOk();
        $account = $this->qrTenant();
        WhatsAppSession::where('account_id', $account->id)->update(['status' => 'disconnected']);
        $lead = $this->lead($account);

        $this->reply($this->user($account), $lead)
            ->assertStatus(422)
            ->assertExactJson(['message' => 'WhatsApp account is disconnected. Please connect your device first.']);

        Http::assertNothingSent();
        $this->assertSame(0, $this->used($account));
        $this->assertSame('failed', $this->logs($account)->sole()->status);
    }

    public function test_a_lead_without_a_phone_is_still_refused_with_the_original_response(): void
    {
        $this->fakeProvidersOk();
        $account = $this->qrTenant();
        $lead = $this->lead($account, null);

        $this->reply($this->user($account), $lead)
            ->assertStatus(422)
            ->assertExactJson(['message' => 'This lead has no phone number on file.']);

        Http::assertNothingSent();
        $this->assertSame(0, $this->used($account));
        $this->assertCount(0, $this->logs($account));
    }

    // ------------------------------------------------------------------
    // 4 -- tenant isolation
    // ------------------------------------------------------------------
    public function test_a_tenant_cannot_reply_to_another_tenants_lead(): void
    {
        $this->fakeProvidersOk();
        $victim = $this->qrTenant();
        $attacker = $this->qrTenant();
        $victimLead = $this->lead($victim);

        $this->reply($this->user($attacker), $victimLead)
            ->assertStatus(422)
            ->assertJsonValidationErrors('thread_id');

        Http::assertNothingSent();
        $this->assertSame(0, $this->used($victim));
        $this->assertSame(0, $this->used($attacker));
        $this->assertSame(0, MessageDispatchLog::count());
    }

    public function test_a_caller_supplied_account_id_never_selects_the_sending_tenant(): void
    {
        $this->fakeProvidersOk();
        $victim = $this->qrTenant();
        $attacker = $this->qrTenant();
        $attackerLead = $this->lead($attacker);
        $victimLead = $this->lead($victim);

        // account_id in the body AND the query string, aimed at the victim.
        $this->actingAs($this->user($attacker))
            ->postJson(self::ENDPOINT.'?account_id='.$victim->id, [
                'thread_id' => "lead:{$victimLead->id}",
                'message' => 'hi',
                'account_id' => $victim->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('thread_id');
        Http::assertNothingSent();

        // Replying to their OWN lead with the victim's id in the body
        // charges and logs the attacker only.
        $this->reply($this->user($attacker), $attackerLead, 'hi', ['account_id' => $victim->id])->assertOk();

        $this->assertSame(1, $this->used($attacker));
        $this->assertSame(0, $this->used($victim));
        $this->assertSame(1, $this->logs($attacker)->count());
        $this->assertSame(0, $this->logs($victim)->count());
        Http::assertSent(fn (HttpRequest $r) => $r['account_id'] === $attacker->id);
    }

    public function test_the_social_inbox_module_gate_still_applies(): void
    {
        $this->fakeProvidersOk();
        $account = $this->qrTenant();
        $account->forceFill(['allowed_modules' => array_values(array_diff(Account::MODULES, ['social_inbox']))])->save();
        $lead = $this->lead($account);

        $this->reply($this->user($account), $lead)->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame(0, $this->used($account));
    }

    public function test_a_user_without_the_social_leads_permission_is_forbidden(): void
    {
        $this->fakeProvidersOk();
        $account = $this->qrTenant();
        $lead = $this->lead($account);
        $member = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);

        $this->reply($member, $lead)->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame(0, $this->used($account));
    }

    // ------------------------------------------------------------------
    // 7 + 8 -- exactly one credit, exactly one dispatch per request
    // ------------------------------------------------------------------
    public function test_one_reply_is_exactly_one_provider_call_one_credit_and_one_log(): void
    {
        $this->fakeProvidersOk();
        $account = $this->qrTenant(['used_messages' => 10]);
        $lead = $this->lead($account);

        $this->reply($this->user($account), $lead)->assertOk();

        Http::assertSentCount(1);
        $this->assertSame(11, $this->used($account));
        $this->assertSame(1, $this->logs($account)->count());
    }

    public function test_each_reply_is_charged_and_logged_once_like_every_other_direct_send(): void
    {
        $this->fakeProvidersOk();
        $account = $this->qrTenant();
        $lead = $this->lead($account);
        $user = $this->user($account);

        $this->reply($user, $lead, 'first')->assertOk();
        $this->reply($user, $lead, 'second')->assertOk();

        Http::assertSentCount(2);
        $this->assertSame(2, $this->used($account));
        $this->assertSame(['first', 'second'], $this->logs($account)->sortBy('id')->pluck('message_preview')->all());
    }

    public function test_the_controller_holds_no_provider_quota_or_logging_logic_of_its_own(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Api/SocialInboxController.php'));

        $this->assertStringContainsString('DirectMessageDispatcher::dispatch(', $source);

        foreach ([
            'WhatsAppEngineFactory',
            '->sendMessage(',
            'MessageQuotaService',
            'used_messages',
            'increment(',
            'MessageDispatchLog',
            'lockForUpdate',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, "SocialInboxController must not contain {$forbidden}");
        }
    }
}
