<?php

namespace App\Services\Access;

use App\Models\Account;

/**
 * Phase 12 Task 4 — a short-lived memo for ONE authorization decision (one
 * IndustryAuthorizer::denial() call, or one IndustryModuleResolver::contextFor()
 * pass). It lets the several target checks inside that single call share the
 * fresh Account + currentSubscription load, the capability/module lookups and
 * the industry-assignment lookup instead of re-running identical queries.
 *
 * Deliberately NOT a container singleton, static, or cache: every instance is
 * created by the caller, lives on the caller's stack and is discarded with it,
 * so nothing can survive into another request, user, tenant or job, and a
 * state change between two requests (or two separate denial() calls) is always
 * seen. Every key includes the account id, so one scope can never answer for
 * a different account than the one it was filled for.
 */
final class DenialScope
{
    /** @var array<int, Account|null> */
    private array $targets = [];

    /** @var array<string, bool> */
    private array $memo = [];

    /** Fresh target (with currentSubscription), loaded once per scope — the same load the gate always did. */
    public function target(Account $account): ?Account
    {
        if (! array_key_exists($account->id, $this->targets)) {
            $this->targets[$account->id] = Account::query()->with('currentSubscription')->find($account->id);
        }

        return $this->targets[$account->id];
    }

    /** @param  callable(): bool  $resolve */
    public function remember(string $key, callable $resolve): bool
    {
        return $this->memo[$key] ??= $resolve();
    }

    /** Seed an answer the caller has already read from the database inside this same call. */
    public function seed(string $key, bool $value): void
    {
        $this->memo[$key] = $value;
    }
}
