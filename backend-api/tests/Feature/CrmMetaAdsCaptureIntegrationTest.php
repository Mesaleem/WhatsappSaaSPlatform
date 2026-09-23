<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\Contact;
use App\Models\CrmCaptureLinkFailure;
use App\Models\CrmLead;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\SocialAccount;
use App\Models\SocialProviderConfig;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Services\Access\AccessControlService;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Crm\CaptureLeadLinker;
use App\Services\Crm\ContactResolver;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 6 — CRM Task 10. Meta / Ads lead capture -> CRM.
 *
 *   Lead Ads form  (POST /api/social/webhook/meta, leadgen)   -> leads(provider=meta)
 *   Click-to-WA    (POST /api/webhooks/meta, message.referral) -> leads(provider=whatsapp_ctwa)
 *        -> CaptureLeadLinker::linkQuietly() -> Contact (resolved/reused) -> crm_leads(source=meta_ad)
 *
 * Driven through the real signed webhook endpoints wherever the path
 * exists over HTTP, so the capture behaviour is asserted exactly as Meta
 * exercises it.
 */
class CrmMetaAdsCaptureIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const APP_SECRET = 'meta_app_secret_task10_never_log_me';

    private const PAGE_ID = '777000111';

    private const OTHER_PAGE_ID = '777000222';

    private const PHONE_ID = '109876543210987';

    private const OTHER_PHONE_ID = '555000111222333';

    private const LEADS = '/api/crm/leads';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);

        SocialProviderConfig::create([
            'provider' => 'meta',
            'client_id' => 'app-id',
            'client_secret' => self::APP_SECRET,
            'is_active' => true,
        ]);

        // The Graph API field_data fetch; every other outbound call (the
        // two WhatsApp sends) gets Http::fake()'s default empty 200.
        Http::fake([
            'graph.facebook.com/*' => Http::response(['field_data' => self::fieldData('+91 98765 43210')], 200),
        ]);
    }

    // =================================================================
    // Fixtures
    // =================================================================

    /** @return list<array{name: string, values: list<string>}> */
    private static function fieldData(string $phone, string $name = 'Ada Lovelace', string $email = 'ada@example.com'): array
    {
        return [
            ['name' => 'full_name', 'values' => [$name]],
            ['name' => 'phone_number', 'values' => [$phone]],
            ['name' => 'email', 'values' => [$email]],
        ];
    }

    private function grant(Account $account, string $slug): void
    {
        $capability = Capability::where('slug', $slug)->firstOrFail();
        AccountEntitlement::firstOrCreate(
            ['account_id' => $account->id, 'capability_id' => $capability->id],
            ['source' => 'manual_grant', 'granted_by_account_id' => null],
        );
    }

    /** @return array{account: Account, page: SocialAccount} */
    private function adsTenant(bool $withCrm = true, string $pageId = self::PAGE_ID, array $accountAttributes = []): array
    {
        $account = Account::factory()->create($accountAttributes);
        Subscription::factory()->create(['account_id' => $account->id]);
        if ($withCrm) {
            $this->grant($account, 'crm');
        }

        $page = SocialAccount::create([
            'account_id' => $account->id,
            'provider' => 'meta',
            'asset_type' => 'facebook_page',
            'provider_id' => $pageId,
            'name' => 'Page '.$pageId,
            'access_token' => 'PAGE_TOKEN_'.$pageId,
        ]);

        return ['account' => $account->fresh(), 'page' => $page];
    }

    /** A Meta-engine tenant for the CTWA path (inbound Cloud API messages). */
    private function ctwaTenant(bool $withCrm = true, string $phoneId = self::PHONE_ID): Account
    {
        $account = Account::factory()->create();
        Subscription::factory()->create(['account_id' => $account->id]);
        if ($withCrm) {
            $this->grant($account, 'crm');
        }

        WhatsAppSession::create([
            'account_id' => $account->id,
            'status' => 'connected',
            'meta_phone_number_id' => $phoneId,
            'meta_waba_id' => '123456789012345',
            'meta_access_token' => 'EAAG_tenant_token',
            'meta_webhook_verify_token' => 'Verify'.str_replace('.', '', uniqid()),
        ]);

        return $account->fresh();
    }

    private function user(Account $account, string $role = 'admin'): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function signedPost(string $uri, array $payload, string $secret = self::APP_SECRET)
    {
        $body = json_encode($payload);

        return $this->call('POST', $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
        ], $body);
    }

    private function leadgen(string $leadgenId, string $pageId = self::PAGE_ID, string $adId = 'AD-1')
    {
        return $this->signedPost('/api/social/webhook/meta', [
            'object' => 'page',
            'entry' => [[
                'id' => $pageId,
                'changes' => [[
                    'field' => 'leadgen',
                    'value' => ['leadgen_id' => $leadgenId, 'page_id' => $pageId, 'form_id' => 'FORM-1', 'ad_id' => $adId],
                ]],
            ]],
        ]);
    }

    private function ctwa(string $wamid, string $from = '919111111111', string $phoneId = self::PHONE_ID, string $profileName = 'Bob')
    {
        return $this->signedPost('/api/webhooks/meta', [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'waba-id',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '919999999999', 'phone_number_id' => $phoneId],
                        'contacts' => [['profile' => ['name' => $profileName], 'wa_id' => $from]],
                        'messages' => [[
                            'from' => $from,
                            'id' => $wamid,
                            'timestamp' => '1700000000',
                            'type' => 'text',
                            'text' => ['body' => 'Hi, I saw your ad'],
                            'referral' => ['source_id' => 'AD-CTWA-9', 'source_type' => 'ad', 'source_url' => 'https://fb.me/x'],
                        ]],
                    ],
                ]],
            ]],
        ]);
    }

    // =================================================================
    // 1. Lead Ads -> CRM Lead
    // =================================================================

    public function test_a_lead_ads_submission_creates_a_meta_ad_crm_lead_linked_to_its_capture(): void
    {
        $t = $this->adsTenant();

        $this->leadgen('LG-100')->assertOk();

        $capture = Lead::where('provider_lead_id', 'LG-100')->firstOrFail();
        $crmLead = CrmLead::where('capture_lead_id', $capture->id)->firstOrFail();

        $this->assertSame(CrmLead::SOURCE_META_AD, $crmLead->source);
        $this->assertSame(CrmLead::STATUS_NEW, $crmLead->status);
        $this->assertNull($crmLead->assigned_user_id, 'capture promotion never picks an owner');
        $this->assertSame($t['account']->id, $crmLead->account_id);
        $this->assertSame($t['account']->id, $crmLead->contact->account_id);
        $this->assertSame('919876543210', $crmLead->contact->phone_number);
        $this->assertSame('Ada Lovelace', $crmLead->contact->name);
        $this->assertSame('ada@example.com', $crmLead->contact->email);

        // Both directions of the relationship.
        $this->assertSame($crmLead->id, $capture->crmLead->id);
        $this->assertSame($capture->id, $crmLead->captureLead->id);
        $this->assertDatabaseCount('crm_capture_link_failures', 0);
    }

    public function test_the_crm_api_serves_the_promoted_lead_with_its_capture_origin(): void
    {
        $t = $this->adsTenant();
        $this->leadgen('LG-101')->assertOk();
        $crmLead = CrmLead::firstOrFail();

        $this->actingAs($this->user($t['account']))
            ->getJson(self::LEADS.'/'.$crmLead->id)
            ->assertOk()
            ->assertJsonPath('data.source', 'meta_ad')
            ->assertJsonPath('data.capture_lead.provider', 'meta')
            ->assertJsonPath('data.capture_lead.provider_lead_id', 'LG-101')
            ->assertJsonPath('data.contact.phone_number', '919876543210');

        $this->actingAs($this->user($t['account']))
            ->getJson(self::LEADS.'?source=meta_ad')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_an_existing_contact_is_reused_not_duplicated(): void
    {
        $t = $this->adsTenant();
        $existing = Contact::factory()->forAccount($t['account'])->create([
            'phone_number' => '919876543210',
            'name' => 'Curated Name',
            'email' => null,
        ]);

        $this->leadgen('LG-102')->assertOk();

        $this->assertDatabaseCount('contacts', 1);
        $crmLead = CrmLead::firstOrFail();
        $this->assertSame($existing->id, $crmLead->contact_id);
        // Existing ContactResolver rules: a curated name is kept, a blank email is filled.
        $this->assertSame('Curated Name', $existing->fresh()->name);
        $this->assertSame('ada@example.com', $existing->fresh()->email);
    }

    public function test_a_new_contact_is_created_when_none_matches(): void
    {
        $t = $this->adsTenant();
        Contact::factory()->forAccount($t['account'])->create(['phone_number' => '919000000001']);

        $this->leadgen('LG-103')->assertOk();

        $this->assertDatabaseCount('contacts', 2);
        $this->assertSame('919876543210', CrmLead::firstOrFail()->contact->phone_number);
    }

    public function test_another_tenants_contact_with_the_same_phone_is_never_reused(): void
    {
        $other = $this->adsTenant(true, self::OTHER_PAGE_ID);
        $theirs = Contact::factory()->forAccount($other['account'])->create(['phone_number' => '919876543210']);
        $t = $this->adsTenant();

        $this->leadgen('LG-104')->assertOk();

        $crmLead = CrmLead::where('account_id', $t['account']->id)->firstOrFail();
        $this->assertNotSame($theirs->id, $crmLead->contact_id);
        $this->assertSame($t['account']->id, $crmLead->contact->account_id);
        $this->assertSame(0, CrmLead::where('account_id', $other['account']->id)->count());
    }

    // =================================================================
    // 2. Click-to-WhatsApp -> conversation -> Contact -> CRM Lead
    // =================================================================

    public function test_a_click_to_whatsapp_referral_creates_a_meta_ad_crm_lead(): void
    {
        $account = $this->ctwaTenant();

        $this->ctwa('wamid.CTWA-1')->assertOk();

        $capture = Lead::where('provider_lead_id', 'wamid.CTWA-1')->firstOrFail();
        $this->assertSame('whatsapp_ctwa', $capture->provider);
        $this->assertSame('AD-CTWA-9', $capture->ad_id);

        $crmLead = $capture->crmLead;
        $this->assertNotNull($crmLead);
        $this->assertSame(CrmLead::SOURCE_META_AD, $crmLead->source);
        $this->assertSame($account->id, $crmLead->account_id);
        $this->assertSame('919111111111', $crmLead->contact->phone_number);
        $this->assertSame('Bob', $crmLead->contact->name);
    }

    public function test_a_click_to_whatsapp_referral_reuses_the_existing_contact(): void
    {
        $account = $this->ctwaTenant();
        $existing = Contact::factory()->forAccount($account)->create(['phone_number' => '919111111111']);

        $this->ctwa('wamid.CTWA-2')->assertOk();

        $this->assertDatabaseCount('contacts', 1);
        $this->assertSame($existing->id, CrmLead::firstOrFail()->contact_id);
    }

    public function test_an_ordinary_inbound_message_creates_no_crm_lead(): void
    {
        $this->ctwaTenant();
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'waba-id',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '919999999999', 'phone_number_id' => self::PHONE_ID],
                        'contacts' => [['profile' => ['name' => 'Bob'], 'wa_id' => '919111111111']],
                        'messages' => [[
                            'from' => '919111111111', 'id' => 'wamid.PLAIN', 'timestamp' => '1700000000',
                            'type' => 'text', 'text' => ['body' => 'hello'],
                        ]],
                    ],
                ]],
            ]],
        ];

        $this->signedPost('/api/webhooks/meta', $payload)->assertOk();

        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseCount('crm_leads', 0);
    }

    // =================================================================
    // 3. Duplicates / idempotency
    // =================================================================

    public function test_a_redelivered_leadgen_event_creates_nothing_new(): void
    {
        $this->adsTenant();

        $this->leadgen('LG-200')->assertOk();
        $callsAfterFirst = count(Http::recorded());
        $this->leadgen('LG-200')->assertOk();
        $this->leadgen('LG-200')->assertOk();

        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseCount('crm_leads', 1);
        $this->assertDatabaseCount('contacts', 1);
        // No Graph fetch or send on a redelivery: the existing guard still exits first.
        $this->assertCount($callsAfterFirst, Http::recorded());
        $this->assertSame(1, Http::recorded()->filter(fn ($pair) => str_contains($pair[0]->url(), 'graph.facebook.com/v18.0/LG-200'))->count());
    }

    public function test_a_redelivered_ctwa_message_creates_nothing_new(): void
    {
        $this->ctwaTenant();

        $this->ctwa('wamid.CTWA-DUP')->assertOk();
        $firstCrmLeadId = CrmLead::firstOrFail()->id;
        $this->ctwa('wamid.CTWA-DUP')->assertOk();
        $this->ctwa('wamid.CTWA-DUP')->assertOk();

        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseCount('crm_leads', 1);
        $this->assertSame($firstCrmLeadId, CrmLead::firstOrFail()->id);
        $this->assertDatabaseCount('crm_capture_link_failures', 0);
    }

    public function test_the_same_phone_within_24_hours_is_still_collapsed_by_the_ads_flow(): void
    {
        $this->adsTenant();

        $this->leadgen('LG-201')->assertOk();
        $this->leadgen('LG-202')->assertOk(); // same phone (Http fake), new leadgen id

        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseCount('crm_leads', 1);
    }

    public function test_an_already_promoted_capture_returns_its_existing_crm_lead(): void
    {
        $t = $this->adsTenant();
        $this->leadgen('LG-203')->assertOk();
        $capture = Lead::firstOrFail();
        $existing = CrmLead::firstOrFail();
        $linker = app(CaptureLeadLinker::class);

        $this->assertSame($existing->id, $linker->linkQuietly($capture->fresh())->id);
        $this->assertSame($existing->id, $linker->link($capture->fresh())->id);

        // Even after CRM is revoked, the existing projection is returned (a read) and nothing is written.
        AccountEntitlement::where('account_id', $t['account']->id)->update(['revoked_at' => now()]);
        $this->assertSame($existing->id, $linker->linkQuietly($capture->fresh())->id);

        $this->assertDatabaseCount('crm_leads', 1);
        $this->assertDatabaseCount('crm_capture_link_failures', 0);
    }

    public function test_a_concurrent_duplicate_promotion_returns_the_winner_instead_of_failing(): void
    {
        $t = $this->adsTenant();
        $capture = Lead::create([
            'account_id' => $t['account']->id,
            'provider' => 'meta',
            'provider_lead_id' => 'LG-RACE',
            'lead_phone' => '919876543210',
        ]);
        $winner = app(CaptureLeadLinker::class)->link($capture);

        // Simulates the losing request: both of its existence checks
        // (linkQuietly's and link's) ran before the winner committed, so it
        // goes on to INSERT and hits unique(capture_lead_id).
        $loser = new class(app(ContactResolver::class), app(AccessControlService::class)) extends CaptureLeadLinker {
            public int $calls = 0;

            public function existingCrmLeadFor(Lead $lead): ?CrmLead
            {
                return ++$this->calls <= 2 ? null : parent::existingCrmLeadFor($lead);
            }
        };

        $result = $loser->linkQuietly($capture->fresh());

        $this->assertSame(3, $loser->calls, 'the INSERT was attempted and the winner re-read');
        $this->assertNotNull($result);
        $this->assertSame($winner->id, $result->id);
        $this->assertDatabaseCount('crm_leads', 1);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('crm_capture_link_failures', 0);
    }

    // =================================================================
    // 4. Tenant isolation
    // =================================================================

    public function test_a_lead_is_attributed_only_to_the_tenant_that_owns_the_page(): void
    {
        $mine = $this->adsTenant();
        $theirs = $this->adsTenant(true, self::OTHER_PAGE_ID);

        $this->leadgen('LG-300', self::OTHER_PAGE_ID)->assertOk();

        $crmLead = CrmLead::firstOrFail();
        $this->assertSame($theirs['account']->id, $crmLead->account_id);
        $this->assertSame($theirs['account']->id, $crmLead->contact->account_id);
        $this->assertSame(0, CrmLead::where('account_id', $mine['account']->id)->count());

        $me = $this->user($mine['account']);
        $this->actingAs($me)->getJson(self::LEADS)->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($me)->getJson(self::LEADS.'/'.$crmLead->id)->assertNotFound();
        $this->actingAs($me)->patchJson(self::LEADS.'/'.$crmLead->id.'/status', ['status' => 'contacted'])->assertNotFound();
    }

    public function test_a_ctwa_wamid_owned_by_another_tenant_never_creates_a_crm_lead_for_the_claimant(): void
    {
        $mine = $this->ctwaTenant();
        $theirs = $this->ctwaTenant(true, self::OTHER_PHONE_ID);

        $this->ctwa('wamid.SHARED', '919222222222', self::OTHER_PHONE_ID)->assertOk();
        $theirLead = CrmLead::firstOrFail();

        // The same WAMID now arrives on MY number (the Task 9 re-parenting guard refuses it).
        $this->ctwa('wamid.SHARED', '919222222222', self::PHONE_ID)->assertOk();

        $this->assertSame(0, CrmLead::where('account_id', $mine->id)->count());
        $this->assertSame(0, Contact::where('account_id', $mine->id)->count());
        $this->assertSame($theirs->id, $theirLead->fresh()->account_id);
        $this->assertDatabaseCount('crm_leads', 1);
    }

    public function test_the_crm_projection_always_uses_the_capture_rows_account(): void
    {
        $a = $this->adsTenant();
        $b = $this->adsTenant(true, self::OTHER_PAGE_ID);
        $capture = Lead::create([
            'account_id' => $b['account']->id,
            'provider' => 'meta',
            'provider_lead_id' => 'LG-B',
            'lead_phone' => '919876543210',
        ]);

        $crmLead = app(CaptureLeadLinker::class)->linkQuietly($capture);

        $this->assertSame($b['account']->id, $crmLead->account_id);
        $this->assertSame($b['account']->id, $crmLead->contact->account_id);
        $this->assertSame(0, Contact::where('account_id', $a['account']->id)->count());
    }

    // =================================================================
    // 5. Authorization: webhook authenticity, RBAC, module, capability
    // =================================================================

    public function test_an_unsigned_or_forged_leadgen_webhook_creates_nothing(): void
    {
        $this->adsTenant();
        $payload = ['object' => 'page', 'entry' => [[
            'id' => self::PAGE_ID,
            'changes' => [['field' => 'leadgen', 'value' => ['leadgen_id' => 'LG-X', 'page_id' => self::PAGE_ID]]],
        ]]];

        $this->postJson('/api/social/webhook/meta', $payload)->assertStatus(403);
        $this->signedPost('/api/social/webhook/meta', $payload, 'wrong-secret')->assertStatus(403);

        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseCount('crm_leads', 0);
        $this->assertDatabaseCount('contacts', 0);
    }

    public function test_a_forged_ctwa_webhook_creates_nothing(): void
    {
        $this->ctwaTenant();
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => []]);

        $this->call('POST', '/api/webhooks/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'wrong'),
        ], $body);

        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseCount('crm_leads', 0);
    }

    public function test_reading_promoted_leads_requires_the_existing_crm_guards(): void
    {
        $t = $this->adsTenant();
        $this->leadgen('LG-400')->assertOk();
        $crmLead = CrmLead::firstOrFail();

        // Unauthenticated.
        $this->getJson(self::LEADS)->assertUnauthorized();

        // A member without manage-crm (manage-social-leads alone is not enough).
        $member = User::factory()->create(['account_id' => $t['account']->id, 'is_active' => true]);
        $member->givePermissionTo('manage-social-leads');
        $this->actingAs($member)->getJson(self::LEADS.'/'.$crmLead->id)->assertForbidden();

        // The admin can.
        $this->actingAs($this->user($t['account']))->getJson(self::LEADS.'/'.$crmLead->id)->assertOk();
    }

    public function test_without_the_crm_capability_the_ads_capture_still_works_but_no_crm_lead_is_created(): void
    {
        $t = $this->adsTenant(withCrm: false);

        $this->leadgen('LG-500')->assertOk();

        $capture = Lead::where('provider_lead_id', 'LG-500')->firstOrFail();
        $this->assertSame('919876543210', $capture->lead_phone);
        $this->assertSame('ada@example.com', $capture->lead_email);
        $this->assertNotNull($capture->tenant_notify_error ?? $capture->tenant_notified_at, 'tenant notification still attempted');
        $this->assertNotNull($capture->lead_welcome_error ?? $capture->lead_welcomed_at, 'welcome message still attempted');

        $this->assertDatabaseCount('crm_leads', 0);
        $this->assertDatabaseCount('contacts', 0);
        $failure = CrmCaptureLinkFailure::where('lead_id', $capture->id)->firstOrFail();
        $this->assertSame(CrmCaptureLinkFailure::REASON_NOT_ENTITLED, $failure->reason);
        $this->assertSame($t['account']->id, $failure->account_id);
    }

    public function test_a_revoked_crm_entitlement_stops_promotion(): void
    {
        $t = $this->adsTenant();
        AccountEntitlement::where('account_id', $t['account']->id)->update(['revoked_at' => now()]);

        $this->leadgen('LG-501')->assertOk();

        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseCount('crm_leads', 0);
        $this->assertSame(CrmCaptureLinkFailure::REASON_NOT_ENTITLED, CrmCaptureLinkFailure::firstOrFail()->reason);
    }

    public function test_a_disabled_lead_crm_module_stops_promotion(): void
    {
        $modules = array_values(array_diff(Account::MODULES, ['lead_crm']));
        $this->adsTenant(true, self::PAGE_ID, ['allowed_modules' => $modules]);

        $this->leadgen('LG-502')->assertOk();

        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseCount('crm_leads', 0);
        $this->assertSame(CrmCaptureLinkFailure::REASON_NOT_ENTITLED, CrmCaptureLinkFailure::firstOrFail()->reason);
    }

    public function test_a_ctwa_referral_without_the_crm_capability_captures_but_does_not_promote(): void
    {
        $this->ctwaTenant(withCrm: false);

        $this->ctwa('wamid.NOCRM')->assertOk();

        $this->assertDatabaseHas('leads', ['provider_lead_id' => 'wamid.NOCRM', 'provider' => 'whatsapp_ctwa']);
        $this->assertDatabaseCount('crm_leads', 0);
        $this->assertSame(CrmCaptureLinkFailure::REASON_NOT_ENTITLED, CrmCaptureLinkFailure::firstOrFail()->reason);
    }

    public function test_a_not_entitled_capture_is_promoted_by_retry_after_the_account_is_entitled(): void
    {
        $t = $this->adsTenant(withCrm: false);
        $this->leadgen('LG-503')->assertOk();
        $failure = CrmCaptureLinkFailure::firstOrFail();
        $linker = app(CaptureLeadLinker::class);

        // Still not entitled: the retry records another attempt, creates nothing.
        $this->assertNull($linker->retry($failure));
        $this->assertSame(2, $failure->fresh()->attempts);
        $this->assertDatabaseCount('crm_leads', 0);

        $this->grant($t['account'], 'crm');
        $crmLead = $linker->retry($failure->fresh());

        $this->assertNotNull($crmLead);
        $this->assertSame(CrmLead::SOURCE_META_AD, $crmLead->source);
        $this->assertNotNull($failure->fresh()->resolved_at);
        $this->assertSame($crmLead->id, $failure->fresh()->resolved_crm_lead_id);
    }

    // =================================================================
    // 6. Rollback when CRM creation fails
    // =================================================================

    public function test_a_crm_failure_rolls_back_cleanly_and_never_breaks_the_ads_flow(): void
    {
        $this->adsTenant();
        CrmLead::creating(function (): void {
            throw new RuntimeException('simulated CRM write failure');
        });

        $this->leadgen('LG-600')->assertOk();

        // The capture survives and the rest of the Ads flow still ran.
        $capture = Lead::where('provider_lead_id', 'LG-600')->firstOrFail();
        $this->assertNotNull($capture->tenant_notify_error ?? $capture->tenant_notified_at);
        $this->assertNotNull($capture->lead_welcome_error ?? $capture->lead_welcomed_at);

        // Nothing half-written: the Contact created in the same transaction is gone too.
        $this->assertDatabaseCount('crm_leads', 0);
        $this->assertDatabaseCount('contacts', 0);

        $failure = CrmCaptureLinkFailure::where('lead_id', $capture->id)->firstOrFail();
        $this->assertSame(CrmCaptureLinkFailure::REASON_EXCEPTION, $failure->reason);
        $this->assertStringContainsString('simulated CRM write failure', $failure->message);
    }

    public function test_a_ctwa_crm_failure_still_returns_200_and_keeps_the_capture(): void
    {
        $this->ctwaTenant();
        CrmLead::creating(function (): void {
            throw new RuntimeException('simulated CRM write failure');
        });

        $this->ctwa('wamid.FAIL')->assertOk();

        $this->assertDatabaseHas('leads', ['provider_lead_id' => 'wamid.FAIL']);
        $this->assertDatabaseCount('crm_leads', 0);
        $this->assertDatabaseCount('contacts', 0);
        $this->assertSame(CrmCaptureLinkFailure::REASON_EXCEPTION, CrmCaptureLinkFailure::firstOrFail()->reason);
    }

    // =================================================================
    // 7. The existing Ads flow is unchanged
    // =================================================================

    public function test_the_capture_row_is_written_exactly_as_before(): void
    {
        $t = $this->adsTenant();

        $this->leadgen('LG-700', self::PAGE_ID, 'AD-77')->assertOk();

        $this->assertDatabaseHas('leads', [
            'account_id' => $t['account']->id,
            'social_account_id' => $t['page']->id,
            'provider' => 'meta',
            'provider_lead_id' => 'LG-700',
            'form_id' => 'FORM-1',
            'ad_id' => 'AD-77',
            'lead_name' => 'Ada Lovelace',
            'lead_phone' => '919876543210',
            'lead_email' => 'ada@example.com',
        ]);
        $this->assertSame(self::fieldData('+91 98765 43210'), Lead::firstOrFail()->raw_field_data);
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('leads', 'crm_lead_id'), 'leads gains no CRM column');
    }

    public function test_the_instant_lead_screen_still_lists_the_capture(): void
    {
        $t = $this->adsTenant();
        $this->leadgen('LG-701')->assertOk();

        $this->actingAs($this->user($t['account']))
            ->getJson('/api/social/leads')
            ->assertOk()
            ->assertJsonPath('data.0.lead_phone', '919876543210')
            ->assertJsonPath('data.0.ad_id', 'AD-1');
    }

    public function test_an_unknown_page_is_still_dropped_without_any_crm_write(): void
    {
        $this->adsTenant();

        $this->leadgen('LG-702', '999999999')->assertOk();

        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseCount('crm_leads', 0);
        $this->assertDatabaseCount('crm_capture_link_failures', 0);
    }

    // =================================================================
    // 8. Plan / entitlement wiring
    // =================================================================

    private function subscribe(Account $account, string $planKey): void
    {
        $plan = PlanCatalog::find($planKey);
        $invoice = Invoice::create([
            'account_id' => $account->id,
            'invoice_number' => 'INV-'.uniqid(),
            'plan_key' => $planKey,
            'plan_label' => $plan['label'],
            'amount' => $plan['price'],
            'tax_amount' => 0,
            'total_amount' => $plan['price'],
            'currency' => 'INR',
            'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.uniqid(),
            'status' => 'pending',
        ]);

        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());
    }

    public function test_the_business_plan_grants_both_ads_and_crm_so_its_captures_are_promoted(): void
    {
        $account = Account::factory()->create();
        $this->subscribe($account, 'business');
        SocialAccount::create([
            'account_id' => $account->id, 'provider' => 'meta', 'asset_type' => 'facebook_page',
            'provider_id' => self::PAGE_ID, 'name' => 'P', 'access_token' => 'T',
        ]);

        $access = app(AccessControlService::class);
        $this->assertTrue($access->canTenant($account->fresh(), 'crm'));
        $this->assertTrue($access->canTenant($account->fresh(), 'ads'));

        $this->leadgen('LG-800')->assertOk();

        $this->assertSame(CrmLead::SOURCE_META_AD, CrmLead::firstOrFail()->source);
    }

    public function test_the_starter_plan_has_no_crm_so_its_captures_are_not_promoted(): void
    {
        $account = Account::factory()->create();
        $this->subscribe($account, 'starter');
        SocialAccount::create([
            'account_id' => $account->id, 'provider' => 'meta', 'asset_type' => 'facebook_page',
            'provider_id' => self::PAGE_ID, 'name' => 'P', 'access_token' => 'T',
        ]);

        $this->assertFalse(app(AccessControlService::class)->canTenant($account->fresh(), 'crm'));

        $this->leadgen('LG-801')->assertOk();

        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseCount('crm_leads', 0);
        $this->assertSame(CrmCaptureLinkFailure::REASON_NOT_ENTITLED, CrmCaptureLinkFailure::firstOrFail()->reason);
    }

    // =================================================================
    // 9. Scope boundary
    // =================================================================

    public function test_no_new_route_or_permission_or_capability_was_added(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())->map->uri();

        $this->assertSame(0, $uris->filter(fn (string $u) => str_contains($u, 'capture'))->count(), 'no capture/failure API');
        $this->assertSame(0, $uris->filter(fn (string $u) => str_starts_with($u, 'api/v1/') && str_contains($u, 'meta'))->count(), 'no Developer API surface');
        $this->assertNull(Capability::where('slug', 'meta_ads_crm')->first());
        $this->assertSame(['crm', 'ads'], Capability::whereIn('slug', ['crm', 'ads'])->orderByDesc('slug')->pluck('slug')->all());
    }
}
