<?php

namespace App\Console\Commands;

use App\Models\CrmActivity;
use App\Models\InAppNotification;
use App\Models\User;
use App\Services\Mail\SystemMailer;
use Illuminate\Console\Command;

/**
 * QA fixes (2026-10-14) — CRM follow-ups actually remind someone.
 *
 * A task / call / meeting with a due time and an assignee gets one reminder
 * when it is due within the next 15 minutes (or is already overdue and was
 * never reminded): an in-app notification and an email to the assignee.
 * crm_activities.reminded_at makes it once per task; changing the due time
 * clears it (CrmActivity::saving) so the new time is reminded too.
 */
class SendCrmTaskReminders extends Command
{
    protected $signature = 'crm:send-task-reminders';

    protected $description = 'Remind assignees of CRM tasks that are due soon or overdue.';

    public function handle(SystemMailer $mailer): int
    {
        $sent = 0;

        CrmActivity::query()
            ->where('status', CrmActivity::STATUS_PENDING)
            ->whereNotNull('assigned_user_id')
            ->whereNotNull('due_at')
            ->whereNull('reminded_at')
            ->where('due_at', '<=', now()->addMinutes(15))
            // Very old open tasks are not dug up: a week back at most.
            ->where('due_at', '>=', now()->subDays(7))
            ->orderBy('id')
            ->chunkById(200, function ($activities) use ($mailer, &$sent) {
                foreach ($activities as $activity) {
                    $user = User::query()->whereKey($activity->assigned_user_id)->where('is_active', true)->first();
                    $activity->forceFill(['reminded_at' => now()])->saveQuietly();
                    if ($user === null) {
                        continue;
                    }

                    $when = $activity->due_at->isPast() ? 'was due '.$activity->due_at->timezone('Asia/Kolkata')->format('d M, h:i A') : 'is due at '.$activity->due_at->timezone('Asia/Kolkata')->format('h:i A');
                    $title = ucfirst((string) $activity->type).': '.($activity->subject ?: 'Follow-up');
                    $body = "{$title} {$when}.";

                    InAppNotification::query()->create([
                        'user_id' => $user->id,
                        'title' => 'CRM reminder',
                        'body' => $body,
                        'category' => 'crm',
                        'link' => '/crm/tasks',
                        'is_read' => false,
                    ]);

                    $mailer->sendLater('account.alert', $user->email, [
                        'name' => $user->name,
                        'company_name' => (string) ($user->account?->company_name ?? ''),
                        'alert_title' => 'CRM reminder',
                        'alert_message' => $body,
                        'action_label' => 'Open CRM tasks',
                        'action_url' => $mailer->url('/crm/tasks'),
                    ], [], $user->account_id, $user->name);
                    $sent++;
                }
            });

        $this->info("Sent {$sent} CRM task reminder(s).");

        return self::SUCCESS;
    }
}
