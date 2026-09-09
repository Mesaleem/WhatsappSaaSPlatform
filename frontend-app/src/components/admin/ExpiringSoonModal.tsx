import { useEffect, useState } from 'react';
import { AlertTriangle, Loader2, RefreshCw, X, XCircle } from 'lucide-react';
import accountService from '../../services/accountService';
import type { Account } from '../../types/account';
import type { ExpiringSoonAccount } from '../../types/analytics';
import CreateAccountModal from './CreateAccountModal';

function extractMessage(err: unknown, fallback: string): string {
  return err instanceof Error ? err.message : fallback;
}

function formatDate(value: string): string {
  return new Date(value).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

function urgencyBadge(daysRemaining: number): string {
  if (daysRemaining <= 2) return 'bg-red-50 text-red-700 ring-red-600/20';
  if (daysRemaining <= 4) return 'bg-amber-50 text-amber-700 ring-amber-600/20';
  return 'bg-slate-100 text-slate-600 ring-slate-500/20';
}

/**
 * Super Admin Dashboard Enhancement — "Expiring in 7 Days" metric card's
 * detail modal. Backed by GET /api/admin/accounts/expiring-soon (see
 * AccountController::expiringSoon()). "Renew / Extend" reuses the
 * existing CreateAccountModal in edit mode rather than building a second,
 * narrower subscription-edit form — it already has the exact fields
 * (expires_at, price_paid, billing model, ...) this action needs, and
 * keeps renewal logic in one place instead of two forms that could drift
 * apart. It needs a full Account (with subscription history), which this
 * list's rows don't carry — accountService.get(id) fetches it on demand,
 * only when a row's "Renew / Extend" is actually clicked.
 */
export default function ExpiringSoonModal({ onClose }: { onClose: () => void }) {
  const [rows, setRows] = useState<ExpiringSoonAccount[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [renewTarget, setRenewTarget] = useState<Account | null>(null);
  const [loadingRenewId, setLoadingRenewId] = useState<number | null>(null);

  const load = () => {
    setIsLoading(true);
    setError(null);
    accountService
      .expiringSoon()
      .then(setRows)
      .catch((err) => setError(extractMessage(err, 'Failed to load expiring accounts.')))
      .finally(() => setIsLoading(false));
  };

  useEffect(() => {
    load();
  }, []);

  const openRenew = async (accountId: number) => {
    setLoadingRenewId(accountId);
    setError(null);
    try {
      const detail = await accountService.get(accountId);
      setRenewTarget(detail);
    } catch (err) {
      setError(extractMessage(err, 'Could not open this account for renewal.'));
    } finally {
      setLoadingRenewId(null);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" onClick={onClose}>
      <div
        className="max-h-[85vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white shadow-xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-center justify-between border-b border-slate-200 px-6 py-4">
          <div className="flex items-center gap-2">
            <AlertTriangle className="h-5 w-5 text-amber-500" />
            <h2 className="text-lg font-semibold text-slate-900">Expiring in 7 Days</h2>
          </div>
          <div className="flex items-center gap-2">
            <button onClick={load} className="rounded-md p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600" title="Refresh">
              <RefreshCw className="h-4 w-4" />
            </button>
            <button onClick={onClose} className="rounded-md p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
              <X className="h-5 w-5" />
            </button>
          </div>
        </div>

        <div className="px-6 py-4">
          {error && (
            <div className="mb-4 flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
              <XCircle className="h-4 w-4 flex-shrink-0" />
              {error}
            </div>
          )}

          {isLoading ? (
            <div className="flex justify-center py-10">
              <Loader2 className="h-5 w-5 animate-spin text-slate-400" />
            </div>
          ) : rows.length === 0 ? (
            <p className="py-10 text-center text-sm text-slate-400">No accounts expiring in the next 7 days.</p>
          ) : (
            <div className="overflow-hidden rounded-lg border border-slate-200">
              <table className="w-full divide-y divide-slate-200 text-sm">
                <thead className="bg-slate-50">
                  <tr className="text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                    <th className="px-4 py-2.5">Client</th>
                    <th className="px-4 py-2.5">Plan</th>
                    <th className="px-4 py-2.5">Renewal Date</th>
                    <th className="px-4 py-2.5">Days Left</th>
                    <th className="px-4 py-2.5 text-right">Action</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.map((row) => (
                    <tr key={row.account_id} className="hover:bg-slate-50">
                      <td className="px-4 py-2.5 font-medium text-slate-900">{row.company_name}</td>
                      <td className="px-4 py-2.5 text-slate-600">{row.plan_label}</td>
                      <td className="px-4 py-2.5 text-slate-600">{formatDate(row.expires_at)}</td>
                      <td className="px-4 py-2.5">
                        <span
                          className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${urgencyBadge(row.days_remaining)}`}
                        >
                          {row.days_remaining} day{row.days_remaining === 1 ? '' : 's'}
                        </span>
                      </td>
                      <td className="px-4 py-2.5 text-right">
                        <button
                          onClick={() => void openRenew(row.account_id)}
                          disabled={loadingRenewId === row.account_id}
                          className="inline-flex items-center gap-1.5 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-100 disabled:opacity-60"
                        >
                          {loadingRenewId === row.account_id && <Loader2 className="h-3 w-3 animate-spin" />}
                          Renew / Extend
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>

      {renewTarget && (
        <CreateAccountModal
          account={renewTarget}
          onClose={() => setRenewTarget(null)}
          onSaved={() => {
            setRenewTarget(null);
            load();
          }}
        />
      )}
    </div>
  );
}
