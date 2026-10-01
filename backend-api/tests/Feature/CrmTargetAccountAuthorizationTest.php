<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\ActivityLog;
use App\Models\Capability;
use App\Models\CrmCaptureLinkFailure;
use App\Models\CrmLead;
use App\Models\Lead;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Access\EntitlementAuditLogger;
use App\Services\Crm\CaptureLeadLinker;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 6 fix P6-2 — CRM target account & capture authorization.
 *
 * Gap closed: subscription.guard checks the CALLER's own account, so a
 * caller acting on ANOTHER account (Agent → sub-client via ?account_id=,
 * Super Admin → client) could write CRM data into a suspended or
 * unsubscribed account; and CaptureLeadLinker promoted automated captures
 * for such accounts (it checked module + capability only). Now the
 * resolved TARGET account's own state decides, for manual writes
 * (EnsureCrmTargetAccount) and automated capture (accountMayUseCrm) alike.
 * Everything else asserted here is the pre-existing isolation, pinned.
 */
class CrmTargetAccountAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const LEADS = '/api/crm/leads';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    // ------------------------------------------------------------------ fixtures

    private function account(array $attributes = [], bool $crm = true, bool $subscription = true): Account
    {
        $account = Account::factory()->create($attributes);

        if ($subscription) {
            Subscription::factory()->create(['account_id' => $account->id]);
        }

        if ($crm) {
            AccountEntitlement::firstOrCreate(
                ['account_id' => $account->id, 'capability_id' => Capability::where('slug', 'crm')->value('id')],
                ['source' => 'manual_grant', 'granted_by_account_id' => null],
            );
        }

        return $account->fresh();
    }

    private function agent(): Account
    {
        return $this->account(['account_type' => 'agent']);
    }

    private function user(?Account $account, array $roles = ['admin']): User
    {
        $user = User::factory()->create(['account_id' => $account?->id, 'is_active' => true]);
        $user->assignRole($roles);

        return $user->fresh();
    }

    private function superAdmin(): User
    {
        return $this->user(null, ['super_admin']);
    }

    private function agentUser(Account $agent): User
    {
        return $this->user($agent, ['admin', 'agent']);
    }

    private function create(User $actor, ?Account $target = null, array $body = [], array $query = [])
    {
        $query = $target ? ['account_id' => $target->id] + $query : $query;

        return $this->actingAs($actor)->postJson(self::LEADS.($query ? '?'.http_build_query($query) : ''), ['phone_number' => '+91 98765 '.random_int(10000, 99999), 'name' => 'Ada'] + $body);
    }

    private function leadsOf(Account $account): int
    {
        return CrmLead::where('account_id', $account->id)->count();
    }

    private function expire(Account $account): void
    {
        $account->currentSubscription->forceFill(['expires_at' => now()->subDay(), 'status' => 'expired'])->save();
    }

    private function captureLead(Account $account, string $provider = 'meta'): Lead
    {
        return Lead::create([
            'account_id' => $account->id, 'provider' => $provider, 'provider_lead_id' => $provider.':'.uniqid(),
            'lead_name' => 'Grace', 'lead_phone' => '9198'.random_int(10000000, 99999999),
        ]);
    }

    private function deniedAudit(string $category)
    {
        return ActivityLog::where('module_name', EntitlementAuditLogger::MODULE)->where('action_type', 'denied')->get()
            ->filter(fn ($log) => ($log->new_values['category'] ?? null) === $category && ($log->new_values['action'] ?? null) === 'crm.target')->values();
    }

    // ================================================================== Super Admin

    public function test_1_super_admin_creates_a_lead_for_an_entitled_selected_client(): void
    {
        $client = $this->account();

        $this->create($this->superAdmin(), $client)->assertCreated()->assertJsonPath('data.source', CrmLead::SOURCE_MANUAL);

        $this->assertSame(1, $this->leadsOf($client));
    }

    public function test_2_super_admin_skips_the_client_plan_and_invalid_targets_keep_their_denials(): void
    {
        $superAdmin = $this->superAdmin();
        $noCrm = $this->account(crm: false);

        // Owner decision (2026-09-30): the client's plan does not bind a Super Admin.
        $this->create($superAdmin, $noCrm)->assertCreated();
        $this->actingAs($superAdmin)->postJson(self::LEADS.'?account_id=999999', ['phone_number' => '9876543210'])->assertNotFound();
        $this->assertSame(1, CrmLead::count());
    }

    public function test_super_admin_cannot_write_into_a_suspended_or_unsubscribed_client_but_can_read(): void
    {
        $superAdmin = $this->superAdmin();
        $suspended = $this->account(['status' => 'suspended']);
        $expired = $this->account();
        $this->expire($expired);
        $none = $this->account(subscription: false);

        $this->create($superAdmin, $suspended)->assertForbidden()->assertJsonPath('error_code', 'CLIENT_ACCOUNT_SUSPENDED');
        // Owner decision (2026-09-30): a lapsed subscription does not stop a Super Admin (suspension and module switches still do).
        $this->create($superAdmin, $expired)->assertCreated();
        $this->create($superAdmin, $none)->assertCreated();
        $this->actingAs($superAdmin)->getJson(self::LEADS.'?account_id='.$expired->id)->assertOk();

        $this->assertSame(0, CrmLead::where('account_id', $suspended->id)->count());
        $this->assertSame(2, CrmLead::count());
        $this->assertCount(1, $this->deniedAudit('account_suspended'));
        $this->assertCount(0, $this->deniedAudit('no_active_subscription'));
    }

    public function test_super_admin_own_platform_crm_is_unaffected(): void
    {
        $platform = app(\App\Services\Crm\PlatformCrmAccount::class)->ensure()['account'];

        $this->create($this->superAdmin())->assertCreated();

        $this->assertSame(1, $this->leadsOf($platform), 'no subscription needed for the platform account (by design)');
    }

    // ================================================================== Agent

    public function test_3_agent_creates_a_lead_for_its_own_entitled_client(): void
    {
        $agent = $this->agent();
        $client = $this->account(['agent_id' => $agent->id]);

        $this->create($this->agentUser($agent), $client)->assertCreated();

        $this->assertSame([1, 0], [$this->leadsOf($client), $this->leadsOf($agent)]);
    }

    public function test_4_and_5_agent_cannot_target_another_agents_client_or_an_unrelated_account(): void
    {
        $agentA = $this->agent();
        $agentB = $this->agent();
        $clientOfB = $this->account(['agent_id' => $agentB->id]);
        $unrelated = $this->account();
        $userA = $this->agentUser($agentA);

        $this->create($userA, $clientOfB)->assertNotFound();
        $this->create($userA, $unrelated)->assertNotFound();
        $this->create($userA, $agentB)->assertNotFound();
        $this->actingAs($userA)->getJson(self::LEADS.'?account_id='.$clientOfB->id)->assertNotFound();

        $this->assertSame([0, 0, 0, 0], [$this->leadsOf($clientOfB), $this->leadsOf($unrelated), $this->leadsOf($agentB), $this->leadsOf($agentA)]);
    }

    public function test_agent_cannot_write_into_its_own_client_when_that_client_is_suspended_or_unsubscribed(): void
    {
        $agent = $this->agent();
        $expired = $this->account(['agent_id' => $agent->id]);
        $this->expire($expired);
        $suspended = $this->account(['agent_id' => $agent->id, 'status' => 'suspended']);
        $agentUser = $this->agentUser($agent);

        // the Agent's own active subscription used to be the only one checked
        $this->create($agentUser, $expired)->assertForbidden()->assertJsonPath('error_code', 'SUBSCRIPTION_EXPIRED');
        $this->create($agentUser, $suspended)->assertForbidden()->assertJsonPath('error_code', 'CLIENT_ACCOUNT_SUSPENDED');
        $this->actingAs($agentUser)->getJson(self::LEADS.'?account_id='.$expired->id)->assertOk();

        $this->assertSame([0, 0], [$this->leadsOf($expired), $this->leadsOf($suspended)]);
        $log = $this->deniedAudit('no_active_subscription')->sole();
        $this->assertSame([$expired->id, $agent->id, $expired->id], [$log->account_id, $log->new_values['actor_account_id'], $log->new_values['target_account_id']]);
    }

    public function test_the_agents_own_crm_entitlement_does_not_cover_a_client_without_crm(): void
    {
        $agent = $this->agent(); // holds crm
        $client = $this->account(['agent_id' => $agent->id], crm: false);

        $this->create($this->agentUser($agent), $client)->assertForbidden()->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
        $this->assertSame(0, CrmLead::count());
    }

    public function test_an_agent_cannot_reach_another_accounts_lead_through_its_own_client(): void
    {
        $agent = $this->agent();
        $mine = $this->account(['agent_id' => $agent->id]);
        $other = $this->account();
        $foreignLead = CrmLead::factory()->create(['account_id' => $other->id]);
        $agentUser = $this->agentUser($agent);

        $this->actingAs($agentUser)->getJson(self::LEADS."/{$foreignLead->id}?account_id={$mine->id}")->assertNotFound();
        $this->actingAs($agentUser)->patchJson(self::LEADS."/{$foreignLead->id}/status?account_id={$mine->id}", ['status' => CrmLead::STATUS_CONTACTED])->assertNotFound();
        $this->actingAs($agentUser)->deleteJson(self::LEADS."/{$foreignLead->id}?account_id={$mine->id}")->assertNotFound();

        $this->assertSame(CrmLead::STATUS_NEW, $foreignLead->fresh()->status);
    }

    // ================================================================== client / forged identifiers

    public function test_6_and_7_a_client_cannot_redirect_a_write_with_forged_identifiers(): void
    {
        $mine = $this->account();
        $other = $this->account();
        $user = $this->user($mine);

        $this->create($user, $other, ['account_id' => $other->id, 'tenant_id' => $other->id, 'agent_id' => $other->id, 'contact_id' => 1, 'capture_lead_id' => 1], ['tenant_id' => $other->id])
            ->assertCreated();

        $this->assertSame([1, 0], [$this->leadsOf($mine), $this->leadsOf($other)], 'the query/body ids are ignored for a client user');
        $this->assertNull(CrmLead::where('account_id', $mine->id)->value('capture_lead_id'));

        $foreign = CrmLead::factory()->create(['account_id' => $other->id]);
        $this->actingAs($user)->getJson(self::LEADS."/{$foreign->id}?account_id={$other->id}")->assertNotFound();
    }

    public function test_forged_body_ids_cannot_redirect_an_agent_or_super_admin_write(): void
    {
        $agent = $this->agent();
        $client = $this->account(['agent_id' => $agent->id]);
        $elsewhere = $this->account();

        $this->create($this->agentUser($agent), $client, ['account_id' => $elsewhere->id, 'tenant_id' => $elsewhere->id])->assertCreated();
        $this->create($this->superAdmin(), $client, ['account_id' => $elsewhere->id])->assertCreated();

        $this->assertSame([2, 0], [$this->leadsOf($client), $this->leadsOf($elsewhere)]);
    }

    // ================================================================== entitlement / permission

    public function test_8_to_11_capability_module_and_permission_are_required(): void
    {
        $entitled = $this->account();
        $this->create($this->user($entitled))->assertCreated(); // 9

        $noCrm = $this->account(crm: false);
        $this->create($this->user($noCrm))->assertForbidden()->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED'); // 8

        $noModule = $this->account(['allowed_modules' => array_values(array_diff(Account::MODULES, ['lead_crm']))]);
        $this->create($this->user($noModule))->assertForbidden()->assertJsonPath('error_code', 'MODULE_DISABLED'); // 10

        $noPermission = $this->account();
        $this->create($this->user($noPermission, ['user']))->assertForbidden(); // 11

        $this->assertSame([1, 0, 0, 0], [$this->leadsOf($entitled), $this->leadsOf($noCrm), $this->leadsOf($noModule), $this->leadsOf($noPermission)]);
    }

    // ================================================================== capture

    public function test_12_an_authorized_manual_lead_is_created_as_manual(): void
    {
        $account = $this->account();

        $this->create($this->user($account), null, ['source' => 'manual'])->assertCreated()->assertJsonPath('data.source', 'manual');
        $this->create($this->user($account), null, ['source' => 'meta_ad'])->assertStatus(422); // unchanged validation
    }

    public static function providers(): array
    {
        return ['meta lead ads' => ['meta'], 'click-to-whatsapp' => ['whatsapp_ctwa'], 'journey' => ['whatsapp_journey']];
    }

    #[DataProvider('providers')]
    public function test_13_an_authorized_automated_capture_is_promoted_under_its_own_account(string $provider): void
    {
        $account = $this->account();
        $lead = $this->captureLead($account, $provider);

        $crmLead = app(CaptureLeadLinker::class)->linkQuietly($lead);

        $this->assertNotNull($crmLead);
        $this->assertSame([$account->id, $lead->id], [(int) $crmLead->account_id, (int) $crmLead->capture_lead_id]);
        $this->assertSame((int) $account->id, (int) $crmLead->contact->account_id);
    }

    #[DataProvider('providers')]
    public function test_14_and_15_an_unauthorized_capture_is_never_promoted_whatever_its_source(string $provider): void
    {
        $linker = app(CaptureLeadLinker::class);
        $cases = [
            'suspended' => $this->account(['status' => 'suspended']),
            'no subscription' => $this->account(subscription: false),
            'no crm' => $this->account(crm: false),
            'no module' => $this->account(['allowed_modules' => array_values(array_diff(Account::MODULES, ['lead_crm']))]),
        ];
        $expired = $this->account();
        $this->expire($expired);
        $cases['expired'] = $expired;

        foreach ($cases as $label => $account) {
            $lead = $this->captureLead($account, $provider);

            $this->assertNull($linker->linkQuietly($lead), $label);
            $this->assertSame(0, $this->leadsOf($account), "{$label}: no CRM lead");
            $this->assertSame(CrmCaptureLinkFailure::REASON_NOT_ENTITLED, CrmCaptureLinkFailure::where('lead_id', $lead->id)->value('reason'), "{$label}: recorded, retryable");
            $this->assertDatabaseHas('leads', ['id' => $lead->id]); // the capture itself survives
        }
    }

    public function test_a_blocked_capture_is_promoted_by_retry_once_the_account_is_entitled_again(): void
    {
        $account = $this->account();
        $this->expire($account);
        $lead = $this->captureLead($account);
        $linker = app(CaptureLeadLinker::class);
        $this->assertNull($linker->linkQuietly($lead));

        $account->currentSubscription->forceFill(['expires_at' => now()->addMonth(), 'status' => 'active'])->save();

        $crmLead = $linker->retry(CrmCaptureLinkFailure::where('lead_id', $lead->id)->firstOrFail());
        $this->assertNotNull($crmLead);
        $this->assertSame((int) $account->id, (int) $crmLead->account_id);
    }

    public function test_a_capture_can_only_ever_become_a_lead_of_its_own_account(): void
    {
        $a = $this->account();
        $b = $this->account();
        $leadOfB = $this->captureLead($b);

        $crmLead = app(CaptureLeadLinker::class)->linkQuietly($leadOfB);

        $this->assertSame([0, 1], [$this->leadsOf($a), $this->leadsOf($b)]);
        $this->assertSame((int) $b->id, (int) $crmLead->account_id);

        // a cross-account promotion is refused outright (model guard first,
        // composite foreign keys behind it) — nothing is stored
        try {
            CrmLead::create(['account_id' => $a->id, 'contact_id' => $crmLead->contact_id, 'capture_lead_id' => $this->captureLead($b)->id, 'status' => 'new', 'source' => 'meta_ad']);
            $this->fail('a cross-account CRM lead was stored');
        } catch (\Illuminate\Validation\ValidationException|\Illuminate\Database\QueryException) {
        }
        $this->assertSame(0, $this->leadsOf($a));
    }
}
