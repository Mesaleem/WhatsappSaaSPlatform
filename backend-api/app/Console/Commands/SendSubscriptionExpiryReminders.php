<?php

namespace App\Console\Commands;

use App\Services\Billing\SubscriptionExpiryReminderService;
use Illuminate\Console\Command;

/** Daily: the plan-expiry reminders (7 days before, and 7 days after the term ended). See the service's docblock. */
class SendSubscriptionExpiryReminders extends Command
{
    protected $signature = 'subscriptions:send-expiry-reminders';

    protected $description = 'Send the 7-days-before and 7-days-after plan expiry reminders (email and in-app).';

    public function handle(SubscriptionExpiryReminderService $reminders): int
    {
        $sent = $reminders->sendDue();

        $this->info("Expiring-soon reminders sent: {$sent['expiring']}. Expired reminders sent: {$sent['expired']}.");

        return self::SUCCESS;
    }
}
