import { useEffect, useState } from 'react';
import { AlertCircle, Gauge, Loader2, X } from 'lucide-react';
import accountService from '../../services/accountService';
import type { Account, AccountDetail } from '../../types/account';
import { extractErrorMessage } from '../../utils/apiError';

const inputClass =
  'mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100';

/**
 * 3-Tier Hierarchy & Agent-Client Scope Engine (Phase 4) — Agent Quota
 * Pool & Allocation. A narrower, Agent-facing counterpart to
 * CreateAccountModal's own Subscription section: numeric quota only (no
 * engine/billing/pricing fields — see AccountController::updateQuota()'s
 * docblock for why), plus the "Remaining Unallocated Agent Pool"
 * indicator this phase's spec calls for.
 *
 * [Disclosed]: only reachable today from AccountsPage's Agent-only "Edit
 * Quota" button (see there) — a Sub-Client on 'unlimited' billing has no
 * numeric quota to set at all, so this renders a plain notice instead of
 * a form in that case (unchanged by, and not addressed by, this modal —
 * an Agent would need the broader Subscription editor for that, which
 * they don't have access to; flagged rather than silently hidden).
 */
export default function UpdateQuotaModal({
  account,
  onClose,
  onSaved,
}: {
  account: Account;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [detail, setDetail] = useState<AccountDetail | null>(null);
  const [isLoadingDetail, setIsLoadingDetail] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);

  const [totalAllocated, setTotalAllocated] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setIsLoadingDetail(true);
      setLoadError(null);
      try {
        const data = await accountService.get(account.id);
        if (cancelled) return;
        setDetail(data);
        setTotalAllocated(
          data.current_subscription?.total_allocated_messages != null
            ? String(data.current_subscription.total_allocated_messages)
            : '',
        );
      } catch (err) {
        if (!cancelled) setLoadError(extractErrorMessage(err, 'Failed to load this client’s current quota.'));
      } finally {
        if (!cancelled) setIsLoadingDetail(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [account.id]);

  const subscription = detail?.current_subscription ?? null;
  const isUnlimited = subscription?.billing_model === 'unlimited';

  // The pool ceiling this Sub-Client's OWN new allocation must fit under:
  // whatever's still unallocated (already excludes this account's
  // current contribution — see AccountController::show()) PLUS what
  // this account currently holds, since re-saving the same number (or a
  // smaller one) must never read as "over pool". null = the Agent's own
  // pool has no ceiling (their subscription is 'unlimited' or uncapped),
  // or agent_remaining_pool is entirely absent (a non-Agent viewer — see
  // this modal's own docblock: not expected in practice today).
  const effectiveCeiling =
    detail?.agent_remaining_pool != null
      ? detail.agent_remaining_pool + (subscription?.total_allocated_messages ?? 0)
      : null;

  const parsed = Number(totalAllocated);
  const isValidNumber = totalAllocated.trim() !== '' && Number.isInteger(parsed) && parsed >= 1;
  const overCeiling = isValidNumber && effectiveCeiling !== null && parsed > effectiveCeiling;

  const handleSubmit = async () => {
    if (!isValidNumber) {
      setFormError('Enter a whole number of at least 1.');
      return;
    }
    setFormError(null);
    setIsSubmitting(true);
    try {
      await accountService.updateQuota(account.id, { total_allocated_messages: parsed });
      onSaved();
    } catch (err) {
      setFormError(extractErrorMessage(err, 'Could not update this quota. Please try again.'));
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
        <div className="flex items-center justify-between">
          <h3 className="flex items-center gap-2 text-base font-semibold text-slate-900">
            <Gauge className="h-4 w-4 text-indigo-600" />
            Edit Quota — {account.company_name}
          </h3>
          <button
            onClick={onClose}
            className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
            aria-label="Close"
            disabled={isSubmitting}
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        {isLoadingDetail ? (
          <div className="mt-6 flex items-center justify-center py-6 text-slate-400">
            <Loader2 className="h-5 w-5 animate-spin" />
          </div>
        ) : loadError ? (
          <div
            role="alert"
            className="mt-4 flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
          >
            <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />
            <span>{loadError}</span>
          </div>
        ) : isUnlimited ? (
          <p className="mt-4 text-sm text-slate-500">
            This client's plan is Unlimited — there is no numeric message quota to allocate. Change its billing
            model first if it needs a fixed quota.
          </p>
        ) : (
          <>
            <div
              className={`mt-4 rounded-lg border px-3 py-2 text-sm ${
                detail?.agent_remaining_pool != null && detail.agent_remaining_pool <= 0
                  ? 'border-amber-200 bg-amber-50 text-amber-800'
                  : 'border-slate-200 bg-slate-50 text-slate-700'
              }`}
            >
              <span className="font-medium">Remaining Unallocated Agent Pool: </span>
              {detail?.agent_remaining_pool == null
                ? 'Unlimited'
                : `${detail.agent_remaining_pool.toLocaleString()} messages`}
            </div>

            <div className="mt-4">
              <label htmlFor="quota-total-allocated" className="text-sm font-medium text-slate-700">
                Message Quota (Total Allocated Messages)
              </label>
              <input
                id="quota-total-allocated"
                type="number"
                min={1}
                step={1}
                value={totalAllocated}
                onChange={(e) => setTotalAllocated(e.target.value)}
                placeholder="e.g. 10000"
                className={inputClass}
              />
              {overCeiling && (
                <p className="mt-1.5 text-xs text-amber-700">
                  This exceeds your remaining agent pool ({effectiveCeiling?.toLocaleString()} available for this
                  client) — saving will be rejected.
                </p>
              )}
            </div>

            {formError && (
              <div
                role="alert"
                className="mt-4 flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
              >
                <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />
                <span>{formError}</span>
              </div>
            )}
          </>
        )}

        <div className="mt-5 flex justify-end gap-2">
          <button
            onClick={onClose}
            disabled={isSubmitting}
            className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60"
          >
            Cancel
          </button>
          {!isLoadingDetail && !loadError && !isUnlimited && (
            <button
              onClick={() => void handleSubmit()}
              disabled={isSubmitting || !isValidNumber}
              className="flex items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
            >
              {isSubmitting && <Loader2 className="h-4 w-4 animate-spin" />}
              Save Quota
            </button>
          )}
        </div>
      </div>
    </div>
  );
}
