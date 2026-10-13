import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { CheckCircle2, Download, Loader2 } from 'lucide-react';
import crmDocumentsService from '../../services/crmDocumentsService';
import { loadRazorpayScript } from '../../utils/loadRazorpayScript';
import { describeApiError } from '../../utils/apiError';
import { formatMoney } from '../../types/crm';
import { CRM_DOC_TYPE_LABELS, crmDocStatusLabel, type CrmPublicDocument } from '../../types/crmSales';

/**
 * CRM Sales Phases 3–4 — what a business's CUSTOMER sees from the WhatsApp / email link
 * (/d/:token, no login): the document, its PDF, and "Pay now" through the business's own
 * Razorpay. The payment is confirmed by the server with the gateway's signature before
 * anything is recorded; this page never decides an amount.
 */
export default function PublicDocumentPage() {
  const { token = '' } = useParams();
  const [doc, setDoc] = useState<CrmPublicDocument | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [paying, setPaying] = useState(false);
  const [paid, setPaid] = useState<string | null>(null);

  const load = () =>
    crmDocumentsService
      .publicDocument(token)
      .then(setDoc)
      .catch((e: unknown) => setError(describeApiError(e, 'This link is not valid or the document is no longer available.').message));

  useEffect(() => {
    void load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token]);

  const pay = async () => {
    setPaying(true);
    setError(null);
    try {
      const order = await crmDocumentsService.publicPay(token);
      if (order.gateway !== 'razorpay' || !(await loadRazorpayScript()) || !window.Razorpay) {
        throw new Error('Online payment is not available right now. Please use another method.');
      }
      new window.Razorpay({
        key: order.key_id,
        amount: order.amount_minor,
        currency: order.currency,
        name: order.seller_name ?? 'Payment',
        description: `Invoice ${order.invoice_number}`,
        order_id: order.order_id,
        handler: (res) => {
          crmDocumentsService
            .publicConfirm(token, { ...res })
            .then((r) => {
              setPaid(r.data.receipt ?? 'received');
              void load();
            })
            .catch((e: unknown) => setError(describeApiError(e, 'We could not confirm the payment yet. If money was deducted, it will be updated shortly.').message))
            .finally(() => setPaying(false));
        },
        modal: { ondismiss: () => setPaying(false) },
        theme: { color: '#059669' },
      }).open();
    } catch (e: unknown) {
      setError(e instanceof Error && !('response' in (e as object)) ? e.message : describeApiError(e, 'Could not start the payment.').message);
      setPaying(false);
    }
  };

  if (error && !doc) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-slate-50 p-6">
        <p className="max-w-md text-center text-slate-600">{error}</p>
      </div>
    );
  }
  if (!doc) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-slate-50">
        <Loader2 className="h-6 w-6 animate-spin text-slate-400" />
      </div>
    );
  }

  const payable = doc.type === 'invoice' && Number(doc.balance_due) > 0 && ['issued', 'partially_paid'].includes(doc.status);

  return (
    <div className="min-h-screen bg-slate-50 px-4 py-8">
      <div className="mx-auto max-w-3xl space-y-4">
        <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div>
              <p className="text-lg font-semibold text-slate-900">{doc.seller.name}</p>
              {doc.seller.gstin && <p className="text-xs text-slate-500">GSTIN {doc.seller.gstin}</p>}
              <p className="text-xs text-slate-500">{(doc.seller.address ?? []).join(', ')}</p>
            </div>
            <div className="text-right">
              <p className="text-sm font-semibold uppercase tracking-wide text-emerald-700">{CRM_DOC_TYPE_LABELS[doc.type]}</p>
              <p className="text-base font-semibold text-slate-900">{doc.number}</p>
              <p className="text-xs text-slate-500">{crmDocStatusLabel(doc.status)}</p>
            </div>
          </div>

          <div className="mt-4 grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
            <p className="text-slate-700">
              <span className="text-slate-500">To:</span> {doc.customer.name}
            </p>
            <p className="text-slate-700 sm:text-right">
              {doc.issue_date && <>Date {doc.issue_date}</>}
              {doc.due_date && <> · Due {doc.due_date}</>}
              {doc.valid_until && <> · Valid until {doc.valid_until}</>}
            </p>
          </div>

          {doc.body && <p className="mt-4 whitespace-pre-line text-sm text-slate-700">{doc.body}</p>}

          {doc.items.length > 0 && (
            <div className="mt-4 overflow-x-auto">
              <table className="min-w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-200 text-left text-xs uppercase text-slate-500">
                    <th className="py-2 pr-3">Item</th>
                    <th className="py-2 pr-3 text-right">Qty</th>
                    <th className="py-2 pr-3 text-right">Rate</th>
                    <th className="py-2 text-right">Amount</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {doc.items.map((i, k) => (
                    <tr key={k}>
                      <td className="py-2 pr-3">{i.description}</td>
                      <td className="py-2 pr-3 text-right">{Number(i.quantity)} {i.unit}</td>
                      <td className="py-2 pr-3 text-right">{formatMoney(i.unit_price)}</td>
                      <td className="py-2 text-right">{formatMoney(i.line_total)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          <dl className="ml-auto mt-4 max-w-xs space-y-1 text-sm">
            <div className="flex justify-between text-slate-600"><dt>Taxable</dt><dd>{formatMoney(doc.taxable_total)}</dd></div>
            <div className="flex justify-between text-slate-600"><dt>GST</dt><dd>{formatMoney(doc.tax_total)}</dd></div>
            <div className="flex justify-between border-t border-slate-200 pt-1 font-semibold text-slate-900"><dt>Total</dt><dd>{formatMoney(doc.grand_total)}</dd></div>
            {doc.type === 'invoice' && (
              <>
                <div className="flex justify-between text-slate-600"><dt>Paid</dt><dd>{formatMoney(doc.amount_paid)}</dd></div>
                <div className="flex justify-between font-semibold text-slate-900"><dt>Balance due</dt><dd>{formatMoney(doc.balance_due)}</dd></div>
              </>
            )}
          </dl>

          {doc.terms && <p className="mt-4 whitespace-pre-line text-xs text-slate-500">{doc.terms}</p>}
        </div>

        {paid && (
          <div role="status" className="flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
            <CheckCircle2 className="h-5 w-5" /> Payment received. Thank you!{paid !== 'received' && ` Receipt ${paid}.`}
          </div>
        )}
        {error && <p role="alert" className="text-sm text-red-600">{error}</p>}

        <div className="flex flex-wrap gap-3">
          <a href={crmDocumentsService.publicPdfUrl(token)} target="_blank" rel="noreferrer" className="flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
            <Download className="h-4 w-4" /> Download PDF
          </a>
          {payable && doc.payable_online && (
            <button type="button" onClick={() => void pay()} disabled={paying} className="flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60">
              {paying && <Loader2 className="h-4 w-4 animate-spin" />} Pay {formatMoney(doc.balance_due)} now
            </button>
          )}
        </div>
        {payable && !doc.payable_online && (doc.seller.upi_id || doc.seller.bank_details) && (
          <div className="rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-700">
            <p className="font-medium text-slate-900">How to pay</p>
            {doc.seller.upi_id && <p>UPI: {doc.seller.upi_id}</p>}
            {doc.seller.bank_details && <p className="whitespace-pre-line">{doc.seller.bank_details}</p>}
          </div>
        )}
      </div>
    </div>
  );
}
