<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * QA fixes (2026-10-14) — "own leads only".
 *
 * A CRM user holding `view-all-crm` (admin, social_marketer, Super Admin)
 * sees every lead, deal and task of the account, as before. Anyone else with
 * CRM access (the 'sales' role, or a member given manage-crm on the Team
 * page) sees only the records assigned to them. Applied by the CRM list and
 * lookup queries, so a record that is not yours is "not found".
 */
final class CrmVisibility
{
    public static function seesAll(?User $user): bool
    {
        // An agent manages its clients' CRM: it sees all of a client's records.
        return $user === null || $user->isSuperAdmin() || $user->account?->account_type === 'agent' || $user->checkPermissionTo('view-all-crm');
    }

    /** @param  list<string>  $ownerColumns  any of them matching the user makes the row visible */
    public static function apply(Builder $query, ?User $user, array $ownerColumns): Builder
    {
        if (self::seesAll($user)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user, $ownerColumns) {
            foreach ($ownerColumns as $column) {
                $q->orWhere($q->getModel()->qualifyColumn($column), $user->id);
            }
        });
    }
}
