<?php

namespace App\Console\Commands;

use App\Services\Crm\Sales\CrmReminderService;
use Illuminate\Console\Command;

/** CRM Sales Phase 5 — plan and send due payment reminders (see CrmReminderService). */
class SendCrmPaymentReminders extends Command
{
    protected $signature = 'crm:send-payment-reminders';

    protected $description = 'Plan and send due CRM invoice payment reminders.';

    public function handle(CrmReminderService $reminders): int
    {
        $stats = $reminders->run();
        $this->info(sprintf('Planned %d, sent %d, skipped %d, failed %d, awaiting approval %d.', $stats['planned'], $stats['sent'], $stats['skipped'], $stats['failed'], $stats['awaiting']));

        return self::SUCCESS;
    }
}
