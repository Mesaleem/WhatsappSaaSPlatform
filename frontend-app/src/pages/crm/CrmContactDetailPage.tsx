import { useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { GitMerge, Loader2, Pencil, Trash2, XCircle } from 'lucide-react';
import crmService from '../../services/crmService';
import { Card, TableCard } from '../../components/common/Card';
import ConfirmModal from '../../components/common/ConfirmModal';
import { ClearFiltersButton, Pagination, SearchInput, StatusFilterSelect } from '../../components/common/DataTableControls';
import { TableSkeletonRows } from '../../components/common/Skeleton';
import ContactFormModal from '../../components/crm/ContactFormModal';
import {
  EmptyState,
  ErrorBanner,
  NoClientSelected,
  READ_ONLY_TITLE,
  Spinner,
  StatusBadge,
  TagChips,
  Toast,
} from '../../components/crm/CrmUi';
import { CRM_DEFAULT_PER_PAGE, formatDate } from '../../components/crm/crmFilters';
import { useCrmContext, useCrmQuery, useCrmToast } from '../../components/crm/crmHooks';
import { describeApiError } from '../../utils/apiError';
import {
  CRM_LEAD_SOURCES,
  CRM_LEAD_STATUSES,
  crmLabel,
  crmSourceLabel,
  type CrmContact,
  type CrmLead,
  type CrmLeadSource,
  type CrmLeadStatus,
} from '../../types/crm';

/**
 * Phase 6 — CRM Task 8. One contact — GET /api/crm/contacts/{id} — and its
 * leads — GET /api/crm/contacts/{id}/leads, which accepts status and source
 * (the backend's own rules for that endpoint; it has no search/assignee/tag
 * filters, so none are offered here). Both filters and the page are in the
 * URL.
 *
 * Existing contact operations: edit (PATCH), delete (DELETE — the backend
 * refuses with 409 while leads or group memberships still reference the
 * contact; that message is shown as-is) and merge into another contact
 * (POST /contacts/{source}/merge/{target} — this contact is the source and
 * is removed; its leads move to the target).
 */
export default function CrmContactDetailPage() {
  const { id } = useParams();
  const contactId = Number(id);
  const navigate = useNavigate();
  const { needsClient, readOnly, selectedAccountId } = useCrmContext();
  const { toast, show } = useCrmToast();
  const [params, setParams] = useSearchParams();

  const statusParam = params.get('status');
  const sourceParam = params.get('source');
  const status = (CRM_LEAD_STATUSES as readonly string[]).includes(statusParam ?? '') ? (statusParam as CrmLeadStatus) : undefined;
  const source = (CRM_LEAD_SOURCES as readonly string[]).includes(sourceParam ?? '') ? (sourceParam as CrmLeadSource) : undefined;
  const page = Math.max(1, Number(params.get('page')) || 1);

  const setLeadFilter = (next: { status?: string; source?: string; page?: number }) => {
    const out = new URLSearchParams();
    const s = next.status !== undefined ? next.status : status ?? '';
    const src = next.source !== undefined ? next.source : source ?? '';
    const p = next.page ?? 1;
    if (s) out.set('status', s);
    if (src) out.set('source', src);
    if (p > 1) out.set('page', String(p));
    setParams(out);
  };

  const [editing, setEditing] = useState(false);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [merging, setMerging] = useState(false);

  const validId = Number.isInteger(contactId) && contactId > 0;
  const accountKey = selectedAccountId ?? 'own';

  const contactQuery = useCrmQuery(
    needsClient || !validId ? null : `contact:${accountKey}:${contactId}`,
    () => crmService.getContact(contactId),
    'Failed to load this contact.',
  );
  const contact = contactQuery.data;
  const isLoading = contactQuery.isLoading;
  const loadError = !validId
    ? { status: 404, message: 'Contact not found.' }
    : contactQuery.error !== null
      ? { status: contactQuery.status, message: contactQuery.error }
      : null;

  const leadsQuery = useCrmQuery(
    needsClient || !validId ? null : JSON.stringify({ account: accountKey, contactId, status, source, page }),
    () => crmService.contactLeads(contactId, { status, source }, page, CRM_DEFAULT_PER_PAGE),
    'Failed to load this contact’s leads.',
  );
  const leads: CrmLead[] = leadsQuery.data?.data ?? [];
  const leadsTotal = leadsQuery.data?.total ?? 0;
  const leadsLastPage = leadsQuery.data?.last_page ?? 1;
  const leadsLoading = leadsQuery.isLoading;
  const leadsError = leadsQuery.error;

  const doDelete = () => {
    if (!contact || deleting) return;
    setDeleting(true);
    crmService
      .deleteContact(contact.id)
      .then(() => navigate('/crm/contacts', { replace: true }))
      .catch((err: unknown) => {
        // 409 CONTACT_HAS_DEPENDENTS carries the backend's own explanation.
        show(describeApiError(err, 'Failed to delete the contact.').message);
        setDeleting(false);
        setConfirmDelete(false);
      });
  };

  const hasFilters = Boolean(status || source);

  return (
    <div className="p-6">
      <div className="w-full space-y-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <Link to="/crm/contacts" className="text-xs font-medium text-indigo-600 hover:underline">
              All contacts
            </Link>
            <h1 className="mt-1 text-xl font-semibold text-slate-900">{contact ? contact.name || 'Unnamed contact' : 'Contact'}</h1>
            <p className="mt-1 text-sm text-slate-500">Contact details and every CRM lead for this person.</p>
          </div>
          {contact && (
            <div className="flex flex-wrap gap-2">
              {[
                { label: 'Edit', icon: Pencil, onClick: () => setEditing(true), danger: false },
                { label: 'Merge into…', icon: GitMerge, onClick: () => setMerging(true), danger: false },
                { label: 'Delete', icon: Trash2, onClick: () => setConfirmDelete(true), danger: true },
              ].map(({ label, icon: Icon, onClick, danger }) => (
                <button
                  key={label}
                  type="button"
                  onClick={onClick}
                  disabled={readOnly}
                  title={readOnly ? READ_ONLY_TITLE : undefined}
                  className={`flex items-center gap-1.5 rounded-lg border px-3 py-2 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-50 ${
                    danger ? 'border-red-200 bg-white text-red-600 hover:bg-red-50' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
                  }`}
                >
                  <Icon className="h-4 w-4" />
                  {label}
                </button>
              ))}
            </div>
          )}
        </div>

        {needsClient ? (
          <NoClientSelected what="CRM contacts" />
        ) : isLoading ? (
          <Spinner />
        ) : loadError ? (
          <div className="space-y-3">
            <ErrorBanner message={loadError.status === 404 ? 'This contact could not be found. It may have been deleted or merged.' : loadError.message} />
            <Link to="/crm/contacts" className="text-sm font-medium text-indigo-600 hover:underline">
              Back to contacts
            </Link>
          </div>
        ) : contact ? (
          <>
            <Card>
              <dl className="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div>
                  <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">Phone</dt>
                  <dd className="mt-1 text-slate-800">{contact.phone_number}</dd>
                </div>
                <div>
                  <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">Email</dt>
                  <dd className="mt-1 text-slate-800">{contact.email ?? '—'}</dd>
                </div>
                <div>
                  <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">Leads</dt>
                  <dd className="mt-1 text-slate-800">{contact.crm_leads_count ?? leadsTotal}</dd>
                </div>
                <div>
                  <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">Created</dt>
                  <dd className="mt-1 text-slate-800">{formatDate(contact.created_at)}</dd>
                </div>
              </dl>
            </Card>

            <div className="space-y-3">
              <h2 className="text-sm font-semibold text-slate-900">Leads</h2>
              <div className="flex flex-wrap items-center gap-3">
                <StatusFilterSelect
                  value={status ?? ''}
                  onChange={(s) => setLeadFilter({ status: s })}
                  options={CRM_LEAD_STATUSES.map((s) => ({ value: s, label: crmLabel(s) }))}
                />
                <StatusFilterSelect
                  value={source ?? ''}
                  onChange={(s) => setLeadFilter({ source: s })}
                  options={CRM_LEAD_SOURCES.map((s) => ({ value: s, label: crmSourceLabel(s) }))}
                  allLabel="All sources"
                />
                <ClearFiltersButton active={hasFilters} onClear={() => setLeadFilter({ status: '', source: '' })} />
              </div>
              {leadsError && <ErrorBanner message={leadsError} />}
              <TableCard>
                <table className="min-w-full divide-y divide-slate-200 text-sm" data-testid="crm-contact-leads">
                  <thead className="bg-slate-50">
                    <tr>
                      <th className="px-4 py-3 text-left font-medium text-slate-600">Lead</th>
                      <th className="px-4 py-3 text-left font-medium text-slate-600">Status</th>
                      <th className="px-4 py-3 text-left font-medium text-slate-600">Source</th>
                      <th className="px-4 py-3 text-left font-medium text-slate-600">Assignee</th>
                      <th className="px-4 py-3 text-left font-medium text-slate-600">Tags</th>
                      <th className="px-4 py-3 text-left font-medium text-slate-600">Created</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {leadsLoading ? (
                      <TableSkeletonRows columns={6} rows={3} />
                    ) : leads.length === 0 ? (
                      <tr>
                        <td colSpan={6}>
                          <EmptyState title={hasFilters ? 'No leads match these filters.' : 'This contact has no CRM leads.'} />
                        </td>
                      </tr>
                    ) : (
                      leads.map((lead) => (
                        <tr key={lead.id} className="cursor-pointer hover:bg-slate-50" onClick={() => navigate(`/crm/leads/${lead.id}`)}>
                          <td className="px-4 py-3">
                            <Link to={`/crm/leads/${lead.id}`} onClick={(e) => e.stopPropagation()} className="font-medium text-indigo-600 hover:underline">
                              Lead #{lead.id}
                            </Link>
                          </td>
                          <td className="px-4 py-3">
                            <StatusBadge status={lead.status} />
                          </td>
                          <td className="px-4 py-3 text-slate-700">{crmSourceLabel(lead.source)}</td>
                          <td className="px-4 py-3 text-slate-700">{lead.assigned_user?.name ?? 'Unassigned'}</td>
                          <td className="px-4 py-3">
                            <TagChips tags={lead.tags} />
                          </td>
                          <td className="whitespace-nowrap px-4 py-3 text-slate-500">{formatDate(lead.created_at)}</td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
                <Pagination
                  page={page}
                  lastPage={leadsLastPage}
                  total={leadsTotal}
                  perPage={CRM_DEFAULT_PER_PAGE}
                  onPageChange={(p) => setLeadFilter({ page: p })}
                />
              </TableCard>
            </div>
          </>
        ) : null}
      </div>

      {editing && contact && (
        <ContactFormModal
          contact={contact}
          onCancel={() => setEditing(false)}
          onSaved={(saved, message) => {
            setEditing(false);
            contactQuery.setData(() => saved);
            show(message);
            leadsQuery.reload();
          }}
        />
      )}

      {confirmDelete && contact && (
        <ConfirmModal
          title="Delete contact"
          message={`Delete ${contact.name || contact.phone_number}? This is only allowed when the contact has no leads or group memberships left; otherwise the server will refuse and nothing is changed.`}
          confirmLabel="Delete contact"
          isLoading={deleting}
          onConfirm={doDelete}
          onCancel={() => setConfirmDelete(false)}
        />
      )}

      {merging && contact && (
        <MergeContactModal
          source={contact}
          onCancel={() => setMerging(false)}
          onMerged={(target, message) => {
            setMerging(false);
            show(message);
            navigate(`/crm/contacts/${target.id}`, { replace: true });
          }}
        />
      )}

      <Toast message={toast} />
    </div>
  );
}

/**
 * Merge THIS contact (source) into another (target). The backend moves the
 * source's leads and group memberships to the target, keeps the target's
 * phone number and deletes the source. Because the source's phone number
 * is discarded when the two differ, the backend requires an explicit
 * confirm_phone_discard — surfaced here as a required checkbox; if it is
 * missing, the server's own 422 is shown.
 */
function MergeContactModal({
  source,
  onCancel,
  onMerged,
}: {
  source: CrmContact;
  onCancel: () => void;
  onMerged: (target: CrmContact, message: string) => void;
}) {
  const [search, setSearch] = useState('');
  const [target, setTarget] = useState<CrmContact | null>(null);
  const [confirmed, setConfirmed] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const candidates = useCrmQuery(
    `merge-candidates:${search}`,
    () => crmService.listContacts(search, 1, 10),
    'Failed to load contacts.',
  );
  const options = (candidates.data?.data ?? []).filter((c) => c.id !== source.id);
  const loading = candidates.isLoading;

  const phoneDiffers = target !== null && target.phone_number !== source.phone_number;

  const submit = () => {
    if (!target || saving) return;
    setSaving(true);
    setError(null);
    crmService
      .mergeContacts(source.id, target.id, confirmed)
      .then((res) => onMerged(res.data, res.message))
      .catch((err: unknown) => {
        setError(describeApiError(err, 'Failed to merge the contacts.').message);
        setSaving(false);
      });
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl" data-testid="crm-merge-contact">
        <div className="flex items-center justify-between">
          <h3 className="text-base font-semibold text-slate-900">Merge contact</h3>
          <button type="button" onClick={onCancel} disabled={saving} className="text-slate-400 hover:text-slate-600" aria-label="Close">
            <XCircle className="h-5 w-5" />
          </button>
        </div>
        <p className="mt-1 text-xs text-slate-500">
          {source.name || source.phone_number} will be merged into the contact you choose and then removed. Its leads move to that contact.
        </p>
        <div className="mt-4">
          <SearchInput value={search} onChange={setSearch} placeholder="Search contacts…" />
        </div>
        <div className="mt-3 max-h-56 space-y-1 overflow-y-auto">
          {loading ? (
            <Spinner />
          ) : options.length === 0 ? (
            <p className="py-4 text-center text-sm text-slate-500">No other contacts found.</p>
          ) : (
            options.map((c) => (
              <button
                key={c.id}
                type="button"
                onClick={() => setTarget(c)}
                className={`flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm ${
                  target?.id === c.id ? 'bg-indigo-50 ring-1 ring-indigo-200' : 'hover:bg-slate-50'
                }`}
              >
                <span className="font-medium text-slate-900">{c.name || 'Unnamed contact'}</span>
                <span className="text-slate-500">{c.phone_number}</span>
              </button>
            ))
          )}
        </div>
        {phoneDiffers && target && (
          <label className="mt-3 flex items-start gap-2 text-sm text-slate-700">
            <input type="checkbox" checked={confirmed} onChange={(e) => setConfirmed(e.target.checked)} className="mt-0.5" />
            I understand {source.phone_number} will be discarded; the merged contact keeps {target.phone_number}.
          </label>
        )}
        {(error ?? candidates.error) && (
          <p className="mt-3 text-sm text-red-600" role="alert">
            {error ?? candidates.error}
          </p>
        )}
        <div className="mt-5 flex justify-end gap-2">
          <button
            type="button"
            onClick={onCancel}
            disabled={saving}
            className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60"
          >
            Cancel
          </button>
          <button
            type="button"
            onClick={submit}
            disabled={!target || saving || (phoneDiffers && !confirmed)}
            className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60"
          >
            {saving && <Loader2 className="h-4 w-4 animate-spin" />}
            Merge
          </button>
        </div>
      </div>
    </div>
  );
}
