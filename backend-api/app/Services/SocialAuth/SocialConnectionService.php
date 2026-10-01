<?php

namespace App\Services\SocialAuth;

use App\Models\SocialAccount;
use App\Services\SocialAuth\Exceptions\ProviderRequestFailed;
use App\Services\SocialAuth\Exceptions\SocialConnectionException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 9 Task 2 — the one place a social connection's health is decided
 * and persisted. Provider-agnostic: it talks only to
 * SocialOAuthProviderInterface (checkConnection() / classifyApiFailure()),
 * never to a provider URL or error code.
 *
 * Callers:
 *   - `social:check-connections` (claimDue()) → CheckSocialConnectionJob (check())
 *   - POST /social/accounts/{id}/check (check())
 *   - features using a stored connection (ads, publishing, inbox):
 *     assertUsable() before calling the provider, escalate()/observe() when
 *     a provider call fails.
 *
 * Status transitions (stored in social_accounts.health_status, see
 * SocialConnectionStatus):
 *   connected → expired    token_expires_at passed (decided locally, no provider call)
 *                          or the provider reports the token expired
 *   connected → revoked    the provider no longer accepts the grant / a permission was removed
 *   expired|revoked → connected   a manual Check the provider confirms, or a reconnect (bind)
 *   unknown (unreachable / not configured / unrecognised failure) → no status change;
 *                          only health_check_attempted_at moves, so the next attempt waits an interval
 *   locally disconnected (row deleted) → nothing to check; a result is dropped
 */
class SocialConnectionService
{
    public function intervalMinutes(): int
    {
        return max(5, (int) config('social.connection_checks.interval_minutes', 60));
    }

    public function batchSize(): int
    {
        return max(1, (int) config('social.connection_checks.batch_size', 100));
    }

    /**
     * Claims up to $limit connections that are due for a scheduled check and
     * returns their ids. Only `connected` rows of an implemented provider are
     * eligible (expired/revoked ones need the user to reconnect — asking the
     * provider again changes nothing). A row is due when neither a check
     * attempt nor a confirmed status is newer than the interval. Each row is
     * claimed with one conditional UPDATE, so of any number of concurrent
     * runs exactly one wins it.
     *
     * @return list<int>
     */
    public function claimDue(?int $limit = null): array
    {
        $cutoff = now()->subMinutes($this->intervalMinutes());

        $candidates = $this->dueQuery($cutoff)
            ->orderBy('health_check_attempted_at')
            ->orderBy('id')
            ->limit($limit ?? $this->batchSize())
            ->pluck('id');

        $claimed = [];

        foreach ($candidates as $id) {
            $won = $this->dueQuery($cutoff)->whereKey($id)->update(['health_check_attempted_at' => now()]);

            if ($won === 1) {
                $claimed[] = (int) $id;
            }
        }

        return $claimed;
    }

    /**
     * Checks one connection now and persists the outcome. A token already
     * known to be expired is marked expired without calling the provider.
     */
    public function check(SocialAccount $socialAccount, string $trigger): ConnectionCheck
    {
        if (! SocialOAuthProviderFactory::isImplemented($socialAccount->provider)) {
            $result = ConnectionCheck::unknown('This provider is not available right now, so the connection could not be checked.');
        } elseif ($socialAccount->isTokenExpired()) {
            $result = $this->locallyExpired($socialAccount);
        } else {
            try {
                $result = SocialOAuthProviderFactory::make($socialAccount->provider)->checkConnection($socialAccount);
            } catch (Throwable $e) {
                // The contract says never throws; a driver bug must still not flip a status.
                $result = ConnectionCheck::unknown('The connection could not be checked right now. Try again later.');
                Log::warning('Social connection check failed unexpectedly.', ['social_account_id' => $socialAccount->id, 'exception' => class_basename($e)]);
            }
        }

        $this->record($socialAccount, $result, $trigger);

        return $result;
    }

    /**
     * Before a feature calls the provider with this connection: a
     * connection whose stored state is expired/revoked (or whose token has
     * passed its expiry) is refused here, without a provider call.
     *
     * @throws SocialConnectionException
     */
    public function assertUsable(SocialAccount $socialAccount, string $operation): void
    {
        if ($socialAccount->health_status === SocialAccount::HEALTH_CONNECTED && $socialAccount->isTokenExpired()) {
            $this->record($socialAccount, $this->locallyExpired($socialAccount), 'preflight:'.$operation);
        }

        $status = $socialAccount->connectionStatus();

        if ($status !== SocialConnectionStatus::CONNECTED) {
            throw SocialConnectionException::for($socialAccount, $status, $socialAccount->status_reason);
        }
    }

    /**
     * A provider call made with this connection failed: when the provider
     * says the failure means the connection is expired/revoked, the new
     * state is persisted and SocialConnectionException is thrown; any other
     * failure is re-thrown unchanged.
     *
     * @throws SocialConnectionException|Throwable
     */
    public function escalate(SocialAccount $socialAccount, Throwable $failure, string $operation): never
    {
        if ($failure instanceof ProviderRequestFailed && ($result = $this->observe($socialAccount, $failure, $operation))) {
            throw SocialConnectionException::for($socialAccount, $result->status, $result->reason);
        }

        throw $failure;
    }

    /**
     * The non-throwing twin of escalate() for background paths (a list that
     * should keep going, a queued job): persists an expired/revoked result
     * and returns it, or null when the failure is not a connection problem.
     */
    public function observe(SocialAccount $socialAccount, ProviderRequestFailed $failure, string $operation): ?ConnectionCheck
    {
        if (! SocialOAuthProviderFactory::isImplemented($socialAccount->provider)) {
            return null;
        }

        $result = SocialOAuthProviderFactory::make($socialAccount->provider)->classifyApiFailure($failure->httpStatus, $failure->errorBody);

        if ($result === null || ! in_array($result->status, [SocialConnectionStatus::EXPIRED, SocialConnectionStatus::REVOKED], true)) {
            return null;
        }

        $this->record($socialAccount, $result, 'api:'.$operation);

        return $result;
    }

    /**
     * Persists a check outcome. Under a row lock, and only if the row still
     * holds the credentials that were checked: a connection deleted
     * meanwhile (locally disconnected) or reconnected meanwhile (new token)
     * is left alone, so a slow check of an old token can never overwrite a
     * fresh reconnect. An `unknown` result changes no status.
     */
    public function record(SocialAccount $socialAccount, ConnectionCheck $result, string $trigger): void
    {
        $previous = $socialAccount->connectionStatus();
        $checkedToken = $socialAccount->access_token;
        $applied = false;

        DB::transaction(function () use ($socialAccount, $result, $checkedToken, &$applied) {
            $current = SocialAccount::query()->whereKey($socialAccount->id)->lockForUpdate()->first();

            if (! $current || $current->access_token !== $checkedToken) {
                return;
            }

            $attributes = ['health_check_attempted_at' => now()];

            if ($health = SocialConnectionStatus::healthFor($result->status)) {
                $attributes += [
                    'health_status' => $health,
                    'status_reason' => $result->status === SocialConnectionStatus::CONNECTED ? null : mb_substr((string) $result->reason, 0, 255),
                    'status_checked_at' => now(),
                ];
            }

            $current->forceFill($attributes)->save();
            $socialAccount->setRawAttributes($current->getAttributes(), true);
            $applied = true;
        });

        // Safe fields only — never a token, header, code or provider payload.
        Log::info('Social connection checked.', [
            'social_account_id' => $socialAccount->id,
            'account_id' => $socialAccount->account_id,
            'provider' => $socialAccount->provider,
            'asset_type' => $socialAccount->asset_type,
            'trigger' => $trigger,
            'result' => $result->status,
            'previous_status' => $previous,
            'new_status' => $applied ? $socialAccount->connectionStatus() : $previous,
            'applied' => $applied,
            'request_id' => request()?->header('X-Request-Id'),
        ]);
    }

    private function locallyExpired(SocialAccount $socialAccount): ConnectionCheck
    {
        $date = $socialAccount->token_expires_at instanceof Carbon ? $socialAccount->token_expires_at->toDateString() : null;

        return ConnectionCheck::expired($date
            ? "The access token expired on {$date}. Reconnect the account."
            : 'The access token has expired. Reconnect the account.');
    }

    private function dueQuery(Carbon $cutoff): Builder
    {
        return SocialAccount::query()
            ->where('health_status', SocialAccount::HEALTH_CONNECTED)
            ->whereIn('provider', SocialOAuthProviderFactory::IMPLEMENTED_PROVIDERS)
            ->where(fn (Builder $q) => $q->whereNull('health_check_attempted_at')->orWhere('health_check_attempted_at', '<=', $cutoff))
            ->where(fn (Builder $q) => $q->whereNull('status_checked_at')->orWhere('status_checked_at', '<=', $cutoff));
    }
}
