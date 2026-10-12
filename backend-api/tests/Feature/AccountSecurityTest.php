<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\EmailVerificationCode;
use App\Models\MailSetting;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Account safety (2026-10-12): real emails at signup (code + disposable/MX
 * checks), one login per user, login alert emails, and sign-out everywhere
 * on a password change.
 */
class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    /** Mail Settings pointed at Laravel's in-memory "array" transport, so sent emails can be read back. */
    private function configureMail(): void
    {
        MailSetting::create(['mailer' => 'array', 'host' => 'smtp.example.test', 'port' => 587, 'from_address' => 'no-reply@example.test', 'from_name' => 'WapHub']);
    }

    /** @return array<int, string> subjects of the account emails sent so far */
    private function sentSubjects(): array
    {
        if (! config('mail.mailers.account_mail')) {
            return []; // nothing was ever sent
        }

        return collect(Mail::mailer('account_mail')->getSymfonyTransport()->messages())
            ->map(fn ($m) => $m->getOriginalMessage()->getSubject())
            ->values()->all();
    }

    private function clientUser(string $email = 'asha@acme.test'): User
    {
        $account = Account::create(['company_name' => 'Acme', 'status' => 'active', 'module_assignment' => 'both', 'account_type' => 'client']);
        Subscription::create([
            'account_id' => $account->id, 'engine_type' => 'qr', 'billing_model' => 'unlimited', 'used_messages' => 0,
            'price_paid' => 0, 'payment_mode' => 'cash', 'starts_at' => now()->subDay(), 'expires_at' => now()->addMonth(), 'status' => 'active',
        ]);
        $user = User::factory()->create(['email' => $email, 'password' => 'secret-pass', 'account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('admin');

        return $user;
    }

    private function login(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/login', ['email' => 'asha@acme.test', 'password' => 'secret-pass'] + $extra);
    }

    // ---- signup email checks ------------------------------------------------------------------

    public function test_a_disposable_email_is_refused_before_any_code_is_sent(): void
    {
        $this->configureMail();

        $this->postJson('/api/auth/register/send-code', ['email' => 'bot@mailinator.com'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email']);
        $this->postJson('/api/auth/register/send-code', ['email' => 'bot@x.yopmail.com'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email']);

        $this->assertSame(0, EmailVerificationCode::count());
    }

    public function test_domains_from_the_shipped_community_list_are_refused(): void
    {
        $list = file(resource_path('data/disposable_email_domains.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertGreaterThan(5000, count($list));

        foreach ([$list[100], $list[(int) (count($list) / 2)], end($list)] as $domain) {
            $this->postJson('/api/auth/register/send-code', ['email' => "someone@{$domain}"])
                ->assertUnprocessable()->assertJsonValidationErrors(['email']);
        }
        $this->assertTrue(\App\Rules\DeliverableEmail::isDisposable('inbox.temp-mail.org'));
        $this->assertFalse(\App\Rules\DeliverableEmail::isDisposable('gmail.com'));
        $this->assertFalse(\App\Rules\DeliverableEmail::isDisposable('simplelogic.in'));
    }

    public function test_a_domain_that_cannot_receive_mail_is_refused_when_the_mx_check_is_on(): void
    {
        config(['security.signup.check_mx' => true]);

        $this->postJson('/api/auth/register/send-code', ['email' => 'someone@no-such-domain.invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email']);
    }

    public function test_an_email_that_already_has_an_account_is_refused(): void
    {
        $this->clientUser();

        $this->postJson('/api/auth/register/send-code', ['email' => 'asha@acme.test'])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', 'An account with this email already exists. Please log in instead.');
    }

    public function test_a_code_is_emailed_and_stored_hashed(): void
    {
        $this->configureMail();

        $this->postJson('/api/auth/register/send-code', ['email' => 'Asha@Acme.test'])->assertOk()->assertJsonPath('expires_in_minutes', 10);

        $row = EmailVerificationCode::where('email', 'asha@acme.test')->firstOrFail();
        $this->assertStringNotContainsString($row->code_hash, json_encode($row->toArray()));
        $this->assertCount(1, $this->sentSubjects());
        $this->assertMatchesRegularExpression('/^\d{6} is your WapHub verification code$/', $this->sentSubjects()[0]);
        $this->assertTrue(Hash::check(substr($this->sentSubjects()[0], 0, 6), $row->code_hash));
    }

    public function test_without_mail_settings_signup_says_so_instead_of_pretending(): void
    {
        $this->postJson('/api/auth/register/send-code', ['email' => 'asha@acme.test'])
            ->assertStatus(503)->assertJsonPath('error_code', 'EMAIL_NOT_SENT');

        $this->assertSame(0, EmailVerificationCode::count());
    }

    public function test_a_wrong_code_is_counted_and_locks_after_too_many_tries(): void
    {
        EmailVerificationCode::create(['email' => 'asha@acme.test', 'code_hash' => Hash::make('123456'), 'attempts' => 0, 'expires_at' => now()->addMinutes(10)]);
        $body = ['company_name' => 'Acme', 'name' => 'Asha', 'email' => 'asha@acme.test', 'password' => 'secret-pass', 'password_confirmation' => 'secret-pass', 'accept_terms' => true];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/register', $body + ['email_code' => '000000'])->assertUnprocessable()->assertJsonValidationErrors(['email_code']);
        }

        // Even the right code no longer works once locked.
        $this->postJson('/api/auth/register', $body + ['email_code' => '123456'])
            ->assertUnprocessable()->assertJsonPath('errors.email_code.0', 'Too many wrong codes. Please request a new one.');
        $this->assertDatabaseMissing('users', ['email' => 'asha@acme.test']);
    }

    public function test_an_expired_code_is_refused(): void
    {
        EmailVerificationCode::create(['email' => 'asha@acme.test', 'code_hash' => Hash::make('123456'), 'attempts' => 0, 'expires_at' => now()->subMinute()]);

        $this->postJson('/api/auth/register', [
            'company_name' => 'Acme', 'name' => 'Asha', 'email' => 'asha@acme.test', 'password' => 'secret-pass',
            'password_confirmation' => 'secret-pass', 'accept_terms' => true, 'email_code' => '123456',
        ])->assertUnprocessable()->assertJsonPath('errors.email_code.0', 'This code has expired. Please request a new one.');
    }

    // ---- device limit (2) ---------------------------------------------------------------------

    public function test_two_devices_log_in_normally_without_any_email(): void
    {
        $this->configureMail();
        $this->clientUser();

        $this->login()->assertOk();
        $this->login()->assertOk();

        $this->assertSame(2, User::where('email', 'asha@acme.test')->first()->tokens()->count());
        $this->assertSame([], $this->sentSubjects());
    }

    public function test_a_third_device_is_warned_and_can_log_every_device_out_which_emails_the_user(): void
    {
        $this->configureMail();
        $this->clientUser();
        $first = $this->login()->assertOk()->json('token');
        $second = $this->login()->assertOk()->json('token');

        $this->login()->assertStatus(409)
            ->assertJsonPath('error_code', 'ACTIVE_SESSION')
            ->assertJsonPath('active_sessions', 2)
            ->assertJsonPath('max_devices', 2);
        $this->assertSame([], $this->sentSubjects());

        $third = $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0')->login(['force' => true])->assertOk()->json('token');

        $this->assertSame(1, User::where('email', 'asha@acme.test')->first()->tokens()->count());
        foreach ([$first, $second] as $old) {
            $this->app['auth']->forgetGuards();
            $this->withToken($old)->getJson('/api/auth/me')->assertUnauthorized();
        }
        $this->app['auth']->forgetGuards();
        $this->withToken($third)->getJson('/api/auth/me')->assertOk();

        $this->assertSame(['Security alert: all devices were logged out of your WapHub account'], $this->sentSubjects());
        $body = Mail::mailer('account_mail')->getSymfonyTransport()->messages()->first()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('Chrome on Windows', $body);
        $this->assertStringContainsString('/forgot-password', $body);
    }

    public function test_logging_out_frees_a_device_slot(): void
    {
        $this->clientUser();
        $this->login()->assertOk();
        $token = $this->login()->assertOk()->json('token');
        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();

        $this->login()->assertOk();
    }

    public function test_the_device_limit_can_be_configured_or_switched_off(): void
    {
        $this->clientUser();

        config(['security.account.max_devices' => 1]);
        $this->login()->assertOk();
        $this->login()->assertStatus(409);

        config(['security.account.max_devices' => 0]);
        $this->login()->assertOk();
        $this->login()->assertOk();
    }

    // ---- emails -------------------------------------------------------------------------------

    public function test_a_failed_login_sends_no_alert(): void
    {
        $this->configureMail();
        $this->clientUser();

        $this->postJson('/api/auth/login', ['email' => 'asha@acme.test', 'password' => 'wrong-pass'])->assertUnprocessable();

        $this->assertSame([], $this->sentSubjects());
    }

    public function test_changing_the_password_signs_out_every_other_device_and_emails_the_user(): void
    {
        config(['security.account.max_devices' => 0]);
        $this->configureMail();
        $this->clientUser();
        $here = $this->login()->json('token');
        $elsewhere = $this->login()->json('token');

        $this->withToken($here)->patchJson('/api/auth/profile', [
            'name' => 'Asha', 'current_password' => 'secret-pass', 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass',
        ])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($elsewhere)->getJson('/api/auth/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($here)->getJson('/api/auth/me')->assertOk();
        $this->assertSame(['Your WapHub password was changed'], $this->sentSubjects());
    }
}
