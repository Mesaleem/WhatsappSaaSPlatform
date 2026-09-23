<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Capability;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1 Foundation, Task 7 — write-time provider-compatibility
 * enforcement on AccountController::grantEntitlement()/revokeEntitlement().
 */
class AccountEntitlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    private function makeSuperAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function makePlainAdmin(Account $account): User
    {
        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');

        return $user;
    }

    public function test_super_admin_can_grant_a_capability_supported_by_the_accounts_provider(): void
    {
        $account = Account::factory()->create();
        Subscription::factory()->for($account)->create(['engine_type' => 'meta']);
        $superAdmin = $this->makeSuperAdmin();

        $response = $this->actingAs($superAdmin)->postJson(
            "/api/admin/accounts/{$account->id}/entitlements",
            ['capability' => 'crm']
        );

        $response->assertOk();
        $this->assertDatabaseHas('account_entitlements', [
            'account_id' => $account->id,
            'capability_id' => Capability::where('slug', 'crm')->value('id'),
        ]);
    }

    public function test_grant_is_rejected_when_the_provider_does_not_support_the_capability(): void
    {
        $account = Account::factory()->create();
        Subscription::factory()->for($account)->create(['engine_type' => 'qr']);
        $superAdmin = $this->makeSuperAdmin();

        /*
         * Phase 5 Task 9 FOLLOW-UP: this used to use 'crm', which QR no
         * longer blocks — CRM is engine-agnostic and the confirmed plan
         * matrix bundles it into the QR-engine Growth plan.
         *
         * 'journey_automation' is now the standing example of a real
         * QR-incompatible capability: it remains a deliberate Meta-tier
         * product gate, explicitly kept by that same decision. The
         * BEHAVIOUR under test is unchanged — a provider-incompatible
         * grant is refused 422 — only the example moved.
         *
         * Phase 7 Task 1.5: journey_automation became QR-supported (owner
         * decision), so the example moved once more, to 'ads' — still a
         * QR business-tier refusal. Behaviour under test unchanged.
         */
        $response = $this->actingAs($superAdmin)->postJson(
            "/api/admin/accounts/{$account->id}/entitlements",
            ['capability' => 'ads']
        );

        $response->assertStatus(422);
        $this->assertDatabaseMissing('account_entitlements', [
            'account_id' => $account->id,
            'capability_id' => Capability::where('slug', 'ads')->value('id'),
        ]);
    }

    public function test_a_non_super_admin_cannot_grant_entitlements(): void
    {
        $account = Account::factory()->create();
        Subscription::factory()->for($account)->create(['engine_type' => 'meta']);
        $admin = $this->makePlainAdmin($account);

        $response = $this->actingAs($admin)->postJson(
            "/api/admin/accounts/{$account->id}/entitlements",
            ['capability' => 'crm']
        );

        $response->assertForbidden();
    }

    public function test_super_admin_can_revoke_a_granted_capability(): void
    {
        $account = Account::factory()->create();
        Subscription::factory()->for($account)->create(['engine_type' => 'meta']);
        $capability = Capability::where('slug', 'crm')->firstOrFail();
        $account->entitlements()->create(['capability_id' => $capability->id, 'source' => 'manual_grant']);
        $superAdmin = $this->makeSuperAdmin();

        $response = $this->actingAs($superAdmin)->deleteJson(
            "/api/admin/accounts/{$account->id}/entitlements/crm"
        );

        $response->assertOk();

        /*
         * Phase 5 Task 9 changed this deliberately. Revocation used to
         * DELETE the row; it now MARKS it (revoked_at), because a deleted
         * row is indistinguishable from "never granted" and the plan
         * backfill added in Task 9 would therefore re-grant exactly what
         * an administrator had just taken away.
         *
         * The assertion that matters is unchanged in substance: after a
         * revoke the account no longer HOLDS the capability. What changed
         * is that the decision now leaves a record.
         */
        $this->assertDatabaseHas('account_entitlements', [
            'account_id' => $account->id,
            'capability_id' => $capability->id,
        ]);
        $this->assertFalse(
            app(\App\Services\Access\AccessControlService::class)->canTenant($account->fresh(), 'crm'),
            'A revoked capability must not be held.'
        );
        $this->assertNotNull(
            \App\Models\AccountEntitlement::where('account_id', $account->id)
                ->where('capability_id', $capability->id)
                ->value('revoked_at')
        );
    }

    public function test_provider_capability_service_reflects_the_seeded_qr_meta_split(): void
    {
        // Direct assertions on the seeded catalog itself — the exact
        // worked example locked for Phase 1 (QR: messaging/groups only;
        // Meta: no groups but yes crm/journey/ads).
        $this->assertTrue(app(\App\Services\Access\ProviderCapabilityService::class)->supports('qr', 'whatsapp_send'));
        $this->assertTrue(app(\App\Services\Access\ProviderCapabilityService::class)->supports('qr', 'whatsapp_groups'));
        // Phase 7 Task 1.5 — owner decision: Journey automation is supported on QR; ads is not.
        $this->assertTrue(app(\App\Services\Access\ProviderCapabilityService::class)->supports('qr', 'journey_automation'));
        $this->assertFalse(app(\App\Services\Access\ProviderCapabilityService::class)->supports('qr', 'ads'));
        $this->assertFalse(app(\App\Services\Access\ProviderCapabilityService::class)->supports('meta', 'whatsapp_groups'));
        $this->assertTrue(app(\App\Services\Access\ProviderCapabilityService::class)->supports('meta', 'journey_automation'));
    }
}
