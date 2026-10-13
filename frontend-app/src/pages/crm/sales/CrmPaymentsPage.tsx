import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Download, MessageSquare, Plus, Undo2 } from 'lucide-react';
import crmDocumentsService from '../../../services/crmDocumentsService';
import { TableCard } from '../../../components/common/Card';
import { Pagination, SearchInput } from '../../../components/common/DataTableControls';
import PromptModal from '../../../components/common/PromptModal';
import { TableSkeletonRows } from '../../../components/common/Skeleton';
import { EmptyState, ErrorBanner, NoClientSelected, READ_ONLY_TITLE, Toast } from '../../../components/crm/CrmUi';
import { formatDate } from '../../../components/crm/crmFilters';
import { useCrmContext, useCrmQuery, useCrmToast } from '../../../components/crm/crmHooks';
import RecordPaymentModal from '../../../components/crm/sales/RecordPaymentModal';
import { CustomerPicker, DocStatusBadge } from '../../../components/crm/sales/SalesUi';
import { describeApiError } from '../../../utils/apiError';
import { crmLabel, formatMoney } from '../../../types/crm';
import type { CrmPayment } from '../../../types/crmSales';

/**
 * CRM Sales Phase 3 — payments received: manual ones recorded here (applied to open
 * invoices) and online ones from the tenant's own gateway. A payment is never edited;
 * a wrong one is reversed with a reason.
 */
export default function CrmPaymentsPage() {
  const { needsClient, readOnly, selectedAccountId } = useCrmContext();
  const { toast, show } = useCrmToast();
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [version, setVersion] = useState(0);
  const [choosing, setChoosing] = useState(false);
  const [customer, setCustomer] = useState<{ contact_id: number | null; crm_company_id: number | null } | null>(null);
  const [recording, setRecording] = useState(false);
  const [reversing, setReversing] = useState<CrmPayment | null>(null);
  const [busy, setBusy] = useState(false);

  const query = useCrmQuery(needsClient ? null : `payments:${selectedAccountId ?? 'own'}:${search}:${page}:${version}`, () => crmDocumentsService.listPayments({ search, page }), 'Failed to load payments.');
  const rows = query.data?.data ?? [];

  const reverse = (reason: string) => {
    if (!reversing) return;
    setBusy(true);
    crmDocumentsService
      .reversePayment(reversing.id, reason)
      .then((res) => {
        show(res.message);
        setVersion((v) => v + 1);
      })
      .catch((err: unknown) => show(describeApiError(err, 'Could not reverse the payment.').message))
      .finally(() => {
        setBusy(false);
        setReversing(null);
      });
  };

  return (
    <div className="p-6">
      <div className="w-full space-y-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 className="text-xl font-semibold text-slate-900">Payments received</h1>
            <p className="mt-1 text-sm text-slate-500">Cash, UPI, bank and online payments from your customers, and the invoices they paid.</p>
          </div>
          {!needsClient && (
            <button
              type="button"
              onClick={() => setChoosing(true)}
              disabled={readOnly}
              title={readOnly ? READ_ONLY_TITLE : undefined}
              className="flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 disabled:opacity-60"
            >
              <Plus className="h-4 w-4" /> Record payment
            </button>
          )}
        </div>

        {needsClient ? (
          <NoClientSelected what="payments" />
        ) : (
          <>
            {choosing && (
              <div className="space-y-3 rounded-xl border border-slate-200 bg-white p-4">
                <p className="text-sm font-semibold text-slate-900">Who paid?</p>
                <CustomerPicker contactId={customer?.contact_id ?? null} companyId={customer?.crm_company_id ?? null} onChange={setCustomer} />
                <div className="flex justify-end gap-2">
                  <button type="button" onClick={() => { setChoosing(false); setCustomer(null); }} className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700">
                    Cancel
                  </button>
                  <button type="button" disabled={!customer?.contact_id && !customer?.crm_company_id} onClick={() => setRecording(true)} className="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white disabled:bg-slate-300">
                    Continue
                  </button>
                </div>
              </div>
            )}
            <SearchInput value={search} onChange={(q) => { setSearch(q); setPage(1); }} placeholder="Search receipt number or reference…" />
            {query.error && <ErrorBanner message={query.error} />}
            <TableCard>
              <table className="min-w-full divide-y divide-slate-200 text-sm" data-testid="crm-payments-table">
                <thead className="bg-slate-50">
                  <tr>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Receipt</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Customer</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Method</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Date</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Applied to</th>
                    <th className="px-4 py-3 text-right font-medium text-slate-600">Amount</th>
                    <th className="px-4 py-3" />
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {query.isLoading ? (
                    <TableSkeletonRows columns={7} />
                  ) : rows.length === 0 ? (
                    <tr>
                      <td colSpan={7}>
                        <EmptyState title="No payments yet." />
                      </td>
                    </tr>
                  ) : (
                    rows.map((p) => (
                      <tr key={p.id} className={p.status === 'reversed' ? 'text-slate-400' : ''}>
                        <td className="whitespace-nowrap px-4 py-3 font-medium text-slate-900">
                          {p.number} {p.status === 'reversed' && <DocStatusBadge status="reversed" />}
                          {p.reference && <p className="text-xs text-slate-500">Ref {p.reference}</p>}
                        </td>
                        <td className="px-4 py-3 text-slate-700">{p.company?.name ?? p.contact?.name ?? p.contact?.phone_number ?? '—'}</td>
                        <td className="px-4 py-3 text-slate-700">{p.method === 'upi' ? 'UPI' : crmLabel(p.method)}</td>
                        <td className="whitespace-nowrap px-4 py-3 text-slate-500">{formatDate(p.paid_on)}</td>
                        <td className="px-4 py-3 text-slate-700">
                          {p.allocations.filter((a) => !a.reversed).map((a) => (
                            <Link key={a.id} to={`/crm/documents/${a.crm_document_id}`} className="mr-2 text-emerald-700 hover:underline">
                              {a.number ?? `#${a.crm_document_id}`} ({formatMoney(a.amount)})
                            </Link>
                          ))}
                          {p.unallocated !== '0.00' && p.status !== 'reversed' && <span className="text-xs text-amber-700">Credit {formatMoney(p.unallocated)}</span>}
                        </td>
                        <td className="whitespace-nowrap px-4 py-3 text-right font-medium">{formatMoney(p.amount, p.currency)}</td>
                        <td className="px-4 py-3 text-right">
                          <button type="button" onClick={() => crmDocumentsService.downloadReceipt(p.id, `receipt-${p.number.replace(/\//g, '-')}.pdf`).catch(() => show('Could not download the receipt.'))} aria-label={`Download receipt ${p.number}`} title="Receipt PDF" className="rounded p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700">
                            <Download className="h-4 w-4" />
                          </button>
                          {p.status !== 'reversed' && (
                            <button
                              type="button"
                              onClick={() =>
                                crmDocumentsService
                                  .sendReceipt(p.id, 'whatsapp')
                                  .then((r) => show(r.message))
                                  .catch((e: unknown) => show(describeApiError(e, 'Could not send the receipt.').message))
                              }
                              disabled={readOnly}
                              aria-label={`Send receipt ${p.number} on WhatsApp`}
                              title="Send receipt on WhatsApp"
                              className="rounded p-1.5 text-slate-400 hover:bg-emerald-50 hover:text-emerald-700 disabled:opacity-40"
                            >
                              <MessageSquare className="h-4 w-4" />
                            </button>
                          )}
                          {p.status !== 'reversed' && (
                            <button type="button" onClick={() => setReversing(p)} disabled={readOnly} aria-label={`Reverse ${p.number}`} title="Reverse (never deletes)" className="rounded p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600 disabled:opacity-40">
                              <Undo2 className="h-4 w-4" />
                            </button>
                          )}
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
              <Pagination page={page} lastPage={query.data?.last_page ?? 1} total={query.data?.total ?? 0} perPage={query.data?.per_page ?? 20} onPageChange={setPage} onPerPageChange={() => undefined} perPageOptions={[20]} />
            </TableCard>
          </>
        )}
      </div>

      {recording && customer && (
        <RecordPaymentModal
          customer={customer}
          onCancel={() => setRecording(false)}
          onSaved={(_, message) => {
            setRecording(false);
            setChoosing(false);
            setCustomer(null);
            show(message);
            setVersion((v) => v + 1);
          }}
        />
      )}
      {reversing && (
        <PromptModal
          title={`Reverse ${reversing.number}`}
          message="The payment stays in the history, marked reversed, and the invoices it paid are open again. Use this for a bounced cheque or a mistake."
          label="Reason"
          required
          confirmLabel="Reverse payment"
          isLoading={busy}
          onSubmit={reverse}
          onCancel={() => setReversing(null)}
        />
      )}
      <Toast message={toast} />
    </div>
  );
}
