import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { Pencil, Trash2 } from 'lucide-react';
import crmSalesService from '../../services/crmSalesService';
import { Card } from '../../components/common/Card';
import ConfirmModal from '../../components/common/ConfirmModal';
import ActivityPanel from '../../components/crm/ActivityPanel';
import CompanyFormModal from '../../components/crm/CompanyFormModal';
import LinkedDocumentsCard from '../../components/crm/sales/LinkedDocumentsCard';
import { ErrorBanner, NoClientSelected, READ_ONLY_TITLE, Spinner, Toast } from '../../components/crm/CrmUi';
import { useCrmContext, useCrmQuery, useCrmToast } from '../../components/crm/crmHooks';
import { crmLabel, formatMoney } from '../../types/crm';
import { describeApiError } from '../../utils/apiError';

/** CRM Sales Phase 1 — one company: details, its people, its deals and the shared timeline. */
export default function CrmCompanyDetailPage() {
  const { id } = useParams();
  const companyId = Number(id);
  const navigate = useNavigate();
  const { needsClient, readOnly, selectedAccountId } = useCrmContext();
  const { toast, show } = useCrmToast();
  const [editing, setEditing] = useState(false);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [version, setVersion] = useState(0);

  const valid = Number.isInteger(companyId) && companyId > 0;
  const query = useCrmQuery(
    needsClient || !valid ? null : `company:${selectedAccountId ?? 'own'}:${companyId}:${version}`,
    () => crmSalesService.getCompany(companyId),
    'Failed to load this company.',
  );
  const company = query.data;

  const doDelete = () => {
    setDeleting(true);
    crmSalesService
      .deleteCompany(companyId)
      .then(() => navigate('/crm/companies', { replace: true }))
      .catch((err: unknown) => {
        show(describeApiError(err, 'Could not delete the company.').message);
        setDeleting(false);
        setConfirmDelete(false);
      });
  };

  const address = company ? [company.address_line1, company.address_line2, company.city, company.state, company.pincode, company.country].filter(Boolean).join(', ') : '';

  return (
    <div className="p-6">
      <div className="w-full space-y-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <Link to="/crm/companies" className="text-xs font-medium text-emerald-700 hover:underline">
              All companies
            </Link>
            <h1 className="mt-1 text-xl font-semibold text-slate-900">{company?.name ?? 'Company'}</h1>
            <p className="mt-1 text-sm text-slate-500">A customer business: its people, deals and history.</p>
          </div>
          {company && (
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
          <NoClientSelected what="CRM companies" />
        ) : query.isLoading ? (
          <Spinner />
        ) : query.error || !valid ? (
          <ErrorBanner message={query.status === 404 || !valid ? 'This company could not be found. It may have been deleted.' : (query.error ?? '')} />
        ) : company ? (
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <Card className="space-y-4 lg:col-span-2">
              <dl className="grid grid-cols-1 gap-4 text-sm sm:grid-cols-3">
                <Info label="Type" value={crmLabel(company.type)} />
                <Info label="GSTIN" value={company.gstin} />
                <Info label="Industry" value={company.industry} />
                <Info label="Phone" value={company.phone} />
                <Info label="Email" value={company.email} />
                <Info label="Website" value={company.website} />
                <div className="sm:col-span-3">
                  <Info label="Address" value={address} />
                </div>
              </dl>

              <div>
                <p className="text-sm font-semibold text-slate-900">People ({company.contacts.length})</p>
                {company.contacts.length === 0 ? (
                  <p className="mt-1 text-sm text-slate-500">No contacts yet. A contact joins when one of their leads is converted to this company.</p>
                ) : (
                  <ul className="mt-2 divide-y divide-slate-100">
                    {company.contacts.map((c) => (
                      <li key={c.id} className="flex items-center justify-between py-2 text-sm">
                        <Link to={`/crm/contacts/${c.id}`} className="font-medium text-slate-900 hover:text-emerald-700">
                          {c.name || 'Unnamed contact'}
                        </Link>
                        <span className="text-slate-500">{c.designation ? `${c.designation} · ` : ''}{c.phone_number}</span>
                      </li>
                    ))}
                  </ul>
                )}
              </div>

              <div>
                <p className="text-sm font-semibold text-slate-900">Deals ({company.deals.length})</p>
                {company.deals.length === 0 ? (
                  <p className="mt-1 text-sm text-slate-500">No deals yet.</p>
                ) : (
                  <ul className="mt-2 divide-y divide-slate-100">
                    {company.deals.map((d) => (
                      <li key={d.id} className="flex items-center justify-between py-2 text-sm">
                        <Link to={`/crm/deals/${d.id}`} className="font-medium text-slate-900 hover:text-emerald-700">
                          {d.number} · {d.name}
                        </Link>
                        <span className="text-slate-600">
                          {formatMoney(d.value, d.currency)} · {d.stage?.name ?? crmLabel(d.status)}
                        </span>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            </Card>

            <Card>
              <ActivityPanel link={{ crm_company_id: company.id }} readOnly={readOnly} />
            </Card>

            <div className="lg:col-span-2">
              <LinkedDocumentsCard companyId={company.id} readOnly={readOnly} />
            </div>
          </div>
        ) : null}
      </div>

      {editing && company && (
        <CompanyFormModal
          company={company}
          onCancel={() => setEditing(false)}
          onSaved={(_, message) => {
            setEditing(false);
            setVersion((v) => v + 1);
            show(message);
          }}
        />
      )}
      {confirmDelete && company && (
        <ConfirmModal
          title="Delete company"
          message={`Delete ${company.name}? Its contacts, leads, deals and activities are kept, just no longer linked to a company.`}
          confirmLabel="Delete company"
          isLoading={deleting}
          onConfirm={doDelete}
          onCancel={() => setConfirmDelete(false)}
        />
      )}
      <Toast message={toast} />
    </div>
  );
}

function Info({ label, value }: { label: string; value: string | null | undefined }) {
  return (
    <div>
      <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</dt>
      <dd className="mt-1 break-words text-slate-800">{value || <span className="text-slate-400">—</span>}</dd>
    </div>
  );
}
