<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\InAppNotification;
use App\Models\Subscription;
use App\Models\SubscriptionReminder;
use App\Models\User;
use App\Services\Billing\SubscriptionExpiryReminderService;
use Carbon\Carbon;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Plan renewal reminders: 7 days before the term ends, and 7 days after it ended. Each is sent once per term. */
class SubscriptionExpiryReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    /** An account with an owner (an Admin user) and one plan term ending at $expiresAt. */
    private function accountEndingAt(Carbon $expiresAt, ?Carbon $startsAt = null): User
    {
        $account = Account::factory()->create(['company_name' => 'Stock Management']);
        Subscription::factory()->for($account)->create([
            'engine_type' => 'qr',
            'status' => 'active',
            'starts_at' => $startsAt ?? $expiresAt->copy()->subMonth(),
            'expires_at' => $expiresAt,
        ]);

        $owner = User::factory()->create(['account_id' => $account->id]);
        $owner->assignRole('admin');

        return $owner;
    }

    private function run_(): array
    {
        return app(SubscriptionExpiryReminderService::class)->sendDue();
    }

    public function test_a_plan_ending_within_seven_days_gets_one_reminder_and_one_notification(): void
    {
        $owner = $this->accountEndingAt(now()->addDays(5));

        $this->assertSame(['expiring' => 1, 'expired' => 0], $this->run_());

        $notice = InAppNotification::query()->where('user_id', $owner->id)->firstOrFail();
        $this->assertSame('subscription', $notice->category);
        $this->assertSame('/billing', $notice->link);
    }

    public function test_a_second_run_never_sends_the_same_reminder_again(): void
    {
        $owner = $this->accountEndingAt(now()->addDays(3));

        $this->run_();
        $this->assertSame(['expiring' => 0, 'expired' => 0], $this->run_());

        $this->assertSame(1, InAppNotification::query()->where('user_id', $owner->id)->count());
        $this->assertSame(1, SubscriptionReminder::query()->count());
    }

    public function test_a_plan_ending_later_than_seven_days_gets_nothing_yet(): void
    {
        $this->accountEndingAt(now()->addDays(10));

        $this->assertSame(['expiring' => 0, 'expired' => 0], $this->run_());
    }

    public function test_a_plan_that_ended_seven_days_ago_gets_the_expired_reminder(): void
    {
        $owner = $this->accountEndingAt(now()->subDays(7)->subHour());

        $this->assertSame(['expiring' => 0, 'expired' => 1], $this->run_());

        $this->assertSame('Your plan has expired', InAppNotification::query()->where('user_id', $owner->id)->value('title'));
    }

    public function test_a_plan_that_ended_only_two_days_ago_gets_nothing_yet(): void
    {
        $this->accountEndingAt(now()->subDays(2));

        $this->assertSame(['expiring' => 0, 'expired' => 0], $this->run_());
    }

    public function test_a_renewed_account_gets_no_expired_reminder_for_the_old_term(): void
    {
        $account = Account::factory()->create();
        Subscription::factory()->for($account)->create([
            'engine_type' => 'qr', 'status' => 'expired',
            'starts_at' => now()->subMonths(2), 'expires_at' => now()->subDays(8),
        ]);
        Subscription::factory()->for($account)->create([
            'engine_type' => 'qr', 'status' => 'active',
            'starts_at' => now()->subDay(), 'expires_at' => now()->addDays(29),
        ]);
        $owner = User::factory()->create(['account_id' => $account->id]);
        $owner->assignRole('admin');

        $this->assertSame(['expiring' => 0, 'expired' => 0], $this->run_());
        $this->assertSame(0, InAppNotification::query()->where('user_id', $owner->id)->count());
    }

    public function test_the_renewal_email_shows_the_term_end_in_ist_and_links_to_billing(): void
    {
        config(['services.frontend.url' => 'https://app.example.com']);
        $owner = $this->accountEndingAt(now()->addDays(4));
        $subscription = $owner->account->currentSubscription;

        [$subject, $html] = app(SubscriptionExpiryReminderService::class)
            ->render($owner, $owner->account, $subscription, SubscriptionExpiryReminderService::KIND_EXPIRING);

        $this->assertStringContainsString('expires on', $subject);
        $this->assertStringContainsString('IST', $html);
        $this->assertStringContainsString('Stock Management', $html);
        $this->assertStringContainsString('https://app.example.com/billing', $html);
        $this->assertStringContainsString('Renew now', $html);
    }
}
