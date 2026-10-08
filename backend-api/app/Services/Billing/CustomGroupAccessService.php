<?php

namespace App\Services\Billing;

use App\Models\Account;
use App\Models\ContactGroup;
use App\Models\ModuleAddonRequest;
use Illuminate\Support\Facades\DB;

/**
 * Which Native WhatsApp Groups a client may use under its paid "Native WhatsApp Groups" term.
 *
 * [Re-scoped 2026-10-07, disclosed]: this class originally governed `internal_segment` ("Custom
 * Contact Groups") -- see git history / the "Native WhatsApp Group Re-Architecture" migration for
 * that original shape. Internal segment groups (a DB broadcast list -- each member gets their own
 * DM) are now a free, always-on baseline feature for every account (Account::effectiveModules()
 * always includes 'contact_groups'; ContactGroupController::store()'s internal branch has no limit
 * at all) -- there is nothing left for a term/allowance to govern there. A Native WhatsApp Group (a
 * real WhatsApp group chat, requiring the QR/Baileys engine) remains the chargeable one, exactly the
 * same way it always was ("jaise humne pahle kiya tha" -- reusing this exact paid-addon mechanism,
 * Super-Admin-editable price/term/units, rather than building a second one): the module key stays
 * `contact_groups` (its offer's `units_included`/label describe "group chats", i.e. native groups,
 * unchanged by this re-scoping) and every method below now counts/locks GROUP_TYPE_NATIVE rows.
 *
 * - The term allows its units (the groups it was bought for). Groups within that allowance are open.
 * - A new term that allows fewer groups than the client has leaves the client to choose the groups
 *   to keep. The others are locked until the next term, when the client chooses again.
 * - An upgrade, or a term that starts while another paid term still runs, only raises the allowance:
 *   nothing is locked.
 * - No paid term means native groups are off entirely (gated separately by the `whatsapp_groups`
 *   capability, NativeGroupEntitlement -- this class only ever narrows a COUNT, never the on/off gate).
 */
class CustomGroupAccessService
{
    public function __construct(private readonly ModuleAddonService $addons)
    {
    }

    /** The client's own Native WhatsApp Groups -- the ones a paid term's unit count allows or locks. */
    public function nativeGroups(Account $account)
    {
        return ContactGroup::query()
            ->where('account_id', $account->id)
            ->where('is_default', false)
            ->where('group_type', ContactGroup::GROUP_TYPE_NATIVE);
    }

    /** How many native groups the account currently has, against its term's allowance (null = unlimited). */
    public function usage(Account $account): array
    {
        return [
            'used' => $this->nativeGroups($account)->count(),
            'limit' => $this->addons->activeUnitLimit($account, 'contact_groups'),
        ];
    }

    /**
     * True once adding one more native group would exceed the account's term allowance. False when
     * there is no limit (no paid term tracked -- e.g. a Super Admin granted `whatsapp_groups`
     * directly without a purchased term, same "null = unlimited" convention activeUnitLimit() always
     * used) or usage is still under it. Callers must separately check the `whatsapp_groups`
     * capability (NativeGroupEntitlement) first -- this only ever narrows a COUNT, never the on/off
     * gate, exactly like it did for internal segment groups before this class was re-scoped.
     */
    public function limitReached(Account $account): bool
    {
        $usage = $this->usage($account);

        return $usage['limit'] !== null && $usage['used'] >= $usage['limit'];
    }

    /**
     * True while the client still has to choose which groups to keep this term: some groups are
     * locked and no choice has been recorded for the running term yet.
     */
    public function selectionRequired(Account $account): bool
    {
        if (! $this->nativeGroups($account)->whereNotNull('locked_at')->exists()) {
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

        $total = $this->nativeGroups($account)->count();

        if ($total <= $limit) {
            $this->nativeGroups($account)->whereNotNull('locked_at')->update(['locked_at' => null]);

            return;
        }

        $unlocked = $this->nativeGroups($account)->whereNull('locked_at')->count();
        if ($freshTerm || $unlocked > $limit) {
            $this->nativeGroups($account)->update(['locked_at' => now()]);
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
            throw new \RuntimeException('Native WhatsApp Groups is not active on this account.');
        }

        $keepIds = array_values(array_unique(array_map('intval', $keepIds)));
        $nativeIds = $this->nativeGroups($account)->pluck('id')->map(fn ($id) => (int) $id)->all();

        if (array_diff($keepIds, $nativeIds) !== []) {
            throw new \InvalidArgumentException('Choose only your own WhatsApp groups.');
        }

        $required = min($limit, count($nativeIds));
        if (count($keepIds) !== $required) {
            throw new \InvalidArgumentException("Choose exactly {$required} group".($required === 1 ? '' : 's').' to keep.');
        }

        DB::transaction(function () use ($account, $keepIds): void {
            $this->nativeGroups($account)->whereIn('id', $keepIds)->update(['locked_at' => null]);
            $this->nativeGroups($account)->whereNotIn('id', $keepIds)->update(['locked_at' => now()]);

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
