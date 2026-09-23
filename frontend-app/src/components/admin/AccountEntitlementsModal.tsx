import { useCallback, useEffect, useState } from 'react';
import { AlertCircle, Ban, Check, KeyRound, Loader2, Lock, X } from 'lucide-react';

import accountService from '../../services/accountService';
import type { Account, AccountEntitlementRow, AccountEntitlementsResponse } from '../../types/account';
import { extractErrorMessage } from '../../utils/apiError';

/**
 * Phase 5 Task 8 — Super Admin capability entitlement panel.
 *
 * WHY THIS EXISTS: Phase 1 shipped grant and revoke endpoints but no
 * way to SEE what an account holds, so an administrator asked to
 * "give this client the API node" had nothing to look at. This is that
 * screen, built on the existing account entitlement APIs — no new
 * entitlement source, no new column, no redesign of Manage Clients.
 *
 * It distinguishes the four states the existing data model can already
 * express:
 *
 *   Granted by plan      source === 'plan'
 *   Granted manually     source === 'manual_grant'
 *   Delegated by agent   source === 'agent_delegated'
 *   Not entitled         source === null
 *
 * plus the one case worth calling out on its own: a capability the
 * account's PLAN bundles that its WhatsApp provider cannot support
 * (plan_granted && !granted). That is not a bug to hide — it is
 * InvoiceCreditService skipping a provider-incompatible grant, and an
 * administrator needs to see it rather than wonder why a paid-for
 * feature never appeared.
 *
 * NOT AUTHORIZATION. Everything here is a read of server state plus the
 * existing grant/revoke calls, each of which re-authorizes server-side.
 */

const SOURCE_LABELS: Record<string, string> = {
  plan: 'Granted by plan',
  manual_grant: 'Granted manually',
  agent_delegated: 'Delegated by agent',
};

const SOURCE_CLASSES: Record<string, string> = {
  plan: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
  manual_grant: 'bg-indigo-50 text-indigo-700 ring-indigo-200',
  agent_delegated: 'bg-sky-50 text-sky-700 ring-sky-200',
};

function StateBadge({ row }: { row: AccountEntitlementRow }) {
  if (!row.granted) {
    /*
      Phase 5 Task 9 — revoked outranks every other "not held" reason.
      An administrator took this away on purpose, the plan backfill will
      NOT put it back, and only an explicit re-grant clears it. Showing
      that as a plain "Not entitled" would hide the one state that
      explains why a paid-for capability is missing.
    */
    if (row.revoked) {
      /*
        Phase 5 Task 10 — the two revocations mean different things to
        an administrator, so they must not look the same:

          plan_downgrade  the plan stopped including it (or the account
                          moved to a provider that cannot run it). It
                          comes back by itself if the plan does.
          manual          a person took it away. Nothing automatic ever
                          restores it; only an explicit re-grant does.
      */
      const byPlan = row.revoked_reason === 'plan_downgrade';
      const on = row.revoked_at ? ` on ${new Date(row.revoked_at).toLocaleDateString()}` : '';

      return (
        <span
          className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ${
            byPlan
              ? 'bg-slate-100 text-slate-600 ring-slate-200'
              : 'bg-rose-50 text-rose-700 ring-rose-200'
          }`}
          title={
            byPlan
              ? `Removed${on} because the current plan no longer includes it. It returns automatically if the plan includes it again.`
              : `Revoked by an administrator${on}. No plan change or backfill will restore it — grant it again to re-enable.`
          }
        >
          <Ban className="h-3 w-3" />
          {byPlan ? 'Removed by plan change' : 'Revoked by admin'}
        </span>
      );
    }

    if (row.plan_granted) {
      return (
        <span
          className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-700 ring-1 ring-amber-200"
          title="This account's plan bundles this capability, but its WhatsApp provider does not support it, so it was not granted."
        >
          <AlertCircle className="h-3 w-3" />
          In plan — provider unsupported
        </span>
      );
    }

    return (
      <span className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-500 ring-1 ring-slate-200">
        <Lock className="h-3 w-3" />
        Not entitled
      </span>
    );
  }

  const source = row.source ?? 'manual_grant';

  return (
    <span
      className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ${SOURCE_CLASSES[source] ?? SOURCE_CLASSES.manual_grant}`}
    >
      <Check className="h-3 w-3" />
      {SOURCE_LABELS[source] ?? 'Granted'}
    </span>
  );
}

export default function AccountEntitlementsModal({
  account,
  canManage,
  onClose,
}: {
  account: Account;
  /** Super Admin only — an Agent viewing a sub-client gets a read-only panel. */
  canManage: boolean;
  onClose: () => void;
}) {
  const [response, setResponse] = useState<AccountEntitlementsResponse | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [busySlug, setBusySlug] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setIsLoading(true);
    setLoadError(null);
    try {
      setResponse(await accountService.entitlements(account.id));
    } catch (err) {
      setLoadError(extractErrorMessage(err, 'Failed to load this client’s entitlements.'));
    } finally {
      setIsLoading(false);
    }
  }, [account.id]);

  useEffect(() => {
    void load();
  }, [load]);

  const toggle = async (row: AccountEntitlementRow) => {
    setBusySlug(row.slug);
    setActionError(null);
    try {
      if (row.granted) {
        await accountService.revokeEntitlement(account.id, row.slug);
      } else {
        await accountService.grantEntitlement(account.id, row.slug);
      }
      // Re-read rather than patching state locally: the server decides
      // the resulting source, and a provider-incompatible grant can be
      // refused outright.
      await load();
    } catch (err) {
      setActionError(extractErrorMessage(err, 'That change was refused.'));
    } finally {
      setBusySlug(null);
    }
  };

  const rows = response?.data ?? [];

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4">
      <div
        className="flex max-h-[85vh] w-full max-w-2xl flex-col rounded-2xl bg-white shadow-xl"
        data-testid="entitlements-modal"
      >
        <div className="flex items-start justify-between border-b border-slate-200 px-5 py-4">
          <div>
            <h2 className="flex items-center gap-2 font-display text-base font-bold text-slate-900">
              <KeyRound className="h-4 w-4 text-indigo-600" />
              Capabilities — {account.company_name}
            </h2>
            <p className="mt-0.5 text-xs text-slate-500">
              {response?.meta.plan ? `Plan: ${response.meta.plan}` : 'No paid plan on record'}
              {response?.meta.provider ? ` · Provider: ${response.meta.provider}` : ' · No WhatsApp provider'}
            </p>
          </div>
          <button onClick={onClose} className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100" aria-label="Close">
            <X className="h-4 w-4" />
          </button>
        </div>

        <div className="min-h-0 flex-1 overflow-y-auto px-5 py-4">
          {isLoading && (
            <p className="flex items-center gap-2 text-sm text-slate-500">
              <Loader2 className="h-4 w-4 animate-spin" />
              Loading capabilities…
            </p>
          )}

          {loadError && <p className="text-sm text-red-600">{loadError}</p>}

          {actionError && (
            <p data-testid="entitlement-action-error" className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
              {actionError}
            </p>
          )}

          {!isLoading && !loadError && (
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="text-[11px] uppercase tracking-wide text-slate-400">
                  <th className="pb-2 font-semibold">Capability</th>
                  <th className="pb-2 font-semibold">State</th>
                  {canManage && <th className="pb-2 text-right font-semibold">Action</th>}
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((row) => (
                  <tr key={row.slug} data-testid={`entitlement-row-${row.slug}`}>
                    <td className="py-2.5">
                      <span className="font-medium text-slate-800">{row.label}</span>
                      <span className="ml-1.5 text-[11px] text-slate-400">{row.slug}</span>
                    </td>
                    <td className="py-2.5">
                      <StateBadge row={row} />
                    </td>
                    {canManage && (
                      <td className="py-2.5 text-right">
                        <button
                          type="button"
                          data-testid={`entitlement-toggle-${row.slug}`}
                          disabled={busySlug === row.slug}
                          onClick={() => void toggle(row)}
                          className="rounded-lg border border-slate-300 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                        >
                          {busySlug === row.slug
                            ? '…'
                            : row.granted
                              ? 'Revoke'
                              : row.revoked_reason === 'manual'
                                ? 'Restore'
                                : 'Grant'}
                        </button>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>

        <div className="border-t border-slate-200 px-5 py-3">
          <p className="text-[11px] text-slate-500">
            Capabilities gate product features such as Journey nodes. A grant here is enforced server-side on every
            request — this panel only shows and changes the stored entitlement.
          </p>
        </div>
      </div>
    </div>
  );
}
