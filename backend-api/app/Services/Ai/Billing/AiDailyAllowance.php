<?php

namespace App\Services\Ai\Billing;

use App\Models\Account;
use App\Models\PlatformSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Free daily AI credits (owner rule, 2026-10-13).
 *
 * The Super Admin sets how many free AI credits a client account and an agent account
 * get each day (PlatformSetting AI_FREE_DAILY_CLIENT / AI_FREE_DAILY_AGENT, default 0).
 * They are used before the paid wallet and EXPIRE at the end of the day: today's
 * unused credits are never added to tomorrow's (each day is its own row, so N a day
 * stays N, never N+N). The day boundary is ai.credits.daily_timezone.
 *
 * Holds are atomic, like the wallet's: hold() claims the estimate only when it fits
 * in what is left today (one conditional UPDATE, so two concurrent calls can never
 * spend more than the allowance), settle() gives back what the call did not use,
 * release() gives back all of it when the call failed.
 */
class AiDailyAllowance
{
    /** Free credits per day for this account's type (the Super Admin's own account needs none: it is unmetered). */
    public function perDayFor(Account $account): int
    {
        $key = match ($account->account_type) {
            'agent' => PlatformSetting::AI_FREE_DAILY_AGENT,
            'client' => PlatformSetting::AI_FREE_DAILY_CLIENT,
            default => null,
        };

        return $key === null ? 0 : max(0, (int) PlatformSetting::get($key));
    }

    /**
     * Hold $amount of today's free credits. Returns the allowance row id, or null when
     * today's free credits cannot cover it (the caller then uses the paid wallet).
     */
    public function hold(Account $account, int $amount): ?int
    {
        if ($amount < 1) {
            return null;
        }

        $id = $this->today($account);

        if ($id === null) {
            return null;
        }

        $claimed = DB::table('ai_daily_allowances')
            ->where('id', $id)
            ->whereRaw('used + ? <= allowance', [$amount])
            ->increment('used', $amount, ['updated_at' => now()]);

        return $claimed === 1 ? $id : null;
    }

    /** The call used $charged of the $held credits: give the rest back. */
    public function settle(int $id, int $held, int $charged): void
    {
        $this->giveBack($id, max(0, $held - max(0, $charged)));
    }

    /** The call failed: give the whole hold back. */
    public function release(int $id, int $held): void
    {
        $this->giveBack($id, $held);
    }

    /**
     * For the header and the credits page.
     *
     * @return array{per_day: int, used: int, remaining: int, resets_at: string}
     */
    public function summary(Account $account): array
    {
        $perDay = $this->perDayFor($account);
        $used = (int) DB::table('ai_daily_allowances')
            ->where('account_id', $account->id)
            ->where('day', $this->day()->toDateString())
            ->value('used');
        $used = min($used, $perDay);

        return [
            'per_day' => $perDay,
            'used' => $used,
            'remaining' => max(0, $perDay - $used),
            'resets_at' => $this->day()->addDay()->toIso8601String(),
        ];
    }

    /**
     * Today's row for the account, with the allowance set to the CURRENT setting (so a
     * change by the Super Admin applies at once). Null when the account gets none.
     */
    private function today(Account $account): ?int
    {
        $perDay = $this->perDayFor($account);

        if ($perDay < 1) {
            return null;
        }

        $day = $this->day()->toDateString();
        $now = now();

        DB::table('ai_daily_allowances')->insertOrIgnore([
            'account_id' => $account->id, 'day' => $day, 'allowance' => $perDay, 'used' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::table('ai_daily_allowances')
            ->where('account_id', $account->id)
            ->where('day', $day)
            ->where('allowance', '!=', $perDay)
            ->update(['allowance' => $perDay, 'updated_at' => $now]);

        return (int) DB::table('ai_daily_allowances')->where('account_id', $account->id)->where('day', $day)->value('id');
    }

    private function giveBack(int $id, int $amount): void
    {
        if ($amount < 1) {
            return;
        }

        // Floored at 0: a double give-back can never create credits.
        DB::table('ai_daily_allowances')->where('id', $id)->update([
            'used' => DB::raw('CASE WHEN used > '.(int) $amount.' THEN used - '.(int) $amount.' ELSE 0 END'),
            'updated_at' => now(),
        ]);
    }

    /** Midnight today in the allowance's timezone. */
    private function day(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('ai.credits.daily_timezone', 'Asia/Kolkata'))->startOfDay();
    }
}
