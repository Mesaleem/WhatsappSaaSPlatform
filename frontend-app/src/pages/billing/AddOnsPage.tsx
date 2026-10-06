import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { useAuth } from '../../core/context/AuthContext';
import moduleAddonService, {
  type AddonHistory,
  type AddonHistoryNumberPurchase,
  type AddonHistoryRequest,
  type ModuleAddonStatus,
} from '../../services/moduleAddonService';
import ModuleAddonQueueCard from '../../components/billing/ModuleAddonQueueCard';
import RecordAddonPaymentModal from '../../components/billing/RecordAddonPaymentModal';
import { pickOnlineGateway, useOnlineInvoicePayment } from '../../components/billing/useOnlineInvoicePayment';
import NumberChangeQueueCard from '../../components/billing/NumberChangeQueueCard';
import billingService from '../../services/billingService';

const REQUEST_STATUS: Record<ModuleAddonStatus, { text: string; tone: string }> = {
  requested: { text: 'Waiting for approval', tone: 'bg-amber-50 text-amber-700 ring-amber-200' },
  invoiced: { text: 'Approved, waiting for payment', tone: 'bg-sky-50 text-sky-700 ring-sky-200' },
  paid: { text: 'Paid and active', tone: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
  expired: { text: 'Term ended', tone: 'bg-slate-100 text-slate-600 ring-slate-200' },
  rejected: { text: 'Not approved', tone: 'bg-red-50 text-red-700 ring-red-200' },
};

const INVOICE_STATUS: Record<string, { text: string; tone: string }> = {
  pending: { text: 'Unpaid', tone: 'bg-amber-50 text-amber-700 ring-amber-200' },
  paid: { text: 'Paid', tone: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
  cancelled: { text: 'Cancelled', tone: 'bg-slate-100 text-slate-600 ring-slate-200' },
};

const NUMBER_STATUS: Record<string, string> = {
  pending_payment: 'Waiting for payment',
  unlinked: 'Not connected',
  linked: 'Connected',
  paused: 'Paused (term ended)',
};

function formatDate(value: string | null): string {
  if (!value) return '—';
  return new Date(value).toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function formatAmount(value: number | null): string {
  return value === null ? '—' : `₹${value.toFixed(2)}`;
}

function Pill({ tone, children }: { tone: string; children: ReactNode }) {
  return <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${tone}`}>{children}</span>;
}

/**
 * Add-ons: the paid module add-ons (for example Custom Contact Groups) and the WhatsApp
 * numbers bought, with each step's date. Admins and agents also see the queue to act on.
 * The API decides which clients each person sees; this page only renders it.
 */
export default function AddOnsPage() {
  const { isSuperAdmin, hasRole } = useAuth();
  const admin = isSuperAdmin() || hasRole('agent');

  const [history, setHistory] = useState<AddonHistory | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [recording, setRecording] = useState<AddonHistoryNumberPurchase | null>(null);
  const { payOnline, payingId, stripeCard } = useOnlineInvoicePayment({
    onPaid: () => load(),
    onError: setError,
  });
  const onlineGateway = pickOnlineGateway(history?.gateways ?? []);

  const load = useCallback(() => {
    moduleAddonService
      .history({ admin })
      .then((data) => {
        setHistory(data);
        setError(null);
      })
      .catch(() => setError('Could not load the add-on history. Please try again.'));
  }, [admin]);

  useEffect(() => {
    load();
  }, [load]);

  const th = 'px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500';
  const td = 'px-4 py-3 align-top text-sm text-slate-700';

  return (
    <div className="p-6">
      <div className="w-full space-y-6">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">Add-ons</h1>
          <p className="mt-1 text-sm text-slate-500">
            Paid add-ons requested, approved and paid, and the WhatsApp numbers bought, with the date of each step.
          </p>
        </div>

        {admin && <ModuleAddonQueueCard onChanged={load} />}
        {admin && <NumberChangeQueueCard />}

        {error && <p className="text-sm text-red-600">{error}</p>}

        <section className="rounded-xl border border-slate-200 bg-white shadow-sm">
          <div className="border-b border-slate-200 px-6 py-4">
            <h2 className="text-sm font-semibold text-slate-900">Add-on requests</h2>
            <p className="mt-0.5 text-xs text-slate-500">A request is listed until it is approved or rejected, then its invoice and payment follow.</p>
          </div>
          {history && history.requests.length === 0 ? (
            <p className="px-6 py-8 text-center text-sm text-slate-500">No add-on has been requested yet.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-slate-200">
                <thead className="bg-slate-50">
                  <tr>
                    {admin && <th className={th}>Client</th>}
                    <th className={th}>Add-on</th>
                    <th className={th}>Status</th>
                    <th className={th}>Requested on</th>
                    <th className={th}>Approved on</th>
                    <th className={th}>Paid on</th>
                    <th className={th}>Term</th>
                    <th className={th}>Invoice</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {(history?.requests ?? []).map((row: AddonHistoryRequest) => (
                    <tr key={row.id}>
                      {admin && <td className={td}>{row.client ?? '—'}</td>}
                      <td className={td}>
                        <p className="font-medium text-slate-900">{row.label}</p>
                        {row.units !== null && <p className="text-xs text-slate-500">{row.units} {row.units === 1 ? 'group' : 'groups'}</p>}
                        {row.decision_note && <p className="text-xs text-slate-500">Note: {row.decision_note}</p>}
                      </td>
                      <td className={td}>
                        <Pill tone={REQUEST_STATUS[row.status].tone}>{REQUEST_STATUS[row.status].text}</Pill>
                      </td>
                      <td className={td}>{formatDate(row.requested_at)}</td>
                      <td className={td}>{formatDate(row.decided_at)}</td>
                      <td className={td}>{formatDate(row.paid_at)}</td>
                      <td className={td}>
                        {row.term_starts_at ? `${formatDate(row.term_starts_at)} to ${formatDate(row.term_ends_at)}` : '—'}
                      </td>
                      <td className={td}>
                        {row.invoice_number ? (
                          <>
                            <p>{row.invoice_number}</p>
                            <p className="text-xs text-slate-500">{formatAmount(row.total_amount)} (GST included)</p>
                          </>
                        ) : (
                          '—'
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>

        <section className="rounded-xl border border-slate-200 bg-white shadow-sm">
          <div className="border-b border-slate-200 px-6 py-4">
            <h2 className="text-sm font-semibold text-slate-900">WhatsApp numbers bought</h2>
            <p className="mt-0.5 text-xs text-slate-500">Each purchase is one invoice, with one line per extra number.</p>
          </div>
          {history && history.number_purchases.length === 0 ? (
            <p className="px-6 py-8 text-center text-sm text-slate-500">No extra WhatsApp number has been bought yet.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-slate-200">
                <thead className="bg-slate-50">
                  <tr>
                    {admin && <th className={th}>Client</th>}
                    <th className={th}>Numbers</th>
                    <th className={th}>Invoice</th>
                    <th className={th}>Bought on</th>
                    <th className={th}>Paid on</th>
                    <th className={th}>Term ends</th>
                    <th className={th}>Payment</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {(history?.number_purchases ?? []).map((row: AddonHistoryNumberPurchase, index) => (
                    <tr key={`${row.invoice_number ?? 'x'}-${index}`}>
                      {admin && <td className={td}>{row.client ?? '—'}</td>}
                      <td className={td}>
                        <p className="font-medium text-slate-900">
                          {row.number_count} {row.number_count === 1 ? 'number' : 'numbers'}
                        </p>
                        <ul className="mt-1 space-y-0.5 text-xs text-slate-500">
                          {row.numbers.map((n) => (
                            <li key={n.phone_number}>
                              +{n.phone_number} · {NUMBER_STATUS[n.status] ?? n.status}
                            </li>
                          ))}
                        </ul>
                      </td>
                      <td className={td}>
                        <p>{row.invoice_number ?? '—'}</p>
                        <p className="text-xs text-slate-500">{formatAmount(row.total_amount)} (GST included)</p>
                      </td>
                      <td className={td}>{formatDate(row.bought_at)}</td>
                      <td className={td}>{formatDate(row.paid_at)}</td>
                      <td className={td}>{formatDate(row.term_ends_at)}</td>
                      <td className={td}>
                        {row.status && INVOICE_STATUS[row.status] ? (
                          <Pill tone={INVOICE_STATUS[row.status].tone}>{INVOICE_STATUS[row.status].text}</Pill>
                        ) : (
                          '—'
                        )}
                        {row.status === 'pending' && row.invoice_id !== null && (
                          <div className="mt-2 flex flex-wrap gap-2">
                            {onlineGateway && (
                              <button
                                type="button"
                                disabled={payingId === row.invoice_id}
                                onClick={() =>
                                  void payOnline(
                                    { id: row.invoice_id as number, account_id: row.account_id, plan_key: 'whatsapp_addon', plan_label: 'WhatsApp extra number' },
                                    onlineGateway,
                                  )
                                }
                                className="rounded-lg border border-indigo-600 px-3 py-1.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-50 disabled:opacity-60"
                              >
                                {payingId === row.invoice_id ? 'Starting…' : 'Pay online'}
                              </button>
                            )}
                            {admin ? (
                              <button
                                type="button"
                                onClick={() => setRecording(row)}
                                className="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700"
                              >
                                Record payment
                              </button>
                            ) : (
                              !onlineGateway && <p className="text-xs text-slate-500">Awaiting payment. Pay by bank transfer, UPI or cash, and your account manager will confirm it once received.</p>
                            )}
                          </div>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>

        {stripeCard}

        {recording && recording.invoice_id !== null && (
          <RecordAddonPaymentModal
            invoiceId={recording.invoice_id}
            accountId={recording.account_id}
            invoiceNumber={recording.invoice_number ?? ''}
            totalAmount={Number(recording.total_amount ?? 0)}
            term={recording.term_months ? { months: recording.term_months } : null}
            submit={(body) => billingService.recordInvoicePayment(recording.invoice_id as number, body)}
            onClose={() => setRecording(null)}
            onRecorded={() => {
              setRecording(null);
              load();
            }}
          />
        )}
      </div>
    </div>
  );
}
