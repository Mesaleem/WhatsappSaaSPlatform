<?php

namespace App\Services\Billing;

use App\Models\Account;
use App\Models\InAppNotification;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionReminder;
use App\Models\User;
use App\Services\Mail\SystemMailer;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Renewal reminders for a plan term: one email and one in-app notification 7 days before the term ends, and one more
 * 7 days after it ended (when sending is paused). Each reminder is sent once per term; SubscriptionReminder records it.
 *
 * The email (master templates billing.plan_expiring / billing.plan_expired, see SystemMailer) goes out through the
 * Super Admin's saved SMTP settings (MailSetting). Without them the in-app notification
 * still goes out and the reminder is recorded, so the job never sends the same reminder twice.
 */
class SubscriptionExpiryReminderService
{
    public const KIND_EXPIRING = 'expiring_7d';
    public const KIND_EXPIRED = 'expired_7d';
    /** QA fixes (2026-10-14): the day the plan ends (nothing was sent between 7 days before and 7 days after). */
    public const KIND_ENDED = 'expired_0d';

    /** A trial is only reminded in its last days (a 7-day trial got "expires soon" right after signup). */
    private const TRIAL_WINDOW_DAYS = 2;

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
                    $isTrial = $this->lastPaidPlanKey($account) === Plan::TRIAL_SLUG;
                    if ($isTrial && $account->currentSubscription->expires_at->gt($now->copy()->addDays(self::TRIAL_WINDOW_DAYS))) {
                        continue;
                    }
                    if ($this->remind($account, self::KIND_EXPIRING, $now)) {
                        $sent['expiring']++;
                    }
                }
            });

        // QA fixes (2026-10-14): accounts whose term ended in the last day — told the same day.
        Account::query()
            ->whereHas('currentSubscription', fn ($q) => $q->whereBetween('expires_at', [$now->copy()->subDay(), $now]))
            ->with('currentSubscription')
            ->chunkById(100, function ($accounts) use ($now, &$sent) {
                foreach ($accounts as $account) {
                    if ($this->remind($account, self::KIND_ENDED, $now)) {
                        $sent['expired']++;
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

        // QA fixes (2026-10-14): the Super Admin (and the client's agent) see who just lapsed.
        if ($kind === self::KIND_ENDED) {
            $mailer = app(SystemMailer::class);
            $mailer->notifyUsers(
                $mailer->reviewersFor($account),
                'Plan ended: '.$account->company_name,
                ($this->planLabel($account) ?? 'The plan').' ended on '.$this->date($subscription->expires_at).'. The client is read-only until it renews.',
                'subscription',
                '/admin/accounts',
            );
        }

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
                : "Your plan ended on {$date}. Sending is paused until you renew or choose a plan.",
            'category' => 'subscription',
            'link' => '/billing',
            'is_read' => false,
        ]);
    }

    private function email(User $owner, Account $account, Subscription $subscription, string $kind): void
    {
        if (! $owner->email) {
            Log::info('Subscription reminder email skipped: the owner has no email.', ['account_id' => $account->id, 'kind' => $kind]);

            return;
        }

        // Best effort: a failed email does not undo the reminder (the in-app notice is already there and the term is recorded).
        [$slug, $vars] = $this->templateData($owner, $account, $subscription, $kind);
        app(SystemMailer::class)->send($slug, $owner->email, $vars, [], $account->id, $owner->name);
    }

    /**
     * The subject and HTML body for a reminder — the "billing.plan_expiring" / "billing.plan_expired" master
     * templates. Public so the wording can be checked without sending.
     *
     * @return array{0: string, 1: string}
     */
    public function render(User $owner, Account $account, Subscription $subscription, string $kind): array
    {
        [$slug, $vars] = $this->templateData($owner, $account, $subscription, $kind);

        return app(SystemMailer::class)->render($slug, $vars);
    }

    /** @return array{0: string, 1: array<string, string>} the template slug and its variables */
    private function templateData(User $owner, Account $account, Subscription $subscription, string $kind): array
    {
        $amount = $this->renewalAmount($account);
        $planKey = $this->lastPaidPlanKey($account);
        // QA fixes (2026-10-14): a trial or a retired plan cannot be "renewed" — point to the plans instead.
        $renewable = $planKey !== null && $planKey !== Plan::TRIAL_SLUG
            && (bool) Plan::query()->where('slug', $planKey)->value('is_active');

        return [
            $kind === self::KIND_EXPIRING ? 'billing.plan_expiring' : 'billing.plan_expired',
            [
                'name' => $owner->name ?: 'there',
                'company_name' => (string) $account->company_name,
                'plan_name' => $this->planLabel($account) ?? '-',
                'expires_at' => $this->date($subscription->expires_at),
                'renewal_amount' => $renewable && $amount !== null ? '₹'.number_format($amount, 0) : 'Choose a plan on the Billing page',
                'billing_url' => rtrim((string) config('services.frontend.url'), '/').'/billing',
            ],
        ];
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
            // id, not the (back-datable) paid_at — QA fixes, 2026-10-14.
            ->orderByDesc('id')
            ->value('plan_key');
    }
}
