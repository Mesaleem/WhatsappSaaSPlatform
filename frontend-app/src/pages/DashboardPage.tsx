import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { AxiosError } from 'axios';
import {
  Area,
  AreaChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';
import {
  AlertTriangle,
  ArrowUpRight,
  Bot,
  Building2,
  CalendarDays,
  CheckCircle2,
  Clock,
  CreditCard,
  IndianRupee,
  Loader2,
  MessageSquare,
  Percent,
  Send,
  Settings,
  Smartphone,
  TrendingUp,
  Users,
  Wifi,
  XCircle,
} from 'lucide-react';
import { useAuth } from '../core/context/AuthContext';
import { useTenant } from '../core/context/TenantContext';
import analyticsService from '../services/analyticsService';
import { indigo, NAV_TINTS, cardShadow, type Tint } from '../theme/signalIndigo';
import type { ApiErrorResponse } from '../types/auth';
import type { AnalyticsChartsResponse, AnalyticsSummary, GlobalAnalyticsSummary } from '../types/analytics';
import type { PaymentAlert, PaymentAlertStatus } from '../types/alert';
import type { EngineType, Subscription } from '../types/subscription';
import ExpiringSoonModal from '../components/admin/ExpiringSoonModal';

const ENGINE_LABEL: Record<EngineType, string> = { qr: 'QR (Baileys)', meta: 'Meta Cloud API' };

const STATUS_BADGE: Record<PaymentAlertStatus, string> = {
  pending: 'bg-slate-100 text-slate-600',
  queued: 'bg-[#E3F0FF] text-[#2563EB]',
  sent: 'bg-[#E6F8F1] text-[#0E9F6E]',
  failed: 'bg-[#FDECEC] text-[#DC2626]',
};

function extractMessage(err: unknown, fallback: string): string {
  const axiosErr = err as AxiosError<ApiErrorResponse>;
  return axiosErr.response?.data?.message ?? fallback;
}

/** Financial & Revenue Analytics — every revenue figure from the backend is a decimal-cast string (e.g. "4999.00"). */
function formatINR(value: string | number): string {
  const n = typeof value === 'string' ? Number(value) : value;
  return `₹${n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function StatCard({
  label,
  value,
  sub,
  icon: Icon,
  tint,
  onClick,
}: {
  label: string;
  value: string;
  sub?: string;
  icon: typeof MessageSquare;
  tint: Tint;
  /** Makes the whole card an interactive trigger (e.g. "Expiring in 7 Days" opens a modal, "Failed Messages" jumps to the logs). */
  onClick?: () => void;
}) {
  const Tag = onClick ? 'button' : 'div';

  return (
    <Tag
      type={onClick ? 'button' : undefined}
      onClick={onClick}
      className={`w-full rounded-2xl border bg-white p-5 text-left transition ${onClick ? 'hover:-translate-y-0.5 cursor-pointer' : ''}`}
      style={{ borderColor: indigo.border, boxShadow: cardShadow }}
    >
      <div className="flex items-start justify-between">
        <div>
          <p className="text-[11px] font-semibold uppercase tracking-wide" style={{ color: indigo.muted }}>
            {label}
          </p>
          <p className="mt-2 font-mono text-2xl font-semibold" style={{ color: indigo.ink }}>
            {value}
          </p>
          {sub && (
            <p className="mt-1 text-xs" style={{ color: indigo.muted }}>
              {sub}
            </p>
          )}
        </div>
        <div className="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl" style={{ background: tint.bg, color: tint.fg }}>
          <Icon className="h-4 w-4" />
        </div>
      </div>
    </Tag>
  );
}

/**
 * Super Admin Dashboard Enhancement / Analytics upgrade — "Heartbeat/
 * Pulse" smooth curved line chart (recharts type="monotone" + a
 * gradient-filled Area, rather than the previous bar chart), reused by
 * both TenantDashboard and SuperAdminDashboard. Self-contained: fetches
 * its own 7-day daily series via the same GET /api/analytics/charts
 * AnalyticsPage already uses (which already supports scope=global for a
 * Super Admin with no client selected — see AnalyticsController::charts()),
 * so no new backend endpoint is needed here.
 */
function DailyPulseChart() {
  const { selectedAccountId } = useTenant();
  const [charts, setCharts] = useState<AnalyticsChartsResponse | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  const load = useCallback(async () => {
    setIsLoading(true);
    try {
      const data = await analyticsService.getCharts({ range: '7' });
      setCharts(data);
    } catch {
      setCharts(null);
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load, selectedAccountId]);

  const data = (charts?.daily ?? []).map((d) => ({ date: d.date.slice(5), Sent: d.sent, Failed: d.failed }));

  return (
    <div className="rounded-2xl border bg-white p-5" style={{ borderColor: indigo.border, boxShadow: cardShadow }}>
      <p className="font-display text-sm font-bold" style={{ color: indigo.ink }}>
        Message Pulse — Last 7 Days
      </p>
      <div className="mt-3 h-56">
        {isLoading ? (
          <div className="flex h-full items-center justify-center">
            <Loader2 className="h-5 w-5 animate-spin" style={{ color: indigo.muted }} />
          </div>
        ) : (
          <ResponsiveContainer width="100%" height="100%">
            <AreaChart data={data}>
              <defs>
                <linearGradient id="pulseSent" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="5%" stopColor="#4F46E5" stopOpacity={0.35} />
                  <stop offset="95%" stopColor="#4F46E5" stopOpacity={0} />
                </linearGradient>
                <linearGradient id="pulseFailed" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="5%" stopColor="#ef4444" stopOpacity={0.3} />
                  <stop offset="95%" stopColor="#ef4444" stopOpacity={0} />
                </linearGradient>
              </defs>
              <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" vertical={false} />
              <XAxis dataKey="date" tick={{ fontSize: 11, fill: '#94a3b8' }} axisLine={false} tickLine={false} />
              <YAxis allowDecimals={false} tick={{ fontSize: 11, fill: '#94a3b8' }} axisLine={false} tickLine={false} width={28} />
              <Tooltip />
              <Area type="monotone" dataKey="Sent" stroke="#4F46E5" strokeWidth={2.5} fill="url(#pulseSent)" dot={false} />
              <Area type="monotone" dataKey="Failed" stroke="#ef4444" strokeWidth={2} fill="url(#pulseFailed)" dot={false} />
            </AreaChart>
          </ResponsiveContainer>
        )}
      </div>
    </div>
  );
}

/**
 * Super Admin Dashboard Overhaul — "ECG/Heartbeat" chart: Daily Revenue
 * (₹) vs Daily Message Traffic for the last 30 days, on two independent
 * Y-axes (revenue and message count are different units/scales — sharing
 * one axis would flatten whichever series is smaller). Super-Admin-only:
 * daily_revenue is null for a tenant-scoped charts() call (see
 * AnalyticsController::charts()'s docblock), so this is never rendered
 * from TenantDashboard.
 */
function RevenuePulseChart() {
  const [charts, setCharts] = useState<AnalyticsChartsResponse | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  const load = useCallback(async () => {
    setIsLoading(true);
    try {
      const data = await analyticsService.getCharts({ range: '30' });
      setCharts(data);
    } catch {
      setCharts(null);
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const revenueByDate = new Map((charts?.daily_revenue ?? []).map((r) => [r.date, Number(r.revenue)]));
  const data = (charts?.daily ?? []).map((d) => ({
    date: d.date.slice(5),
    Traffic: d.sent + d.failed,
    Revenue: revenueByDate.get(d.date) ?? 0,
  }));

  return (
    <div className="rounded-2xl border bg-white p-5" style={{ borderColor: indigo.border, boxShadow: cardShadow }}>
      <p className="font-display text-sm font-bold" style={{ color: indigo.ink }}>
        Revenue &amp; Traffic — Last 30 Days
      </p>
      <div className="mt-3 h-64">
        {isLoading ? (
          <div className="flex h-full items-center justify-center">
            <Loader2 className="h-5 w-5 animate-spin" style={{ color: indigo.muted }} />
          </div>
        ) : (
          <ResponsiveContainer width="100%" height="100%">
            <AreaChart data={data}>
              <defs>
                <linearGradient id="pulseRevenue" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="5%" stopColor="#0E9F6E" stopOpacity={0.35} />
                  <stop offset="95%" stopColor="#0E9F6E" stopOpacity={0} />
                </linearGradient>
                <linearGradient id="pulseTraffic" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="5%" stopColor="#4F46E5" stopOpacity={0.25} />
                  <stop offset="95%" stopColor="#4F46E5" stopOpacity={0} />
                </linearGradient>
              </defs>
              <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" vertical={false} />
              <XAxis dataKey="date" tick={{ fontSize: 11, fill: '#94a3b8' }} axisLine={false} tickLine={false} />
              <YAxis
                yAxisId="revenue"
                orientation="left"
                allowDecimals={false}
                tick={{ fontSize: 11, fill: '#0E9F6E' }}
                axisLine={false}
                tickLine={false}
                width={44}
                tickFormatter={(v: number) => `₹${v}`}
              />
              <YAxis
                yAxisId="traffic"
                orientation="right"
                allowDecimals={false}
                tick={{ fontSize: 11, fill: '#4F46E5' }}
                axisLine={false}
                tickLine={false}
                width={32}
              />
              <Tooltip formatter={(value, name) => (name === 'Revenue' ? formatINR(Number(value)) : value)} />
              <Area yAxisId="revenue" type="monotone" dataKey="Revenue" stroke="#0E9F6E" strokeWidth={2.5} fill="url(#pulseRevenue)" dot={false} />
              <Area yAxisId="traffic" type="monotone" dataKey="Traffic" stroke="#4F46E5" strokeWidth={2} fill="url(#pulseTraffic)" dot={false} />
            </AreaChart>
          </ResponsiveContainer>
        )}
      </div>
      <div className="mt-2 flex items-center gap-4 text-xs" style={{ color: indigo.muted }}>
        <span className="flex items-center gap-1.5"><span className="h-2 w-2 rounded-full" style={{ background: '#0E9F6E' }} /> Revenue (₹)</span>
        <span className="flex items-center gap-1.5"><span className="h-2 w-2 rounded-full" style={{ background: '#4F46E5' }} /> Message Traffic</span>
      </div>
    </div>
  );
}

function QuickAction({ to, label, icon: Icon, tint }: { to: string; label: string; icon: typeof Send; tint: Tint }) {
  return (
    <Link
      to={to}
      className="flex items-center justify-between rounded-2xl border bg-white p-4 transition hover:-translate-y-0.5"
      style={{ borderColor: indigo.border, boxShadow: cardShadow }}
    >
      <span className="flex items-center gap-3">
        <span className="flex h-9 w-9 items-center justify-center rounded-xl" style={{ background: tint.bg, color: tint.fg }}>
          <Icon className="h-4 w-4" />
        </span>
        <span className="text-sm font-semibold" style={{ color: indigo.ink }}>
          {label}
        </span>
      </span>
      <ArrowUpRight className="h-4 w-4" style={{ color: indigo.muted }} />
    </Link>
  );
}

/**
 * Client Admin Dashboard Overhaul — "Tenant Health & Quota" card: Plan
 * Name / Renewal Date / Subscription Amount plus an interactive Message
 * Quota progress bar in one place, rather than folding all of it into a
 * single StatCard (which has no room for a real bar). The whole card is
 * a Link to /billing — "interactive" here means it doubles as the
 * Upgrade/Renew shortcut, and the bar's title attribute surfaces the
 * exact used/total numbers on hover.
 */
function SubscriptionHealthCard({
  subscription,
  quotaPercentUsed,
  quotaLabel,
}: {
  subscription: Subscription | null;
  quotaPercentUsed: number | null;
  quotaLabel: string;
}) {
  if (!subscription) return null;

  const planLabel = `${subscription.billing_model.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())} (${subscription.engine_type.toUpperCase()})`;
  const renewalDate = new Date(subscription.expires_at).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
  const percent = quotaPercentUsed ?? 0;
  const barColor = percent >= 90 ? '#DC2626' : percent >= 70 ? '#D97706' : '#0E9F6E';

  return (
    <Link
      to="/billing"
      className="block rounded-2xl border bg-white p-5 transition hover:-translate-y-0.5"
      style={{ borderColor: indigo.border, boxShadow: cardShadow }}
    >
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <p className="text-[11px] font-semibold uppercase tracking-wide" style={{ color: indigo.muted }}>
            Current Subscription Plan
          </p>
          <p className="mt-1 font-display text-base font-bold" style={{ color: indigo.ink }}>
            {planLabel}
          </p>
        </div>
        <div className="text-right">
          <p className="text-[11px] font-semibold uppercase tracking-wide" style={{ color: indigo.muted }}>
            Renews / Expires
          </p>
          <p className="mt-1 text-sm font-semibold" style={{ color: indigo.ink }}>
            {renewalDate}
          </p>
        </div>
        <div className="text-right">
          <p className="text-[11px] font-semibold uppercase tracking-wide" style={{ color: indigo.muted }}>
            Subscription Amount
          </p>
          <p className="mt-1 font-mono text-sm font-semibold" style={{ color: indigo.ink }}>
            {formatINR(subscription.price_paid)}
          </p>
        </div>
      </div>

      {quotaPercentUsed !== null && (
        <div className="mt-4" title={quotaLabel}>
          <div className="flex items-center justify-between text-xs" style={{ color: indigo.muted }}>
            <span>Message Quota</span>
            <span>{quotaLabel}</span>
          </div>
          <div className="mt-1.5 h-2.5 w-full overflow-hidden rounded-full bg-slate-100">
            <div
              className="h-full rounded-full transition-all"
              style={{ width: `${Math.min(100, Math.max(0, percent))}%`, background: barColor }}
            />
          </div>
        </div>
      )}
    </Link>
  );
}

function TenantDashboard({ impersonatedAccountName }: { impersonatedAccountName?: string } = {}) {
  const { user, hasPermission } = useAuth();
  const navigate = useNavigate();
  const canViewAnalytics = hasPermission('view-analytics');
  const canViewLogs = hasPermission('view-logs');
  const canSendAlerts = hasPermission('send-messages');
  const canManageChatbot = hasPermission('manage-chatbot');
  const canManageBilling = hasPermission('manage-subscriptions');
  const canManageTeam = hasPermission('manage-team');

  const subscription = user?.account?.current_subscription ?? null;

  const [summary, setSummary] = useState<AnalyticsSummary | null>(null);
  const [summaryError, setSummaryError] = useState<string | null>(null);
  const [isLoadingSummary, setIsLoadingSummary] = useState(canViewAnalytics);

  const [logs, setLogs] = useState<PaymentAlert[]>([]);
  const [logsError, setLogsError] = useState<string | null>(null);
  const [isLoadingLogs, setIsLoadingLogs] = useState(canViewLogs);

  const loadSummary = useCallback(async () => {
    if (!canViewAnalytics) return;
    setIsLoadingSummary(true);
    setSummaryError(null);
    try {
      const to = new Date().toISOString().slice(0, 10);
      const from = new Date(Date.now() - 29 * 86400000).toISOString().slice(0, 10);
      const data = await analyticsService.getSummary({ from, to });
      setSummary(data);
    } catch (err) {
      setSummaryError(extractMessage(err, 'Failed to load account summary.'));
    } finally {
      setIsLoadingSummary(false);
    }
  }, [canViewAnalytics]);

  const loadLogs = useCallback(async () => {
    if (!canViewLogs) return;
    setIsLoadingLogs(true);
    setLogsError(null);
    try {
      const res = await analyticsService.getLogs({ page: 1, per_page: 5 });
      setLogs(res.data);
    } catch (err) {
      setLogsError(extractMessage(err, 'Failed to load recent transactions.'));
    } finally {
      setIsLoadingLogs(false);
    }
  }, [canViewLogs]);

  useEffect(() => {
    void loadSummary();
  }, [loadSummary]);

  useEffect(() => {
    void loadLogs();
  }, [loadLogs]);

  const quota = summary?.quota;
  const quotaLabel = quota?.billing_model === 'unlimited'
    ? 'Unlimited'
    : quota
      ? `${quota.remaining_messages ?? '—'} left`
      : subscription?.total_allocated_messages != null
        ? `${Math.max(subscription.total_allocated_messages - subscription.used_messages, 0)} left`
        : '—';
  const quotaSub = quota?.billing_model === 'unlimited'
    ? 'No message cap on this plan'
    : quota
      ? `${quota.used_messages} / ${quota.total_allocated_messages ?? '—'} used`
      : subscription
        ? `${subscription.used_messages} / ${subscription.total_allocated_messages ?? '—'} used`
        : undefined;

  const engineType = quota?.engine_type ?? subscription?.engine_type ?? null;

  return (
    <div className="p-6">
      <div className="w-full space-y-6">
        <div>
          <h2 className="font-display text-lg font-bold" style={{ color: indigo.ink }}>
            Welcome back, {user?.name}
          </h2>
          <p className="mt-1 text-sm" style={{ color: indigo.muted }}>
            {impersonatedAccountName
              ? <>Viewing <span className="font-semibold" style={{ color: indigo.ink }}>{impersonatedAccountName}</span> as Super Admin.</>
              : <>Here's what's happening with {user?.account?.company_name}.</>}
          </p>
        </div>

        {summaryError && (
          <div className="flex items-center gap-2 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            <XCircle className="h-4 w-4 flex-shrink-0" />
            {summaryError}
          </div>
        )}

        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <StatCard
            label="Total Messages"
            value={!canViewAnalytics ? '—' : isLoadingSummary ? '…' : String(summary?.total_alerts_attempted ?? 0)}
            sub={!canViewAnalytics ? 'Requires view-analytics' : isLoadingSummary ? undefined : 'Last 30 days'}
            icon={MessageSquare}
            tint={NAV_TINTS.send}
          />
          <StatCard
            label="Success Rate"
            value={!canViewAnalytics ? '—' : isLoadingSummary ? '…' : `${summary?.delivered_rate ?? 0}%`}
            sub={!canViewAnalytics ? 'Requires view-analytics' : isLoadingSummary ? undefined : `${summary?.total_failed ?? 0} failed`}
            icon={TrendingUp}
            tint={NAV_TINTS.analytics}
          />
          <StatCard label="Quota Health" value={quotaLabel} sub={quotaSub} icon={CreditCard} tint={NAV_TINTS.billing} />
          <StatCard
            label="Active Engine"
            value={engineType ? ENGINE_LABEL[engineType] : '—'}
            sub={engineType === 'qr' ? 'Self-hosted via QR pairing' : engineType === 'meta' ? 'Official WhatsApp Cloud API' : undefined}
            icon={engineType === 'qr' ? Smartphone : Wifi}
            tint={NAV_TINTS.whatsapp}
          />
          <StatCard
            label="Today's Messages"
            value={!canViewAnalytics ? '—' : isLoadingSummary ? '…' : `${summary?.total_sent_today ?? 0} sent`}
            sub={!canViewAnalytics ? 'Requires view-analytics' : isLoadingSummary ? undefined : `${summary?.total_failed_today ?? 0} failed today`}
            icon={MessageSquare}
            tint={NAV_TINTS.dashboard}
            onClick={canViewLogs ? () => navigate('/analytics') : undefined}
          />
        </div>

        <SubscriptionHealthCard subscription={subscription} quotaPercentUsed={quota?.quota_percent_used ?? null} quotaLabel={quotaSub ?? quotaLabel} />

        {canViewAnalytics && <DailyPulseChart />}

        <div>
          <h3 className="mb-3 font-display text-sm font-bold" style={{ color: indigo.ink }}>
            Quick Actions
          </h3>
          <div className="grid gap-3 sm:grid-cols-3">
            {canSendAlerts && <QuickAction to="/alerts/send" label="Send Payment Alert" icon={Send} tint={NAV_TINTS.send} />}
            {canManageTeam && <QuickAction to="/users" label="Manage Team" icon={Users} tint={NAV_TINTS.team} />}
            {canManageBilling && <QuickAction to="/billing" label="Upgrade / Renew Plan" icon={CreditCard} tint={NAV_TINTS.billing} />}
            {canManageChatbot && <QuickAction to="/chatbot" label="Manage Chatbot" icon={Bot} tint={NAV_TINTS.chatbot} />}
          </div>
        </div>

        <div className="overflow-hidden rounded-2xl border bg-white" style={{ borderColor: indigo.border, boxShadow: cardShadow }}>
          <div className="flex items-center justify-between px-5 py-3.5" style={{ borderBottom: `1px solid ${indigo.border}` }}>
            <h3 className="font-display text-sm font-bold" style={{ color: indigo.ink }}>
              Recent Transactions
            </h3>
            {canViewLogs && (
              <Link to="/analytics" className="text-xs font-semibold" style={{ color: indigo.accentSolid }}>
                View all
              </Link>
            )}
          </div>

          {!canViewLogs ? (
            <p className="px-5 py-6 text-sm" style={{ color: indigo.muted }}>
              Message logs require the "view-logs" permission.
            </p>
          ) : logsError ? (
            <p className="px-5 py-6 text-sm text-red-600">{logsError}</p>
          ) : isLoadingLogs ? (
            <div className="flex justify-center py-6">
              <Loader2 className="h-5 w-5 animate-spin" style={{ color: indigo.muted }} />
            </div>
          ) : logs.length === 0 ? (
            <p className="px-5 py-6 text-sm" style={{ color: indigo.muted }}>
              No transactions yet.
            </p>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y text-sm" style={{ borderColor: indigo.border }}>
                <thead style={{ background: '#FAFAFF' }}>
                  <tr>
                    <th className="px-5 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wide" style={{ color: indigo.muted }}>
                      Customer
                    </th>
                    <th className="px-5 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wide" style={{ color: indigo.muted }}>
                      Amount
                    </th>
                    <th className="px-5 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wide" style={{ color: indigo.muted }}>
                      Payment Ref
                    </th>
                    <th className="px-5 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wide" style={{ color: indigo.muted }}>
                      Status
                    </th>
                    <th className="px-5 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wide" style={{ color: indigo.muted }}>
                      Sent At
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y" style={{ borderColor: indigo.border }}>
                  {logs.map((log) => (
                    <tr key={log.id} style={{ borderColor: indigo.border }}>
                      <td className="px-5 py-2.5" style={{ color: indigo.ink }}>
                        {log.customer_name}
                      </td>
                      <td className="px-5 py-2.5 font-mono tabular-nums" style={{ color: indigo.ink }}>
                        ₹{Number(log.amount).toFixed(2)}
                      </td>
                      <td className="px-5 py-2.5 font-mono text-xs" style={{ color: indigo.muted }}>
                        {log.payment_ref}
                      </td>
                      <td className="px-5 py-2.5">
                        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ${STATUS_BADGE[log.status]}`}>
                          {log.status}
                        </span>
                      </td>
                      <td className="px-5 py-2.5" style={{ color: indigo.muted }}>
                        {log.sent_at ? new Date(log.sent_at).toLocaleString() : '—'}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

/**
 * Super Admin Dashboard Overhaul: replaces the previous empty "no tenant
 * attached" placeholder with system-wide platform metrics, sourced from
 * the new Super-Admin-only GET /api/analytics/global-summary (see
 * AnalyticsController::globalSummary()). Rendered only when Super Admin
 * has NOT selected a client in the Header's "Select Client" switcher —
 * selecting one switches to <TenantDashboard>, scoped to that client.
 */
const FAILED_TINT: Tint = { bg: '#FDECEC', fg: '#DC2626' };

function SuperAdminDashboard() {
  const { user } = useAuth();
  const navigate = useNavigate();

  const [summary, setSummary] = useState<GlobalAnalyticsSummary | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [showExpiringModal, setShowExpiringModal] = useState(false);

  const loadSummary = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const data = await analyticsService.getGlobalSummary();
      setSummary(data);
    } catch (err) {
      setError(extractMessage(err, 'Failed to load platform-wide metrics.'));
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadSummary();
  }, [loadSummary]);

  return (
    <div className="p-6">
      <div className="w-full space-y-6">
        <div>
          <h2 className="font-display text-lg font-bold" style={{ color: indigo.ink }}>
            Welcome back, {user?.name}
          </h2>
          <p className="mt-1 text-sm" style={{ color: indigo.muted }}>
            Platform-level administration — system-wide metrics across every client account.
          </p>
        </div>

        {error && (
          <div className="flex items-center gap-2 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            <XCircle className="h-4 w-4 flex-shrink-0" />
            {error}
          </div>
        )}

        <div>
          <h3 className="mb-3 font-display text-sm font-bold" style={{ color: indigo.ink }}>
            Financial Overview
          </h3>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard
              label="Total Platform Revenue"
              value={isLoading ? '…' : formatINR(summary?.total_platform_revenue ?? '0')}
              sub="Lifetime, all clients"
              icon={IndianRupee}
              tint={NAV_TINTS.billing}
            />
            <StatCard
              label="Current Month Revenue"
              value={isLoading ? '…' : formatINR(summary?.current_month_revenue ?? '0')}
              sub="Paid this calendar month"
              icon={CalendarDays}
              tint={NAV_TINTS.developer}
            />
            <StatCard
              label="Pending / Overdue Payments"
              value={isLoading ? '…' : formatINR(summary?.pending_overdue_revenue ?? '0')}
              sub="Value of expired/unpaid plans"
              icon={Clock}
              tint={FAILED_TINT}
            />
            <StatCard
              label="ARPU"
              value={isLoading ? '…' : formatINR(summary?.arpu ?? '0')}
              sub="Avg. lifetime revenue / client"
              icon={Percent}
              tint={NAV_TINTS.gateway}
            />
          </div>
        </div>

        <RevenuePulseChart />

        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <StatCard
            label="Total Clients"
            value={isLoading ? '…' : String(summary?.total_clients ?? 0)}
            sub="Registered tenant accounts"
            icon={Building2}
            tint={NAV_TINTS.accounts}
          />
          <StatCard
            label="Active Clients"
            value={isLoading ? '…' : String(summary?.active_clients_count ?? 0)}
            sub="Click to view active accounts"
            icon={CheckCircle2}
            tint={NAV_TINTS.team}
            onClick={() => navigate('/admin/accounts?status=active')}
          />
          <StatCard
            label="Messages Sent"
            value={isLoading ? '…' : String(summary?.total_messages_sent ?? 0)}
            sub="Across the entire platform"
            icon={MessageSquare}
            tint={NAV_TINTS.send}
          />
          <StatCard
            label="Global Success Rate"
            value={isLoading ? '…' : `${summary?.global_success_rate ?? 0}%`}
            sub={isLoading ? undefined : `${summary?.total_messages_failed ?? 0} failed`}
            icon={TrendingUp}
            tint={NAV_TINTS.analytics}
          />
          <StatCard
            label="Active WhatsApp Engines"
            value={isLoading ? '…' : String(summary?.active_whatsapp_engines ?? 0)}
            sub="Currently connected sessions"
            icon={Wifi}
            tint={NAV_TINTS.whatsapp}
          />
          <StatCard
            label="Expiring in 7 Days"
            value={isLoading ? '…' : String(summary?.expiring_in_7_days_count ?? 0)}
            sub="Click to view accounts & renew"
            icon={AlertTriangle}
            tint={NAV_TINTS.chatbot}
            onClick={() => setShowExpiringModal(true)}
          />
          <StatCard
            label="Today's Total Messages"
            value={isLoading ? '…' : String(summary?.total_messages_sent_today ?? 0)}
            sub="Since midnight, server time"
            icon={MessageSquare}
            tint={NAV_TINTS.dashboard}
            onClick={() => navigate('/analytics')}
          />
          <StatCard
            label="Total Failed Messages"
            value={isLoading ? '…' : String(summary?.total_messages_failed ?? 0)}
            sub="Click to view logs"
            icon={XCircle}
            tint={FAILED_TINT}
            onClick={() => navigate('/analytics')}
          />
          <StatCard
            label="Today's Failed Messages"
            value={isLoading ? '…' : String(summary?.total_messages_failed_today ?? 0)}
            sub="Since midnight, server time"
            icon={XCircle}
            tint={FAILED_TINT}
            onClick={() => navigate('/analytics')}
          />
        </div>

        <DailyPulseChart />

        <div>
          <h3 className="mb-3 font-display text-sm font-bold" style={{ color: indigo.ink }}>
            Quick Actions
          </h3>
          <div className="grid gap-3 sm:grid-cols-2">
            <QuickAction to="/admin/accounts" label="Manage Accounts" icon={Building2} tint={NAV_TINTS.accounts} />
            <QuickAction to="/admin/billing/gateway-settings" label="Gateway Settings" icon={Settings} tint={NAV_TINTS.gateway} />
          </div>
        </div>
      </div>

      {showExpiringModal && <ExpiringSoonModal onClose={() => setShowExpiringModal(false)} />}
    </div>
  );
}

/**
 * Super Admin Multi-Tenant Scoping: a Super Admin with NO client selected
 * (selectedAccountId === null) sees the platform-wide SuperAdminDashboard;
 * selecting a client in the Header switcher renders the same
 * TenantDashboard a real tenant user sees, scoped to that client via
 * axiosInstance's request interceptor — impersonatedAccountName covers the
 * one piece of copy that otherwise reads from user.account (null for
 * Super Admin).
 */
export default function DashboardPage() {
  const { user } = useAuth();
  const { selectedAccountId, selectedAccount } = useTenant();

  if (user?.account_id) {
    return <TenantDashboard />;
  }

  if (selectedAccountId) {
    return <TenantDashboard impersonatedAccountName={selectedAccount?.company_name} />;
  }

  return <SuperAdminDashboard />;
}
