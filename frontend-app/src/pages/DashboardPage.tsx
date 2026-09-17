import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { AxiosError } from 'axios';
import {
  Area,
  AreaChart,
  CartesianGrid,
  ComposedChart,
  Line,
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
  Contact,
  CreditCard,
  Eye,
  IndianRupee,
  Loader2,
  Megaphone,
  MessageSquare,
  Percent,
  Send,
  Settings,
  Smartphone,
  Target,
  TrendingUp,
  Users,
  Wifi,
  XCircle,
  Zap,
} from 'lucide-react';
import { useAuth } from '../core/context/AuthContext';
import { useTenant } from '../core/context/TenantContext';
import accountService from '../services/accountService';
import analyticsService from '../services/analyticsService';
import reportsService from '../services/reportsService';
import messageLogsService from '../services/messageLogsService';
import QuotaTopUpModal from '../components/billing/QuotaTopUpModal';
import { indigo, NAV_TINTS, cardShadow, type Tint } from '../theme/signalIndigo';
import type { ApiErrorResponse } from '../types/auth';
import type { Account, ModuleAssignment } from '../types/account';
import type { AnalyticsChartsResponse, AnalyticsSummary, GlobalAnalyticsSummary } from '../types/analytics';
import type { MessageDispatchLog, MessageDispatchSource, MessageDispatchStatus } from '../types/messageLog';
import type { SocialReportSummary } from '../types/reports';
import type { EngineType, Subscription } from '../types/subscription';
import ExpiringSoonModal from '../components/admin/ExpiringSoonModal';

const ENGINE_LABEL: Record<EngineType, string> = { qr: 'QR (Baileys)', meta: 'Meta Cloud API' };

/**
 * [Bugfix, disclosed]: narrowed from the old PaymentAlertStatus-keyed map —
 * the Recent Logs widget now sources message_dispatch_logs. Widened again
 * for Group Messaging Phase 4's new 'queued' status (see types/messageLog.ts).
 */
const STATUS_BADGE: Record<MessageDispatchStatus, string> = {
  sent: 'bg-[#E6F8F1] text-[#0E9F6E]',
  failed: 'bg-[#FDECEC] text-[#DC2626]',
  queued: 'bg-[#FEF3C7] text-[#B45309]',
};

/** Mirrors MessageLogsPage.tsx's SOURCE_BADGE labels, kept short for this narrower card. */
const SOURCE_LABEL: Record<MessageDispatchSource, string> = {
  web_ui: 'Web',
  web_template: 'Template',
  api: 'API',
  chatbot: 'Chatbot',
  journey: 'Journey',
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
  const { hasModule } = useAuth();
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

  // Message Pulse Clarity Fix (2026-09-16) — the previous "all / individual
  // / group" toggle showed one ambiguous Sent/Failed pair at a time,
  // leaving it unclear whether a line meant individual or group traffic.
  // Now all four series render together with explicit labels, sourced
  // directly from the already-correct, recipient-aware
  // `daily_by_recipient_type` (no new calculation — see
  // AnalyticsController::dailyRecipientTypeSeries()). Gated on
  // hasModule('contact_groups') so an account without the Group
  // Messaging module never sees group-derived series, matching the same
  // gate the KPI cards above already use; Super Admin's own
  // hasModule() always returns true (see AuthContext), so the four-series
  // view renders unconditionally on the platform-wide dashboard too. When
  // either condition fails, this falls back to exactly the original
  // combined Sent/Failed area chart — zero visual change for those cases.
  const byRecipientType = charts?.daily_by_recipient_type ?? null;
  // Group Module Blank-Screen Fix, disclosed: byRecipientType itself can be truthy while its own .group is null (Module 2 Fix suppresses just that key for the RESOLVED account, not the whole object — see the .group?./.individual?. fix above) — checking .group here too is what makes the byRecipientType!.group[index] access below actually safe.
  const canShowRecipientSeries = Boolean(byRecipientType?.individual) && Boolean(byRecipientType?.group) && hasModule('contact_groups');

  const recipientData = canShowRecipientSeries
    ? byRecipientType!.individual.map((individualDay, index) => {
        // TS2531 fix, disclosed: canShowRecipientSeries already guarantees
        // byRecipientType.group is non-null at runtime (that's the whole
        // point of checking Boolean(byRecipientType?.group) above), but
        // the compiler can't carry that boolean-flag narrowing through a
        // member-expression access inside this .map() callback — hence
        // TS2531 "Object is possibly null" on `byRecipientType!.group[index]`
        // despite the `!` already asserting byRecipientType itself.
        // Capturing .group in its own const the ternary's condition
        // already makes safe, with a `?? []` fallback that's a type-level
        // safety net only (never hit at runtime here), resolves it.
        const groupDay = (byRecipientType!.group ?? [])[index];
        return {
          date: individualDay.date.slice(5),
          'Individual Sent': individualDay.sent,
          'Individual Failed': individualDay.failed,
          'Group Sent': groupDay.sent,
          'Group Failed': groupDay.failed,
        };
      })
    : [];
  const combinedData = (charts?.daily ?? []).map((d) => ({ date: d.date.slice(5), Sent: d.sent, Failed: d.failed }));

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
        ) : canShowRecipientSeries ? (
          <ResponsiveContainer width="100%" height="100%">
            <ComposedChart data={recipientData}>
              <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" vertical={false} />
              <XAxis dataKey="date" tick={{ fontSize: 11, fill: '#94a3b8' }} axisLine={false} tickLine={false} />
              <YAxis allowDecimals={false} tick={{ fontSize: 11, fill: '#94a3b8' }} axisLine={false} tickLine={false} width={28} />
              <Tooltip />
              <Line type="monotone" dataKey="Individual Sent" stroke="#4F46E5" strokeWidth={2.5} dot={false} />
              <Line type="monotone" dataKey="Individual Failed" stroke="#ef4444" strokeWidth={2} dot={false} />
              <Line type="monotone" dataKey="Group Sent" stroke="#7C3AED" strokeWidth={2.5} strokeDasharray="6 4" dot={false} />
              <Line type="monotone" dataKey="Group Failed" stroke="#F97316" strokeWidth={2} strokeDasharray="6 4" dot={false} />
            </ComposedChart>
          </ResponsiveContainer>
        ) : (
          <ResponsiveContainer width="100%" height="100%">
            <AreaChart data={combinedData}>
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
      {canShowRecipientSeries && (
        <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs" style={{ color: indigo.muted }}>
          <span className="flex items-center gap-1.5">
            <span className="h-0.5 w-4 rounded-full" style={{ background: '#4F46E5' }} /> Individual Sent
          </span>
          <span className="flex items-center gap-1.5">
            <span className="h-0.5 w-4 rounded-full" style={{ background: '#ef4444' }} /> Individual Failed
          </span>
          <span className="flex items-center gap-1.5">
            <span className="h-0.5 w-4 rounded-full border-t-2 border-dashed" style={{ borderColor: '#7C3AED' }} /> Group Sent
          </span>
          <span className="flex items-center gap-1.5">
            <span className="h-0.5 w-4 rounded-full border-t-2 border-dashed" style={{ borderColor: '#F97316' }} /> Group Failed
          </span>
        </div>
      )}
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
  // Quota Exhaustion Request Workflow — "Request Extra Quota" button.
  // Self-contained modal/toast state (this card is a plain function
  // component with no parent-owned state today, matching the pattern
  // SocialAdsSummaryCards below already uses for its own self-contained
  // fetch state). Hooks must run before the `if (!subscription) return
  // null;` guard below (Rules of Hooks), so they're declared first.
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [toast, setToast] = useState<string | null>(null);

  if (!subscription) return null;

  const planLabel = `${subscription.billing_model.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())} (${subscription.engine_type.toUpperCase()})`;
  const renewalDate = new Date(subscription.expires_at).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
  const percent = quotaPercentUsed ?? 0;
  const barColor = percent >= 90 ? '#DC2626' : percent >= 70 ? '#D97706' : '#0E9F6E';

  // Conditional Quota Top-Up Button — server-mirrored gate (see
  // QuotaRequestController::store()): flat_quota (a finite
  // total_allocated_messages to run out of) and per_message (a rupee
  // wallet that can run low) both qualify; 'unlimited' never does.
  // quotaPercentUsed is used/total_allocated_messages for BOTH models —
  // for per_message that's used_messages / floor(price_paid / rate),
  // which tracks wallet-percent-used closely enough that no separate
  // rupee-based percentage is needed just to gate this button. Shown at
  // 90%+ usage (spec: "reaches 90% or 100%").
  const canRequestTopUp =
    (subscription.billing_model === 'flat_quota' || subscription.billing_model === 'per_message') &&
    quotaPercentUsed !== null &&
    quotaPercentUsed >= 90;

  const handleSubmitted = (message: string) => {
    setIsModalOpen(false);
    setToast(message);
    setTimeout(() => setToast(null), 4000);
  };

  return (
    <>
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

        {/* Wallet Visibility, disclosed: this is the Client Admin's OWN
            view of the same Spent/Balance breakdown Super Admin/Agent see
            in Manage Clients and Client Billing Summary. Balance is
            amount-based (price_paid - spent), not derived from the
            message-count quota (total_allocated_messages is
            floor(price_paid / rate), kept for send-gating only) — deriving
            Balance from that quota instead loses the floor-rounding
            remainder from the rupee figure the admin reads as their
            actual wallet balance. Computed from this card's own
            `subscription` prop rather than a separate request. Only
            rendered for 'per_message' (the only model with a real
            rate_per_message). */}
        {subscription.billing_model === 'per_message' && subscription.rate_per_message !== null && (
          <div className="mt-2 flex flex-wrap gap-x-4 gap-y-0.5 text-xs" style={{ color: indigo.muted }}>
            <span>Spent: {formatINR(subscription.used_messages * Number(subscription.rate_per_message))}</span>
            <span>
              Balance:{' '}
              {formatINR(
                Math.max(
                  Number(subscription.price_paid) - subscription.used_messages * Number(subscription.rate_per_message),
                  0,
                ),
              )}
            </span>
            <span>Rate: {formatINR(subscription.rate_per_message)}/msg</span>
          </div>
        )}

        {canRequestTopUp && (
          <button
            onClick={(e) => {
              // The card itself is a <Link to="/billing"> — without
              // these, clicking this button would also navigate away
              // before the modal ever opens.
              e.preventDefault();
              e.stopPropagation();
              setIsModalOpen(true);
            }}
            className="mt-4 flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:opacity-90"
            style={{ background: barColor }}
          >
            <Zap className="h-3.5 w-3.5" />
            {subscription.billing_model === 'per_message' ? 'Add Funds' : 'Request Extra Quota'}
          </button>
        )}
      </Link>

      {isModalOpen && (
        <QuotaTopUpModal
          billingModel={subscription.billing_model === 'per_message' ? 'per_message' : 'flat_quota'}
          onClose={() => setIsModalOpen(false)}
          onSubmitted={handleSubmitted}
        />
      )}

      {toast && (
        <div className="fixed bottom-6 right-6 z-50 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 shadow-lg">
          {toast}
        </div>
      )}
    </>
  );
}

/**
 * Dynamic Permission & Module-Based Dashboard — Social Media widget set.
 * Self-contained fetch, same pattern as DailyPulseChart/RevenuePulseChart
 * above: reuses GET /api/social/reports/summary (SocialReportsPage's own
 * data source — see reportsService.ts), so no new backend endpoint was
 * needed for this. Rendered only when the account's module_assignment is
 * 'social_media' or 'both' AND the caller holds view-social-analytics
 * (the same permission SocialReportsPage itself requires) — a user
 * without that permission never triggers this request.
 */
function SocialAdsSummaryCards() {
  const [summary, setSummary] = useState<SocialReportSummary | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [loadError, setLoadError] = useState(false);

  const load = useCallback(async () => {
    setIsLoading(true);
    setLoadError(false);
    try {
      const data = await reportsService.getSummary();
      setSummary(data);
    } catch {
      setLoadError(true);
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  if (loadError) return null;

  return (
    <div>
      <h3 className="mb-3 font-display text-sm font-bold" style={{ color: indigo.ink }}>
        Social Media &amp; Meta Ads — This Month
      </h3>
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard
          label="Total Ad Spend"
          value={isLoading ? '…' : formatINR(summary?.total_ad_spend ?? 0)}
          sub={summary?.period.label}
          icon={Megaphone}
          tint={NAV_TINTS.chatbot}
        />
        <StatCard
          label="Leads Generated"
          value={isLoading ? '…' : String(summary?.total_leads_generated ?? 0)}
          sub="Instant Lead Bridge, this month"
          icon={Target}
          tint={NAV_TINTS.team}
        />
        <StatCard
          label="Average CPL"
          value={isLoading ? '…' : summary?.average_cpl != null ? formatINR(summary.average_cpl) : 'N/A'}
          sub="Cost per Meta-attributed lead"
          icon={TrendingUp}
          tint={NAV_TINTS.analytics}
        />
        <StatCard
          label="Combined Impressions"
          value={isLoading ? '…' : String(summary?.combined_impressions ?? 0)}
          sub="Ad views across active campaigns"
          icon={Eye}
          tint={NAV_TINTS.send}
        />
      </div>
    </div>
  );
}

/**
 * 3-Tier Hierarchy & Agent-Client Scope Engine (Phase 3 UI, requirement
 * 4) — an Agent's own Dashboard additionally shows this aggregated
 * overview of their managed Sub-Clients, on top of (not instead of) the
 * ordinary TenantDashboard widgets below (an Agent's own account still
 * has its own WhatsApp/Analytics/etc. modules, gated exactly like any
 * other tenant's).
 *
 * [Disclosed]: sourced entirely client-side from the existing GET
 * /api/admin/accounts (AccountController::index(), already forced to
 * this Agent's own ownedByAgent() scope server-side since Phase 1) —
 * this phase adds no dedicated aggregate endpoint, per its stated
 * frontend-only scope. Requested at per_page=100 (that endpoint's own
 * ceiling) to approximate "every Sub-Client" without one; an Agent with
 * more than 100 Sub-Clients will see an undercount here (the "Total
 * Sub-Clients" card still shows the real, non-paginated total from the
 * API — only "Combined Message Pulse" and "Quota Health" are capped to
 * the first 100 rows, and the sub-label says so). A real fix would add
 * a lightweight backend summary endpoint mirroring
 * AnalyticsController::globalSummary() but scoped ownedByAgent() —
 * flagged for a future phase, not built here.
 */
function AgentSubClientsOverview() {
  const navigate = useNavigate();
  const [accounts, setAccounts] = useState<Account[]>([]);
  const [total, setTotal] = useState(0);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setIsLoading(true);
      setError(null);
      try {
        const res = await accountService.list({ per_page: 100 });
        if (cancelled) return;
        setAccounts(res.data);
        setTotal(res.total);
      } catch (err) {
        if (!cancelled) setError(extractMessage(err, 'Failed to load your Sub-Clients.'));
      } finally {
        if (!cancelled) setIsLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  const combinedUsed = accounts.reduce((sum, a) => sum + (a.current_subscription?.used_messages ?? 0), 0);
  const combinedAllocated = accounts.reduce(
    (sum, a) => sum + (a.current_subscription?.total_allocated_messages ?? 0),
    0,
  );
  const atRiskCount = accounts.filter((a) => {
    const s = a.current_subscription;
    if (!s || s.billing_model === 'unlimited' || s.total_allocated_messages == null) return false;
    return s.used_messages / s.total_allocated_messages >= 0.8;
  }).length;
  const capped = total > accounts.length;

  return (
    <div>
      <h3 className="mb-3 font-display text-sm font-bold" style={{ color: indigo.ink }}>
        Your Sub-Clients
      </h3>
      {error && (
        <div className="mb-3 flex items-center gap-2 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
          <XCircle className="h-4 w-4 flex-shrink-0" />
          {error}
        </div>
      )}
      <div className="grid gap-4 sm:grid-cols-3">
        <StatCard
          label="Total Sub-Clients"
          value={isLoading ? '…' : String(total)}
          sub="Provisioned under your agent account"
          icon={Building2}
          tint={NAV_TINTS.accounts}
          onClick={() => navigate('/admin/accounts')}
        />
        <StatCard
          label="Combined Message Pulse"
          value={isLoading ? '…' : combinedUsed.toLocaleString()}
          sub={
            isLoading
              ? undefined
              : `of ${combinedAllocated.toLocaleString()} allocated${capped ? ` (first ${accounts.length} of ${total})` : ''}`
          }
          icon={MessageSquare}
          tint={NAV_TINTS.send}
        />
        <StatCard
          label="Quota Health"
          value={isLoading ? '…' : `${atRiskCount} at risk`}
          sub={`Sub-clients at/above 80% of quota${capped ? ` (first ${accounts.length} of ${total})` : ''}`}
          icon={CreditCard}
          tint={NAV_TINTS.billing}
        />
      </div>
    </div>
  );
}

function TenantDashboard({ impersonatedAccountName }: { impersonatedAccountName?: string } = {}) {
  const { user, hasPermission, hasModule } = useAuth();
  const navigate = useNavigate();
  const canViewAnalytics = hasPermission('view-analytics');
  const canViewLogs = hasPermission('view-logs');
  const canSendAlerts = hasPermission('send-messages');
  const canManageChatbot = hasPermission('manage-chatbot');
  const canManageBilling = hasPermission('manage-subscriptions');
  const canManageTeam = hasPermission('manage-team');
  const canViewSocialAnalytics = hasPermission('view-social-analytics');

  // Dynamic Permission & Module-Based Dashboard — module_assignment is
  // DISTINCT from the granular allowed_modules sidebar checklist (see
  // Account::MODULE_ASSIGNMENTS' docblock on the backend and this
  // refactor's audit report): it drives ONLY which widget SET renders
  // here. Defaults to 'both' for every account created before this
  // column existed (zero-regression — 'both' shows everything this page
  // already showed).
  const moduleAssignment: ModuleAssignment = user?.account?.module_assignment ?? 'both';
  const showWhatsApp = moduleAssignment !== 'social_media';
  // [Bugfix, disclosed]: module_assignment/canViewSocialAnalytics alone
  // do not reflect the tenant's actual per-module toggle — an admin can
  // disable `allowed_modules.reports` (the same slug the sidebar's
  // "Social Reports" nav item and its ProtectedRoute both already gate
  // on) while module_assignment stays 'both' and the user still holds
  // view-social-analytics. Without hasModule('reports') here, this
  // Dashboard kept rendering the "Social Media & Meta Ads" summary and
  // the "View Social Reports" quick action even after that module was
  // disabled — confirmed root cause of that report. 'reports' (not
  // 'social_accounts') because both widgets mirror /social/reports'
  // own data and its own module gate exactly, the same "nav item's
  // requiresModule matches its destination route's module" convention
  // this codebase already uses everywhere else.
  const showSocial = moduleAssignment !== 'whatsapp_messaging' && canViewSocialAnalytics && hasModule('reports');

  const subscription = user?.account?.current_subscription ?? null;

  const [summary, setSummary] = useState<AnalyticsSummary | null>(null);
  const [summaryError, setSummaryError] = useState<string | null>(null);
  const [isLoadingSummary, setIsLoadingSummary] = useState(canViewAnalytics);

  const [logs, setLogs] = useState<MessageDispatchLog[]>([]);
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
      // [Bugfix, disclosed]: sources message_dispatch_logs (every
      // dispatch pathway) instead of the old payment_alerts-only
      // analyticsService.getLogs() — see the widget's own comment below
      // for the full root-cause explanation. Already newest-first
      // (MessageDispatchLogController::index() -> latest('id')).
      const res = await messageLogsService.list(1, 5, {});
      setLogs(res.data);
    } catch (err) {
      setLogsError(extractMessage(err, 'Failed to load recent messages.'));
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

  // [Disclosed, relocated]: the "refresh the cached account on every
  // visit" fix previously lived here as a Dashboard-only mount effect.
  // Moved to AppLayout.tsx (the persistent layout shell every page
  // renders inside, per its own comment) so it covers every
  // module-gated sidebar item, not only the widgets this one page
  // happens to render.

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

        {/* 3-Tier Hierarchy & Agent-Client Scope Engine (Phase 3 UI,
            requirement 4) — an Agent's own account_type, never an
            impersonated Super-Admin view (impersonatedAccountName is
            only ever set for Super Admin — see DashboardPage's own
            selection logic below). */}
        {user?.account?.account_type === 'agent' && <AgentSubClientsOverview />}

        {summaryError && (
          <div className="flex items-center gap-2 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            <XCircle className="h-4 w-4 flex-shrink-0" />
            {summaryError}
          </div>
        )}

        {/* Dynamic Permission & Module-Based Dashboard — WhatsApp Only / Both. Every card here is WhatsApp message/engine/quota data, hidden entirely for a 'social_media' client. */}
        {showWhatsApp && (
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
        )}

        {/*
          Group Messaging Phase 5 — Dashboard Analytics Upgrade. Individual
          Messages is shown alongside every other WhatsApp KPI above
          (canViewAnalytics is the only gate — every tenant sends
          individually). Group Messages / Active Contact Groups are
          additionally gated on hasModule('contact_groups') — Group
          Messaging is a paid addon (see ContactGroupsPage's own locked-
          card gating), so a tenant without it sees no group-shaped KPI
          cards at all rather than a permanent "0" advertising a feature
          they don't have, consistent with how the rest of this Dashboard
          hides WhatsApp-only or Social-only cards by module/assignment.
        */}
        {showWhatsApp && canViewAnalytics && (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <StatCard
              label="Individual Messages"
              value={isLoadingSummary ? '…' : String(summary?.recipient_breakdown?.individual?.sent ?? 0)}
              sub={isLoadingSummary ? undefined : `${summary?.recipient_breakdown?.individual?.failed ?? 0} failed · last 30 days`}
              icon={Send}
              tint={NAV_TINTS.send}
            />
            {hasModule('contact_groups') && (
              <>
                <StatCard
                  label="Group Messages"
                  value={isLoadingSummary ? '…' : String(summary?.recipient_breakdown?.group?.sent ?? 0)}
                  sub={isLoadingSummary ? undefined : `${summary?.recipient_breakdown?.group?.failed ?? 0} failed · last 30 days`}
                  icon={Users}
                  tint={NAV_TINTS.whatsapp}
                />
                <StatCard
                  label="Active Contact Groups"
                  value={isLoadingSummary ? '…' : String(summary?.active_contact_groups ?? 0)}
                  sub="Custom contact groups"
                  icon={Contact}
                  tint={NAV_TINTS.analytics}
                  onClick={() => navigate('/contact-groups')}
                />
              </>
            )}
          </div>
        )}

        {/*
          Dashboard & Analytics Fix Round 2 — "Today's Group & Individual
          Sent/Failed Breakdown". Same gating split as the period-level
          cards above: Today's Individual Messages is shown to every
          WhatsApp-enabled tenant, Today's Group Messages is additionally
          gated on hasModule('contact_groups').
        */}
        {showWhatsApp && canViewAnalytics && (
          <div className="grid gap-4 sm:grid-cols-2">
            <StatCard
              label="Today's Individual Messages"
              value={isLoadingSummary ? '…' : `${summary?.today_breakdown?.today_individual_sent ?? 0} sent`}
              sub={isLoadingSummary ? undefined : `${summary?.today_breakdown?.today_individual_failed ?? 0} failed today`}
              icon={Send}
              tint={NAV_TINTS.send}
            />
            {hasModule('contact_groups') && (
              <StatCard
                label="Today's Group Messages"
                value={isLoadingSummary ? '…' : `${summary?.today_breakdown?.today_group_sent ?? 0} sent`}
                sub={isLoadingSummary ? undefined : `${summary?.today_breakdown?.today_group_failed ?? 0} failed today`}
                icon={Users}
                tint={NAV_TINTS.whatsapp}
              />
            )}
          </div>
        )}

        {/* Dynamic Permission & Module-Based Dashboard — Social Media Only / Both. */}
        {showSocial && <SocialAdsSummaryCards />}

        <SubscriptionHealthCard subscription={subscription} quotaPercentUsed={quota?.quota_percent_used ?? null} quotaLabel={quotaSub ?? quotaLabel} />

        {showWhatsApp && canViewAnalytics && <DailyPulseChart />}

        <div>
          <h3 className="mb-3 font-display text-sm font-bold" style={{ color: indigo.ink }}>
            Quick Actions
          </h3>
          <div className="grid gap-3 sm:grid-cols-3">
            {showWhatsApp && canSendAlerts && <QuickAction to="/alerts/send" label="Send Payment Alert" icon={Send} tint={NAV_TINTS.send} />}
            {canManageTeam && <QuickAction to="/users" label="Manage Team" icon={Users} tint={NAV_TINTS.team} />}
            {canManageBilling && <QuickAction to="/billing" label="Upgrade / Renew Plan" icon={CreditCard} tint={NAV_TINTS.billing} />}
            {showWhatsApp && canManageChatbot && <QuickAction to="/chatbot" label="Manage Chatbot" icon={Bot} tint={NAV_TINTS.chatbot} />}
            {showSocial && <QuickAction to="/social/reports" label="View Social Reports" icon={Megaphone} tint={NAV_TINTS.chatbot} />}
          </div>
        </div>

        {showWhatsApp && (
        <div className="overflow-hidden rounded-2xl border bg-white" style={{ borderColor: indigo.border, boxShadow: cardShadow }}>
          <div className="flex items-center justify-between px-5 py-3.5" style={{ borderBottom: `1px solid ${indigo.border}` }}>
            <div>
              {/*
                [Bugfix, disclosed]: this widget now sources
                message_dispatch_logs via messageLogsService (every
                dispatch pathway — web, template, chatbot, journey, API)
                instead of the old payment_alerts-only
                analyticsService.getLogs() / /alerts/logs. That table was,
                and always was, scoped to payment_alerts only (see
                MessageLogController's own docblock) — a Send Template /
                Chatbot / Journey / API send never wrote a payment_alerts
                row, so this list correctly stayed unchanged when only
                those pathways were used. That was the confirmed root
                cause of "stale old entry, new sends not appearing" here,
                not a stale query or a missing refresh — the query was
                already `latest('id')`, i.e. newest-first, and still is.
              */}
              <h3 className="font-display text-sm font-bold" style={{ color: indigo.ink }}>
                Recent Message Logs
              </h3>
            </div>
            {canViewLogs && (
              <Link to="/message-logs" className="text-xs font-semibold" style={{ color: indigo.accentSolid }}>
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
              No messages sent yet.
            </p>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y text-sm" style={{ borderColor: indigo.border }}>
                <thead style={{ background: '#FAFAFF' }}>
                  <tr>
                    <th className="px-5 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wide" style={{ color: indigo.muted }}>
                      Recipient
                    </th>
                    <th className="px-5 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wide" style={{ color: indigo.muted }}>
                      Source / Template
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
                      <td className="px-5 py-2.5 font-mono text-xs" style={{ color: indigo.ink }}>
                        {log.recipient_phone}
                      </td>
                      <td className="px-5 py-2.5" style={{ color: indigo.ink }}>
                        <div className="text-xs font-semibold">{SOURCE_LABEL[log.source]}</div>
                        {log.template_name && (
                          <div className="text-xs" style={{ color: indigo.muted }}>{log.template_name}</div>
                        )}
                      </td>
                      <td className="px-5 py-2.5">
                        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ${STATUS_BADGE[log.status]}`}>
                          {log.status}
                        </span>
                      </td>
                      <td className="px-5 py-2.5" style={{ color: indigo.muted }}>
                        {new Date(log.created_at).toLocaleString()}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
        )}
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
  const { user, hasModule } = useAuth();
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

  // Super Admin Dashboard Group KPI Fix (2026-09-16) — computeGlobalSummary()
  // (backing `summary`/getGlobalSummary() above) has no group-messaging
  // fields at all. The correct, already-existing recipient-aware group
  // figures live on GET /api/analytics/summary instead (via
  // recipient_breakdown/today_breakdown/active_contact_groups) — calling
  // it with no ?account_id= resolves to the same global ($account === null)
  // scope Super Admin's platform view already uses elsewhere. Fetched
  // independently from `summary` above so one failing doesn't block the
  // other, matching this file's existing per-section fetch convention
  // (see DailyPulseChart/RevenuePulseChart).
  const [groupSummary, setGroupSummary] = useState<AnalyticsSummary | null>(null);
  const [isLoadingGroups, setIsLoadingGroups] = useState(true);

  const loadGroupSummary = useCallback(async () => {
    setIsLoadingGroups(true);
    try {
      const data = await analyticsService.getSummary();
      setGroupSummary(data);
    } catch {
      setGroupSummary(null);
    } finally {
      setIsLoadingGroups(false);
    }
  }, []);

  useEffect(() => {
    void loadGroupSummary();
  }, [loadGroupSummary]);

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

        {/*
          Super Admin Dashboard Group KPI Fix (2026-09-16) — same
          hasModule('contact_groups') gate TenantDashboard's own group
          cards already use (Super Admin always passes, per
          AuthContext.hasModule()'s own rule, so this renders
          unconditionally for a real Super Admin — the gate is kept for
          consistency with the rest of this codebase's module-gating
          convention rather than because it can ever be false here).
          Sourced from groupSummary (GET /api/analytics/summary, global
          scope) — see the fetch above for why this is a separate call
          from `summary`.
        */}
        {hasModule('contact_groups') && (
          <div>
            <h3 className="mb-3 font-display text-sm font-bold" style={{ color: indigo.ink }}>
              Group Messaging
            </h3>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
              <StatCard
                label="Total Groups Added"
                value={isLoadingGroups ? '…' : String(groupSummary?.active_contact_groups ?? 0)}
                sub="Platform-wide"
                icon={Contact}
                tint={NAV_TINTS.analytics}
              />
              <StatCard
                label="Total Group Messages"
                value={isLoadingGroups ? '…' : String(groupSummary?.recipient_breakdown?.group?.sent ?? 0)}
                sub="Across the platform · last 30 days"
                icon={Users}
                tint={NAV_TINTS.whatsapp}
              />
              <StatCard
                label="Total Failed Group Messages"
                value={isLoadingGroups ? '…' : String(groupSummary?.recipient_breakdown?.group?.failed ?? 0)}
                sub="Last 30 days"
                icon={XCircle}
                tint={FAILED_TINT}
              />
              <StatCard
                label="Today's Group Messages"
                value={isLoadingGroups ? '…' : String(groupSummary?.today_breakdown?.today_group_sent ?? 0)}
                sub="Since midnight, server time"
                icon={Users}
                tint={NAV_TINTS.whatsapp}
              />
              <StatCard
                label="Today's Failed Group Messages"
                value={isLoadingGroups ? '…' : String(groupSummary?.today_breakdown?.today_group_failed ?? 0)}
                sub="Since midnight, server time"
                icon={XCircle}
                tint={FAILED_TINT}
              />
            </div>
          </div>
        )}

        <DailyPulseChart />

        <div>
          <h3 className="mb-3 font-display text-sm font-bold" style={{ color: indigo.ink }}>
            Quick Actions
          </h3>
          <div className="grid gap-3 sm:grid-cols-2">
            <QuickAction to="/admin/accounts" label="Manage Clients" icon={Building2} tint={NAV_TINTS.accounts} />
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
