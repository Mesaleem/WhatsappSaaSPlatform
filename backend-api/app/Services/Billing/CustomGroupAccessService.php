<?php

namespace App\Services\Billing;

use App\Models\Account;
use App\Models\ContactGroup;
use App\Models\ModuleAddonRequest;
use Illuminate\Support\Facades\DB;

/**
 * Which custom contact groups a client may use under its paid Custom Contact Groups term.
 *
 * - The term allows its units (the groups it was bought for). Groups within that allowance are open.
 * - A new term that allows fewer groups than the client has leaves the client to choose the groups
 *   to keep. The others are locked until the next term, when the client chooses again.
 * - An upgrade, or a term that starts while another paid term still runs, only raises the allowance:
 *   nothing is locked.
 * - No paid term means the module is off, and nothing here changes.
 */
class CustomGroupAccessService
{
    public function __construct(private readonly ModuleAddonService $addons)
    {
    }

    /** The client's own custom groups: not the default "All Contacts" group, and not a native WhatsApp group. */
    public function customGroups(Account $account)
    {
        return ContactGroup::query()
            ->where('account_id', $account->id)
            ->where('is_default', false)
            ->where('group_type', ContactGroup::GROUP_TYPE_INTERNAL);
    }

    /**
     * True while the client still has to choose which groups to keep this term: some groups are
     * locked and no choice has been recorded for the running term yet.
     */
    public function selectionRequired(Account $account): bool
    {
        if (! $this->customGroups($account)->whereNotNull('locked_at')->exists()) {
            return false;
        }

        return ! ModuleAddonRequest::query()
            ->where('account_id', $account->id)
            ->where('module', 'contact_groups')
            ->where('status', ModuleAddonRequest::PAID)
            ->where('term_ends_at', '>', now())
            ->whereNotNull('group_selection_at')
            ->exists();
    }

    /**
     * Brings the locks in line with the allowance. $freshTerm is true when a term has just started
     * with no other paid term running: then a client over the allowance must choose again.
     */
    public function applyAllowance(Account $account, bool $freshTerm): void
    {
        $limit = $this->addons->activeUnitLimit($account, 'contact_groups');
        if ($limit === null) {
            return;
        }

        $total = $this->customGroups($account)->count();

        if ($total <= $limit) {
            $this->customGroups($account)->whereNotNull('locked_at')->update(['locked_at' => null]);

            return;
        }

        $unlocked = $this->customGroups($account)->whereNull('locked_at')->count();
        if ($freshTerm || $unlocked > $limit) {
            $this->customGroups($account)->update(['locked_at' => now()]);
        }
    }

    /**
     * The client's choice: keep exactly the allowed number of groups (or all of them, when there are
     * fewer), lock the rest. Throws the message the screen shows when the choice is not valid.
     *
     * @param  list<int>  $keepIds
     */
    public function keep(Account $account, array $keepIds): void
    {
        $limit = $this->addons->activeUnitLimit($account, 'contact_groups');
        if ($limit === null) {
            throw new \RuntimeException('Custom Contact Groups is not active on this account.');
        }

        $keepIds = array_values(array_unique(array_map('intval', $keepIds)));
        $customIds = $this->customGroups($account)->pluck('id')->map(fn ($id) => (int) $id)->all();

        if (array_diff($keepIds, $customIds) !== []) {
            throw new \InvalidArgumentException('Choose only your own contact groups.');
        }

        $required = min($limit, count($customIds));
        if (count($keepIds) !== $required) {
            throw new \InvalidArgumentException("Choose exactly {$required} group".($required === 1 ? '' : 's').' to keep.');
        }

        DB::transaction(function () use ($account, $keepIds): void {
            $this->customGroups($account)->whereIn('id', $keepIds)->update(['locked_at' => null]);
            $this->customGroups($account)->whereNotIn('id', $keepIds)->update(['locked_at' => now()]);

            // The choice is recorded against the running term, so the prompt does not come back this term.
            ModuleAddonRequest::query()
                ->where('account_id', $account->id)
                ->where('module', 'contact_groups')
                ->where('status', ModuleAddonRequest::PAID)
                ->where('term_ends_at', '>', now())
                ->orderByDesc('term_starts_at')
                ->limit(1)
                ->update(['group_selection_at' => now()]);
        });
    }
}
