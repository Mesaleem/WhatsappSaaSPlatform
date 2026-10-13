import { Link } from 'react-router-dom';
import { Plus } from 'lucide-react';
import crmDocumentsService from '../../../services/crmDocumentsService';
import { Card } from '../../common/Card';
import { useCrmQuery } from '../crmHooks';
import { useAuth } from '../../../core/context/AuthContext';
import { formatMoney } from '../../../types/crm';
import { CRM_DOC_TYPE_LABELS } from '../../../types/crmSales';
import { DocStatusBadge } from './SalesUi';

/**
 * CRM Sales — the quotations, orders and invoices of a deal or a company, with buttons
 * that open a new document already linked to it (deal, contact and company pre-filled).
 * Hidden for a user without manage-crm-documents.
 */
export default function LinkedDocumentsCard({ dealId, companyId, contactId, readOnly }: { dealId?: number; companyId?: number | null; contactId?: number | null; readOnly?: boolean }) {
  const { hasPermission, isSuperAdmin } = useAuth();
  const allowed = isSuperAdmin() || hasPermission('manage-crm-documents');
  const query = useCrmQuery(
    allowed ? `linked-docs:${dealId ?? ''}:${companyId ?? ''}` : null,
    () => crmDocumentsService.listDocuments(dealId ? { crm_deal_id: dealId, per_page: 50 } : { crm_company_id: companyId ?? undefined, per_page: 50 }),
    'Failed to load documents.',
  );
  if (!allowed) return null;

  const params = new URLSearchParams();
  if (dealId) params.set('crm_deal_id', String(dealId));
  if (companyId) params.set('crm_company_id', String(companyId));
  if (contactId) params.set('contact_id', String(contactId));
  const docs = query.data?.data ?? [];

  return (
    <Card className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm font-semibold text-slate-900">Quotations & invoices</p>
        {!readOnly && (
          <div className="flex gap-2">
            {(['quotation', 'invoice'] as const).map((t) => (
              <Link key={t} to={`/crm/documents/new?type=${t}&${params.toString()}`} className="flex items-center gap-1 rounded-lg border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50">
                <Plus className="h-3.5 w-3.5" /> {CRM_DOC_TYPE_LABELS[t]}
              </Link>
            ))}
          </div>
        )}
      </div>
      {docs.length === 0 ? (
        <p className="text-sm text-slate-500">None yet.</p>
      ) : (
        <ul className="divide-y divide-slate-100 text-sm">
          {docs.map((d) => (
            <li key={d.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
              <Link to={`/crm/documents/${d.id}`} className="font-medium text-slate-900 hover:text-emerald-700">
                {CRM_DOC_TYPE_LABELS[d.type]} {d.number ?? `draft #${d.id}`}
              </Link>
              <span className="flex items-center gap-2">
                <DocStatusBadge status={d.status} overdue={d.is_overdue} />
                <span className="text-slate-700">{formatMoney(d.grand_total)}</span>
              </span>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}
