import { useCallback, useEffect, useMemo, useState } from 'react';
import { AxiosError } from 'axios';
import { Loader2, RefreshCw, Users, XCircle } from 'lucide-react';
import {
  Area,
  AreaChart,
  CartesianGrid,
  Cell,
  ComposedChart,
  Legend,
  Line,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';
import { useAuth } from '../../core/context/AuthContext';
import { useTenant } from '../../core/context/TenantContext';
import analyticsService from '../../services/analyticsService';
import messageLogsService from '../../services/messageLogsService';
import { ClearFiltersButton, Pagination, SearchInput, StatusFilterSelect } from '../../components/common/DataTableControls';
import { TableCard } from '../../components/common/Card';
import { TableSkeletonRows } from '../../components/common/Skeleton';
import type { ApiErrorResponse } from '../../types/auth';
import type { AnalyticsChartsResponse, AnalyticsSummary, ChartRange, DateRange } from '../../types/analytics';
import type { EngineType } from '../../types/subscription';
import type { MessageDispatchLog, MessageDispatchLogFilters, MessageDispatchSource, MessageDispatchStatus } from '../../types/messageLog';

// Analytics/Dashboard Log Source Discrepancy Fix: same MessageDispatchStatus-
// keyed status vocabulary MessageLogsPage.tsx and DashboardPage.tsx's
// "Recent Message Logs" widget already use for message_dispatch_logs rows
// ('sent' | 'failed' | 'queued' — note there is no 'pending' state here,
// unlike the old payment_alerts-only PaymentAlertStatus this replaces).
// Duplicated locally rather than imported/shared, matching this codebase's
// existing convention of small per-page presentational maps (see
// DashboardPage.tsx's own local STATUS_BADGE/SOURCE_LABEL).
const STATUS_OPTIONS: Array<{ value: MessageDispatchStatus | ''; label: string }> = [
  { value: '', label: 'All statuses' },
  { value: 'sent', label: 'Sent' },
  { value: 'failed', label: 'Failed' },
  { value: 'queued', label: 'Queued' },
];

const STATUS_BADGE: Record<MessageDispatchStatus, string> = {
  sent: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  failed: 'bg-red-50 text-red-700 ring-red-600/20',
  queued: 'bg-amber-50 text-amber-700 ring-amber-600/20',
};

/** Mirrors MessageLogsPage.tsx's SOURCE_BADGE exactly — same labels/colors, so a source badge reads identically on both pages. */
const SOURCE_BADGE: Record<MessageDispatchSource, { label: string; className: string }> = {
  web_ui: { label: 'Web', className: 'bg-indigo-50 text-indigo-700 ring-indigo-600/20' },
  web_template: { label: 'Template', className: 'bg-violet-50 text-violet-700 ring-violet-600/20' },
  api: { label: 'API', className: 'bg-amber-50 text-amber-700 ring-amber-600/20' },
  chatbot: { label: 'Chatbot', className: 'bg-cyan-50 text-cyan-700 ring-cyan-600/20' },
  journey: { label: 'Journey', className: 'bg-fuchsia-50 text-fuchsia-700 ring-fuchsia-600/20' },
};

const ENGINE_COLOR: Record<EngineType, string> = {
  qr: '#3b82f6',
  meta: '#10b981',
};

const ENGINE_LABEL: Record<EngineType, string> = {
  qr: 'QR (Baileys)',
  meta: 'Meta Cloud API',
};

const inputClass =
  'rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100';

function todayIso(): string {
  return new Date().toISOString().slice(0, 10);
}

function daysAgoIso(days: number): string {
  const d = new Date();
  d.setDate(d.getDate() - (days - 1));
  return d.toISOString().slice(0, 10);
}

function formatDateTime(value: string | null): string {
  if (!value) return '—';
  return new Date(value).toLocaleString();
}

/** Derives an explicit {from, to} pair for the currently selected range, so the KPI
 *  cards and the chart below them always describe the same window. */
function resolveRange(range: ChartRange, customFrom: string, customTo: string): DateRange {
  if (range === 'custom') {
    return { from: customFrom || daysAgoIso(7), to: customTo || todayIso() };
  }
  const days = Number(range);
  return { from: daysAgoIso(days), to: todayIso() };
}

export default function AnalyticsPage() {
  const { hasPermission } = useAuth();
  const canViewLogs = hasPermission('view-logs');

  const [range, setRange] = useState<ChartRange>('7');
  const [customFrom, setCustomFrom] = useState('');
  const [customTo, setCustomTo] = useState('');

  const resolvedRange = useMemo(() => resolveRange(range, customFrom, customTo), [range, customFrom, customTo]);

  return (
    <div className="p-6">
      <div className="w-full space-y-6">
        <div className="flex flex-wrap items-center justify-between gap-4">
          <div>
            <h1 className="text-xl font-semibold text-slate-900">Analytics</h1>
            <p className="mt-1 text-sm text-slate-500">Message volume, delivery health, and transaction logs.</p>
          </div>
          <RangePicker
            range={range}
            setRange={setRange}
            customFrom={customFrom}
            setCustomFrom={setCustomFrom}
            customTo={customTo}
            setCustomTo={setCustomTo}
          />
        </div>

        <SummarySection resolvedRange={resolvedRange} />
        <ChartsSection range={range} resolvedRange={resolvedRange} />

        {canViewLogs ? (
          <LogsSection resolvedRange={resolvedRange} />
        ) : (
          <div className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-600 shadow-sm">
            Message logs and exports require the "view-logs" permission. Ask an account Admin to grant it if you
            need row-level detail or exports.
          </div>
        )}
      </div>
    </div>
  );
}

function RangePicker({
  range,
  setRange,
  customFrom,
  setCustomFrom,
  customTo,
  setCustomTo,
}: {
  range: ChartRange;
  setRange: (r: ChartRange) => void;
  customFrom: string;
  setCustomFrom: (v: string) => void;
  customTo: string;
  setCustomTo: (v: string) => void;
}) {
  return (
    <div className="flex flex-wrap items-center gap-2">
      {(['7', '30', 'custom'] as ChartRange[]).map((r) => (
        <button
          key={r}
          type="button"
          onClick={() => setRange(r)}
          className={`rounded-lg border px-3 py-1.5 text-sm font-medium transition ${
            range === r
              ? 'border-indigo-600 bg-indigo-600 text-white'
              : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
          }`}
        >
          {r === '7' ? '7 Days' : r === '30' ? '30 Days' : 'Custom'}
        </button>
      ))}
      {range === 'custom' && (
        <div className="flex items-center gap-2">
          <input type="date" value={customFrom} onChange={(e) => setCustomFrom(e.target.value)} className={inputClass} />
          <span className="text-sm text-slate-400">to</span>
          <input type="date" value={customTo} onChange={(e) => setCustomTo(e.target.value)} className={inputClass} />
        </div>
      )}
    </div>
  );
}

function KpiCard({ label, value, sub }: { label: string; value: string; sub?: string }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
      <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</p>
      <p className="mt-2 text-2xl font-semibold text-slate-900">{value}</p>
      {sub && <p className="mt-1 text-xs text-slate-500">{sub}</p>}
    </div>
  );
}

function SummarySection({ resolvedRange }: { resolvedRange: DateRange }) {
  // Super Admin Multi-Tenant Scoping: selectedAccountId isn't read directly
  // here (axiosInstance's interceptor attaches it to every request), but it
  // MUST be a load() dependency so switching the Header's client selector
  // re-fetches this section for the newly selected tenant instead of
  // silently continuing to show the previous tenant's numbers.
  const { selectedAccountId } = useTenant();
  const { hasModule } = useAuth();
  const [summary, setSummary] = useState<AnalyticsSummary | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const data = await analyticsService.getSummary({ from: resolvedRange.from, to: resolvedRange.to });
      setSummary(data);
    } catch (err) {
      const axiosErr = err as AxiosError<ApiErrorResponse>;
      setError(axiosErr.response?.data?.message ?? 'Failed to load analytics summary.');
    } finally {
      setIsLoading(false);
    }
  }, [resolvedRange.from, resolvedRange.to]);

  useEffect(() => {
    void load();
  }, [load, selectedAccountId]);

  if (error) {
    return (
      <div className="flex items-center gap-2 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
        <XCircle className="h-4 w-4 flex-shrink-0" />
        {error}
      </div>
    );
  }

  const quota = summary?.quota;
  const quotaPercent = quota?.quota_percent_used;
  const quotaBarColor = quotaPercent === null || quotaPercent === undefined
    ? 'bg-slate-300'
    : quotaPercent >= 90
      ? 'bg-red-500'
      : quotaPercent >= 70
        ? 'bg-amber-500'
        : 'bg-emerald-500';

  return (
    <div className="space-y-4">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <KpiCard
          label="Total Messages"
          value={isLoading ? '…' : String(summary?.total_alerts_attempted ?? 0)}
          sub={isLoading ? undefined : `${summary?.total_sent ?? 0} sent`}
        />
        <KpiCard
          label="Success Rate"
          value={isLoading ? '…' : `${summary?.delivered_rate ?? 0}%`}
          sub={isLoading ? undefined : `${summary?.total_failed ?? 0} failed`}
        />
        <KpiCard
          label="Total Failed"
          value={isLoading ? '…' : String(summary?.total_failed ?? 0)}
        />
        <KpiCard
          label="Total Cost Incurred"
          value={isLoading ? '…' : `₹${Number(summary?.total_cost_incurred ?? '0').toFixed(4)}`}
          sub={isLoading ? undefined : quota?.billing_model}
        />
      </div>

      {!isLoading && summary?.scope === 'global' ? (
        <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
          <p className="text-sm font-medium text-slate-900">Quota Health</p>
          <p className="mt-1.5 text-xs text-slate-500">
            Quota is per-client — select a client above to see its usage against plan.
          </p>
        </div>
      ) : (
        <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
          <div className="flex items-center justify-between">
            <p className="text-sm font-medium text-slate-900">Quota Health</p>
            {!isLoading && quota && (
              <span className="text-xs text-slate-500">
                {quota.billing_model === 'unlimited'
                  ? 'Unlimited plan'
                  : `${quota.used_messages} / ${quota.total_allocated_messages ?? '—'} used`}
              </span>
            )}
          </div>
          <div className="mt-2 h-2.5 w-full overflow-hidden rounded-full bg-slate-100">
            <div
              className={`h-full rounded-full transition-all ${quotaBarColor}`}
              style={{ width: `${quotaPercent === null || quotaPercent === undefined ? 0 : Math.min(100, quotaPercent)}%` }}
            />
          </div>
          {!isLoading && quota?.remaining_messages !== null && quota?.remaining_messages !== undefined && (
            <p className="mt-1.5 text-xs text-slate-500">{quota.remaining_messages} messages remaining</p>
          )}
        </div>
      )}

      {/*
        Complete Analytics Message Breakdown — Individual + Group + Overall
        (2026-09-16). Supersedes the single "Group Messaging" block added
        in the previous task: that block only surfaced Group figures,
        leaving Individual figures visible nowhere on this page except by
        reading the four-series chart below (explicitly disallowed —
        "the user should NOT have to infer Individual vs Group from a
        chart"). This section adds explicit Individual and Overall card
        rows alongside a renamed/reorganized Group row, all sourced from
        the SAME `summary` object already fetched above — still zero new
        API calls and zero new backend fields.

        Derivation map (every value is either a literal API field or a
        same-window Sent+Failed sum computed here — never an independent
        recalculation):
          Overall Sent/Failed/Total       -> summary.total_sent / total_failed / total_alerts_attempted
                                              (AnalyticsController::resolveRecipientAwareTotals() already
                                              defines these as Individual+Group combined; verified in the
                                              implementation report's reconciliation check)
          Today's Total Sent/Failed        -> summary.total_sent_today / total_failed_today (same helper,
                                              re-windowed to today)
          Today's Total Messages           -> today_sent + today_failed (frontend sum; no dedicated
                                              backend field exists for this exact combination)
          Individual Sent/Failed           -> summary.recipient_breakdown.individual.sent/failed
          Individual Total                 -> individual.sent + individual.failed (frontend sum)
          Today's Individual Sent/Failed   -> summary.today_breakdown.today_individual_sent/failed
          Today's Individual Total         -> frontend sum of the two above
          Group Sent/Failed                -> summary.recipient_breakdown.group.sent/failed (already
                                              SUM(success_count)/SUM(failure_count), never COUNT(*))
          Group Total                      -> group.sent + group.failed (frontend sum)
          Today's Group Sent/Failed        -> summary.today_breakdown.today_group_sent/failed
          Today's Group Total              -> frontend sum of the two above
          Total Groups Added               -> summary.active_contact_groups (current count, not
                                              period-scoped — labelled as such, same as before)

        Individual and Overall render unconditionally (every account can
        send individual messages regardless of Group Messaging
        entitlement — Section 13's explicit requirement: "continue
        showing Individual and Overall statistics where valid" when
        contact_groups is disabled). Only the Group row and the Groups
        row stay behind hasModule('contact_groups') — the exact same gate
        ChartsSection below already uses for its Group chart series, not
        a new or weakened check.

        Self-healing nulls (recipient_breakdown/today_breakdown are null
        only when the recipient_type/success_count migrations haven't
        run yet) fall back to 0 via `?? 0`, identical to how every other
        field on this page already handles that condition — no section
        is hidden outright, matching this file's existing convention
        rather than inventing a new one.
      */}
      <div className="space-y-5">
        <p className="text-sm font-semibold text-slate-900">Message Statistics</p>

        <div>
          <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">
            Overall &middot; Selected Period
          </p>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <KpiCard
              label="Overall Sent"
              value={isLoading ? '…' : String(summary?.total_sent ?? 0)}
              sub="Individual + Group, selected period"
            />
            <KpiCard
              label="Overall Failed"
              value={isLoading ? '…' : String(summary?.total_failed ?? 0)}
              sub="Individual + Group, selected period"
            />
            <KpiCard
              label="Overall Total"
              value={isLoading ? '…' : String(summary?.total_alerts_attempted ?? 0)}
              sub="Sent + Failed, selected period"
            />
          </div>
        </div>

        <div>
          <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">
            Overall &middot; Today
          </p>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <KpiCard
              label="Today's Total Sent"
              value={isLoading ? '…' : String(summary?.total_sent_today ?? 0)}
              sub="Since midnight, server time"
            />
            <KpiCard
              label="Today's Total Failed"
              value={isLoading ? '…' : String(summary?.total_failed_today ?? 0)}
              sub="Since midnight, server time"
            />
            <KpiCard
              label="Today's Total Messages"
              value={isLoading ? '…' : String((summary?.total_sent_today ?? 0) + (summary?.total_failed_today ?? 0))}
              sub="Today's Sent + Failed"
            />
          </div>
        </div>

        <div>
          <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">
            Individual Messages
          </p>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <KpiCard
              label="Individual Sent"
              value={isLoading ? '…' : String(summary?.recipient_breakdown?.individual?.sent ?? 0)}
              sub="Selected period"
            />
            <KpiCard
              label="Individual Failed"
              value={isLoading ? '…' : String(summary?.recipient_breakdown?.individual?.failed ?? 0)}
              sub="Selected period"
            />
            <KpiCard
              label="Individual Total"
              value={
                isLoading
                  ? '…'
                  : String(
                      (summary?.recipient_breakdown?.individual?.sent ?? 0) +
                        (summary?.recipient_breakdown?.individual?.failed ?? 0),
                    )
              }
              sub="Sent + Failed, selected period"
            />
          </div>
          <div className="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <KpiCard
              label="Today's Individual Sent"
              value={isLoading ? '…' : String(summary?.today_breakdown?.today_individual_sent ?? 0)}
              sub="Since midnight, server time"
            />
            <KpiCard
              label="Today's Individual Failed"
              value={isLoading ? '…' : String(summary?.today_breakdown?.today_individual_failed ?? 0)}
              sub="Since midnight, server time"
            />
            <KpiCard
              label="Today's Individual Total"
              value={
                isLoading
                  ? '…'
                  : String(
                      (summary?.today_breakdown?.today_individual_sent ?? 0) +
                        (summary?.today_breakdown?.today_individual_failed ?? 0),
                    )
              }
              sub="Today's Sent + Failed"
            />
          </div>
        </div>

        {hasModule('contact_groups') && (
          <div>
            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">
              Group Messages
            </p>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              <KpiCard
                label="Group Sent"
                value={isLoading ? '…' : String(summary?.recipient_breakdown?.group?.sent ?? 0)}
                sub="Selected period"
              />
              <KpiCard
                label="Group Failed"
                value={isLoading ? '…' : String(summary?.recipient_breakdown?.group?.failed ?? 0)}
                sub="Selected period"
              />
              <KpiCard
                label="Group Total"
                value={
                  isLoading
                    ? '…'
                    : String(
                        (summary?.recipient_breakdown?.group?.sent ?? 0) +
                          (summary?.recipient_breakdown?.group?.failed ?? 0),
                      )
                }
                sub="Sent + Failed, selected period"
              />
            </div>
            <div className="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              <KpiCard
                label="Today's Group Sent"
                value={isLoading ? '…' : String(summary?.today_breakdown?.today_group_sent ?? 0)}
                sub="Since midnight, server time"
              />
              <KpiCard
                label="Today's Group Failed"
                value={isLoading ? '…' : String(summary?.today_breakdown?.today_group_failed ?? 0)}
                sub="Since midnight, server time"
              />
              <KpiCard
                label="Today's Group Total"
                value={
                  isLoading
                    ? '…'
                    : String(
                        (summary?.today_breakdown?.today_group_sent ?? 0) +
                          (summary?.today_breakdown?.today_group_failed ?? 0),
                      )
                }
                sub="Today's Sent + Failed"
              />
            </div>
          </div>
        )}

        {hasModule('contact_groups') && (
          <div>
            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Groups</p>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              <KpiCard
                label="Total Groups Added"
                value={isLoading ? '…' : String(summary?.active_contact_groups ?? 0)}
                sub="Current count, not tied to the selected period"
              />
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

function ChartsSection({ range, resolvedRange }: { range: ChartRange; resolvedRange: DateRange }) {
  // See SummarySection's comment — same reason for this dependency.
  const { selectedAccountId } = useTenant();
  const { hasModule } = useAuth();
  const [charts, setCharts] = useState<AnalyticsChartsResponse | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const data = await analyticsService.getCharts(
        range === 'custom'
          ? { range: 'custom', from: resolvedRange.from, to: resolvedRange.to }
          : { range },
      );
      setCharts(data);
    } catch (err) {
      const axiosErr = err as AxiosError<ApiErrorResponse>;
      setError(axiosErr.response?.data?.message ?? 'Failed to load chart data.');
    } finally {
      setIsLoading(false);
    }
  }, [range, resolvedRange.from, resolvedRange.to]);

  useEffect(() => {
    void load();
  }, [load, selectedAccountId]);

  // Message Pulse Clarity Fix (2026-09-16) — same recipient-aware,
  // module-gated four-series treatment as DashboardPage.tsx's
  // DailyPulseChart (see that component's docblock for the full
  // reasoning); reuses `daily_by_recipient_type` as-is, no new
  // calculation. Falls back to the original combined Sent/Failed series
  // when recipient-type data isn't available or the module isn't
  // entitled — zero visual change for those cases.
  const byRecipientType = charts?.daily_by_recipient_type ?? null;
  // Group Module Blank-Screen Fix, disclosed: byRecipientType itself can be truthy while its own .group is null (Module 2 Fix suppresses just that key for the RESOLVED account, not the whole object — see the .group?./.individual?. fix above) — checking .group here too is what makes the byRecipientType!.group[index] access below actually safe.
  const canShowRecipientSeries = Boolean(byRecipientType?.individual) && Boolean(byRecipientType?.group) && hasModule('contact_groups');
  const recipientChartData = canShowRecipientSeries
    ? byRecipientType!.individual.map((individualDay, index) => {
        const groupDay = byRecipientType!.group[index];
        return {
          date: individualDay.date.slice(5),
          'Individual Sent': individualDay.sent,
          'Individual Failed': individualDay.failed,
          'Group Sent': groupDay.sent,
          'Group Failed': groupDay.failed,
        };
      })
    : [];
  const chartData = (charts?.daily ?? []).map((d) => ({ date: d.date.slice(5), Sent: d.sent, Failed: d.failed }));
  const engineData = (charts?.engine_breakdown ?? []).map((e) => ({
    name: ENGINE_LABEL[e.engine_type],
    value: e.count,
    engine_type: e.engine_type,
  }));
  const hasEngineData = engineData.some((e) => e.value > 0);

  return (
    <div className="grid gap-4 lg:grid-cols-3">
      <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm lg:col-span-2">
        <p className="text-sm font-medium text-slate-900">Daily Message Volume</p>
        <div className="mt-4 h-72">
          {isLoading ? (
            <div className="flex h-full items-center justify-center text-sm text-slate-400">
              <Loader2 className="h-5 w-5 animate-spin" />
            </div>
          ) : error ? (
            <div className="flex h-full items-center justify-center text-sm text-red-600">{error}</div>
          ) : (
            <ResponsiveContainer width="100%" height="100%">
              {canShowRecipientSeries ? (
                <ComposedChart data={recipientChartData}>
                  <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" vertical={false} />
                  <XAxis dataKey="date" tick={{ fontSize: 12, fill: '#64748b' }} axisLine={false} tickLine={false} />
                  <YAxis allowDecimals={false} tick={{ fontSize: 12, fill: '#64748b' }} axisLine={false} tickLine={false} />
                  <Tooltip />
                  <Legend />
                  <Line type="monotone" dataKey="Individual Sent" stroke="#10b981" strokeWidth={2.5} dot={false} />
                  <Line type="monotone" dataKey="Individual Failed" stroke="#ef4444" strokeWidth={2} dot={false} />
                  <Line type="monotone" dataKey="Group Sent" stroke="#7C3AED" strokeWidth={2.5} strokeDasharray="6 4" dot={false} />
                  <Line type="monotone" dataKey="Group Failed" stroke="#F97316" strokeWidth={2} strokeDasharray="6 4" dot={false} />
                </ComposedChart>
              ) : (
                <AreaChart data={chartData}>
                  <defs>
                    <linearGradient id="analyticsSent" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="5%" stopColor="#10b981" stopOpacity={0.35} />
                      <stop offset="95%" stopColor="#10b981" stopOpacity={0} />
                    </linearGradient>
                    <linearGradient id="analyticsFailed" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="5%" stopColor="#ef4444" stopOpacity={0.3} />
                      <stop offset="95%" stopColor="#ef4444" stopOpacity={0} />
                    </linearGradient>
                  </defs>
                  <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" vertical={false} />
                  <XAxis dataKey="date" tick={{ fontSize: 12, fill: '#64748b' }} axisLine={false} tickLine={false} />
                  <YAxis allowDecimals={false} tick={{ fontSize: 12, fill: '#64748b' }} axisLine={false} tickLine={false} />
                  <Tooltip />
                  <Legend />
                  <Area type="monotone" dataKey="Sent" stroke="#10b981" strokeWidth={2.5} fill="url(#analyticsSent)" dot={false} />
                  <Area type="monotone" dataKey="Failed" stroke="#ef4444" strokeWidth={2} fill="url(#analyticsFailed)" dot={false} />
                </AreaChart>
              )}
            </ResponsiveContainer>
          )}
        </div>
      </div>

      <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <p className="text-sm font-medium text-slate-900">Engine Usage</p>
        <div className="mt-4 h-72">
          {isLoading ? (
            <div className="flex h-full items-center justify-center text-sm text-slate-400">
              <Loader2 className="h-5 w-5 animate-spin" />
            </div>
          ) : !hasEngineData ? (
            <div className="flex h-full items-center justify-center text-center text-sm text-slate-400">
              No alerts sent in this range yet.
            </div>
          ) : (
            <ResponsiveContainer width="100%" height="100%">
              <PieChart>
                <Pie data={engineData} dataKey="value" nameKey="name" innerRadius={55} outerRadius={85} paddingAngle={2}>
                  {engineData.map((entry) => (
                    <Cell key={entry.engine_type} fill={ENGINE_COLOR[entry.engine_type]} />
                  ))}
                </Pie>
                <Tooltip />
                <Legend />
              </PieChart>
            </ResponsiveContainer>
          )}
        </div>
        <p className="mt-2 text-xs text-slate-400">
          {charts?.scope === 'global'
            ? 'Summed across every client\'s current engine assignment, not per-message history.'
            : "Reflects the account's current engine assignment, not per-message history."}
        </p>
      </div>
    </div>
  );
}

/**
 * Analytics/Dashboard Log Source Discrepancy Fix.
 *
 * [Bugfix, disclosed, root cause]: this table used to read `payment_alerts`
 * via analyticsService.getLogs() (`/alerts/logs`, MessageLogController) —
 * a table that was, and always was, scoped to payment_alerts only (see
 * MessageLogController's own docblock). A Send Template / Chatbot /
 * Journey / Group / external-API send never writes a payment_alerts row,
 * so this grid silently never showed those sends, while the Dashboard's
 * "Recent Message Logs" widget (DashboardPage.tsx) had already been fixed
 * to read `message_dispatch_logs` via messageLogsService — the exact same
 * root cause and fix, applied here. Now reads the same table via the same
 * shared service, so the two widgets can never disagree about what "the
 * most recent messages" are again.
 *
 * Two deliberate, disclosed removals that follow directly from switching
 * the data source, not separate feature decisions:
 *   - Row click / detail modal removed: the old modal called
 *     analyticsService.getLogDetail(id) -> GET /alerts/logs/{id}, a
 *     payment_alerts-only lookup. message_dispatch_logs rows have their
 *     own id sequence from a different table — reusing that endpoint
 *     would 404 or, worse, silently show an unrelated payment_alerts
 *     row that happens to share the same numeric id. No
 *     MessageDispatchLogController::show() endpoint exists (only
 *     index()) to replace it with, and adding one was not requested.
 *   - CSV/PDF export buttons removed: ExportController's /exports/csv|pdf
 *     pair is also payment_alerts-only (see messageLogsService.ts's own
 *     docblock — extending export to message_dispatch_logs was
 *     explicitly flagged there as a follow-up, not done). Keeping the
 *     buttons wired to the old export would reintroduce this exact bug
 *     in a new shape: the exported file would silently disagree with
 *     the table now shown above it.
 * Both are one-line follow-ups (an admin.templates-style single show()
 * action, a new export query) if wanted later — flag it if you want that
 * done; not silently assumed in scope here.
 */
function LogsSection({ resolvedRange }: { resolvedRange: DateRange }) {
  // See SummarySection's comment — same reason for this dependency.
  const { selectedAccountId } = useTenant();
  const [logs, setLogs] = useState<MessageDispatchLog[]>([]);
  const [scope, setScope] = useState<'account' | 'global'>('account');
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [perPage, setPerPage] = useState(15);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<MessageDispatchStatus | ''>('');

  const hasActiveLogFilters = search !== '' || status !== '';
  const clearLogFilters = () => {
    setSearch('');
    setStatus('');
  };

  const filters: MessageDispatchLogFilters = useMemo(
    () => ({ search, status, from: resolvedRange.from, to: resolvedRange.to }),
    [search, status, resolvedRange.from, resolvedRange.to],
  );

  const load = useCallback(
    async (pageToLoad: number) => {
      setIsLoading(true);
      setError(null);
      try {
        const res = await messageLogsService.list(pageToLoad, perPage, filters);
        setLogs(res.data);
        setScope(res.scope);
        setPage(res.current_page);
        setLastPage(res.last_page);
        setTotal(res.total);
      } catch (err) {
        const axiosErr = err as AxiosError<ApiErrorResponse>;
        setError(axiosErr.response?.data?.message ?? 'Failed to load message logs.');
      } finally {
        setIsLoading(false);
      }
    },
    [perPage, filters],
  );

  useEffect(() => {
    void load(1);
  }, [load, selectedAccountId]);

  const showClientColumn = scope === 'global';
  const columnCount = showClientColumn ? 5 : 4;

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-2">
          <SearchInput value={search} onChange={setSearch} placeholder="Search by phone, template, or group name…" />
          <StatusFilterSelect
            value={status}
            onChange={(v) => setStatus(v as MessageDispatchStatus | '')}
            options={STATUS_OPTIONS.filter((opt) => opt.value !== '')}
            allLabel="All statuses"
          />
          <button
            type="button"
            onClick={() => void load(page)}
            className="flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
          >
            <RefreshCw className="h-4 w-4" />
          </button>
          <ClearFiltersButton active={hasActiveLogFilters} onClear={clearLogFilters} />
        </div>
      </div>

      {error && (
        <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          <XCircle className="h-4 w-4 flex-shrink-0" />
          {error}
        </div>
      )}

      <TableCard>
        <table className="min-w-full divide-y divide-slate-200 text-sm">
          <thead className="bg-slate-50">
            <tr>
              {showClientColumn && (
                <th className="px-4 py-2.5 text-left font-medium text-slate-600">Client</th>
              )}
              <th className="px-4 py-2.5 text-left font-medium text-slate-600">Recipient Phone / Group Name</th>
              <th className="px-4 py-2.5 text-left font-medium text-slate-600">Source / Template</th>
              <th className="px-4 py-2.5 text-left font-medium text-slate-600">Status</th>
              <th className="px-4 py-2.5 text-left font-medium text-slate-600">Sent At</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading ? (
              <TableSkeletonRows columns={columnCount} />
            ) : error ? null : logs.length === 0 ? (
              <tr>
                <td colSpan={columnCount} className="px-4 py-8 text-center text-slate-400">
                  No message logs match these filters.
                </td>
              </tr>
            ) : (
              logs.map((log) => (
                <tr key={log.id} className="hover:bg-slate-50">
                  {showClientColumn && (
                    <td className="px-4 py-2.5 text-slate-600">{log.account?.company_name ?? '—'}</td>
                  )}
                  <td className="px-4 py-2.5">
                    {log.recipient_type === 'group' ? (
                      <div className="flex items-center gap-1.5">
                        <span className="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 ring-1 ring-inset ring-indigo-600/20">
                          <Users className="h-3 w-3" />
                          Group
                        </span>
                        <span className="truncate text-xs font-semibold text-slate-800">
                          {log.group_name ?? '—'} ({log.recipient_count})
                        </span>
                      </div>
                    ) : (
                      <span className="font-mono text-xs text-slate-700">{log.recipient_phone}</span>
                    )}
                  </td>
                  <td className="px-4 py-2.5 max-w-xs">
                    <span
                      className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${SOURCE_BADGE[log.source].className}`}
                    >
                      {SOURCE_BADGE[log.source].label}
                    </span>
                    {log.template_name && (
                      <div className="mt-1 truncate text-xs text-slate-500">{log.template_name}</div>
                    )}
                  </td>
                  <td className="px-4 py-2.5">
                    <span
                      className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize ring-1 ring-inset ${STATUS_BADGE[log.status]}`}
                      title={log.status === 'failed' ? (log.error_reason ?? undefined) : undefined}
                    >
                      {log.status}
                    </span>
                  </td>
                  <td className="px-4 py-2.5 text-slate-500">{formatDateTime(log.created_at)}</td>
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
          onPageChange={(p) => void load(p)}
          onPerPageChange={(pp) => setPerPage(pp)}
        />
      </TableCard>
    </div>
  );
}
