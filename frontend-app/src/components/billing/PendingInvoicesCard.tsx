import { useCallback, useEffect, useState } from 'react';
import billingService, { type PendingInvoice } from '../../services/billingService';
import RecordAddonPaymentModal from './RecordAddonPaymentModal';
import { pickOnlineGateway, useOnlineInvoicePayment } from './useOnlineInvoicePayment';

const KIND_LABEL: Record<PendingInvoice['kind'], string> = {
  plan: 'Plan',
  whatsapp_addon: 'Extra WhatsApp number',
  module_addon: 'Add-on',
};

function formatDate(value: string | null): string {
  if (!value) return '—';
  return new Date(value).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
}

/**
 * Invoices waiting for payment, for a Super Admin (every client) or an agent (its own
 * clients). Each can be paid two ways: online, once a gateway is configured, or recorded by
 * hand (amount, transaction ID, date paid, when the term starts). The server checks who may
 * do either, and every rule.
 */
export default function PendingInvoicesCard() {
  const [rows, setRows] = useState<PendingInvoice[] | null>(null);
  const [gateways, setGateways] = useState<string[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [recording, setRecording] = useState<PendingInvoice | null>(null);

  const load = useCallback(() => {
    billingService
      .pendingInvoices()
      .then((res) => {
        setRows(res.data);
        setGateways(res.gateways);
        setError(null);
      })
      .catch(() => setError('Could not load the pending invoices.'));
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const { payOnline, payingId, stripeCard } = useOnlineInvoicePayment({
    onPaid: load,
    onError: setError,
  });
  const onlineGateway = pickOnlineGateway(gateways);

  const th = 'px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500';
  const td = 'px-4 py-3 align-top text-sm text-slate-700';

  return (
    <section className="rounded-xl border border-slate-200 bg-white shadow-sm">
      <div className="border-b border-slate-200 px-6 py-4">
        <h2 className="text-sm font-semibold text-slate-900">Pending payments</h2>
        <p className="mt-0.5 text-xs text-slate-500">
          {onlineGateway
            ? 'Pay online, or record a payment received by hand.'
            : 'Online payment is not set up, so record a payment received by hand.'}
        </p>
      </div>

      {error && <p className="px-6 py-4 text-sm text-red-600">{error}</p>}

      {rows && rows.length === 0 && <p className="px-6 py-8 text-center text-sm text-slate-500">No invoice is waiting for payment.</p>}

      {rows && rows.length > 0 && (
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-slate-200">
            <thead className="bg-slate-50">
              <tr>
                <th className={th}>Client</th>
                <th className={th}>Invoice</th>
                <th className={th}>For</th>
                <th className={th}>Amount</th>
                <th className={th}>Created</th>
                <th className={`${th} text-right`}>Action</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {rows.map((row) => (
                <tr key={row.id}>
                  <td className={td}>{row.client ?? '—'}</td>
                  <td className={`${td} font-mono text-xs`}>{row.invoice_number}</td>
                  <td className={td}>
                    <p>{row.label}</p>
                    <p className="text-xs text-slate-500">{KIND_LABEL[row.kind]}</p>
                  </td>
                  <td className={td}>
                    ₹{Number(row.total_amount).toFixed(2)} <span className="text-xs text-slate-500">(GST incl.)</span>
                  </td>
                  <td className={td}>{formatDate(row.created_at)}</td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex justify-end gap-2">
                      {onlineGateway && (
                        <button
                          type="button"
                          onClick={() => void payOnline({ id: row.id, account_id: row.account_id, plan_key: row.plan_key, plan_label: row.label }, onlineGateway)}
                          disabled={payingId === row.id}
                          className="rounded-lg border border-indigo-600 px-3 py-1.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-50 disabled:opacity-60"
                        >
                          {payingId === row.id ? 'Starting…' : 'Pay online'}
                        </button>
                      )}
                      <button
                        type="button"
                        onClick={() => setRecording(row)}
                        className="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700"
                      >
                        Record payment
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {stripeCard}

      {recording && (
        <RecordAddonPaymentModal
          invoiceId={recording.id}
          accountId={recording.account_id}
          invoiceNumber={recording.invoice_number}
          totalAmount={Number(recording.total_amount)}
          startLabel={recording.kind === 'plan' ? 'Plan starts on' : 'Term starts on'}
          term={recording.term}
          submit={(body) => billingService.recordInvoicePayment(recording.id, body)}
          onClose={() => setRecording(null)}
          onRecorded={() => {
            setRecording(null);
            load();
          }}
        />
      )}
    </section>
  );
}
