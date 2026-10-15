<?php

namespace App\Services\Notifications;

use App\Models\Account;
use App\Models\InAppNotification;
use App\Models\User;
use App\Services\Mail\SystemMailer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * QA fixes (2026-10-14) — tell a client when something stops working.
 *
 * WhatsApp disconnecting, a number paused, the message quota or a plan limit
 * running out, a social connection expiring, an ads guard that can no longer
 * read its campaigns: each of these used to change state silently. One call
 * here notifies the account's admins in the app and emails the owner (the
 * "account.alert" master template), at most once per $dedupeKey per
 * $dedupeHours, so a flapping connection cannot flood anyone. Best effort —
 * it never throws into the code path that noticed the problem.
 */
class AccountAlerts
{
    public function __construct(private readonly SystemMailer $mailer) {}

    public function notify(
        Account $account,
        string $dedupeKey,
        string $title,
        string $message,
        string $category,
        ?string $link = null,
        string $actionLabel = 'Open',
        int $dedupeHours = 6,
        bool $email = true,
    ): bool {
        try {
            if (! Cache::add("account_alert:{$account->id}:{$dedupeKey}", 1, now()->addHours($dedupeHours))) {
                return false;
            }

            $admins = User::query()
                ->where('account_id', $account->id)
                ->where('is_active', true)
                ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
                ->get();
            if ($admins->isEmpty()) {
                $admins = User::query()->where('account_id', $account->id)->where('is_active', true)->orderBy('id')->limit(1)->get();
            }

            foreach ($admins as $admin) {
                InAppNotification::query()->create([
                    'user_id' => $admin->id,
                    'title' => $title,
                    'body' => $message,
                    'category' => $category,
                    'link' => $link,
                    'is_read' => false,
                ]);
            }

            $owner = $account->owner ?? $admins->first();
            if ($email && $owner?->email) {
                $this->mailer->sendLater('account.alert', $owner->email, [
                    'name' => $owner->name,
                    'company_name' => $account->company_name,
                    'alert_title' => $title,
                    'alert_message' => $message,
                    'action_label' => $actionLabel,
                    'action_url' => $this->mailer->url($link ?? '/'),
                ], [], $account->id, $owner->name);
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('AccountAlerts: could not notify the account.', ['account_id' => $account->id, 'key' => $dedupeKey, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /** Lets the same alert fire again right away (e.g. after a reconnect, the next disconnect is news). */
    public function reset(Account $account, string $dedupeKey): void
    {
        Cache::forget("account_alert:{$account->id}:{$dedupeKey}");
    }
}
