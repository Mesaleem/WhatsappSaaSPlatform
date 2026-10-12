<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Capability;
use App\Models\ContactGroup;
use App\Models\ContactMessage;
use App\Models\EmailVerificationCode;
use App\Models\Plan;
use App\Models\SiteContent;
use App\Models\User;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Public marketing website: content API, plans, contact form, signup and the Super Admin editor. */
class PublicWebsiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        return $user;
    }

    private function signup(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        // The code a real visitor receives by email (see AuthController::sendSignupCode()).
        EmailVerificationCode::updateOrCreate(
            ['email' => $overrides['email'] ?? 'asha@acme.test'],
            ['code_hash' => Hash::make('123456'), 'attempts' => 0, 'expires_at' => now()->addMinutes(10)],
        );

        return $this->postJson('/api/auth/register', $overrides + [
            'company_name' => 'Acme Traders',
            'name' => 'Asha',
            'email' => 'asha@acme.test',
            'phone' => '+919800000000',
            'password' => 'secret-pass',
            'password_confirmation' => 'secret-pass',
            'accept_terms' => true,
            'email_code' => '123456',
        ]);
    }

    public function test_site_content_is_public_and_falls_back_to_defaults(): void
    {
        $this->getJson('/api/public/site')
            ->assertOk()
            ->assertJsonPath('data.brand.signup_enabled', true)
            ->assertJsonStructure(['data' => ['brand', 'home', 'features', 'about', 'pricing', 'contact', 'faq', 'legal']]);
    }

    public function test_saved_section_replaces_its_default(): void
    {
        SiteContent::create(['key' => 'about', 'value' => ['title' => 'Who we are']]);

        $this->getJson('/api/public/site')
            ->assertOk()
            ->assertJsonPath('data.about', ['title' => 'Who we are'])
            ->assertJsonPath('data.home.hero.primary_cta', 'Get started');
    }

    public function test_public_plans_list_only_active_plans_with_feature_labels(): void
    {
        Plan::query()->update(['is_active' => false]);
        $capability = Capability::first();
        $active = Plan::create([
            'slug' => 'web-starter', 'label' => 'Starter', 'price' => 999, 'duration_days' => 30,
            'engine_type' => 'qr', 'billing_model' => 'flat_quota', 'total_allocated_messages' => 1000, 'is_active' => true,
        ]);
        $active->capabilities()->attach($capability->id);
        Plan::create([
            'slug' => 'web-retired', 'label' => 'Retired', 'price' => 1, 'duration_days' => 30,
            'engine_type' => 'qr', 'billing_model' => 'flat_quota', 'total_allocated_messages' => 10, 'is_active' => false,
        ]);

        $response = $this->getJson('/api/public/plans')->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.key', 'web-starter')
            ->assertJsonPath('data.0.price', '999.00')
            ->assertJsonPath('data.0.features', [$capability->label]);
        $this->assertArrayNotHasKey('is_active', $response->json('data.0'));
    }

    public function test_contact_form_stores_a_message_and_rejects_bots(): void
    {
        $this->postJson('/api/public/contact', [
            'name' => 'Ravi', 'email' => 'ravi@example.test', 'message' => 'Need a demo', 'topic' => 'Sales',
        ])->assertCreated();

        $this->assertDatabaseHas('contact_messages', ['email' => 'ravi@example.test', 'topic' => 'Sales']);

        $this->postJson('/api/public/contact', [
            'name' => 'Bot', 'email' => 'bot@example.test', 'message' => 'spam', 'website' => 'http://spam.test',
        ])->assertUnprocessable();

        $this->postJson('/api/public/contact', ['name' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['email', 'message']);
        $this->assertSame(1, ContactMessage::count());
    }

    public function test_signup_creates_an_account_on_a_trial_and_returns_a_working_token(): void
    {
        $response = $this->signup()->assertCreated()
            ->assertJsonPath('user.email', 'asha@acme.test')
            ->assertJsonPath('token_type', 'Bearer');

        $user = User::where('email', 'asha@acme.test')->firstOrFail();
        $account = $user->account;

        $this->assertTrue($user->hasRole('admin'));
        $this->assertSame('Acme Traders', $account->company_name);
        $this->assertSame('client', $account->account_type);
        $this->assertNull($account->agent_id);
        $this->assertTrue(ContactGroup::where('account_id', $account->id)->where('is_default', true)->exists());

        $subscription = $account->currentSubscription;
        $this->assertSame(50, $subscription->total_allocated_messages);
        $this->assertTrue($subscription->expires_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));
        $this->assertTrue($account->hasActiveSubscription());

        // The token from signup works straight away.
        $this->withToken($response->json('token'))->getJson('/api/auth/me')->assertOk();
        $this->app['auth']->forgetGuards();

        // The new user can log in again afterwards (the trial keeps login open); signup used
        // one of the two allowed devices, so this second one just works.
        $this->postJson('/api/auth/login', ['email' => 'asha@acme.test', 'password' => 'secret-pass'])->assertOk();
    }

    public function test_signup_uses_the_trial_configured_in_website_content(): void
    {
        SiteContent::create(['key' => 'brand', 'value' => ['name' => 'X', 'signup_enabled' => true, 'trial_days' => 14, 'trial_messages' => 200]]);

        $this->signup()->assertCreated();

        $subscription = User::where('email', 'asha@acme.test')->firstOrFail()->account->currentSubscription;
        $this->assertSame(200, $subscription->total_allocated_messages);
        $this->assertTrue($subscription->expires_at->greaterThan(now()->addDays(13)));
    }

    public function test_signup_validates_input_and_rejects_a_taken_email(): void
    {
        User::factory()->create(['email' => 'asha@acme.test']);

        $this->signup(['password_confirmation' => 'different', 'accept_terms' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password', 'accept_terms']);

        $this->assertSame(0, Account::where('company_name', 'Acme Traders')->count());
    }

    public function test_signup_can_be_switched_off_by_the_super_admin(): void
    {
        SiteContent::create(['key' => 'brand', 'value' => ['name' => 'X', 'signup_enabled' => false]]);

        $this->signup()->assertForbidden()->assertJsonPath('error_code', 'SIGNUP_DISABLED');
        $this->assertDatabaseMissing('users', ['email' => 'asha@acme.test']);
    }

    public function test_super_admin_edits_and_resets_a_section(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->putJson('/api/admin/site-content/about', ['value' => ['title' => 'New about']])
            ->assertOk()->assertJsonPath('data.title', 'New about');
        $this->getJson('/api/public/site')->assertJsonPath('data.about.title', 'New about');

        $this->actingAs($admin)->getJson('/api/admin/site-content')
            ->assertOk()->assertJsonPath('customized', ['about'])->assertJsonPath('defaults.about.title', 'About us');

        $this->actingAs($admin)->deleteJson('/api/admin/site-content/about')->assertOk();
        $this->getJson('/api/public/site')->assertJsonPath('data.about.title', 'About us');

        $this->actingAs($admin)->putJson('/api/admin/site-content/unknown', ['value' => ['a' => 1]])->assertNotFound();
    }

    public function test_super_admin_reads_and_handles_contact_messages(): void
    {
        $admin = $this->superAdmin();
        $message = ContactMessage::create(['name' => 'Ravi', 'email' => 'ravi@example.test', 'message' => 'Hi']);

        $this->actingAs($admin)->getJson('/api/admin/contact-messages')
            ->assertOk()->assertJsonPath('open_count', 1)->assertJsonPath('data.0.email', 'ravi@example.test');

        $this->actingAs($admin)->patchJson("/api/admin/contact-messages/{$message->id}", ['handled' => true])->assertOk();
        $this->assertNotNull($message->fresh()->handled_at);
        $this->actingAs($admin)->getJson('/api/admin/contact-messages')->assertJsonPath('open_count', 0)->assertJsonCount(0, 'data');
    }

    public function test_tenant_admin_cannot_edit_website_content(): void
    {
        $this->signup()->assertCreated();
        $tenantAdmin = User::where('email', 'asha@acme.test')->firstOrFail();

        $this->actingAs($tenantAdmin)->putJson('/api/admin/site-content/about', ['value' => ['title' => 'Hacked']])->assertForbidden();
        $this->actingAs($tenantAdmin)->getJson('/api/admin/contact-messages')->assertForbidden();
    }
}
