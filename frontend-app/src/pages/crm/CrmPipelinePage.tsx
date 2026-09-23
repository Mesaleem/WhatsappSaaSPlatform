import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { Loader2 } from 'lucide-react';
import crmService from '../../services/crmService';
import CrmLeadFilterBar from '../../components/crm/CrmLeadFilterBar';
import {
  ErrorBanner,
  NoClientSelected,
  Spinner,
  StatusControl,
  TagChips,
  Toast,
} from '../../components/crm/CrmUi';
import { hasActiveFilters } from '../../components/crm/crmFilters';
import { useCrmAssignees, useCrmContext, useCrmQuery, useCrmTagIndex, useCrmToast, useCrmUrlState } from '../../components/crm/crmHooks';
import { describeApiError } from '../../utils/apiError';
import { crmSourceLabel, type CrmLead, type CrmLeadFilters, type CrmPipelineColumn } from '../../types/crm';

/**
 * Phase 6 — CRM Task 8. The Kanban view — GET /api/crm/pipeline.
 *
 * Columns, their order, labels and totals all come from the response
 * (CrmLead::pipelineStatuses() server-side): the four canonical statuses,
 * nothing else. Each column is paginated by the server; "Load more"
 * requests the next page of ONE column (the endpoint narrows to a column
 * when `status` is sent). Filters (search, source, assignee, tags) are
 * the shared lead filters and are sent with every request; status is not
 * a filter here because it is the column.
 *
 * Moving a card = the dedicated status endpoint; the pipeline is then
 * re-read so counts stay server-authoritative. No drag-and-drop in this
 * foundation task.
 */
export default function CrmPipelinePage() {
  const { needsClient, readOnly, selectedAccountId } = useCrmContext();
  const { filters: urlFilters, perPage, setFilters, clearFilters } = useCrmUrlState();
  const { assignees } = useCrmAssignees(!needsClient, selectedAccountId);
  const { nameOf } = useCrmTagIndex(!needsClient, selectedAccountId);
  const { toast, show } = useCrmToast();

  // Status is the column, never a pipeline filter.
  const filters = useMemo(() => {
    const withoutStatus: CrmLeadFilters = { ...urlFilters };
    delete withoutStatus.status;
    return withoutStatus;
  }, [urlFilters]);

  const [loadingMore, setLoadingMore] = useState<string | null>(null);

  const query = useCrmQuery(
    needsClient ? null : JSON.stringify({ account: selectedAccountId, filters, perPage }),
    () => crmService.pipeline(filters, 1, perPage),
    'Failed to load the pipeline.',
  );
  const columns = query.data ?? [];
  const isLoading = query.isLoading;
  const error = query.error;

  const loadMore = (column: CrmPipelineColumn) => {
    if (loadingMore) return;
    setLoadingMore(column.status);
    crmService
      .pipeline({ ...filters, status: column.status }, column.page + 1, perPage)
      .then((cols) => {
        const next = cols.find((c) => c.status === column.status);
        if (!next) return;
        query.setData((current) =>
          current.map((c) =>
            c.status === column.status
              ? {
                  ...next,
                  leads: [...c.leads, ...next.leads.filter((l) => !c.leads.some((existing) => existing.id === l.id))],
                }
              : c,
          ),
        );
      })
      .catch((err: unknown) => show(describeApiError(err, 'Failed to load more leads.').message))
      .finally(() => setLoadingMore(null));
  };

  /** A status change moves the card; re-read so every column's total is the server's. */
  const onMoved = (_lead: CrmLead, message: string) => {
    show(message);
    query.reload();
  };

  const totalLeads = columns.reduce((sum, c) => sum + c.total, 0);

  return (
    <div className="p-6">
      <div className="w-full space-y-6">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">CRM Pipeline</h1>
          <p className="mt-1 text-sm text-slate-500">Leads by lifecycle status. Change a lead’s status to move it between columns.</p>
        </div>

        {needsClient ? (
          <NoClientSelected what="CRM pipeline" />
        ) : (
          <>
            <CrmLeadFilterBar
              filters={filters}
              onChange={setFilters}
              onClear={clearFilters}
              assignees={assignees}
              tagName={nameOf}
              showStatus={false}
            />

            {error && <ErrorBanner message={error} />}

            {isLoading ? (
              <Spinner />
            ) : columns.length > 0 ? (
              <>
                {totalLeads === 0 && (
                  <p className="text-sm text-slate-500" data-testid="crm-pipeline-empty">
                    {hasActiveFilters(filters) ? 'No leads match these filters.' : 'No CRM leads yet.'}
                  </p>
                )}
                <div className="overflow-x-auto pb-2">
                  <div className="grid min-w-[64rem] grid-cols-4 gap-4" data-testid="crm-pipeline">
                    {columns.map((column) => (
                      <section
                        key={column.status}
                        className="flex min-h-[12rem] flex-col rounded-xl border border-slate-200 bg-slate-50"
                        data-testid={`crm-column-${column.status}`}
                      >
                        <header className="flex items-center justify-between border-b border-slate-200 px-3 py-2.5">
                          <h2 className="text-sm font-semibold text-slate-800">{column.label}</h2>
                          <span
                            className="rounded-full bg-white px-2 py-0.5 text-xs font-semibold text-slate-600 ring-1 ring-slate-200"
                            data-testid={`crm-column-total-${column.status}`}
                          >
                            {column.total}
                          </span>
                        </header>
                        <div className="flex-1 space-y-2 p-2">
                          {column.leads.length === 0 ? (
                            <p className="px-1 py-6 text-center text-xs text-slate-400">No leads</p>
                          ) : (
                            column.leads.map((lead) => (
                              <article
                                key={lead.id}
                                className="space-y-2 rounded-lg border border-slate-200 bg-white p-3 shadow-sm"
                                data-testid={`crm-card-${lead.id}`}
                              >
                                <div>
                                  <Link to={`/crm/leads/${lead.id}`} className="text-sm font-medium text-slate-900 hover:text-indigo-600">
                                    {lead.contact?.name || 'Unnamed contact'}
                                  </Link>
                                  <p className="text-xs text-slate-500">{lead.contact?.phone_number ?? '—'}</p>
                                </div>
                                <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500">
                                  <span>{crmSourceLabel(lead.source)}</span>
                                  <span>{lead.assigned_user ? lead.assigned_user.name : 'Unassigned'}</span>
                                </div>
                                <TagChips tags={lead.tags} />
                                <StatusControl lead={lead} disabled={readOnly} onUpdated={onMoved} onError={show} />
                              </article>
                            ))
                          )}
                        </div>
                        {column.has_more && (
                          <button
                            type="button"
                            onClick={() => loadMore(column)}
                            disabled={loadingMore !== null}
                            className="m-2 flex items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-100 disabled:opacity-60"
                          >
                            {loadingMore === column.status && <Loader2 className="h-3.5 w-3.5 animate-spin" />}
                            Load more ({column.total - column.leads.length} remaining)
                          </button>
                        )}
                      </section>
                    ))}
                  </div>
                </div>
              </>
            ) : null}
          </>
        )}
      </div>

      <Toast message={toast} />
    </div>
  );
}
