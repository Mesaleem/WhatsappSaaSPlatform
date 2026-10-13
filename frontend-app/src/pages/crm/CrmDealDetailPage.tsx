import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { Pencil, Trash2 } from 'lucide-react';
import crmSalesService from '../../services/crmSalesService';
import { Card } from '../../components/common/Card';
import ConfirmModal from '../../components/common/ConfirmModal';
import ActivityPanel from '../../components/crm/ActivityPanel';
import DealFormModal from '../../components/crm/DealFormModal';
import DealStageControl from '../../components/crm/DealStageControl';
import LinkedDocumentsCard from '../../components/crm/sales/LinkedDocumentsCard';
import { ErrorBanner, NoClientSelected, READ_ONLY_TITLE, Spinner, Toast } from '../../components/crm/CrmUi';
import { formatDate } from '../../components/crm/crmFilters';
import { useCrmContext, useCrmQuery, useCrmToast } from '../../components/crm/crmHooks';
import { crmLabel, crmSourceLabel, formatMoney } from '../../types/crm';
import { describeApiError } from '../../utils/apiError';

/** CRM Sales Phase 1 — one deal: value, stage, links and its timeline. */
export default function CrmDealDetailPage() {
  const { id } = useParams();
  const dealId = Number(id);
  const navigate = useNavigate();
  const { needsClient, readOnly, selectedAccountId } = useCrmContext();
  const { toast, show } = useCrmToast();
  const [version, setVersion] = useState(0);
  const [editing, setEditing] = useState(false);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [deleting, setDeleting] = useState(false);

  const valid = Number.isInteger(dealId) && dealId > 0;
  const key = needsClient || !valid ? null : `${selectedAccountId ?? 'own'}:${dealId}:${version}`;
  const query = useCrmQuery(key === null ? null : `deal:${key}`, () => crmSalesService.getDeal(dealId), 'Failed to load this deal.');
  const stages = useCrmQuery(key === null ? null : `stages:${selectedAccountId ?? 'own'}`, () => crmSalesService.listStages(), 'Failed to load stages.');
  const deal = query.data;

  const reload = (message: string) => {
    setVersion((v) => v + 1);
    show(message);
  };

  const doDelete = () => {
    setDeleting(true);
    crmSalesService
      .deleteDeal(dealId)
      .then(() => navigate('/crm/deals', { replace: true }))
      .catch((err: unknown) => {
        show(describeApiError(err, 'Could not delete the deal.').message);
        setDeleting(false);
        setConfirmDelete(false);
      });
  };

  return (
    <div className="p-6">
      <div className="w-full space-y-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <Link to="/crm/deals" className="text-xs font-medium text-emerald-700 hover:underline">
              All deals
            </Link>
            <h1 className="mt-1 text-xl font-semibold text-slate-900">{deal ? `${deal.number} · ${deal.name}` : 'Deal'}</h1>
            <p className="mt-1 text-sm text-slate-500">One sale: its value, stage and history.</p>
          </div>
          {deal && (
            <div className="flex gap-2">
              <button
                type="button"
                onClick={() => setEditing(true)}
                disabled={readOnly}
                title={readOnly ? READ_ONLY_TITLE : undefined}
                className="flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
              >
                <Pencil className="h-4 w-4" /> Edit
              </button>
              <button
                type="button"
                onClick={() => setConfirmDelete(true)}
                disabled={readOnly}
                title={readOnly ? READ_ONLY_TITLE : undefined}
                className="flex items-center gap-2 rounded-lg border border-red-200 bg-white px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50 disabled:opacity-50"
              >
                <Trash2 className="h-4 w-4" /> Delete
              </button>
            </div>
          )}
        </div>

        {needsClient ? (
          <NoClientSelected what="CRM deals" />
        ) : query.isLoading ? (
          <Spinner />
        ) : query.error || !valid ? (
          <ErrorBanner message={query.status === 404 || !valid ? 'This deal could not be found. It may have been deleted.' : (query.error ?? '')} />
        ) : deal ? (
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <Card className="space-y-5 lg:col-span-2">
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                  <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Value</p>
                  <p className="mt-1 text-lg font-semibold text-slate-900">{formatMoney(deal.value, deal.currency)}</p>
                </div>
                <div>
                  <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Stage</p>
                  <div className="mt-1">
                    <DealStageControl deal={deal} stages={stages.data ?? []} disabled={readOnly} onMoved={(_, m) => reload(m)} onError={show} />
                  </div>
                </div>
                <div>
                  <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Status</p>
                  <p className="mt-1 text-sm font-medium text-slate-800">{crmLabel(deal.status)}</p>
                  {deal.lost_reason && <p className="text-xs text-slate-500">Reason: {deal.lost_reason}</p>}
                </div>
              </div>
              <dl className="grid grid-cols-1 gap-4 text-sm sm:grid-cols-3">
                <Info label="Company">{deal.company ? <Link className="text-emerald-700 hover:underline" to={`/crm/companies/${deal.company.id}`}>{deal.company.name}</Link> : null}</Info>
                <Info label="Contact">{deal.contact ? <Link className="text-emerald-700 hover:underline" to={`/crm/contacts/${deal.contact.id}`}>{deal.contact.name || deal.contact.phone_number}</Link> : null}</Info>
                <Info label="From lead">{deal.crm_lead_id ? <Link className="text-emerald-700 hover:underline" to={`/crm/leads/${deal.crm_lead_id}`}>Lead #{deal.crm_lead_id}</Link> : null}</Info>
                <Info label="Expected close">{deal.expected_close_date}</Info>
                <Info label="Source">{deal.source ? crmSourceLabel(deal.source) : null}</Info>
                <Info label="Owner">{deal.owner?.name}</Info>
                <Info label="Created">{formatDate(deal.created_at)}</Info>
                {deal.won_at && <Info label="Won">{formatDate(deal.won_at)}</Info>}
                {deal.lost_at && <Info label="Lost">{formatDate(deal.lost_at)}</Info>}
              </dl>
            </Card>

            <Card>
              <ActivityPanel link={{ crm_deal_id: deal.id }} readOnly={readOnly} />
            </Card>

            <div className="lg:col-span-2">
              <LinkedDocumentsCard dealId={deal.id} companyId={deal.company?.id ?? null} contactId={deal.contact?.id ?? null} readOnly={readOnly} />
            </div>
          </div>
        ) : null}
      </div>

      {editing && deal && (
        <DealFormModal
          deal={deal}
          stages={stages.data ?? []}
          onCancel={() => setEditing(false)}
          onSaved={(_, message) => {
            setEditing(false);
            reload(message);
          }}
        />
      )}
      {confirmDelete && deal && (
        <ConfirmModal
          title="Delete deal"
          message={`Delete ${deal.number}? Its activities stay in the history, no longer linked to a deal.`}
          confirmLabel="Delete deal"
          isLoading={deleting}
          onConfirm={doDelete}
          onCancel={() => setConfirmDelete(false)}
        />
      )}
      <Toast message={toast} />
    </div>
  );
}

function Info({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</dt>
      <dd className="mt-1 text-slate-800">{children || <span className="text-slate-400">—</span>}</dd>
    </div>
  );
}
