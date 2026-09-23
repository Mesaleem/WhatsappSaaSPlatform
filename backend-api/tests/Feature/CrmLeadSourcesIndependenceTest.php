<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\Contact;
use App\Models\CrmLead;
use App\Models\Lead;
use App\Models\SocialAccount;
use App\Models\SocialProviderConfig;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppSession;
use App\Services\Crm\PlatformCrmAccount;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CRM — manual and automated lead creation are independent.
 *
 *   Manual   POST /api/crm/leads            authorization = user + tenant + CRM gates; source defaults to `manual`
 *   Meta Lead Ads  (leadgen webhook)        source `meta_ad`   (CaptureLeadLinker)
 *   Click-to-WhatsApp (messages webhook)    source `meta_ad`   (CaptureLeadLinker, Task 10)
 *   Journey save_lead (messages webhook)    source `journey`   (CaptureLeadLinker)
 *
 * Source describes origin only; it never decides who may create a lead.
 * Every automated path here is driven through the real signed webhook.
 */
class CrmLeadSourcesIndependenceTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'meta_app_secret_sources_test';

    private const PAGE_ID = '880000111';

    private const PHONE_ID = '109800000000001';

    private const URL = '/api/crm/leads';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        SocialProviderConfig::create(['provider' => 'meta', 'client_id' => 'app', 'client_secret' => self::SECRET, 'is_active' => true]);
        Http::fake([
            'graph.facebook.com/*' => Http::response(['field_data' => [
                ['name' => 'full_name', 'values' => ['Ada Lovelace']],
                ['name' => 'phone_number', 'values' => ['+91 98765 43210']],
            ]], 200),
        ]);
    }

    // --- fixtures --------------------------------------------------------

    private function account(bool $crm = true, ?callable $factory = null): Account
    {
        $account = ($factory ? $factory(Account::factory()) : Account::factory())->create();
        Subscription::factory()->create(['account_id' => $account->id]);
        if ($crm) {
            AccountEntitlement::firstOrCreate(
                ['account_id' => $account->id, 'capability_id' => Capability::where('slug', 'crm')->firstOrFail()->id],
                ['source' => 'manual_grant', 'granted_by_account_id' => null],
            );
        }

        return $account->fresh();
    }

    private function user(Account $account, ?string $role = 'admin', array $permissions = []): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        if ($role) {
            $user->assignRole($role);
        }
        foreach ($permissions as $p) {
            $user->givePermissionTo($p);
        }

        return $user;
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function connectMeta(Account $account): void
    {
        SocialAccount::create(['account_id' => $account->id, 'provider' => 'meta', 'asset_type' => 'facebook_page', 'provider_id' => self::PAGE_ID, 'name' => 'P', 'access_token' => 'T']);
        WhatsAppSession::create([
            'account_id' => $account->id, 'status' => 'connected', 'meta_phone_number_id' => self::PHONE_ID,
            'meta_waba_id' => '123456789012345', 'meta_access_token' => 'EAAG', 'meta_webhook_verify_token' => 'V'.uniqid(),
        ]);
    }

    private function signed(string $uri, array $payload)
    {
        $body = json_encode($payload);

        return $this->call('POST', $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::SECRET),
        ], $body);
    }

    private function leadgen(string $id)
    {
        return $this->signed('/api/social/webhook/meta', ['object' => 'page', 'entry' => [[
            'id' => self::PAGE_ID,
            'changes' => [['field' => 'leadgen', 'value' => ['leadgen_id' => $id, 'page_id' => self::PAGE_ID, 'form_id' => 'F', 'ad_id' => 'A']]],
        ]]]);
    }

    private function inbound(string $wamid, string $text, string $from = '919876543210', bool $referral = false)
    {
        $message = ['from' => $from, 'id' => $wamid, 'timestamp' => '1700000000', 'type' => 'text', 'text' => ['body' => $text]];
        if ($referral) {
            $message['referral'] = ['source_id' => 'AD-CTWA', 'source_type' => 'ad'];
        }

        return $this->signed('/api/webhooks/meta', ['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => 'waba', 'changes' => [['field' => 'messages', 'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => ['display_phone_number' => '919999999999', 'phone_number_id' => self::PHONE_ID],
                'contacts' => [['profile' => ['name' => 'Bob'], 'wa_id' => $from]],
                'messages' => [$message],
            ]]],
        ]]]);
    }

    private function journey(Account $account): WhatsAppFlow
    {
        // Phase 7 Task 1.6 — a journey only runs for an account entitled to journey_automation.
        AccountEntitlement::firstOrCreate(
            ['account_id' => $account->id, 'capability_id' => Capability::where('slug', 'journey_automation')->firstOrFail()->id],
            ['source' => 'manual_grant', 'granted_by_account_id' => null],
        );

        return WhatsAppFlow::create([
            'account_id' => $account->id, 'name' => 'Capture', 'trigger_type' => 'keyword', 'trigger_value' => 'join', 'is_active' => true,
            'graph_data' => [
                'nodes' => [
                    ['id' => 't', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
                    ['id' => 's', 'type' => 'save_lead', 'position' => ['x' => 200, 'y' => 0], 'data' => []],
                ],
                'edges' => [['id' => 'e', 'source' => 't', 'target' => 's']],
            ],
        ]);
    }

    private function manual(User $user, array $body = [], string $query = '')
    {
        return $this->actingAs($user)->postJson(self::URL.$query, $body + ['phone_number' => '9876543210', 'name' => 'Manual Entry']);
    }

    // --- 1–9 manual ---------------------------------------------------------

    public function test_every_crm_capable_role_can_create_manually_for_its_permitted_target(): void
    {
        app(PlatformCrmAccount::class)->ensure();
        $client = $this->account();
        $agent = $this->account(factory: fn ($f) => $f->agent());
        $sub = $this->account(factory: fn ($f) => $f->client($agent));
        $agentUser = $this->user($agent, null, ['manage-crm']);
        $sa = $this->superAdmin();

        $cases = [
            'super admin, own account' => [$sa, '', app(PlatformCrmAccount::class)->find()->id],
            'super admin, selected client' => [$sa, '?account_id='.$client->id, $client->id],
            'agent, own account' => [$agentUser, '', $agent->id],
            'agent, own sub-client' => [$agentUser, '?account_id='.$sub->id, $sub->id],
            'client admin, own account' => [$this->user($client), '', $client->id],
            'social marketer, own account' => [$this->user($client, 'social_marketer'), '', $client->id],
            'custom user with manage-crm only' => [$this->user($client, null, ['manage-crm']), '', $client->id],
        ];

        foreach ($cases as $label => [$user, $query, $expectedAccount]) {
            $r = $this->manual($user, [], $query)->assertCreated();
            $lead = CrmLead::findOrFail($r->json('data.id'));
            $this->assertSame($expectedAccount, $lead->account_id, $label);
            $this->assertSame(CrmLead::SOURCE_MANUAL, $lead->source, $label);
            $this->assertNull($lead->capture_lead_id, $label);
        }
    }

    public function test_manual_creation_is_refused_without_permission_capability_module_or_scope(): void
    {
        $client = $this->account();
        $noCrm = $this->account(crm: false);
        $moduleOff = Account::factory()->create(['allowed_modules' => array_values(array_diff(Account::MODULES, ['lead_crm']))]);
        Subscription::factory()->create(['account_id' => $moduleOff->id]);
        $agent = $this->account(factory: fn ($f) => $f->agent());
        $otherAgent = $this->account(factory: fn ($f) => $f->agent());
        $othersSub = $this->account(factory: fn ($f) => $f->client($otherAgent));

        $this->manual($this->user($client, 'user'))->assertForbidden();
        $this->manual($this->user($noCrm))->assertStatus(403)->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
        $this->manual($this->user($moduleOff))->assertStatus(403)->assertJsonPath('error_code', 'MODULE_DISABLED');
        $this->manual($this->user($agent, null, ['manage-crm']), [], '?account_id='.$othersSub->id)->assertNotFound();
        $this->manual($this->superAdmin(), [], '?account_id='.$noCrm->id)->assertStatus(403);

        $this->assertDatabaseCount('crm_leads', 0);
    }

    public function test_manual_creation_needs_no_meta_social_or_whatsapp_connection(): void
    {
        $client = $this->account();
        $this->assertSame(0, SocialAccount::count() + WhatsAppSession::count());

        $this->manual($this->user($client))->assertCreated();
    }

    public function test_source_labels_a_manual_lead_but_never_gates_it(): void
    {
        $client = $this->account();
        $admin = $this->user($client);

        // Manual creation is always source=manual; no other origin can be claimed.
        $this->assertSame('manual', $this->manual($admin)->assertCreated()->json('data.source'));
        $this->manual($admin, ['source' => 'whatsapp', 'phone_number' => '9811111111'])->assertStatus(422)->assertJsonValidationErrors(['source']);
        $this->manual($admin, ['source' => 'tiktok'])->assertStatus(422)->assertJsonValidationErrors(['source']);
        // A source never grants what the CRM gates deny.
        $this->manual($this->user($client, 'user'), ['source' => 'meta_ad'])->assertForbidden();
    }

    // --- 10–14 automated ------------------------------------------------------

    public function test_meta_lead_ads_ctwa_and_journey_each_create_their_crm_lead_with_their_own_source(): void
    {
        $account = $this->account();
        $this->connectMeta($account);
        $this->journey($account);

        $this->leadgen('LG-1')->assertOk();
        $this->inbound('wamid.CTWA1', 'Hi from the ad', '919811100001', referral: true)->assertOk();
        $this->inbound('wamid.J1', 'join', '919811100002')->assertOk();

        $bySource = CrmLead::where('account_id', $account->id)->with('captureLead')->get()
            ->mapWithKeys(fn (CrmLead $l) => [$l->captureLead->provider => $l->source])->all();

        $this->assertSame(['meta' => 'meta_ad', 'whatsapp_ctwa' => 'meta_ad', 'whatsapp_journey' => 'journey'], collect($bySource)->sortKeys()->all());
        $this->assertSame(3, Lead::where('account_id', $account->id)->count());
    }

    public function test_redeliveries_still_create_nothing_new(): void
    {
        $account = $this->account();
        $this->connectMeta($account);
        $this->journey($account);

        foreach (range(1, 3) as $_) {
            $this->leadgen('LG-DUP')->assertOk();
            $this->inbound('wamid.CTWA-DUP', 'Hi', '919811100003', referral: true)->assertOk();
        }

        $this->assertSame(2, Lead::count());
        $this->assertSame(2, CrmLead::count());
    }

    // --- 15–17 separation -----------------------------------------------------

    public function test_manual_and_automated_leads_coexist_on_one_contact_without_interfering(): void
    {
        $account = $this->account();
        $this->connectMeta($account);
        $admin = $this->user($account);

        // Manual first, then the same person arrives from a Meta Lead Ads form.
        $manual = CrmLead::findOrFail($this->manual($admin)->assertCreated()->json('data.id'));
        $this->leadgen('LG-2')->assertOk();
        $captured = Lead::where('provider_lead_id', 'LG-2')->firstOrFail()->crmLead;

        $this->assertNotNull($captured, 'the manual lead must not block automated capture');
        $this->assertNotSame($manual->id, $captured->id);
        $this->assertSame($manual->contact_id, $captured->contact_id, 'same person, same Contact');
        $this->assertSame(['manual', 'meta_ad'], [$manual->fresh()->source, $captured->source]);
        $this->assertNull($manual->fresh()->capture_lead_id, 'the manual lead is not re-linked to the capture');
        $this->assertSame(1, Contact::where('account_id', $account->id)->count());

        // And manual creation after capture still works and stays manual.
        $again = $this->manual($admin, ['phone_number' => '+91 98765 43210'])->assertCreated();
        $this->assertSame('manual', $again->json('data.source'));
        $this->assertSame($manual->contact_id, $again->json('data.contact.id'));
        $this->assertSame(3, CrmLead::where('account_id', $account->id)->count());
    }

    public function test_capture_rows_are_untouched_by_manual_creation(): void
    {
        $account = $this->account();
        $this->connectMeta($account);
        $this->leadgen('LG-3')->assertOk();
        $before = Lead::firstOrFail()->getAttributes();

        $this->manual($this->user($account))->assertCreated();

        $this->assertSame($before, Lead::firstOrFail()->getAttributes());
        $this->assertSame(1, Lead::count(), 'a manual lead never writes a capture row');
    }
}
