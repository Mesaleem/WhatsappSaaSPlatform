import { useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { Plus } from 'lucide-react';
import crmSalesService from '../../services/crmSalesService';
import { TableCard } from '../../components/common/Card';
import { ClearFiltersButton, Pagination, SearchInput } from '../../components/common/DataTableControls';
import { TableSkeletonRows } from '../../components/common/Skeleton';
import CompanyFormModal from '../../components/crm/CompanyFormModal';
import { EmptyState, ErrorBanner, NoClientSelected, READ_ONLY_TITLE, Toast } from '../../components/crm/CrmUi';
import { CRM_DEFAULT_PER_PAGE, CRM_PER_PAGE_OPTIONS } from '../../components/crm/crmFilters';
import { useCrmContext, useCrmQuery, useCrmToast } from '../../components/crm/crmHooks';
import { crmLabel, type CrmCompany } from '../../types/crm';

/**
 * CRM Sales Phase 1 — customer companies (B2B). GET /api/crm/companies with search
 * (name or GSTIN) and pagination in the URL, like CRM Contacts.
 */
export default function CrmCompaniesPage() {
  const navigate = useNavigate();
  const { needsClient, readOnly, selectedAccountId } = useCrmContext();
  const { toast, show } = useCrmToast();
  const [params, setParams] = useSearchParams();
  const [creating, setCreating] = useState(false);

  const search = params.get('q') ?? '';
  const page = Math.max(1, Number(params.get('page')) || 1);
  const rawPerPage = Number(params.get('per_page'));
  const perPage = CRM_PER_PAGE_OPTIONS.includes(rawPerPage) ? rawPerPage : CRM_DEFAULT_PER_PAGE;

  const update = (next: { q?: string; page?: number; perPage?: number }) => {
    const out = new URLSearchParams();
    const q = next.q ?? search;
    if (q.trim() !== '') out.set('q', q);
    if ((next.page ?? page) > 1) out.set('page', String(next.page ?? page));
    if ((next.perPage ?? perPage) !== CRM_DEFAULT_PER_PAGE) out.set('per_page', String(next.perPage ?? perPage));
    setParams(out);
  };

  const query = useCrmQuery(
    needsClient ? null : JSON.stringify({ account: selectedAccountId, search, page, perPage }),
    () => crmSalesService.listCompanies(search, page, perPage),
    'Failed to load companies.',
  );
  const companies: CrmCompany[] = query.data?.data ?? [];

  return (
    <div className="p-6">
      <div className="w-full space-y-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 className="text-xl font-semibold text-slate-900">CRM Companies</h1>
            <p className="mt-1 text-sm text-slate-500">The businesses you sell to, with their people and deals.</p>
          </div>
          {!needsClient && (
            <button
              type="button"
              onClick={() => setCreating(true)}
              disabled={readOnly}
              title={readOnly ? READ_ONLY_TITLE : undefined}
              className="flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
            >
              <Plus className="h-4 w-4" />
              New company
            </button>
          )}
        </div>

        {needsClient ? (
          <NoClientSelected what="CRM companies" />
        ) : (
          <>
            <div className="flex flex-wrap items-center gap-3">
              <SearchInput value={search} onChange={(q) => update({ q, page: 1 })} placeholder="Search name or GSTIN…" />
              <ClearFiltersButton active={search !== ''} onClear={() => update({ q: '', page: 1 })} />
            </div>

            {query.error && <ErrorBanner message={query.error} />}

            <TableCard>
              <table className="min-w-full divide-y divide-slate-200 text-sm" data-testid="crm-companies-table">
                <thead className="bg-slate-50">
                  <tr>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Company</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Type</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">GSTIN</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">City</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Contacts</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Deals</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {query.isLoading ? (
                    <TableSkeletonRows columns={6} />
                  ) : companies.length === 0 ? (
                    <tr>
                      <td colSpan={6}>
                        <EmptyState
                          title={search ? 'No companies match this search.' : 'No companies yet.'}
                          hint={search ? undefined : 'Companies are created when you convert a lead, or you can add one.'}
                        />
                      </td>
                    </tr>
                  ) : (
                    companies.map((c) => (
                      <tr key={c.id} className="cursor-pointer hover:bg-slate-50" onClick={() => navigate(`/crm/companies/${c.id}`)}>
                        <td className="px-4 py-3">
                          <Link to={`/crm/companies/${c.id}`} onClick={(e) => e.stopPropagation()} className="font-medium text-slate-900 hover:text-emerald-700">
                            {c.name}
                          </Link>
                          {c.industry && <p className="text-xs text-slate-500">{c.industry}</p>}
                        </td>
                        <td className="px-4 py-3 text-slate-700">{crmLabel(c.type)}</td>
                        <td className="whitespace-nowrap px-4 py-3 font-mono text-xs text-slate-700">{c.gstin ?? '—'}</td>
                        <td className="px-4 py-3 text-slate-700">{c.city ?? '—'}</td>
                        <td className="px-4 py-3 text-slate-700">{c.contacts_count ?? 0}</td>
                        <td className="px-4 py-3 text-slate-700">{c.deals_count ?? 0}</td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
              <Pagination
                page={page}
                lastPage={query.data?.last_page ?? 1}
                total={query.data?.total ?? 0}
                perPage={perPage}
                onPageChange={(p) => update({ page: p })}
                onPerPageChange={(pp) => update({ perPage: pp, page: 1 })}
                perPageOptions={CRM_PER_PAGE_OPTIONS}
              />
            </TableCard>
          </>
        )}
      </div>

      {creating && (
        <CompanyFormModal
          onCancel={() => setCreating(false)}
          onSaved={(company, message) => {
            setCreating(false);
            show(message);
            navigate(`/crm/companies/${company.id}`);
          }}
        />
      )}
      <Toast message={toast} />
    </div>
  );
}
