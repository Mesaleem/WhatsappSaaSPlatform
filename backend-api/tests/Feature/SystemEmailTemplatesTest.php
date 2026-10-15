<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\EmailVerificationCode;
use App\Models\InAppNotification;
use App\Models\MailLog;
use App\Models\MailSetting;
use App\Models\NotificationTemplate;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Mail\SystemEmailCatalog;
use App\Services\Mail\SystemMailer;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Master (system) email templates: the migration's default rows, rendering
 * from the database with the built-in fallback, the onboarding / billing
 * request triggers, Super-Admin-only access, test sends, and the new
 * broadcast targets (role, all clients, all agents).
 */
class SystemEmailTemplatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    private function configureMail(): void
    {
        MailSetting::create(['mailer' => 'array', 'host' => 'smtp.example.test', 'port' => 587, 'from_address' => 'no-reply@example.test', 'from_name' => 'WapHub']);
    }

    /** @return array<int, array{to: string, subject: string, html: string}> */
    private function sent(): array
    {
        if (! config('mail.mailers.account_mail')) {
            return [];
        }

        return collect(Mail::mailer('account_mail')->getSymfonyTransport()->messages())
            ->map(fn ($m) => [
                'to' => $m->getOriginalMessage()->getTo()[0]->getAddress(),
                'subject' => $m->getOriginalMessage()->getSubject(),
                'html' => (string) $m->getOriginalMessage()->getHtmlBody(),
            ])->values()->all();
    }

    private function superAdmin(string $email = 'boss@platform.test'): User
    {
        $user = User::factory()->create(['email' => $email, 'is_active' => true, 'account_id' => null]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function client(string $type = 'client', string $role = 'admin', ?int $agentId = null): User
    {
        $account = Account::create(['company_name' => 'Acme '.uniqid(), 'status' => 'active', 'module_assignment' => 'both', 'account_type' => $type, 'agent_id' => $agentId]);
        Subscription::create([
            'account_id' => $account->id, 'engine_type' => 'qr', 'billing_model' => 'flat_quota', 'total_allocated_messages' => 500, 'used_messages' => 0,
            'price_paid' => 0, 'payment_mode' => 'cash', 'starts_at' => now()->subDay(), 'expires_at' => now()->addMonth(), 'status' => 'active',
        ]);
        $user = User::factory()->create(['email' => 'u'.uniqid().'@acme.test', 'account_id' => $account->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    // ---- defaults & rendering -----------------------------------------------------------------

    public function test_the_migration_inserts_every_master_template_once(): void
    {
        $slugs = NotificationTemplate::query()->where('is_system', true)->pluck('slug')->all();

        $this->assertEqualsCanonicalizing(array_keys(SystemEmailCatalog::all()), $slugs);

        foreach (NotificationTemplate::query()->where('is_system', true)->get() as $template) {
            $this->assertTrue($template->is_active);
            $this->assertSame('raw_html', $template->type);
            $this->assertArrayHasKey('brand', $template->variables);
            // Email clients only honour inline styles: no <style>, <link> or class attributes.
            $this->assertDoesNotMatchRegularExpression('/<style|<link|\sclass=|<script/i', $template->body, $template->slug);
        }
    }

    public function test_an_edited_template_is_used_and_values_are_escaped(): void
    {
        NotificationTemplate::query()->where('slug', 'onboarding.welcome')->update([
            'subject' => 'Hello {{name}} from {{brand}}',
            'body' => '<p>Hi {{name}}, welcome to {{company_name}}</p>',
        ]);

        [$subject, $html] = app(SystemMailer::class)->render('onboarding.welcome', ['name' => 'Asha <b>', 'company_name' => 'A&B']);

        $this->assertSame('Hello Asha <b> from WapHub', $subject);
        $this->assertSame('<p>Hi Asha &lt;b&gt;, welcome to A&amp;B</p>', $html);
    }

    public function test_a_switched_off_or_missing_template_falls_back_to_the_built_in_default(): void
    {
        NotificationTemplate::query()->where('slug', 'auth.signup_otp')->update(['is_active' => false, 'subject' => 'EDITED']);
        [$subject] = app(SystemMailer::class)->render('auth.signup_otp', ['code' => '111222', 'minutes' => 10]);
        $this->assertSame('111222 is your WapHub verification code', $subject);

        NotificationTemplate::query()->where('slug', 'auth.signup_otp')->delete();
        [$subject, $html] = app(SystemMailer::class)->render('auth.signup_otp', ['code' => '333444', 'minutes' => 10]);
        $this->assertSame('333444 is your WapHub verification code', $subject);
        $this->assertStringContainsString('333444', $html);
    }

    public function test_a_sent_email_is_logged_without_its_one_time_code(): void
    {
        $this->configureMail();
        $this->postJson('/api/auth/register/send-code', ['email' => 'new@acme.test'])->assertOk();

        $log = MailLog::query()->where('template_slug', 'auth.signup_otp')->sole();
        $this->assertSame('sent', $log->status);
        $this->assertSame('Signup verification code (OTP)', $log->subject);
        $this->assertDoesNotMatchRegularExpression('/\d{6}/', $log->subject);
    }

    // ---- triggers -----------------------------------------------------------------------------

    public function test_signup_welcomes_the_customer_and_alerts_every_super_admin(): void
    {
        $this->configureMail();
        $admin = $this->superAdmin();
        EmailVerificationCode::create(['email' => 'asha@acme.test', 'code_hash' => Hash::make('123456'), 'attempts' => 0, 'expires_at' => now()->addMinutes(10)]);

        $this->postJson('/api/auth/register', [
            'company_name' => 'Acme Traders', 'name' => 'Asha', 'email' => 'asha@acme.test', 'phone' => '+919800000000',
            'password' => 'secret-pass', 'password_confirmation' => 'secret-pass', 'accept_terms' => true, 'email_code' => '123456',
        ])->assertCreated();

        $sent = collect($this->sent());
        $welcome = $sent->firstWhere('to', 'asha@acme.test');
        $this->assertSame('Welcome to WapHub, Asha!', $welcome['subject']);
        $this->assertStringContainsString('Acme Traders', $welcome['html']);
        $this->assertSame('New signup: Acme Traders', $sent->firstWhere('to', $admin->email)['subject']);

        $customer = User::query()->where('email', 'asha@acme.test')->sole();
        $this->assertSame(1, InAppNotification::query()->where('user_id', $customer->id)->count());
        $this->assertSame('New signup: Acme Traders', InAppNotification::query()->where('user_id', $admin->id)->value('title'));
    }

    public function test_a_plan_request_emails_the_customer_and_the_super_admin(): void
    {
        $this->configureMail();
        $admin = $this->superAdmin();
        $user = $this->client();
        $plan = Plan::query()->where('is_active', true)->orderByDesc('price')->value('slug');

        $this->actingAs($user)->postJson('/api/billing/manual-checkout', ['plan_key' => $plan])->assertCreated();

        $sent = collect($this->sent());
        $this->assertStringStartsWith('We received your', $sent->firstWhere('to', $user->email)['subject']);
        $this->assertStringContainsString($user->account->company_name, $sent->firstWhere('to', $admin->email)['subject']);
        $this->assertSame(1, InAppNotification::query()->where('user_id', $admin->id)->count());
    }

    public function test_a_quota_request_reaches_the_super_admin_and_the_clients_agent(): void
    {
        $this->configureMail();
        $admin = $this->superAdmin();
        $agent = $this->client('agent', 'agent');
        $user = $this->client('client', 'admin', $agent->account_id);

        $this->actingAs($user)->postJson('/api/quota-requests/store', ['requested_extra_messages' => 5000, 'reason' => "Festival\n<script>"])
            ->assertCreated();

        $sent = collect($this->sent());
        $mine = $sent->firstWhere('to', $user->email);
        $this->assertSame('We received your quota top-up request', $mine['subject']);
        $this->assertStringContainsString('5,000 extra messages', $mine['html']);
        $this->assertStringContainsString('Your Agent', $mine['html']);
        $this->assertStringNotContainsString('<script>', $mine['html']);
        $this->assertNotNull($sent->firstWhere('to', $admin->email));
        $this->assertNotNull($sent->firstWhere('to', $agent->email));
    }

    public function test_a_paid_plan_invoice_emails_the_owner_that_the_plan_is_active(): void
    {
        $this->configureMail();
        $user = $this->client();
        $plan = Plan::query()->where('is_active', true)->orderByDesc('price')->value('slug');
        $invoiceId = $this->actingAs($user)->postJson('/api/billing/manual-checkout', ['plan_key' => $plan])->assertCreated()->json('invoice.id');

        app(\App\Services\Billing\InvoiceCreditService::class)->markPaidAndCreditQuota($invoiceId, 'pay_test');
        // Outside an HTTP request the deferred send runs when the process finishes; run it now.
        app(\Illuminate\Support\Defer\DeferredCallbackCollection::class)->invoke();

        $mail = collect($this->sent())->where('to', $user->email)->last();
        $this->assertStringStartsWith('Payment received', $mail['subject']);
        $this->assertStringContainsString('Valid until', $mail['html']);
    }

    public function test_adding_a_team_member_emails_them_where_to_log_in(): void
    {
        $this->configureMail();
        $owner = $this->client();

        $this->actingAs($owner)->postJson('/api/team/users', [
            'name' => 'Ravi', 'email' => 'ravi@acme.test', 'password' => 'secret-pass', 'role_names' => ['user'],
        ])->assertCreated();

        $mail = collect($this->sent())->firstWhere('to', 'ravi@acme.test');
        $this->assertStringStartsWith('You have been added to', $mail['subject']);
        $this->assertStringNotContainsString('secret-pass', $mail['html']);
    }

    // ---- access ---------------------------------------------------------------------------------

    public function test_only_the_super_admin_sees_and_edits_master_templates(): void
    {
        $id = NotificationTemplate::query()->where('slug', 'auth.password_reset')->value('id');
        $clientAdmin = $this->client();

        $this->actingAs($clientAdmin)->getJson('/api/notification-templates?per_page=100')->assertOk()
            ->assertJsonMissing(['slug' => 'auth.password_reset']);
        $this->actingAs($clientAdmin)->getJson("/api/notification-templates/{$id}")->assertNotFound();
        $this->actingAs($clientAdmin)->putJson("/api/notification-templates/{$id}", ['name' => 'x', 'type' => 'raw_html', 'subject' => 'x', 'body' => 'x'])->assertNotFound();
        $this->actingAs($clientAdmin)->deleteJson("/api/notification-templates/{$id}")->assertNotFound();

        $sa = $this->superAdmin();
        $this->actingAs($sa)->getJson('/api/notification-templates?kind=system&per_page=100')->assertOk()
            ->assertJsonFragment(['slug' => 'auth.password_reset']);
        $this->actingAs($sa)->deleteJson("/api/notification-templates/{$id}")->assertStatus(422);

        $this->actingAs($sa)->putJson("/api/notification-templates/{$id}", [
            'name' => 'Reset', 'type' => 'rich_text', 'subject' => 'Custom {{brand}} reset', 'body' => '<p>{{reset_url}}</p>', 'is_active' => false,
        ])->assertOk()->assertJsonPath('type', 'raw_html')->assertJsonPath('is_active', false)->assertJsonPath('slug', 'auth.password_reset');

        $this->actingAs($sa)->postJson("/api/notification-templates/{$id}/reset")->assertOk()
            ->assertJsonPath('subject', SystemEmailCatalog::get('auth.password_reset')['subject'])
            ->assertJsonPath('is_active', true);
    }

    public function test_send_test_email_goes_only_to_the_caller_with_sample_values(): void
    {
        $this->configureMail();
        $sa = $this->superAdmin();

        $this->actingAs($sa)->postJson('/api/notification-templates/test', [
            'subject' => 'Hi {{name}} {{code}}', 'body' => '<p>{{plan_name}} {{unknown_var}}</p>',
        ])->assertOk();

        $mail = $this->sent()[0];
        $this->assertSame($sa->email, $mail['to']);
        $this->assertStringStartsWith('[Test] Hi ', $mail['subject']);
        $this->assertStringContainsString('Growth [unknown_var]', $mail['html']);
    }

    // ---- broadcast targets --------------------------------------------------------------------

    public function test_broadcast_to_all_clients_all_agents_or_one_role(): void
    {
        $sa = $this->superAdmin();
        $clientA = $this->client();
        $clientB = $this->client('client', 'user');
        $agent = $this->client('agent', 'agent');

        $send = fn (array $target) => $this->actingAs($sa)->postJson('/api/notification-broadcasts', $target + [
            'subject' => 'Hello', 'body' => '<p>Hi {{name}}</p>', 'channels' => ['in_app'],
        ])->assertCreated()->json('recipient_count');

        $this->assertSame(2, $send(['target_type' => 'all_clients']));
        $this->assertSame(1, $send(['target_type' => 'all_agents']));
        $this->assertSame(1, $send(['target_type' => 'role', 'role_name' => 'agent']));
        $this->actingAs($sa)->postJson('/api/notification-broadcasts', [
            'target_type' => 'role', 'subject' => 'x', 'body' => 'x', 'channels' => ['in_app'],
        ])->assertUnprocessable()->assertJsonValidationErrors('role_name');

        $this->assertSame(1, InAppNotification::query()->where('user_id', $clientA->id)->count());
        $this->assertSame(1, InAppNotification::query()->where('user_id', $clientB->id)->count());
        $this->assertSame(2, InAppNotification::query()->where('user_id', $agent->id)->count());
    }

    public function test_a_client_admin_can_still_only_broadcast_to_their_own_users(): void
    {
        $owner = $this->client();
        $this->client(); // another client — must not be reached

        $this->actingAs($owner)->postJson('/api/notification-broadcasts', [
            'target_type' => 'all_clients', 'subject' => 'x', 'body' => 'x', 'channels' => ['in_app'],
        ])->assertCreated()->assertJsonPath('target_type', 'account_users')->assertJsonPath('recipient_count', 1);
    }
}
