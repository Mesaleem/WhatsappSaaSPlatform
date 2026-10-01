<?php

namespace App\Services\Industry;

use App\Models\Account;
use App\Models\AccountIndustry;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Phase 11 Task 1 — which industries an account is assigned. Assignment is data about the account;
 * it never grants access by itself (IndustryAuthorizer decides that).
 */
class IndustryResolver
{
    public function __construct(private readonly IndustryRegistry $registry)
    {
    }

    /** @return Collection<int, AccountIndustry> */
    public function forAccount(Account $account): Collection
    {
        return AccountIndustry::query()->where('account_id', $account->id)->orderBy('industry')->get();
    }

    public function has(Account $account, string $industry): bool
    {
        return AccountIndustry::query()->where('account_id', $account->id)->where('industry', $industry)->exists();
    }

    /**
     * Replace the account's industries with exactly this set (an empty set clears them). Validated
     * against the registry BEFORE anything is written: an unknown industry, a sub-type the industry
     * does not define, or a repeated industry rejects the whole call.
     *
     * @param  list<array{industry: string, subtype?: string|null}>  $industries
     * @return Collection<int, AccountIndustry>
     *
     * @throws InvalidArgumentException
     */
    public function sync(Account $account, array $industries, ?User $actor = null): Collection
    {
        $wanted = [];
        foreach ($industries as $row) {
            $industry = (string) ($row['industry'] ?? '');
            $subtype = isset($row['subtype']) && $row['subtype'] !== '' ? (string) $row['subtype'] : null;

            if (! $this->registry->exists($industry)) {
                throw new InvalidArgumentException("Unknown industry '{$industry}'.");
            }
            if (! $this->registry->validSubtype($industry, $subtype)) {
                throw new InvalidArgumentException("'{$subtype}' is not a valid type for the {$industry} industry.");
            }
            if (array_key_exists($industry, $wanted)) {
                throw new InvalidArgumentException("The {$industry} industry is listed twice.");
            }
            $wanted[$industry] = $subtype;
        }

        $current = AccountIndustry::query()->where('account_id', $account->id)->get()->keyBy('industry');

        foreach ($current as $industry => $row) {
            if (! array_key_exists($industry, $wanted)) {
                $row->delete();
            }
        }
        foreach ($wanted as $industry => $subtype) {
            $existing = $current->get($industry);
            if ($existing) {
                if ($existing->subtype !== $subtype) {
                    $existing->update(['subtype' => $subtype]);
                }

                continue;
            }
            AccountIndustry::create(['account_id' => $account->id, 'industry' => $industry, 'subtype' => $subtype, 'assigned_by_user_id' => $actor?->id]);
        }

        return $this->forAccount($account);
    }
}
