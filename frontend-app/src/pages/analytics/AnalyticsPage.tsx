import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react';
import { AxiosError } from 'axios';
import {
  AlertCircle,
  CheckCircle2,
  Download,
  FileText,
  Loader2,
  RefreshCw,
  Search,
  Send,
  X,
  XCircle,
} from 'lucide-react';
import {
  Area,
  AreaChart,
  CartesianGrid,
  Cell,
  Legend,
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
import type { ApiErrorResponse } from '../../types/auth';
import type { PaymentAlert, PaymentAlertStatus } from '../../types/alert';
import type { AnalyticsChartsResponse, AnalyticsSummary, ChartRange, DateRange } from '../../types/analytics';
import type { EngineType } from '../../types/subscription';

const STATUS_BADGE: Record<PaymentAlertStatus, string> = {
  pending: 'bg-slate-100 text-slate-600 ring-slate-500/20',
  queued: 'bg-blue-50 text-blue-700 ring-blue-600/20',
  sent: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  failed: 'bg-red-50 text-red-700 ring-red-600/20',
};

const STATUS_OPTIONS: Array<{ value: PaymentAlertStatus | ''; label: string }> = [
  { value: '', label: 'All statuses' },
  { value: 'pending', label: 'Pending' },
  { value: 'queued', label: 'Queued' },
  { value: 'sent', label: 'Sent' },
  { value: 'failed', label: 'Failed' },
];

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
    </div>
  );
}

function ChartsSection({ range, resolvedRange }: { range: ChartRange; resolvedRange: DateRange }) {
  // See SummarySection's comment — same reason for this dependency.
  const { selectedAccountId } = useTenant();
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

function LogsSection({ resolvedRange }: { resolvedRange: DateRange }) {
  // See SummarySection's comment — same reason for this dependency.
  const { selectedAccountId } = useTenant();
  const [logs, setLogs] = useState<PaymentAlert[]>([]);
  const [logsScope, setLogsScope] = useState<'account' | 'global'>('account');
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<PaymentAlertStatus | ''>('');
  const [selectedAlertId, setSelectedAlertId] = useState<number | null>(null);

  const [isExportingCsv, setIsExportingCsv] = useState(false);
  const [isExportingPdf, setIsExportingPdf] = useState(false);
  const [exportError, setExportError] = useState<string | null>(null);

  // Debounce the search box so every keystroke doesn't trigger a fetch.
  useEffect(() => {
    const handle = setTimeout(() => setSearch(searchInput.trim()), 400);
    return () => clearTimeout(handle);
  }, [searchInput]);

  const load = useCallback(
    async (pageToLoad: number) => {
      setIsLoading(true);
      setError(null);
      try {
        const res = await analyticsService.getLogs({
          page: pageToLoad,
          per_page: 15,
          search: search || undefined,
          status: status || undefined,
          from: resolvedRange.from,
          to: resolvedRange.to,
        });
        setLogs(res.data);
        setLogsScope(res.scope);
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
    [search, status, resolvedRange.from, resolvedRange.to],
  );

  useEffect(() => {
    void load(1);
  }, [load, selectedAccountId]);

  const runExport = async (kind: 'csv' | 'pdf') => {
    setExportError(null);
    const filters = { search: search || undefined, status: status || undefined, from: resolvedRange.from, to: resolvedRange.to };
    const setBusy = kind === 'csv' ? setIsExportingCsv : setIsExportingPdf;
    setBusy(true);
    try {
      await (kind === 'csv' ? analyticsService.exportCsv(filters) : analyticsService.exportPdf(filters));
    } catch (err) {
      setExportError(err instanceof Error ? err.message : `Could not export the ${kind.toUpperCase()}.`);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="rounded-xl border border-slate-200 bg-white shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-4">
        <div className="flex flex-wrap items-center gap-2">
          <div className="relative">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <input
              type="text"
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
              placeholder="Search name, phone, or payment ref…"
              className={`${inputClass} w-64 pl-9`}
            />
          </div>
          <select value={status} onChange={(e) => setStatus(e.target.value as PaymentAlertStatus | '')} className={inputClass}>
            {STATUS_OPTIONS.map((opt) => (
              <option key={opt.value} value={opt.value}>
                {opt.label}
              </option>
            ))}
          </select>
          <button
            type="button"
            onClick={() => void load(page)}
            className="flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
          >
            <RefreshCw className="h-4 w-4" />
          </button>
        </div>

        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={() => void runExport('csv')}
            disabled={isExportingCsv}
            className="flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60"
          >
            {isExportingCsv ? <Loader2 className="h-4 w-4 animate-spin" /> : <Download className="h-4 w-4" />}
            Export CSV
          </button>
          <button
            type="button"
            onClick={() => void runExport('pdf')}
            disabled={isExportingPdf}
            className="flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60"
          >
            {isExportingPdf ? <Loader2 className="h-4 w-4 animate-spin" /> : <FileText className="h-4 w-4" />}
            Export PDF
          </button>
        </div>
      </div>

      {exportError && (
        <div className="flex items-center gap-2 border-b border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700">
          <XCircle className="h-4 w-4 flex-shrink-0" />
          {exportError}
        </div>
      )}

      <div className="overflow-x-auto">
        <table className="min-w-full divide-y divide-slate-200 text-sm">
          <thead className="bg-slate-50">
            <tr>
              <th className="px-4 py-2.5 text-left font-medium text-slate-600">Customer</th>
              {logsScope === 'global' && (
                <th className="px-4 py-2.5 text-left font-medium text-slate-600">Client</th>
              )}
              <th className="px-4 py-2.5 text-left font-medium text-slate-600">Phone</th>
              <th className="px-4 py-2.5 text-left font-medium text-slate-600">Amount</th>
              <th className="px-4 py-2.5 text-left font-medium text-slate-600">Payment Ref</th>
              <th className="px-4 py-2.5 text-left font-medium text-slate-600">Status</th>
              <th className="px-4 py-2.5 text-left font-medium text-slate-600">Sent At</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading ? (
              <tr>
                <td colSpan={logsScope === 'global' ? 7 : 6} className="px-4 py-8 text-center text-slate-400">
                  <Loader2 className="mx-auto h-5 w-5 animate-spin" />
                </td>
              </tr>
            ) : error ? (
              <tr>
                <td colSpan={logsScope === 'global' ? 7 : 6} className="px-4 py-8 text-center text-red-600">
                  {error}
                </td>
              </tr>
            ) : logs.length === 0 ? (
              <tr>
                <td colSpan={logsScope === 'global' ? 7 : 6} className="px-4 py-8 text-center text-slate-400">
                  No message logs match these filters.
                </td>
              </tr>
            ) : (
              logs.map((log) => (
                <tr
                  key={log.id}
                  onClick={() => setSelectedAlertId(log.id)}
                  className="cursor-pointer hover:bg-slate-50"
                >
                  <td className="px-4 py-2.5 text-slate-800">{log.customer_name}</td>
                  {logsScope === 'global' && (
                    <td className="px-4 py-2.5 text-slate-600">{log.account?.company_name ?? '—'}</td>
                  )}
                  <td className="px-4 py-2.5 text-slate-600">{log.recipient_phone}</td>
                  <td className="px-4 py-2.5 text-slate-800">₹{Number(log.amount).toFixed(2)}</td>
                  <td className="px-4 py-2.5 font-mono text-xs text-slate-600">{log.payment_ref}</td>
                  <td className="px-4 py-2.5">
                    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${STATUS_BADGE[log.status]}`}>
                      {log.status}
                    </span>
                  </td>
                  <td className="px-4 py-2.5 text-slate-500">{log.sent_at ? new Date(log.sent_at).toLocaleString() : '—'}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      <div className="flex items-center justify-between border-t border-slate-200 px-4 py-3 text-sm text-slate-500">
        <span>{total} total log{total === 1 ? '' : 's'}</span>
        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={() => void load(page - 1)}
            disabled={page <= 1 || isLoading}
            className="rounded-lg border border-slate-300 px-3 py-1.5 font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
          >
            Prev
          </button>
          <span>
            Page {page} of {lastPage}
          </span>
          <button
            type="button"
            onClick={() => void load(page + 1)}
            disabled={page >= lastPage || isLoading}
            className="rounded-lg border border-slate-300 px-3 py-1.5 font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
          >
            Next
          </button>
        </div>
      </div>

      {selectedAlertId !== null && (
        <LogDetailModal alertId={selectedAlertId} onClose={() => setSelectedAlertId(null)} />
      )}
    </div>
  );
}

function LogDetailModal({ alertId, onClose }: { alertId: number; onClose: () => void }) {
  const [alert, setAlert] = useState<PaymentAlert | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    setIsLoading(true);
    setError(null);
    analyticsService
      .getLogDetail(alertId)
      .then((data) => {
        if (!cancelled) setAlert(data);
      })
      .catch((err: AxiosError<ApiErrorResponse>) => {
        if (!cancelled) setError(err.response?.data?.message ?? 'Failed to load this alert.');
      })
      .finally(() => {
        if (!cancelled) setIsLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [alertId]);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4" onClick={onClose}>
      <div
        className="max-h-[85vh] w-full max-w-lg overflow-y-auto rounded-xl bg-white p-6 shadow-xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-center justify-between">
          <h2 className="text-base font-semibold text-slate-900">Alert #{alertId}</h2>
          <button type="button" onClick={onClose} className="text-slate-400 hover:text-slate-600">
            <X className="h-5 w-5" />
          </button>
        </div>

        {isLoading ? (
          <div className="flex items-center justify-center py-10">
            <Loader2 className="h-5 w-5 animate-spin text-slate-400" />
          </div>
        ) : error ? (
          <div className="mt-4 flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <AlertCircle className="h-4 w-4 flex-shrink-0" />
            {error}
          </div>
        ) : alert ? (
          <div className="mt-4 space-y-4 text-sm">
            <div className="grid grid-cols-2 gap-3">
              <DetailField label="Customer" value={alert.customer_name} />
              <DetailField label="Phone" value={alert.recipient_phone} />
              <DetailField label="Amount" value={`₹${Number(alert.amount).toFixed(2)}`} />
              <DetailField label="Payment Ref" value={alert.payment_ref} mono />
              <DetailField
                label="Status"
                value={
                  <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${STATUS_BADGE[alert.status]}`}>
                    {alert.status}
                  </span>
                }
              />
              <DetailField label="Cost Deducted" value={`₹${Number(alert.cost_deducted).toFixed(4)}`} />
              <DetailField label="Sent At" value={alert.sent_at ? new Date(alert.sent_at).toLocaleString() : '—'} />
              <DetailField label="Created At" value={new Date(alert.created_at).toLocaleString()} />
            </div>

            {alert.error_reason && (
              <div className="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2.5 text-red-700">
                <AlertCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
                <span>{alert.error_reason}</span>
              </div>
            )}

            {alert.status === 'sent' && !alert.error_reason && (
              <div className="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2.5 text-emerald-700">
                <CheckCircle2 className="h-4 w-4 flex-shrink-0" />
                <span>Delivered successfully.</span>
              </div>
            )}

            <div>
              <p className="mb-1.5 flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-slate-500">
                <Send className="h-3.5 w-3.5" />
                Raw Payload Metadata
              </p>
              {alert.raw_response ? (
                <pre className="max-h-48 overflow-auto rounded-lg bg-slate-900 p-3 text-xs text-slate-100">
                  {JSON.stringify(alert.raw_response, null, 2)}
                </pre>
              ) : (
                <p className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-xs text-slate-500">
                  No raw driver payload was captured for this alert (sent before this was tracked, or no driver
                  response was returned).
                </p>
              )}
            </div>
          </div>
        ) : null}
      </div>
    </div>
  );
}

function DetailField({ label, value, mono }: { label: string; value: ReactNode; mono?: boolean }) {
  return (
    <div>
      <p className="text-xs font-medium uppercase tracking-wide text-slate-400">{label}</p>
      <p className={`mt-0.5 text-slate-800 ${mono ? 'font-mono text-xs' : ''}`}>{value}</p>
    </div>
  );
}
