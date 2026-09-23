import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Plus } from 'lucide-react';
import crmService from '../../services/crmService';
import { TableCard } from '../../components/common/Card';
import { Pagination } from '../../components/common/DataTableControls';
import { TableSkeletonRows } from '../../components/common/Skeleton';
import CrmLeadFilterBar from '../../components/crm/CrmLeadFilterBar';
import CreateLeadModal from '../../components/crm/CreateLeadModal';
import CrmBulkActionBar from '../../components/crm/CrmBulkActionBar';
import {
  AssigneeControl,
  EmptyState,
  ErrorBanner,
  LeadTagEditor,
  NoClientSelected,
  READ_ONLY_TITLE,
  StatusControl,
  Toast,
} from '../../components/crm/CrmUi';
import { CRM_PER_PAGE_OPTIONS, formatDate, hasActiveFilters } from '../../components/crm/crmFilters';
import { useCrmAssignees, useCrmContext, useCrmQuery, useCrmTagIndex, useCrmToast, useCrmUrlState } from '../../components/crm/crmHooks';
import { CRM_BULK_MAX, crmSourceLabel, type CrmLead } from '../../types/crm';

/**
 * Phase 6 — CRM Task 8. The CRM lead list — GET /api/crm/leads.
 *
 * Every filter and the page are in the URL and sent to the server
 * (search, status, source, assignee incl. unassigned, tag_ids AND,
 * page, per_page). Rows are the canonical PresentsCrmLeads shape, so the
 * contact, assignee and tags come with the page — no per-row requests.
 *
 * Inline row actions use the dedicated endpoints (status, assignee, tag
 * attach/detach). Task 9 adds page-scoped selection and a bulk action bar
 * (one /crm/leads/bulk/* request per action; see CrmBulkActionBar). After any mutation the updated lead from the response
 * replaces the row immediately, then the page is re-read so totals and
 * filter membership stay server-authoritative (e.g. a lead that no
 * longer matches the status filter leaves the list).
 */
export default function CrmLeadsPage() {
  const { needsClient, readOnly, selectedAccountId, selectedClientName } = useCrmContext();
  const { filters, page, perPage, setFilters, clearFilters, setPage, setPerPage } = useCrmUrlState();
  const { assignees } = useCrmAssignees(!needsClient, selectedAccountId);
  const { nameOf } = useCrmTagIndex(!needsClient, selectedAccountId);
  const { toast, show } = useCrmToast();

  const [creating, setCreating] = useState(false);

  // The view = account + filters + page + page size. It keys both the
  // server read and the bulk selection.
  const viewKey = needsClient ? null : JSON.stringify({ account: selectedAccountId, filters, page, perPage });

  const query = useCrmQuery(
    viewKey,
    () => crmService.listLeads(filters, page, perPage),
    'Failed to load leads.',
  );
  const leads = query.data?.data ?? [];
  const total = query.data?.total ?? 0;
  const lastPage = query.data?.last_page ?? 1;
  const isLoading = query.isLoading;
  const error = query.error;
  // The target account failed the CRM gates (403 from the list read — e.g.
  // a selected client without the crm capability / lead_crm module). The
  // backend is the authority; the button just mirrors its answer.
  const targetDenied = query.status === 403;
  const addLeadBlockedReason = needsClient
    ? 'Select a client from the switcher at the top of the page to add a lead.'
    : readOnly
      ? READ_ONLY_TITLE
      : targetDenied
        ? 'This account does not have CRM access.'
        : undefined;

  // Landed past the end (e.g. the last row of the last page left the
  // filter after a mutation): go to the real last page.
  const outOfRange = query.data !== null && query.data.data.length === 0 && query.data.total > 0 && page > query.data.last_page;
  useEffect(() => {
    if (outOfRange && query.data) setPage(query.data.last_page);
  }, [outOfRange, query.data, setPage]);

  /**
   * Task 9 — PAGE-SCOPED selection. A selection belongs to exactly one
   * view: changing any filter, the page, the page size or the client
   * (viewKey) drops it, as does leaving the page (unmount). Only ids of
   * rows currently on screen can be selected or sent, so a bulk action can
   * never target a lead the user cannot see. "Select all" means all rows
   * on THIS page, and the bar says so.
   */
  const [selection, setSelection] = useState<{ key: string | null; ids: number[] }>({ key: null, ids: [] });
  const [bulkError, setBulkError] = useState<{ key: string | null; message: string } | null>(null);
  const visibleIds = leads.map((l) => l.id);
  const selectedIds = (selection.key === viewKey ? selection.ids : []).filter((id) => visibleIds.includes(id)).slice(0, CRM_BULK_MAX);
  const allVisibleSelected = visibleIds.length > 0 && selectedIds.length === visibleIds.length;
  const someVisibleSelected = selectedIds.length > 0 && !allVisibleSelected;

  const toggleOne = (id: number) => {
    const next = selectedIds.includes(id) ? selectedIds.filter((x) => x !== id) : [...selectedIds, id];
    setSelection({ key: viewKey, ids: next });
  };
  const toggleAllVisible = () => setSelection({ key: viewKey, ids: allVisibleSelected ? [] : visibleIds });
  const clearSelection = () => setSelection({ key: null, ids: [] });

  const onBulkDone = (message: string) => {
    clearSelection();
    setBulkError(null);
    show(message);
    query.reload();
  };
  // Rejected batch: nothing changed server-side. Show the server's message,
  // keep the selection so it can be adjusted, and re-read the page.
  const onBulkFailed = (message: string) => {
    setBulkError({ key: viewKey, message });
    query.reload();
  };
  const visibleBulkError = bulkError && bulkError.key === viewKey ? bulkError.message : null;

  /**
   * The server's updated lead replaces the row immediately; the page is
   * then re-read so totals and filter membership stay server-authoritative.
   */
  const onLeadUpdated = (updated: CrmLead, message: string) => {
    query.setData((current) => ({ ...current, data: current.data.map((row) => (row.id === updated.id ? updated : row)) }));
    show(message);
    query.reload();
  };

  return (
    <div className="p-6">
      <div className="w-full space-y-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 className="text-xl font-semibold text-slate-900">CRM Leads</h1>
            <p className="mt-1 text-sm text-slate-500">Every CRM opportunity for this account, with its status, owner and tags.</p>
          </div>
          {/*
            Manual lead creation — POST /api/crm/leads (same gates as every
            CRM route). Target: a Super Admin's / Agent's selected client
            (?account_id=); a Super Admin with no client selected → their
            own platform CRM account (EnsureCrmTargetAccount); everyone else
            → their own account. Disabled only when there is no target (a
            Super Admin without a platform account and no selection), on an
            expired subscription, or when the target failed the CRM gates.
            Needs no Meta / social / WhatsApp connection.
          */}
          <button
            type="button"
            onClick={() => setCreating(true)}
            disabled={addLeadBlockedReason !== undefined}
            title={addLeadBlockedReason}
            data-testid="crm-add-lead"
            className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
          >
            <Plus className="h-4 w-4" />
            Add lead
          </button>
        </div>

        {needsClient ? (
          <NoClientSelected what="CRM leads" />
        ) : (
          <>
            <CrmLeadFilterBar
              filters={filters}
              onChange={setFilters}
              onClear={clearFilters}
              assignees={assignees}
              tagName={nameOf}
            />

            {error && <ErrorBanner message={error} />}
            {visibleBulkError && visibleBulkError !== error && <ErrorBanner message={visibleBulkError} />}

            {selectedIds.length > 0 && (
              <CrmBulkActionBar
                selectedIds={selectedIds}
                assignees={assignees}
                readOnly={readOnly}
                onDone={onBulkDone}
                onFailed={onBulkFailed}
                onClear={clearSelection}
              />
            )}

            <TableCard>
              <table className="min-w-full divide-y divide-slate-200 text-sm" data-testid="crm-leads-table">
                <thead className="bg-slate-50">
                  <tr>
                    <th className="w-10 px-4 py-3">
                      <input
                        type="checkbox"
                        aria-label={`Select all ${visibleIds.length} leads on this page`}
                        checked={allVisibleSelected}
                        ref={(el) => {
                          if (el) el.indeterminate = someVisibleSelected;
                        }}
                        onChange={toggleAllVisible}
                        disabled={isLoading || visibleIds.length === 0}
                        className="h-4 w-4 rounded border-slate-300 text-indigo-600"
                      />
                    </th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Lead</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Phone</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Status</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Source</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Assignee</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Tags</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Created</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {isLoading ? (
                    <TableSkeletonRows columns={8} />
                  ) : leads.length === 0 ? (
                    <tr>
                      <td colSpan={8}>
                        {hasActiveFilters(filters) ? (
                          <EmptyState title="No leads match these filters." hint="Change or clear the filters to see more leads." />
                        ) : (
                          <EmptyState title="No CRM leads yet." hint="Create one, or leads will appear here as they are captured." />
                        )}
                      </td>
                    </tr>
                  ) : (
                    leads.map((lead) => (
                      // No whole-row click: the row holds inline controls
                      // (status, assignee, tags); the name links to the lead.
                      <tr
                        key={lead.id}
                        className={`align-top hover:bg-slate-50 ${selectedIds.includes(lead.id) ? 'bg-indigo-50/40' : ''}`}
                        data-testid={`crm-lead-row-${lead.id}`}
                      >
                        <td className="px-4 py-3">
                          <input
                            type="checkbox"
                            aria-label={`Select lead ${lead.contact?.name || lead.id}`}
                            checked={selectedIds.includes(lead.id)}
                            onChange={() => toggleOne(lead.id)}
                            className="h-4 w-4 rounded border-slate-300 text-indigo-600"
                          />
                        </td>
                        <td className="min-w-[10rem] px-4 py-3">
                          <Link
                            to={`/crm/leads/${lead.id}`}
                            className="block font-medium text-slate-900 hover:text-indigo-600"
                          >
                            {lead.contact?.name || 'Unnamed contact'}
                          </Link>
                          {lead.contact?.email && <p className="text-xs text-slate-500">{lead.contact.email}</p>}
                          {lead.contact && (
                            <Link
                              to={`/crm/contacts/${lead.contact.id}`}
                              className="mt-0.5 block text-xs text-indigo-600 hover:underline"
                            >
                              Open contact
                            </Link>
                          )}
                        </td>
                        <td className="whitespace-nowrap px-4 py-3 text-slate-700">{lead.contact?.phone_number ?? '—'}</td>
                        <td className="px-4 py-3">
                          <StatusControl lead={lead} disabled={readOnly} onUpdated={onLeadUpdated} onError={show} />
                        </td>
                        <td className="whitespace-nowrap px-4 py-3 text-slate-700">
                          {crmSourceLabel(lead.source)}
                          {lead.capture_lead?.provider && (
                            <p className="text-xs text-slate-400">via {lead.capture_lead.provider}</p>
                          )}
                        </td>
                        <td className="px-4 py-3">
                          <AssigneeControl
                            lead={lead}
                            assignees={assignees}
                            disabled={readOnly}
                            onUpdated={onLeadUpdated}
                            onError={show}
                          />
                        </td>
                        <td className="min-w-[12rem] px-4 py-3">
                          <LeadTagEditor lead={lead} disabled={readOnly} onUpdated={onLeadUpdated} onError={show} />
                        </td>
                        <td className="whitespace-nowrap px-4 py-3 text-slate-500">{formatDate(lead.created_at)}</td>
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
                onPageChange={setPage}
                onPerPageChange={setPerPage}
                perPageOptions={CRM_PER_PAGE_OPTIONS}
              />
            </TableCard>
          </>
        )}
      </div>

      {creating && addLeadBlockedReason === undefined && (
        <CreateLeadModal
          assignees={assignees}
          targetLabel={selectedClientName}
          onCancel={() => setCreating(false)}
          onCreated={(_lead, message) => {
            // Close (the modal unmounts, so its form resets), confirm, and
            // re-read the list so the new lead appears with server totals.
            setCreating(false);
            show(message);
            query.reload();
          }}
        />
      )}

      <Toast message={toast} />
    </div>
  );
}
