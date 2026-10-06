<?php

namespace App\Services\ApiAccess;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\Plan;
use App\Support\IpMatcher;
use Carbon\Carbon;

/**
 * The Developer API's one authorized server IP per account, and the rules for changing it.
 *
 * The API key alone can be copied, so a request is also checked against the IP it arrives from: the account's
 * API keys work only from `authorized_server_ip`. The IP is the connecting socket address (Request::ip()), never a
 * header a caller can set.
 *
 * Change rules:
 *   - The first save is free and sets ip_registered_at and last_ip_updated_at.
 *   - One free change is allowed EDIT_WAIT_DAYS after the last save (ip_edit_count goes 0 -> 1).
 *   - Every change after that needs a paid plan invoice that was paid after the last save. Paying a plan
 *     renewal unlocks one more change; the paid invoice is checked, the client's word is not trusted.
 *
 * The denial for a request from another IP is generic (see ApiKeyBindingService::denial()), so a holder of a
 * leaked key cannot read the authorized IP from the error.
 */
class ServerIpBindingService
{
    public const EDIT_WAIT_DAYS = 14;

    /** Fallback when the current plan's price cannot be read; the plan's own price is used whenever it can be. */
    public const DEFAULT_RECHARGE_PRICE = 599;

    /** Whether a request from $ip may use this account's API keys. */
    public function allows(Account $account, ?string $ip): bool
    {
        $registered = $account->authorized_server_ip;
        $incoming = $ip === null ? null : IpMatcher::normalize($ip);

        return $registered !== null && $incoming !== null && hash_equals((string) $registered, $incoming);
    }

    /**
     * What the client can do with the IP right now. Used by the API key screen and returned with the change result.
     *
     * @return array{authorized_server_ip: ?string, ip_edit_count: int, ip_registered_at: ?string, last_ip_updated_at: ?string, can_edit: bool, edit_available_at: ?string, require_payment: bool, recharge_price: float}
     */
    public function status(Account $account): array
    {
        $isSet = $account->authorized_server_ip !== null;
        $canEdit = false;
        $requirePayment = false;
        $availableAt = null;

        if ($isSet) {
            if ($account->ip_edit_count === 0) {
                $availableAt = $account->last_ip_updated_at?->copy()->addDays(self::EDIT_WAIT_DAYS);
                $canEdit = $availableAt === null || now()->gte($availableAt);
            } else {
                $requirePayment = ! $this->hasPaidRechargeSince($account);
                $canEdit = ! $requirePayment;
            }
        } else {
            $canEdit = true;
        }

        return [
            'authorized_server_ip' => $account->authorized_server_ip,
            'ip_edit_count' => (int) $account->ip_edit_count,
            'ip_registered_at' => $account->ip_registered_at?->toIso8601String(),
            'last_ip_updated_at' => $account->last_ip_updated_at?->toIso8601String(),
            'can_edit' => $canEdit,
            'edit_available_at' => $isSet && $account->ip_edit_count === 0 && $availableAt && ! $canEdit ? $availableAt->toIso8601String() : null,
            'require_payment' => $requirePayment,
            'recharge_price' => $this->rechargePrice($account),
        ];
    }

    /**
     * Saves a new authorized IP, if the rules allow it.
     *
     * @return array{ok: bool, status: int, code: ?string, message: ?string, extra: array<string, mixed>}
     */
    public function setIp(Account $account, string $raw): array
    {
        $ip = IpMatcher::normalize(trim($raw));
        if ($ip === null || str_contains($raw, '/')) {
            return $this->fail(422, 'INVALID_IP', 'Enter one valid IPv4 or IPv6 address, for example 203.0.113.10.');
        }

        if ($account->authorized_server_ip === $ip) {
            return $this->ok($account);
        }

        if ($account->authorized_server_ip === null) {
            $account->forceFill([
                'authorized_server_ip' => $ip,
                'ip_registered_at' => now(),
                'last_ip_updated_at' => now(),
            ])->save();

            return $this->ok($account);
        }

        $state = $this->status($account);

        if (! $state['can_edit']) {
            if ($state['require_payment']) {
                $price = number_format($state['recharge_price'], 0);

                return $this->fail(403, 'LIMIT_EXCEEDED', "Your 1-time lifetime free IP update has been used. To change your registered server IP, please recharge your current plan (₹{$price}).", ['require_payment' => true, 'recharge_price' => $state['recharge_price']]);
            }

            return $this->fail(403, 'IP_EDIT_TOO_EARLY', 'Your server IP can be changed once, 14 days after it was saved. You can change it on '.$state['edit_available_at'].'.', ['edit_available_at' => $state['edit_available_at']]);
        }

        $account->forceFill([
            'authorized_server_ip' => $ip,
            'ip_edit_count' => (int) $account->ip_edit_count + 1,
            'last_ip_updated_at' => now(),
        ])->save();

        return $this->ok($account);
    }

    /**
     * Support's reset for a shared-hosting client whose host changed the outbound IP: the edit count goes back to
     * zero, so the next IP change is free at once. Only a Super Admin reaches this (see ServerIpAdminController).
     */
    public function resetEditCount(Account $account): void
    {
        $account->forceFill(['ip_edit_count' => 0, 'last_ip_updated_at' => null])->save();
    }

    /** A plan invoice paid after the last IP save: the verified recharge that unlocks one more change. */
    private function hasPaidRechargeSince(Account $account): bool
    {
        if ($account->last_ip_updated_at === null) {
            return true;
        }

        return Invoice::query()
            ->where('account_id', $account->id)
            ->where('status', 'paid')
            ->whereIn('plan_key', Plan::query()->pluck('slug'))
            ->where('paid_at', '>', $account->last_ip_updated_at)
            ->exists();
    }

    /** The price of the plan the account last paid for; the fallback when it cannot be read. */
    private function rechargePrice(Account $account): float
    {
        $planKey = Invoice::query()
            ->where('account_id', $account->id)
            ->where('status', 'paid')
            ->whereIn('plan_key', Plan::query()->pluck('slug'))
            ->orderByDesc('paid_at')
            ->value('plan_key');

        $price = $planKey ? Plan::query()->where('slug', $planKey)->value('price') : null;

        return $price !== null ? (float) $price : (float) self::DEFAULT_RECHARGE_PRICE;
    }

    /** @return array{ok: bool, status: int, code: ?string, message: ?string, extra: array<string, mixed>} */
    private function ok(Account $account): array
    {
        $account->refresh();

        return ['ok' => true, 'status' => 200, 'code' => null, 'message' => null, 'extra' => ['server_ip' => $this->status($account)]];
    }

    /** @return array{ok: bool, status: int, code: ?string, message: ?string, extra: array<string, mixed>} */
    private function fail(int $status, string $code, string $message, array $extra = []): array
    {
        return ['ok' => false, 'status' => $status, 'code' => $code, 'message' => $message, 'extra' => $extra];
    }
}
