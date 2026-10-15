<?php

namespace App\Services\Billing;

use App\Models\Account;
use App\Models\MessageTemplate;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppFlow;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Plan limits (plan restructure, 2026-10-15), enforced from the running
 * subscription — the limits the client's last plan purchase bought
 * (Plan::LIMITS, copied at payment). NULL = unlimited, 0 = not included.
 *
 *   max_meta_templates        the client's own templates (rejected ones do not count)
 *   max_flows                 journeys
 *   max_broadcasts_per_month  bulk sends started in the current 30-day slice of the term
 *   max_users                 see Account::userLimit() / TeamController
 *   marketing_message_limit   see step 3 (message sending)
 *
 * A Super Admin acting for a client is not held to the client's plan (owner
 * decision, 2026-09-30): callers pass $actor and nothing is checked for one.
 */
class PlanLimits
{
    public const BROADCAST = 'broadcast';

    private const PERIOD_DAYS = 30;

    public function assertCanAddTemplate(Account $account, ?User $actor = null): void
    {
        $limit = $this->limit($account, 'max_meta_templates', $actor);
        if ($limit === null) {
            return;
        }

        $used = MessageTemplate::query()->where('account_id', $account->id)->where('status', '!=', 'rejected')->count();
        if ($used >= $limit) {
            throw new PlanLimitException(
                $limit === 0
                    ? 'Your plan does not include message templates. Upgrade your plan to add templates.'
                    : "Your plan allows {$limit} templates and you have {$used}. Delete one or upgrade your plan.",
                'max_meta_templates',
                $limit,
            );
        }
    }

    public function assertCanAddFlow(Account $account, ?User $actor = null): void
    {
        $limit = $this->limit($account, 'max_flows', $actor);
        if ($limit === null) {
            return;
        }

        $used = WhatsAppFlow::query()->where('account_id', $account->id)->count();
        if ($used >= $limit) {
            throw new PlanLimitException(
                $limit === 0
                    ? 'Your plan does not include journeys. Upgrade your plan to build one.'
                    : "Your plan allows {$limit} journeys and you have {$used}. Delete one or upgrade your plan.",
                'max_flows',
                $limit,
            );
        }
    }

    /**
     * QA fixes (2026-10-14) — after a plan change (e.g. Meta Pro -> Meta Growth):
     *  - active journeys above the new limit are switched off (the newest stay on);
     *  - team members / templates above it are left alone (nothing is deleted or
     *    locked out) but the client is told what to reduce.
     */
    public function applyAfterPlanChange(Account $account): void
    {
        $subscription = Subscription::query()->where('account_id', $account->id)->orderByDesc('starts_at')->orderByDesc('id')->first();
        if ($subscription === null) {
            return;
        }
        $notes = [];

        if ($subscription->max_flows !== null) {
            $active = WhatsAppFlow::query()->where('account_id', $account->id)->where('is_active', true)->orderByDesc('updated_at')->orderByDesc('id')->pluck('id');
            $extra = $active->slice((int) $subscription->max_flows);
            if ($extra->isNotEmpty()) {
                WhatsAppFlow::query()->whereIn('id', $extra->all())->update(['is_active' => false, 'updated_at' => now()]);
                $notes[] = $extra->count().' journey(s) were switched off (your plan allows '.$subscription->max_flows.' active)';
            }
        }
        if ($subscription->max_users !== null) {
            $users = \App\Models\User::query()->where('account_id', $account->id)->where('is_active', true)->count();
            if ($users > (int) $subscription->max_users) {
                $notes[] = "you have {$users} active team users and the plan allows {$subscription->max_users} — deactivate some in Team Users";
            }
        }
        if ($subscription->max_meta_templates !== null) {
            $templates = MessageTemplate::query()->where('account_id', $account->id)->where('status', '!=', 'rejected')->count();
            if ($templates > (int) $subscription->max_meta_templates) {
                $notes[] = "you have {$templates} templates and the plan allows {$subscription->max_meta_templates} — no new ones can be added until you delete some";
            }
        }

        if ($notes !== []) {
            app(\App\Services\Notifications\AccountAlerts::class)->notify(
                $account,
                'plan-limits-'.$subscription->id,
                'Your new plan has lower limits',
                'With your new plan: '.implode('; ', $notes).'.',
                'subscription',
                '/billing',
                'Open billing',
                24,
            );
        }
    }

    /**
     * QA fixes (2026-10-14): switching a journey ON must fit the plan's journey limit,
     * counted on ACTIVE journeys — after a downgrade the extra ones stay off.
     */
    public function assertCanActivateFlow(Account $account, WhatsAppFlow $flow, ?User $actor = null): void
    {
        $limit = $this->limit($account, 'max_flows', $actor);
        if ($limit === null) {
            return;
        }

        $active = WhatsAppFlow::query()->where('account_id', $account->id)->where('is_active', true)->whereKeyNot($flow->id)->count();
        if ($active >= $limit) {
            throw new PlanLimitException(
                $limit === 0
                    ? 'Your plan does not include journeys. Upgrade your plan to use them.'
                    : "Your plan allows {$limit} active journeys and {$active} are on. Switch one off or upgrade your plan.",
                'max_flows',
                $limit,
            );
        }
    }

    /**
     * Count one broadcast (a bulk send) against this month of the plan, or
     * refuse it. The check and the count happen under the subscription row
     * lock, so two sends at once cannot both take the last one.
     */
    public function consumeBroadcast(Account $account, string $source, ?User $actor = null): void
    {
        if ($actor?->isSuperAdmin() || $account->isPlatformAccount()) {
            return;
        }

        DB::transaction(function () use ($account, $source, $actor) {
            $subscription = Subscription::query()->where('account_id', $account->id)
                ->orderByDesc('starts_at')->orderByDesc('id')->lockForUpdate()->first();
            $limit = $subscription?->max_broadcasts_per_month;
            if ($limit === null) {
                return;
            }

            $used = $this->broadcastsUsed($account, $subscription);
            if ($used >= $limit) {
                throw new PlanLimitException(
                    $limit === 0
                        ? 'Your plan does not include broadcasts (bulk sending). Upgrade your plan to send to many contacts at once.'
                        : "Your plan allows {$limit} broadcasts a month and this month's are used. They renew on ".$this->periodEnd($subscription)->timezone('Asia/Kolkata')->format('d M Y').'.',
                    'max_broadcasts_per_month',
                    (int) $limit,
                );
            }

            DB::table('plan_usage_events')->insert([
                'account_id' => $account->id, 'kind' => self::BROADCAST, 'source' => $source,
                'user_id' => $actor?->id, 'created_at' => now(),
            ]);
        });
    }

    /**
     * QA fixes (2026-10-14): gives a broadcast back when the send it was counted
     * for did not go out (refused or failed before anything was sent). The most
     * recent matching use of the last few minutes is removed.
     */
    public function releaseBroadcast(Account $account, string $source): void
    {
        $id = DB::table('plan_usage_events')
            ->where('account_id', $account->id)
            ->where('kind', self::BROADCAST)
            ->where('source', $source)
            ->where('created_at', '>=', now()->subMinutes(10))
            ->orderByDesc('id')
            ->value('id');
        if ($id !== null) {
            DB::table('plan_usage_events')->where('id', $id)->delete();
        }
    }

    /** @return array{limit: ?int, used: int, resets_at: ?string} this month's broadcasts, for the UI */
    public function broadcastUsage(Account $account): array
    {
        $subscription = $account->currentSubscription;
        $limit = $subscription?->max_broadcasts_per_month;

        return [
            'limit' => $limit,
            'used' => $subscription ? $this->broadcastsUsed($account, $subscription) : 0,
            'resets_at' => $subscription && $limit !== null ? $this->periodEnd($subscription)->toIso8601String() : null,
        ];
    }

    /**
     * Every plan limit with what is used, for the Billing page.
     *
     * @return array<string, array{limit: ?int, used: int, resets_at?: ?string}>
     */
    public function usage(Account $account): array
    {
        $subscription = $account->currentSubscription;

        return [
            'max_users' => ['limit' => $account->userLimit(), 'used' => $account->users()->count()],
            'max_meta_templates' => [
                'limit' => $subscription?->max_meta_templates,
                'used' => MessageTemplate::query()->where('account_id', $account->id)->where('status', '!=', 'rejected')->count(),
            ],
            'max_flows' => ['limit' => $subscription?->max_flows, 'used' => WhatsAppFlow::query()->where('account_id', $account->id)->count()],
            'max_broadcasts_per_month' => $this->broadcastUsage($account),
            'marketing_message_limit' => [
                'limit' => $subscription?->marketing_message_limit,
                'used' => $subscription ? self::marketingUsed($subscription) : 0,
                'resets_at' => $subscription?->marketing_message_limit !== null ? $this->periodEnd($subscription)->toIso8601String() : null,
            ],
        ];
    }

    private function broadcastsUsed(Account $account, Subscription $subscription): int
    {
        return DB::table('plan_usage_events')
            ->where('account_id', $account->id)
            ->where('kind', self::BROADCAST)
            ->where('created_at', '>=', $this->periodStart($subscription))
            ->count();
    }

    /** Start of the current 30-day slice of the term (the term's own start for the first one). */
    public static function periodStartFor(Subscription $subscription): CarbonInterface
    {
        return (new self())->periodStart($subscription);
    }

    /** Marketing messages counted in the current slice (0 when the counter belongs to an earlier one). */
    public static function marketingUsed(Subscription $subscription): int
    {
        $from = $subscription->marketing_counted_from;

        return $from !== null && $from->lt(self::periodStartFor($subscription)) ? 0 : (int) $subscription->used_marketing_messages;
    }

    private function periodStart(Subscription $subscription): CarbonInterface
    {
        $start = $subscription->starts_at ?? now();
        if ($start->isFuture()) {
            return $start;
        }

        $slices = intdiv((int) $start->diffInDays(now(), true), self::PERIOD_DAYS);

        return $start->copy()->addDays($slices * self::PERIOD_DAYS);
    }

    private function periodEnd(Subscription $subscription): CarbonInterface
    {
        return $this->periodStart($subscription)->copy()->addDays(self::PERIOD_DAYS);
    }

    private function limit(Account $account, string $column, ?User $actor): ?int
    {
        if ($actor?->isSuperAdmin() || $account->isPlatformAccount()) {
            return null;
        }

        $value = $account->currentSubscription?->{$column};

        return $value === null ? null : (int) $value;
    }
}
