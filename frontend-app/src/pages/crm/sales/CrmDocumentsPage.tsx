import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { Plus } from 'lucide-react';
import crmDocumentsService from '../../../services/crmDocumentsService';
import { TableCard } from '../../../components/common/Card';
import { Pagination, SearchInput } from '../../../components/common/DataTableControls';
import { TableSkeletonRows } from '../../../components/common/Skeleton';
import { EmptyState, ErrorBanner, NoClientSelected, READ_ONLY_TITLE } from '../../../components/crm/CrmUi';
import { formatDate } from '../../../components/crm/crmFilters';
import { useCrmContext, useCrmQuery } from '../../../components/crm/crmHooks';
import { DocStatusBadge } from '../../../components/crm/sales/SalesUi';
import { formatMoney } from '../../../types/crm';
import { CRM_DOC_TYPE_LABELS, CRM_DOC_TYPE_PLURALS, CRM_DOC_TYPES, type CrmDocType } from '../../../types/crmSales';

/**
 * CRM Sales Phase 2 — quotations, proposals, sales orders, customer POs, invoices and
 * credit notes, one tab per type (type, search and page live in the URL).
 */
export default function CrmDocumentsPage() {
  const navigate = useNavigate();
  const { needsClient, readOnly, selectedAccountId } = useCrmContext();
  const [params, setParams] = useSearchParams();
  const type = (CRM_DOC_TYPES as readonly string[]).includes(params.get('type') ?? '') ? (params.get('type') as CrmDocType) : 'invoice';
  const search = params.get('q') ?? '';
  const overdue = params.get('overdue') === '1';
  const page = Math.max(1, Number(params.get('page')) || 1);

  const update = (next: { type?: CrmDocType; q?: string; overdue?: boolean; page?: number }) => {
    const out = new URLSearchParams();
    out.set('type', next.type ?? type);
    const q = next.q ?? search;
    if (q) out.set('q', q);
    if (next.overdue ?? overdue) out.set('overdue', '1');
    if ((next.page ?? 1) > 1) out.set('page', String(next.page));
    setParams(out);
  };

  const query = useCrmQuery(
    needsClient ? null : JSON.stringify({ a: selectedAccountId, type, search, overdue, page }),
    () => crmDocumentsService.listDocuments({ type, search, overdue: type === 'invoice' && overdue, page }),
    'Failed to load documents.',
  );
  const rows = query.data?.data ?? [];
  const isInvoice = type === 'invoice' || type === 'credit_note';

  return (
    <div className="p-6">
      <div className="w-full space-y-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 className="text-xl font-semibold text-slate-900">Sales documents</h1>
            <p className="mt-1 text-sm text-slate-500">Quotations, proposals, orders and invoices you send to customers.</p>
          </div>
          {!needsClient && type !== 'credit_note' && (
            <button
              type="button"
              onClick={() => navigate(`/crm/documents/new?type=${type}`)}
              disabled={readOnly}
              title={readOnly ? READ_ONLY_TITLE : undefined}
              className="flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 disabled:opacity-60"
            >
              <Plus className="h-4 w-4" /> New {CRM_DOC_TYPE_LABELS[type].toLowerCase()}
            </button>
          )}
        </div>

        {needsClient ? (
          <NoClientSelected what="sales documents" />
        ) : (
          <>
            <div role="tablist" className="flex flex-wrap gap-2">
              {CRM_DOC_TYPES.map((t) => (
                <button
                  key={t}
                  role="tab"
                  type="button"
                  aria-selected={type === t}
                  onClick={() => update({ type: t, q: '', overdue: false })}
                  className={`rounded-lg px-3 py-1.5 text-sm font-medium ${type === t ? 'bg-emerald-600 text-white' : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50'}`}
                >
                  {CRM_DOC_TYPE_PLURALS[t]}
                </button>
              ))}
            </div>
            <div className="flex flex-wrap items-center gap-3">
              <SearchInput value={search} onChange={(q) => update({ q })} placeholder="Search number, title or PO…" />
              {type === 'invoice' && (
                <label className="flex items-center gap-2 text-sm text-slate-700">
                  <input type="checkbox" checked={overdue} onChange={(e) => update({ overdue: e.target.checked })} className="h-4 w-4 rounded border-slate-300 text-emerald-600" />
                  Overdue only
                </label>
              )}
            </div>
            {query.error && <ErrorBanner message={query.error} />}
            <TableCard>
              <table className="min-w-full divide-y divide-slate-200 text-sm" data-testid="crm-documents-table">
                <thead className="bg-slate-50">
                  <tr>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Number</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Customer</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Status</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Date</th>
                    <th className="px-4 py-3 text-right font-medium text-slate-600">Total</th>
                    {isInvoice && <th className="px-4 py-3 text-right font-medium text-slate-600">Balance</th>}
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {query.isLoading ? (
                    <TableSkeletonRows columns={isInvoice ? 6 : 5} />
                  ) : rows.length === 0 ? (
                    <tr>
                      <td colSpan={isInvoice ? 6 : 5}>
                        <EmptyState title={`No ${CRM_DOC_TYPE_PLURALS[type].toLowerCase()} yet.`} />
                      </td>
                    </tr>
                  ) : (
                    rows.map((d) => (
                      <tr key={d.id} className="cursor-pointer hover:bg-slate-50" onClick={() => navigate(`/crm/documents/${d.id}`)}>
                        <td className="whitespace-nowrap px-4 py-3">
                          <Link to={`/crm/documents/${d.id}`} onClick={(e) => e.stopPropagation()} className="font-medium text-slate-900 hover:text-emerald-700">
                            {d.number ?? (d.type === 'customer_po' ? d.external_reference ?? `PO #${d.id}` : `Draft #${d.id}`)}
                          </Link>
                          {d.title && <p className="text-xs text-slate-500">{d.title}</p>}
                        </td>
                        <td className="px-4 py-3 text-slate-700">{d.customer_name ?? '—'}</td>
                        <td className="px-4 py-3">
                          <DocStatusBadge status={d.status} overdue={d.is_overdue} />
                        </td>
                        <td className="whitespace-nowrap px-4 py-3 text-slate-500">{formatDate(d.issue_date ?? d.created_at)}</td>
                        <td className="whitespace-nowrap px-4 py-3 text-right font-medium text-slate-900">{formatMoney(d.grand_total, d.currency)}</td>
                        {isInvoice && <td className="whitespace-nowrap px-4 py-3 text-right text-slate-700">{formatMoney(d.balance_due, d.currency)}</td>}
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
              <Pagination
                page={page}
                lastPage={query.data?.last_page ?? 1}
                total={query.data?.total ?? 0}
                perPage={query.data?.per_page ?? 20}
                onPageChange={(p) => update({ page: p })}
                onPerPageChange={() => undefined}
                perPageOptions={[20]}
              />
            </TableCard>
          </>
        )}
      </div>
    </div>
  );
}
