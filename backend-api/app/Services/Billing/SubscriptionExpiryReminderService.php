<?php

namespace App\Services\Billing;

use App\Models\Account;
use App\Models\InAppNotification;
use App\Models\Invoice;
use App\Models\MailSetting;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionReminder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Renewal reminders for a plan term: one email and one in-app notification 7 days before the term ends, and one more
 * 7 days after it ended (when sending is paused). Each reminder is sent once per term; SubscriptionReminder records it.
 *
 * The email goes out through the Super Admin's saved SMTP settings (MailSetting). Without them the in-app notification
 * still goes out and the reminder is recorded, so the job never sends the same reminder twice.
 */
class SubscriptionExpiryReminderService
{
    public const KIND_EXPIRING = 'expiring_7d';
    public const KIND_EXPIRED = 'expired_7d';

    private const MAILER_NAME = 'subscription_expiry';
    private const WINDOW_DAYS = 7;

    /** The expired reminder is only for a term that ended within this many days; older ones are not chased. */
    private const EXPIRED_LOOKBACK_DAYS = 30;

    /** @return array{expiring: int, expired: int} how many reminders were sent in this run */
    public function sendDue(?Carbon $now = null): array
    {
        $now ??= now();
        $sent = ['expiring' => 0, 'expired' => 0];

        // Accounts whose CURRENT term ends within the next 7 days. A renewed account has a later term, so it is not here.
        Account::query()
            ->whereHas('currentSubscription', fn ($q) => $q->whereBetween('expires_at', [$now, $now->copy()->addDays(self::WINDOW_DAYS)]))
            ->with('currentSubscription')
            ->chunkById(100, function ($accounts) use ($now, &$sent) {
                foreach ($accounts as $account) {
                    if ($this->remind($account, self::KIND_EXPIRING, $now)) {
                        $sent['expiring']++;
                    }
                }
            });

        // Accounts whose current term ended 7 to 30 days ago and who have not renewed since.
        Account::query()
            ->whereHas('currentSubscription', fn ($q) => $q->whereBetween('expires_at', [$now->copy()->subDays(self::EXPIRED_LOOKBACK_DAYS), $now->copy()->subDays(self::WINDOW_DAYS)]))
            ->with('currentSubscription')
            ->chunkById(100, function ($accounts) use ($now, &$sent) {
                foreach ($accounts as $account) {
                    if ($this->remind($account, self::KIND_EXPIRED, $now)) {
                        $sent['expired']++;
                    }
                }
            });

        return $sent;
    }

    /** Sends one reminder for the account's current term, if it has not gone out yet. Returns true when sent. */
    private function remind(Account $account, string $kind, Carbon $now): bool
    {
        $subscription = $account->currentSubscription;
        $owner = $account->owner;

        if (! $subscription || ! $subscription->expires_at || ! $owner) {
            return false;
        }

        try {
            SubscriptionReminder::query()->create([
                'account_id' => $account->id,
                'kind' => $kind,
                'expires_at' => $subscription->expires_at,
                'sent_at' => $now,
            ]);
        } catch (QueryException) {
            // The unique key: this reminder for this term already went out (another run got there first).
            return false;
        }

        $this->notify($owner, $subscription, $kind);
        $this->email($owner, $account, $subscription, $kind);

        return true;
    }

    private function notify(User $owner, Subscription $subscription, string $kind): void
    {
        $date = $this->date($subscription->expires_at);

        InAppNotification::query()->create([
            'user_id' => $owner->id,
            'title' => $kind === self::KIND_EXPIRING ? 'Your plan expires soon' : 'Your plan has expired',
            'body' => $kind === self::KIND_EXPIRING
                ? "Your plan ends on {$date}. Renew to keep sending without interruption."
                : "Your plan ended on {$date}. Sending is paused until you renew.",
            'category' => 'subscription',
            'link' => '/billing',
            'is_read' => false,
        ]);
    }

    private function email(User $owner, Account $account, Subscription $subscription, string $kind): void
    {
        $mailSetting = MailSetting::currentCached();

        if (! $mailSetting || ! $mailSetting->isConfigured() || ! $owner->email) {
            Log::info('Subscription reminder email skipped: no configured mail settings or no owner email.', ['account_id' => $account->id, 'kind' => $kind]);

            return;
        }

        [$subject, $html] = $this->render($owner, $account, $subscription, $kind);

        try {
            Config::set('mail.mailers.'.self::MAILER_NAME, $mailSetting->toMailerConfig());

            Mail::mailer(self::MAILER_NAME)->html($html, function ($message) use ($owner, $mailSetting, $subject) {
                $message->to($owner->email)
                    ->from($mailSetting->from_address, $mailSetting->from_name ?: 'WhatsApp SaaS Platform')
                    ->subject($subject);
            });
        } catch (\Throwable $e) {
            // A failed email does not undo the reminder: the in-app notice is already there and the term is recorded.
            Log::warning('Subscription reminder email failed.', ['account_id' => $account->id, 'kind' => $kind, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The subject and HTML body for a reminder. Public so the wording can be checked without sending.
     *
     * @return array{0: string, 1: string}
     */
    public function render(User $owner, Account $account, Subscription $subscription, string $kind): array
    {
        $expiring = $kind === self::KIND_EXPIRING;
        $plan = $this->planLabel($account);
        $date = $this->date($subscription->expires_at);
        $amount = $this->renewalAmount($account);
        $renewUrl = rtrim((string) config('services.frontend.url'), '/').'/billing';

        $subject = $expiring
            ? "Your WapHub plan expires on {$date}"
            : 'Your WapHub plan has expired. Renew to resume sending';

        $lead = $expiring
            ? "Your plan ends on <strong>{$date}</strong>. Renew before then so your messages keep going out without a break."
            : "Your plan ended on <strong>{$date}</strong>. Sending is paused until you renew. Renew now to resume.";

        $html = '<div style="font-family:Arial,sans-serif;color:#0f172a;max-width:560px">'
            .'<h2 style="margin:0 0 12px">'.e($subject).'</h2>'
            .'<p>Hello '.e($owner->name ?: 'there').',</p>'
            .'<p>'.$lead.'</p>'
            .'<table style="border-collapse:collapse;margin:16px 0;font-size:14px">'
            .$this->row('Account', $account->company_name)
            .$this->row('Plan', $plan)
            .$this->row('Term ends', $date.' (IST)')
            .$this->row('Renewal amount', $amount !== null ? '₹'.number_format($amount, 0) : 'As shown on the billing page')
            .'</table>'
            .'<p><a href="'.e($renewUrl).'" style="display:inline-block;background:#dc2626;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;font-weight:600">Renew now</a></p>'
            .'<p style="color:#64748b;font-size:12px">You are receiving this because you are the owner of this WapHub account.</p>'
            .'</div>';

        return [$subject, $html];
    }

    private function row(string $label, ?string $value): string
    {
        return '<tr><td style="padding:4px 16px 4px 0;color:#64748b">'.e($label).'</td><td style="padding:4px 0;font-weight:600">'.e((string) $value).'</td></tr>';
    }

    private function date(Carbon $at): string
    {
        return $at->copy()->timezone('Asia/Kolkata')->format('d M Y, h:i A');
    }

    /** The label of the plan the account last paid for. */
    private function planLabel(Account $account): ?string
    {
        $planKey = $this->lastPaidPlanKey($account);

        return $planKey ? Plan::query()->where('slug', $planKey)->value('label') : null;
    }

    /** The current plan's price, the amount to renew. Null when it cannot be read. */
    private function renewalAmount(Account $account): ?float
    {
        $planKey = $this->lastPaidPlanKey($account);
        $price = $planKey ? Plan::query()->where('slug', $planKey)->value('price') : null;

        return $price !== null ? (float) $price : null;
    }

    private function lastPaidPlanKey(Account $account): ?string
    {
        return Invoice::query()
            ->where('account_id', $account->id)
            ->where('status', 'paid')
            ->whereIn('plan_key', Plan::query()->pluck('slug'))
            ->orderByDesc('paid_at')
            ->value('plan_key');
    }
}
