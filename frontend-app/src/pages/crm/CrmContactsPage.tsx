import { useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { Plus } from 'lucide-react';
import crmService from '../../services/crmService';
import { TableCard } from '../../components/common/Card';
import { ClearFiltersButton, Pagination, SearchInput } from '../../components/common/DataTableControls';
import { TableSkeletonRows } from '../../components/common/Skeleton';
import ContactFormModal from '../../components/crm/ContactFormModal';
import { EmptyState, ErrorBanner, NoClientSelected, READ_ONLY_TITLE, Toast } from '../../components/crm/CrmUi';
import { CRM_DEFAULT_PER_PAGE, CRM_PER_PAGE_OPTIONS, formatDate } from '../../components/crm/crmFilters';
import { useCrmContext, useCrmQuery, useCrmToast } from '../../components/crm/crmHooks';
import type { CrmContact } from '../../types/crm';

/**
 * Phase 6 — CRM Task 8. CRM contacts — GET /api/crm/contacts (search +
 * pagination, both server-side, both in the URL). Each row carries
 * crm_leads_count from the same response. Clicking a row opens the
 * contact detail with its leads.
 */
export default function CrmContactsPage() {
  const navigate = useNavigate();
  const { needsClient, readOnly, selectedAccountId } = useCrmContext();
  const { toast, show } = useCrmToast();
  const [params, setParams] = useSearchParams();

  const search = params.get('q') ?? '';
  const page = Math.max(1, Number(params.get('page')) || 1);
  const rawPerPage = Number(params.get('per_page'));
  const perPage = CRM_PER_PAGE_OPTIONS.includes(rawPerPage) ? rawPerPage : CRM_DEFAULT_PER_PAGE;

  const update = (next: { q?: string; page?: number; perPage?: number }) => {
    const q = next.q ?? search;
    const p = next.page ?? page;
    const pp = next.perPage ?? perPage;
    const out = new URLSearchParams();
    if (q.trim() !== '') out.set('q', q);
    if (p > 1) out.set('page', String(p));
    if (pp !== CRM_DEFAULT_PER_PAGE) out.set('per_page', String(pp));
    setParams(out);
  };

  const [creating, setCreating] = useState(false);

  const query = useCrmQuery(
    needsClient ? null : JSON.stringify({ account: selectedAccountId, search, page, perPage }),
    () => crmService.listContacts(search, page, perPage),
    'Failed to load contacts.',
  );
  const contacts: CrmContact[] = query.data?.data ?? [];
  const total = query.data?.total ?? 0;
  const lastPage = query.data?.last_page ?? 1;
  const isLoading = query.isLoading;
  const error = query.error;

  return (
    <div className="p-6">
      <div className="w-full space-y-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 className="text-xl font-semibold text-slate-900">CRM Contacts</h1>
            <p className="mt-1 text-sm text-slate-500">The people behind your CRM leads, one per phone number.</p>
          </div>
          {!needsClient && (
            <button
              type="button"
              onClick={() => setCreating(true)}
              disabled={readOnly}
              title={readOnly ? READ_ONLY_TITLE : undefined}
              className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
            >
              <Plus className="h-4 w-4" />
              New contact
            </button>
          )}
        </div>

        {needsClient ? (
          <NoClientSelected what="CRM contacts" />
        ) : (
          <>
            <div className="flex flex-wrap items-center gap-3">
              <SearchInput value={search} onChange={(q) => update({ q, page: 1 })} placeholder="Search name, phone or email…" />
              <ClearFiltersButton active={search !== ''} onClear={() => update({ q: '', page: 1 })} />
            </div>

            {error && <ErrorBanner message={error} />}

            <TableCard>
              <table className="min-w-full divide-y divide-slate-200 text-sm" data-testid="crm-contacts-table">
                <thead className="bg-slate-50">
                  <tr>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Name</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Phone</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Email</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Leads</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Created</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {isLoading ? (
                    <TableSkeletonRows columns={5} />
                  ) : contacts.length === 0 ? (
                    <tr>
                      <td colSpan={5}>
                        {search ? (
                          <EmptyState title="No contacts match this search." />
                        ) : (
                          <EmptyState title="No CRM contacts yet." hint="Contacts are created with leads, or you can add one." />
                        )}
                      </td>
                    </tr>
                  ) : (
                    contacts.map((c) => (
                      <tr
                        key={c.id}
                        className="cursor-pointer hover:bg-slate-50"
                        onClick={() => navigate(`/crm/contacts/${c.id}`)}
                        data-testid={`crm-contact-row-${c.id}`}
                      >
                        <td className="px-4 py-3">
                          <Link
                            to={`/crm/contacts/${c.id}`}
                            onClick={(e) => e.stopPropagation()}
                            className="font-medium text-slate-900 hover:text-indigo-600"
                          >
                            {c.name || 'Unnamed contact'}
                          </Link>
                        </td>
                        <td className="whitespace-nowrap px-4 py-3 text-slate-700">{c.phone_number}</td>
                        <td className="px-4 py-3 text-slate-700">{c.email ?? '—'}</td>
                        <td className="px-4 py-3 text-slate-700">{c.crm_leads_count ?? 0}</td>
                        <td className="whitespace-nowrap px-4 py-3 text-slate-500">{formatDate(c.created_at)}</td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
              <Pagination
                page={page}
                lastPage={lastPage}
                total={total}
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
        <ContactFormModal
          onCancel={() => setCreating(false)}
          onSaved={(contact, message) => {
            setCreating(false);
            show(message);
            navigate(`/crm/contacts/${contact.id}`);
          }}
        />
      )}

      <Toast message={toast} />
    </div>
  );
}
