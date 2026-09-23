import { useCallback, useMemo } from 'react';
import { useSearchParams } from 'react-router-dom';
import crmService from '../../services/crmService';
import CrmLeadFilterBar from '../../components/crm/CrmLeadFilterBar';
import { EmptyState, ErrorBanner, NoClientSelected, Spinner } from '../../components/crm/CrmUi';
import { buildCrmUrl, CRM_DEFAULT_PER_PAGE, hasActiveFilters, parseCrmUrl } from '../../components/crm/crmFilters';
import { useCrmAssignees, useCrmContext, useCrmQuery, useCrmTagIndex } from '../../components/crm/crmHooks';
import { inputClass } from '../../components/common/Card';
import { crmLabel, crmSourceLabel, type CrmAnalytics, type CrmDateRange, type CrmLeadFilters } from '../../types/crm';

/**
 * Phase 6 — CRM Task 12. CRM Analytics — GET /api/crm/analytics.
 *
 * Every number on this page is the server's (CrmAnalyticsService): totals,
 * conversion rate, the status/source/assignee breakdowns and the trend are
 * displayed, never recomputed, so the page cannot disagree with the API.
 * Filters are the shared CRM lead filters plus an inclusive date range on
 * the lead's created date; all of them live in the URL like the other CRM
 * pages. Access is decided by the backend (same gates as every /api/crm
 * route); the route guard and nav gate are UX only.
 */

const DATE = /^\d{4}-\d{2}-\d{2}$/;

function useAnalyticsUrlState() {
  const [params, setParams] = useSearchParams();
  const filters = useMemo(() => parseCrmUrl(params).filters, [params]);
  const range = useMemo<CrmDateRange>(() => {
    const from = params.get('from');
    const to = params.get('to');
    return { ...(from && DATE.test(from) ? { from } : {}), ...(to && DATE.test(to) ? { to } : {}) };
  }, [params]);

  const write = useCallback(
    (nextFilters: CrmLeadFilters, nextRange: CrmDateRange) => {
      const next = buildCrmUrl({ filters: nextFilters, page: 1, perPage: CRM_DEFAULT_PER_PAGE });
      if (nextRange.from) next.set('from', nextRange.from);
      if (nextRange.to) next.set('to', nextRange.to);
      setParams(next);
    },
    [setParams],
  );

  const setFilters = useCallback(
    (patch: Partial<CrmLeadFilters>) => {
      const merged: CrmLeadFilters = { ...filters, ...patch };
      (Object.keys(merged) as (keyof CrmLeadFilters)[]).forEach((key) => {
        const value = merged[key];
        if (value === undefined || value === '' || (Array.isArray(value) && value.length === 0)) delete merged[key];
      });
      write(merged, range);
    },
    [filters, range, write],
  );

  const setRange = useCallback((patch: CrmDateRange) => write(filters, { ...range, ...patch }), [filters, range, write]);
  const clearAll = useCallback(() => write({}, {}), [write]);

  return { filters, range, setFilters, setRange, clearAll };
}

function percent(count: number, total: number): number {
  return total === 0 ? 0 : Math.round((count / total) * 1000) / 10;
}

function Breakdown({ title, rows, total, testId }: { title: string; rows: { key: string; label: string; count: number }[]; total: number; testId: string }) {
  return (
    <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm" data-testid={testId}>
      <h2 className="text-sm font-semibold text-slate-800">{title}</h2>
      {rows.length === 0 ? (
        <p className="mt-3 text-sm text-slate-400">No leads</p>
      ) : (
        <ul className="mt-3 space-y-2">
          {rows.map((row) => (
            <li key={row.key} data-testid={`${testId}-${row.key}`}>
              <div className="flex items-center justify-between text-sm">
                <span className="text-slate-700">{row.label}</span>
                <span className="font-semibold text-slate-900">
                  {row.count}
                  <span className="ml-1 text-xs font-normal text-slate-500">({percent(row.count, total)}%)</span>
                </span>
              </div>
              <div className="mt-1 h-1.5 rounded-full bg-slate-100">
                <div className="h-1.5 rounded-full bg-indigo-500" style={{ width: `${percent(row.count, total)}%` }} />
              </div>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

function Trend({ data }: { data: CrmAnalytics }) {
  const max = Math.max(1, ...data.trend.map((b) => b.total));
  const unit = data.range.interval === 'day' ? 'Daily' : data.range.interval === 'week' ? 'Weekly' : 'Monthly';
  return (
    <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="crm-analytics-trend">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="text-sm font-semibold text-slate-800">{unit} new leads</h2>
        <p className="text-xs text-slate-500">
          {data.range.from} to {data.range.to} ({data.range.timezone}) · shaded part = converted
        </p>
      </div>
      <div className="mt-4 flex h-40 items-end gap-px overflow-x-auto" role="list" aria-label="Lead trend">
        {data.trend.map((bucket) => (
          <div
            key={bucket.period}
            role="listitem"
            title={`${bucket.period}: ${bucket.total} leads, ${bucket.converted} converted`}
            aria-label={`${bucket.period}: ${bucket.total} leads, ${bucket.converted} converted`}
            className="flex min-w-[6px] flex-1 flex-col justify-end rounded-t bg-indigo-200"
            style={{ height: `${(bucket.total / max) * 100}%` }}
          >
            <div
              className="rounded-t bg-indigo-600"
              style={{ height: bucket.total === 0 ? 0 : `${(bucket.converted / bucket.total) * 100}%` }}
            />
          </div>
        ))}
      </div>
    </section>
  );
}

export default function CrmAnalyticsPage() {
  const { needsClient, selectedAccountId } = useCrmContext();
  const { filters, range, setFilters, setRange, clearAll } = useAnalyticsUrlState();
  const { assignees } = useCrmAssignees(!needsClient, selectedAccountId);
  const { nameOf } = useCrmTagIndex(!needsClient, selectedAccountId);

  const rangeError = range.from && range.to && range.to < range.from ? 'The end date must be on or after the start date.' : null;

  const query = useCrmQuery<CrmAnalytics>(
    needsClient || rangeError ? null : JSON.stringify({ account: selectedAccountId, filters, range }),
    () => crmService.analytics(filters, range),
    'Failed to load CRM analytics.',
  );
  const data = query.data;
  const filtered = hasActiveFilters(filters) || Boolean(range.from || range.to);

  const cards: { key: string; label: string; value: string }[] = data
    ? [
        { key: 'total', label: 'Total leads', value: String(data.totals.total) },
        { key: 'new', label: 'New', value: String(data.totals.new) },
        { key: 'contacted', label: 'Contacted', value: String(data.totals.contacted) },
        { key: 'converted', label: 'Converted', value: String(data.totals.converted) },
        { key: 'not_converted', label: 'Not converted', value: String(data.totals.not_converted) },
        { key: 'conversion_rate', label: 'Conversion rate', value: data.totals.conversion_rate === null ? '—' : `${data.totals.conversion_rate}%` },
      ]
    : [];

  return (
    <div className="p-6">
      <div className="w-full space-y-6">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">CRM Analytics</h1>
          <p className="mt-1 text-sm text-slate-500">Lead totals, conversion and breakdowns for the leads created in the selected period.</p>
        </div>

        {needsClient ? (
          <NoClientSelected what="CRM analytics" />
        ) : (
          <>
            <div className="flex flex-wrap items-end gap-3" data-testid="crm-analytics-range">
              <label className="text-xs font-medium text-slate-600">
                From
                <input type="date" aria-label="From date" className={inputClass} value={range.from ?? ''} onChange={(e) => setRange({ from: e.target.value || undefined })} />
              </label>
              <label className="text-xs font-medium text-slate-600">
                To
                <input type="date" aria-label="To date" className={inputClass} value={range.to ?? ''} onChange={(e) => setRange({ to: e.target.value || undefined })} />
              </label>
              {filtered && (
                <button type="button" onClick={clearAll} className="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100">
                  Reset all filters
                </button>
              )}
            </div>
            <CrmLeadFilterBar filters={filters} onChange={setFilters} onClear={() => setFilters({ search: undefined, status: undefined, source: undefined, assigned_user_id: undefined, tag_ids: undefined })} assignees={assignees} tagName={nameOf} />

            {rangeError && <ErrorBanner message={rangeError} />}
            {query.error && <ErrorBanner message={query.error} />}

            {query.isLoading ? (
              <Spinner />
            ) : data ? (
              <>
                <div className="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6" data-testid="crm-analytics-cards">
                  {cards.map((card) => (
                    <div key={card.key} className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm" data-testid={`crm-analytics-${card.key}`}>
                      <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{card.label}</p>
                      <p className="mt-1 text-2xl font-semibold text-slate-900">{card.value}</p>
                    </div>
                  ))}
                </div>

                {data.totals.total === 0 ? (
                  <EmptyState
                    title={filtered ? 'No leads match these filters' : 'No CRM leads yet'}
                    hint={filtered ? 'Widen the date range or clear a filter.' : 'Leads you create or capture will be analysed here.'}
                  />
                ) : (
                  <>
                    <Trend data={data} />
                    <div className="grid gap-4 lg:grid-cols-3">
                      <Breakdown
                        title="By status"
                        testId="crm-analytics-status"
                        total={data.totals.total}
                        rows={data.by_status.map((r) => ({ key: r.status, label: crmLabel(r.status), count: r.count }))}
                      />
                      <Breakdown
                        title="By source"
                        testId="crm-analytics-source"
                        total={data.totals.total}
                        rows={data.by_source.map((r) => ({ key: r.source, label: crmSourceLabel(r.source), count: r.count }))}
                      />
                      <Breakdown
                        title="By assignee"
                        testId="crm-analytics-assignee"
                        total={data.totals.total}
                        rows={data.by_assignee.map((r) => ({ key: r.assigned_user_id === null ? 'none' : String(r.assigned_user_id), label: r.name, count: r.count }))}
                      />
                    </div>
                  </>
                )}
              </>
            ) : null}
          </>
        )}
      </div>
    </div>
  );
}
