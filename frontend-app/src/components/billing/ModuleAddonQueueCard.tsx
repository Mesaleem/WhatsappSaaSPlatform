import { useCallback, useEffect, useState } from 'react';
import moduleAddonService, { type ModuleAddonRow } from '../../services/moduleAddonService';
import ConfirmModal from '../common/ConfirmModal';
import RecordAddonPaymentModal from './RecordAddonPaymentModal';

function errorMessage(err: unknown, fallback: string): string {
  const data = (err as { response?: { data?: { message?: string } } })?.response?.data;
  return data?.message ?? fallback;
}

/**
 * Super Admin and agent view of paid module add-on requests: approve a request (this
 * creates its invoice), reject it, or record the payment. The server decides which
 * clients each person may act on.
 */
export default function ModuleAddonQueueCard({ onChanged }: { onChanged?: () => void } = {}) {
  const [rows, setRows] = useState<ModuleAddonRow[] | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [paying, setPaying] = useState<ModuleAddonRow | null>(null);
  const [rejecting, setRejecting] = useState<ModuleAddonRow | null>(null);
  // Each module's term in months, so the payment form can show when the add-on ends.
  const [termMonths, setTermMonths] = useState<Record<string, number>>({});

  const load = useCallback(async () => {
    try {
      setRows(await moduleAddonService.pending());
      const offers = await moduleAddonService.offers();
      setTermMonths(Object.fromEntries(offers.map((o) => [o.module, o.term_months])));
    } catch (err) {
      setError(errorMessage(err, 'Could not load the add-on requests.'));
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const run = async (id: number, action: () => Promise<unknown>, fallback: string) => {
    setBusyId(id);
    setError(null);
    try {
      await action();
      await load();
      onChanged?.();
    } catch (err) {
      setError(errorMessage(err, fallback));
    } finally {
      setBusyId(null);
    }
  };

  if (rows === null || rows.length === 0) return null;

  return (
    <div className="mt-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
      <p className="text-sm font-semibold text-slate-900">Paid add-on requests</p>
      <p className="mt-0.5 text-xs text-slate-500">Approve a request to create its invoice, then record the payment.</p>

      {error && <p className="mt-3 text-sm text-red-600">{error}</p>}

      <ul className="mt-4 divide-y divide-slate-200">
        {rows.map((row) => (
          <li key={row.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
            <div className="min-w-0 text-sm">
              <p className="font-medium text-slate-900">
                {row.label} · {row.account?.name ?? `Account ${row.account?.id ?? ''}`}
              </p>
              <p className="text-xs text-slate-500">
                {row.units ? `${row.units} ${row.units === 1 ? 'group' : 'groups'} · ` : ''}
                {row.status === 'requested' ? 'Waiting for approval' : 'Invoice sent. Waiting for payment'}
                {row.total_amount !== null ? ` · ₹${Number(row.total_amount).toFixed(2)} (GST included)` : ''}
                {row.reason ? ` · "${row.reason}"` : ''}
              </p>
            </div>
            <div className="flex items-center gap-2">
              {row.status === 'requested' && (
                <>
                  <button
                    type="button"
                    disabled={busyId === row.id}
                    onClick={() => void run(row.id, () => moduleAddonService.approve(row.id), 'Could not approve this request.')}
                    className="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700 disabled:opacity-60"
                  >
                    Approve
                  </button>
                  <button
                    type="button"
                    disabled={busyId === row.id}
                    onClick={() => setRejecting(row)}
                    className="rounded-lg border border-red-200 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 disabled:opacity-60"
                  >
                    Reject
                  </button>
                </>
              )}
              {row.status === 'invoiced' && (
                <button
                  type="button"
                  onClick={() => setPaying(row)}
                  className="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700"
                >
                  Record payment
                </button>
              )}
            </div>
          </li>
        ))}
      </ul>

      {rejecting && (
        <ConfirmModal
          title="Reject this request?"
          message={
            <>
              <strong>{rejecting.label}</strong> for {rejecting.account?.name ?? `account ${rejecting.account?.id ?? ''}`} will not be approved and no invoice will be created.
            </>
          }
          confirmLabel="Reject request"
          isLoading={busyId === rejecting.id}
          onCancel={() => setRejecting(null)}
          onConfirm={() => {
            const row = rejecting;
            void run(row.id, () => moduleAddonService.reject(row.id), 'Could not reject this request.').then(() => setRejecting(null));
          }}
        />
      )}

      {paying && (
        <RecordAddonPaymentModal
          invoiceId={paying.id}
          accountId={paying.account?.id ?? 0}
          invoiceNumber={`${paying.label} request #${paying.id}`}
          totalAmount={Number(paying.total_amount ?? 0)}
          term={termMonths[paying.module] ? { months: termMonths[paying.module] } : null}
          onClose={() => setPaying(null)}
          onRecorded={() => {
            setPaying(null);
            void load();
            onChanged?.();
          }}
          submit={(body) => moduleAddonService.recordPayment(paying.id, body)}
        />
      )}
    </div>
  );
}
