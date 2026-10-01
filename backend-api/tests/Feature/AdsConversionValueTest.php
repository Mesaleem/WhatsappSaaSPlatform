<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\ActivityLog;
use App\Models\AdAttribution;
use App\Models\AdCampaign;
use App\Models\AdCampaignDailyMetric;
use App\Models\Capability;
use App\Models\Contact;
use App\Models\CrmLead;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 10 Task 5 — conversion value foundation + Ads reporting on top of it.
 * The value belongs to the attribution row holding the lead's conversion credit;
 * unknown is NULL, 0 is a real value, nothing is ever derived from spend.
 */
class AdsConversionValueTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        Http::fake();
    }

    // ------------------------------------------------------------------ fixtures

    private function tenant(array $grants = ['crm', 'ads']): Account
    {
        $account = Account::factory()->create();
        Subscription::factory()->create(['account_id' => $account->id]);
        foreach ($grants as $slug) {
            AccountEntitlement::firstOrCreate(
                ['account_id' => $account->id, 'capability_id' => Capability::where('slug', $slug)->value('id')],
                ['source' => 'manual_grant'],
            );
        }

        return $account->fresh();
    }

    private function user(Account $account, string $role = 'admin'): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function roleUser(Account $account, string $name, array $permissions): User
    {
        Role::firstOrCreate(['name' => $name, 'guard_name' => 'web'])->syncPermissions($permissions);

        return $this->user($account, $name);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function campaign(Account $account, string $name = 'C', array $attributes = []): AdCampaign
    {
        $n = ++$this->seq;

        return AdCampaign::create($attributes + [
            'account_id' => $account->id, 'meta_campaign_id' => "CMP-{$n}", 'meta_adset_id' => "SET-{$n}", 'meta_ad_id' => "AD-{$n}",
            'name' => $name, 'objective' => 'CLICK_TO_WHATSAPP', 'status' => 'ACTIVE', 'daily_budget' => 10, 'currency' => 'INR',
        ]);
    }

    private function spend(AdCampaign $campaign, float $spend, ?string $date = null): void
    {
        AdCampaignDailyMetric::create([
            'account_id' => $campaign->account_id, 'ad_campaign_id' => $campaign->id, 'metric_date' => $date ?? now()->subDay()->toDateString(),
            'spend' => $spend, 'impressions' => 1000, 'leads' => 0, 'cpl' => null,
        ]);
    }

    /** A lead + its attribution row (not converted yet). */
    private function attributed(Account $account, ?AdCampaign $campaign = null, array $row = [], string $source = CrmLead::SOURCE_MANUAL): array
    {
        $n = ++$this->seq;
        $lead = CrmLead::factory()->forContact(Contact::factory()->forAccount($account)->create(['phone_number' => '91990000'.str_pad((string) $n, 4, '0', STR_PAD_LEFT)]))->create(['source' => $source]);
        $attribution = AdAttribution::create($row + [
            'account_id' => $account->id, 'provider' => 'meta', 'channel' => 'whatsapp_ctwa', 'source_type' => 'ad',
            'source_id' => $campaign?->meta_ad_id ?? 'AD-X', 'referral_message_id' => "wamid.{$n}", 'contact_phone' => '9199'.$n,
            'ad_campaign_id' => $campaign?->id, 'crm_lead_id' => $lead->id, 'referral_received_at' => now()->subDays(2),
        ]);

        return [$lead, $attribution];
    }

    private function convert(CrmLead $lead): void
    {
        $lead->update(['status' => CrmLead::STATUS_CONVERTED, 'converted_at' => now()]);
    }

    private function revert(CrmLead $lead): void
    {
        $lead->update(['status' => CrmLead::STATUS_CONTACTED, 'converted_at' => null]);
    }

    private function setValue(User $actor, CrmLead $lead, array $body, string $query = '')
    {
        return $this->actingAs($actor)->patchJson("/api/crm/leads/{$lead->id}/conversion-value{$query}", $body);
    }

    private function dashboard(User $actor, string $query = '')
    {
        return $this->actingAs($actor)->getJson('/api/social/ads/dashboard'.$query);
    }

    // ================================================================== manual value path

    public function test_a_converted_manual_lead_gets_a_value_without_changing_its_source_or_conversion_time(): void
    {
        $account = $this->tenant();
        [$lead, $row] = $this->attributed($account, source: CrmLead::SOURCE_MANUAL);
        $this->convert($lead);
        $stamp = $row->fresh()->converted_at;
        $user = $this->user($account);

        $this->setValue($user, $lead, ['value' => 1500.5, 'currency' => 'inr'])->assertOk()
            ->assertJsonPath('changed', true)->assertJsonPath('data.conversion_value', 1500.5)->assertJsonPath('data.conversion_currency', 'INR')
            ->assertJsonPath('data.source', 'manual');

        $row = $row->fresh();
        $this->assertSame('1500.50', $row->conversion_value);
        $this->assertSame('INR', $row->conversion_currency);
        $this->assertEquals($stamp, $row->converted_at, 'the conversion time is untouched');
        $this->assertSame(CrmLead::SOURCE_MANUAL, $lead->fresh()->source, 'a value never changes the CRM source');

        $this->setValue($user, $lead, ['value' => 2000, 'currency' => 'INR'])->assertOk()->assertJsonPath('changed', true);
        $this->assertSame('2000.00', $row->fresh()->conversion_value);
        $this->actingAs($user)->getJson("/api/crm/leads/{$lead->id}/conversion-value")->assertOk()
            ->assertJsonPath('data.conversion_value', 2000)->assertJsonPath('data.converted', true)->assertJsonPath('data.attributed', true);
    }

    public function test_an_ad_captured_lead_and_a_manual_lead_use_the_same_rules_and_keep_their_sources(): void
    {
        $account = $this->tenant();
        [$ad, $adRow] = $this->attributed($account, source: CrmLead::SOURCE_META_AD);
        [$manual, $manualRow] = $this->attributed($account, source: CrmLead::SOURCE_MANUAL);
        $this->convert($ad);
        $this->convert($manual);
        $user = $this->user($account);

        $this->setValue($user, $ad, ['value' => 10, 'currency' => 'INR'])->assertOk();
        $this->setValue($user, $manual, ['value' => 20, 'currency' => 'INR'])->assertOk();

        $this->assertSame([CrmLead::SOURCE_META_AD, CrmLead::SOURCE_MANUAL], [$ad->fresh()->source, $manual->fresh()->source]);
        $this->assertSame(['10.00', '20.00'], [$adRow->fresh()->conversion_value, $manualRow->fresh()->conversion_value]);
    }

    public function test_zero_is_a_real_value_and_unknown_stays_null(): void
    {
        $account = $this->tenant();
        $campaign = $this->campaign($account);
        $this->spend($campaign, 50);
        [$lead, $row] = $this->attributed($account, $campaign);
        $this->convert($lead);
        $user = $this->user($account);

        $this->assertNull($row->fresh()->conversion_value, 'unknown until recorded');
        $this->dashboard($user)->assertOk()->assertJsonPath('data.attribution.conversion_value.value', null)->assertJsonPath('data.attribution.valued_conversions', 0);

        $this->setValue($user, $lead, ['value' => 0, 'currency' => 'INR'])->assertOk()->assertJsonPath('data.conversion_value', 0);
        $this->assertNotNull($row->fresh()->conversion_value);
        $this->assertSame(0.0, (float) $row->fresh()->conversion_value);
        $this->dashboard($user)->assertOk()
            ->assertJsonPath('data.attribution.conversion_value.value', 0)->assertJsonPath('data.attribution.conversion_value.status', 'available')
            ->assertJsonPath('data.attribution.valued_conversions', 1)->assertJsonPath('data.attribution.roas.value', 0)
            ->assertJsonPath('data.campaigns.0.conversion_value', 0)->assertJsonPath('data.campaigns.0.roas', 0);

        $this->setValue($user, $lead, ['value' => null])->assertOk()->assertJsonPath('data.conversion_value', null)->assertJsonPath('data.conversion_currency', null);
        $this->assertNull($row->fresh()->conversion_value);
        $this->dashboard($user)->assertOk()->assertJsonPath('data.attribution.conversion_value.value', null)->assertJsonPath('data.attribution.roas.value', null);
    }

    public function test_currency_and_value_validation(): void
    {
        $account = $this->tenant();
        [$lead, $row] = $this->attributed($account);
        $this->convert($lead);
        $user = $this->user($account);

        $this->setValue($user, $lead, ['value' => 10])->assertStatus(422)->assertJsonValidationErrors('currency');
        $this->setValue($user, $lead, ['currency' => 'INR'])->assertStatus(422)->assertJsonValidationErrors('value');
        $this->setValue($user, $lead, ['value' => 10, 'currency' => 'RUPEE'])->assertStatus(422)->assertJsonValidationErrors('currency');
        $this->setValue($user, $lead, ['value' => 10, 'currency' => 'I1R'])->assertStatus(422)->assertJsonValidationErrors('currency');
        $this->setValue($user, $lead, ['value' => -1, 'currency' => 'INR'])->assertStatus(422)->assertJsonValidationErrors('value');
        $this->setValue($user, $lead, ['value' => 'abc', 'currency' => 'INR'])->assertStatus(422)->assertJsonValidationErrors('value');
        $this->setValue($user, $lead, ['value' => 10.123, 'currency' => 'INR'])->assertStatus(422)->assertJsonValidationErrors('value');
        $this->setValue($user, $lead, ['value' => 1e12, 'currency' => 'INR'])->assertStatus(422)->assertJsonValidationErrors('value');
        $this->assertNull($row->fresh()->conversion_value);

        // Clearing ignores a stray currency: no orphan currency is ever stored.
        $this->setValue($user, $lead, ['value' => 5, 'currency' => 'USD'])->assertOk();
        $this->setValue($user, $lead, ['value' => null, 'currency' => 'USD'])->assertOk();
        $this->assertNull($row->fresh()->conversion_currency);
    }

    public function test_repeating_the_same_update_is_a_no_op_and_each_real_change_is_audited_once(): void
    {
        $account = $this->tenant();
        [$lead, $row] = $this->attributed($account);
        $this->convert($lead);
        $user = $this->user($account);
        // LogsActivity skips console runs (every PHPUnit run) — lift that guard for this test only.
        $console = new \ReflectionProperty($this->app, 'isRunningInConsole');
        $console->setValue($this->app, false);
        $this->beforeApplicationDestroyed(fn () => $console->setValue($this->app, true));
        $audit = fn () => ActivityLog::query()->where('module_name', 'Ads Attribution')->where('action_type', 'update')->count();
        $before = $audit();

        $this->setValue($user, $lead, ['value' => 99, 'currency' => 'INR'])->assertOk()->assertJsonPath('changed', true);
        $this->assertSame($before + 1, $audit());
        $log = ActivityLog::query()->where('module_name', 'Ads Attribution')->latest('id')->first();
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($account->id, $log->account_id);
        $this->assertSame('99.00', (string) $log->new_values['conversion_value']);
        $stamp = $row->fresh()->updated_at;

        $this->travel(5)->minutes();
        $this->setValue($user, $lead, ['value' => 99, 'currency' => 'INR'])->assertOk()->assertJsonPath('changed', false)->assertJsonPath('message', 'Conversion value unchanged.');
        $this->setValue($user, $lead, ['value' => '99.00', 'currency' => 'inr'])->assertOk()->assertJsonPath('changed', false);
        $this->assertSame($before + 1, $audit(), 'no phantom audit rows');
        $this->assertEquals($stamp, $row->fresh()->updated_at);
        $this->assertSame(1, AdAttribution::whereNotNull('converted_at')->count(), 'no duplicate conversion');

        $this->setValue($user, $lead, ['value' => 120, 'currency' => 'INR'])->assertOk()->assertJsonPath('changed', true);
        $this->assertSame($before + 2, $audit());
    }

    public function test_only_a_converted_attributed_lead_can_carry_a_value(): void
    {
        $account = $this->tenant();
        $user = $this->user($account);
        [$open] = $this->attributed($account);
        $plain = CrmLead::factory()->forContact(Contact::factory()->forAccount($account)->create(['phone_number' => '919811111111']))->create();

        $this->setValue($user, $open, ['value' => 1, 'currency' => 'INR'])->assertStatus(422)->assertJsonPath('error_code', 'LEAD_NOT_CONVERTED');
        $this->setValue($user, $plain, ['value' => 1, 'currency' => 'INR'])->assertStatus(422)->assertJsonPath('error_code', 'LEAD_NOT_ATTRIBUTED');
        $this->assertSame(0, AdAttribution::whereNotNull('conversion_value')->count());
    }

    public function test_leaving_converted_clears_the_value_and_a_later_conversion_starts_unknown(): void
    {
        $account = $this->tenant();
        [$lead, $row] = $this->attributed($account);
        $this->convert($lead);
        $this->setValue($this->user($account), $lead, ['value' => 500, 'currency' => 'INR'])->assertOk();

        $this->revert($lead);
        $fresh = $row->fresh();
        $this->assertNull($fresh->converted_at);
        $this->assertNull($fresh->conversion_value);
        $this->assertNull($fresh->conversion_currency);

        $this->convert($lead);
        $this->assertNotNull($row->fresh()->converted_at);
        $this->assertNull($row->fresh()->conversion_value, 'a value is never resurrected or invented');
    }

    public function test_with_several_attribution_rows_only_the_first_touch_conversion_carries_the_value(): void
    {
        $account = $this->tenant();
        [$lead, $early] = $this->attributed($account, row: ['referral_received_at' => now()->subDays(5)]);
        $late = AdAttribution::create([
            'account_id' => $account->id, 'provider' => 'meta', 'channel' => 'whatsapp_ctwa', 'source_type' => 'ad', 'source_id' => 'AD-LATE',
            'referral_message_id' => 'wamid.LATE', 'crm_lead_id' => $lead->id, 'referral_received_at' => now()->subDay(),
        ]);
        $this->convert($lead);
        $user = $this->user($account);

        $this->setValue($user, $lead, ['value' => 300, 'currency' => 'INR'])->assertOk()->assertJsonPath('data.attribution_id', $early->id);

        $this->assertSame('300.00', $early->fresh()->conversion_value);
        $this->assertNull($late->fresh()->conversion_value);
        $this->assertNull($late->fresh()->converted_at, 'first-touch policy unchanged');
        $this->dashboard($user)->assertOk()->assertJsonPath('data.attribution.conversions', 1)->assertJsonPath('data.attribution.conversion_value.value', 300);

        $this->setValue($user, $lead, ['value' => 350, 'currency' => 'INR'])->assertOk();
        $this->assertSame('350.00', $early->fresh()->conversion_value);
        $this->assertNull($late->fresh()->conversion_value);
        $this->dashboard($user)->assertOk()->assertJsonPath('data.attribution.conversions', 1)->assertJsonPath('data.attribution.conversion_value.value', 350);
    }

    // ================================================================== tenant isolation & authorization

    public function test_a_value_can_never_cross_tenants(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        [$leadA, $rowA] = $this->attributed($a);
        [$leadB, $rowB] = $this->attributed($b);
        $this->convert($leadA);
        $this->convert($leadB);

        // B's admin addressing A's lead: indistinguishable from a missing lead.
        $this->setValue($this->user($b), $leadA, ['value' => 1, 'currency' => 'INR'])->assertNotFound();
        $this->actingAs($this->user($b))->getJson("/api/crm/leads/{$leadA->id}/conversion-value")->assertNotFound();
        // ... and a spoofed ?account_id= does not re-target a tenant user.
        $this->setValue($this->user($b), $leadA, ['value' => 1, 'currency' => 'INR'], "?account_id={$a->id}")->assertNotFound();
        // A Super Admin working on B cannot reach A's lead either.
        $this->setValue($this->superAdmin(), $leadA, ['value' => 1, 'currency' => 'INR'], "?account_id={$b->id}")->assertNotFound();

        $this->assertNull($rowA->fresh()->conversion_value);

        // A forged cross-tenant link (raw write) is never selected by the service.
        DB::table('ad_attributions')->where('id', $rowB->id)->update(['crm_lead_id' => $leadA->id]);
        $this->setValue($this->user($a), $leadA, ['value' => 7, 'currency' => 'INR'])->assertOk();
        $this->assertSame('7.00', $rowA->fresh()->conversion_value);
        $this->assertNull($rowB->fresh()->conversion_value, "another tenant's attribution is never written");
    }

    public function test_super_admin_must_name_the_client_and_the_client_must_pass_the_ads_checks(): void
    {
        $admin = $this->superAdmin();
        $client = $this->tenant();
        [$lead, $row] = $this->attributed($client);
        $this->convert($lead);
        $this->app['env'] = 'local';

        $this->setValue($admin, $lead, ['value' => 1, 'currency' => 'INR'])->assertStatus(422);
        $this->actingAs($admin)->getJson("/api/crm/leads/{$lead->id}/conversion-value")->assertStatus(422);
        $this->assertNull($row->fresh()->conversion_value);

        $this->setValue($admin, $lead, ['value' => 1, 'currency' => 'INR'], "?account_id={$client->id}")->assertOk();
        $this->assertSame('1.00', $row->fresh()->conversion_value);

        $client->forceFill(['status' => 'suspended'])->save();
        $this->setValue($admin, $lead, ['value' => 2, 'currency' => 'INR'], "?account_id={$client->id}")->assertForbidden();
        $this->assertSame('1.00', $row->fresh()->conversion_value);
    }

    public function test_missing_capabilities_disabled_modules_and_missing_permissions_are_denied(): void
    {
        // No `ads` capability.
        $noAds = $this->tenant(['crm']);
        [$lead, $row] = $this->attributed($noAds);
        $this->convert($lead);
        $this->setValue($this->user($noAds), $lead, ['value' => 1, 'currency' => 'INR'])->assertForbidden()->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');

        // `social` does not grant Ads.
        $social = $this->tenant(['crm', 'social']);
        [$leadS] = $this->attributed($social);
        $this->convert($leadS);
        $this->setValue($this->user($social), $leadS, ['value' => 1, 'currency' => 'INR'])->assertForbidden()->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');

        // No `crm` capability.
        $noCrm = $this->tenant(['ads']);
        [$leadC] = $this->attributed($noCrm);
        $this->setValue($this->user($noCrm), $leadC, ['value' => 1, 'currency' => 'INR'])->assertForbidden();

        // meta_ads module switched off.
        $off = $this->tenant();
        [$leadO, $rowO] = $this->attributed($off);
        $this->convert($leadO);
        $off->forceFill(['allowed_modules' => ['lead_crm']])->save();
        $this->setValue($this->user($off), $leadO, ['value' => 1, 'currency' => 'INR'])->assertForbidden()->assertJsonPath('error_code', 'MODULE_DISABLED');

        // Permissions: CRM only / Ads only are each insufficient; both together work.
        $ok = $this->tenant();
        [$leadP, $rowP] = $this->attributed($ok);
        $this->convert($leadP);
        $this->setValue($this->roleUser($ok, 'crm_only', ['manage-crm']), $leadP, ['value' => 1, 'currency' => 'INR'])->assertForbidden();
        $this->setValue($this->roleUser($ok, 'ads_only', ['launch-meta-ads', 'social_ads.view']), $leadP, ['value' => 1, 'currency' => 'INR'])->assertForbidden();
        $this->setValue($this->roleUser($ok, 'plain', ['view-social-analytics']), $leadP, ['value' => 1, 'currency' => 'INR'])->assertForbidden();
        $this->assertNull($rowP->fresh()->conversion_value);
        $this->setValue($this->roleUser($ok, 'crm_and_ads', ['manage-crm', 'social_ads.view']), $leadP, ['value' => 1, 'currency' => 'INR'])->assertOk();

        foreach ([$row, $rowO] as $untouched) {
            $this->assertNull($untouched->fresh()->conversion_value);
        }
    }

    public function test_an_expired_subscription_blocks_the_tenant_but_the_unauthenticated_are_refused(): void
    {
        $account = $this->tenant();
        [$lead, $row] = $this->attributed($account);
        $this->convert($lead);
        Subscription::where('account_id', $account->id)->update(['expires_at' => now()->subDay()]);

        $this->setValue($this->user($account), $lead, ['value' => 1, 'currency' => 'INR'])->assertForbidden();
        $this->assertNull($row->fresh()->conversion_value);

        $this->assertContains($this->patchJson("/api/crm/leads/{$lead->id}/conversion-value", ['value' => 1, 'currency' => 'INR'])->status(), [401, 403]);
        $this->assertNull($row->fresh()->conversion_value);
    }

    // ================================================================== reporting

    public function test_campaign_reporting_facts_cost_per_conversion_roas_and_no_spend_no_ratio(): void
    {
        $account = $this->tenant();
        $user = $this->user($account);
        $funded = $this->campaign($account, 'Funded');
        $unfunded = $this->campaign($account, 'No spend');
        $this->spend($funded, 100);

        // Funded: 3 leads, 2 conversions, one valued 300, one valued 200.
        [$l1] = $this->attributed($account, $funded);
        [$l2] = $this->attributed($account, $funded);
        $this->attributed($account, $funded);
        $this->convert($l1);
        $this->convert($l2);
        $this->setValue($user, $l1, ['value' => 300, 'currency' => 'INR'])->assertOk();
        $this->setValue($user, $l2, ['value' => 200, 'currency' => 'INR'])->assertOk();
        // Unfunded: a valued conversion but no stored spend.
        [$l3] = $this->attributed($account, $unfunded);
        $this->convert($l3);
        $this->setValue($user, $l3, ['value' => 999, 'currency' => 'INR'])->assertOk();

        $data = $this->dashboard($user)->assertOk()->json('data');
        $rows = collect($data['campaigns'])->keyBy('name');

        $f = $rows['Funded'];
        $this->assertSame(100.0, (float) $f['spend']);
        $this->assertSame(3, $f['leads']);
        $this->assertSame(2, $f['conversions']);
        $this->assertSame(500.0, (float) $f['conversion_value']);
        $this->assertSame(2, $f['valued_conversions']);
        $this->assertSame(33.33, (float) $f['cost_per_lead']);
        $this->assertSame(50.0, (float) $f['cost_per_conversion']);
        $this->assertSame(5.0, (float) $f['roas']);
        $this->assertSame('INR', $f['conversion_value_currency']);
        $this->assertNull($f['conversion_value_issue']);

        $u = $rows['No spend'];
        $this->assertNull($u['spend']);
        $this->assertSame(999.0, (float) $u['conversion_value'], 'the value is a fact even without spend');
        $this->assertNull($u['cost_per_conversion'], 'no spend, no spend-based ratio');
        $this->assertNull($u['roas']);
        $this->assertNull($u['cost_per_lead']);

        // Account level uses launcher campaigns WITH stored spend only.
        $a = $data['attribution'];
        $this->assertSame(50.0, (float) $a['cost_per_conversion']['value']);
        $this->assertSame(5.0, (float) $a['roas']['value']);
        $this->assertSame(1499.0, (float) $a['conversion_value']['value']);
        $this->assertSame(3, $a['valued_conversions']);
        $this->assertSame('INR', $a['conversion_value_currency']);
    }

    public function test_cost_per_conversion_is_not_applicable_without_conversions_and_roas_needs_a_value(): void
    {
        $account = $this->tenant();
        $campaign = $this->campaign($account);
        $this->spend($campaign, 40);
        [$lead] = $this->attributed($account, $campaign);
        $user = $this->user($account);

        $data = $this->dashboard($user)->assertOk()->json('data');
        $this->assertSame(['value' => null, 'status' => 'not_applicable'], $data['attribution']['cost_per_conversion']);
        $this->assertNull($data['campaigns'][0]['cost_per_conversion']);
        $this->assertNull($data['campaigns'][0]['roas']);

        $this->convert($lead); // converted, but no value recorded
        $data = $this->dashboard($user)->assertOk()->json('data');
        $this->assertSame(40.0, (float) $data['attribution']['cost_per_conversion']['value']);
        $this->assertNull($data['attribution']['roas']['value']);
        $this->assertSame('unavailable', $data['attribution']['roas']['status']);
        $this->assertNull($data['attribution']['conversion_value']['value']);
        $this->assertNull($data['campaigns'][0]['conversion_value']);
    }

    public function test_values_in_different_currencies_are_never_added_or_divided_by_spend(): void
    {
        $account = $this->tenant();
        $user = $this->user($account);
        $mixed = $this->campaign($account, 'Mixed');
        $usd = $this->campaign($account, 'Dollar value');
        $this->spend($mixed, 100);
        $this->spend($usd, 100);

        [$a] = $this->attributed($account, $mixed);
        [$b] = $this->attributed($account, $mixed);
        [$c] = $this->attributed($account, $usd);
        foreach ([$a, $b, $c] as $lead) {
            $this->convert($lead);
        }
        $this->setValue($user, $a, ['value' => 100, 'currency' => 'INR'])->assertOk();
        $this->setValue($user, $b, ['value' => 100, 'currency' => 'USD'])->assertOk();
        $this->setValue($user, $c, ['value' => 100, 'currency' => 'USD'])->assertOk();

        $data = $this->dashboard($user)->assertOk()->json('data');
        $rows = collect($data['campaigns'])->keyBy('name');
        $this->assertNull($rows['Mixed']['conversion_value']);
        $this->assertSame('mixed_currency', $rows['Mixed']['conversion_value_issue']);
        $this->assertNull($rows['Mixed']['roas']);
        // Value in USD against INR spend: shown as a fact, never a ratio.
        $this->assertSame(100.0, (float) $rows['Dollar value']['conversion_value']);
        $this->assertSame('currency_mismatch', $rows['Dollar value']['conversion_value_issue']);
        $this->assertNull($rows['Dollar value']['roas']);

        $this->assertNull($data['attribution']['conversion_value']['value']);
        $this->assertSame('mixed_currency', $data['attribution']['conversion_value_issue']);
        $this->assertNull($data['attribution']['roas']['value']);
    }

    public function test_daily_conversions_and_value_are_counted_on_the_day_recorded(): void
    {
        $account = $this->tenant();
        $user = $this->user($account);
        [$l1] = $this->attributed($account);
        [$l2] = $this->attributed($account);
        [$l3] = $this->attributed($account);
        $this->travelTo(now()->subDays(2)->setTime(10, 0));
        $this->convert($l1);
        $this->convert($l2);
        $this->setValue($user, $l1, ['value' => 100, 'currency' => 'INR'])->assertOk();
        $this->setValue($user, $l2, ['value' => 0, 'currency' => 'INR'])->assertOk();
        $day2 = now()->toDateString();
        $this->travelBack();
        $this->convert($l3); // today, no value
        $today = now()->toDateString();

        $series = collect($this->dashboard($user, '?range=7d')->assertOk()->json('data.conversion_daily'))->keyBy('date');

        $this->assertCount(7, $series);
        $this->assertSame(2, $series[$day2]['conversions']);
        $this->assertSame(2, $series[$day2]['valued_conversions']);
        $this->assertSame(100.0, (float) $series[$day2]['conversion_value']);
        $this->assertSame(1, $series[$today]['conversions']);
        $this->assertNull($series[$today]['conversion_value'], 'no valued conversion that day → unknown, not 0');
        $empty = $series->first(fn ($p) => $p['conversions'] === 0);
        $this->assertNotNull($empty);
        $this->assertNull($empty['conversion_value']);
    }

    public function test_a_day_whose_only_recorded_value_is_zero_reports_zero_not_null(): void
    {
        $account = $this->tenant();
        $user = $this->user($account);
        [$lead] = $this->attributed($account);
        $this->convert($lead);
        $this->setValue($user, $lead, ['value' => 0, 'currency' => 'INR'])->assertOk();

        $today = collect($this->dashboard($user, '?range=7d')->json('data.conversion_daily'))->firstWhere('date', now()->toDateString());

        $this->assertSame(1, $today['conversions']);
        $this->assertSame(1, $today['valued_conversions']);
        $this->assertNotNull($today['conversion_value']);
        $this->assertEquals(0, $today['conversion_value']);
    }

    public function test_the_task_3_funnel_semantics_are_unchanged_by_values(): void
    {
        $account = $this->tenant();
        $user = $this->user($account);
        $campaign = $this->campaign($account);
        [$lead] = $this->attributed($account, $campaign);
        $before = $this->dashboard($user)->json('data.funnel');

        $this->convert($lead);
        $this->setValue($user, $lead, ['value' => 10, 'currency' => 'INR'])->assertOk();
        $after = $this->dashboard($user)->json('data.funnel');

        $this->assertSame(collect($before)->pluck('stage')->all(), collect($after)->pluck('stage')->all());
        $this->assertSame(1, collect($after)->firstWhere('stage', 'conversions')['count']);
        $this->assertSame(1, collect($after)->firstWhere('stage', 'referrals')['count']);
    }

    public function test_reporting_queries_do_not_grow_with_conversions_and_values(): void
    {
        $account = $this->tenant();
        $user = $this->user($account);
        $campaign = $this->campaign($account);
        $this->spend($campaign, 10);
        [$first] = $this->attributed($account, $campaign);
        $this->convert($first);

        $count = function () use ($user) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->dashboard($user)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $count();
        $small = $count();

        for ($i = 0; $i < 12; $i++) {
            $extra = $this->campaign($account, "Extra {$i}");
            $this->spend($extra, 5);
            [$lead, $row] = $this->attributed($account, $extra);
            $this->convert($lead);
            $row->forceFill(['conversion_value' => 10 + $i, 'conversion_currency' => 'INR'])->saveQuietly();
        }

        $this->assertSame($small, $count());
    }
}
