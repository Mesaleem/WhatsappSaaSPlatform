import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import {
  AlertTriangle,
  ArrowRight,
  Bot,
  Building2,
  CalendarClock,
  FileClock,
  IndianRupee,
  Inbox,
  Layers,
  Megaphone,
  Sparkles,
  TrendingUp,
  UserSearch,
  Workflow,
  Zap,
  type LucideIcon,
} from 'lucide-react';
import { useTenant } from '../../core/context/TenantContext';
import dashboardService, { type AgentOverview, type DashboardOverview, type PlatformOverview, type TenantOverview } from '../../services/dashboardService';
import { NAV_TINTS, cardOutline, cardShadow, indigo, type Tint } from '../../theme/signalIndigo';

/**
 * Role- and plan-aware Dashboard widgets fed by GET /api/dashboard/overview
 * (one request). Super Admin: what needs attention, clients per plan, busiest
 * clients. Agent: its business. Client: only what its plan includes.
 */

const DANGER: Tint = { bg: '#DC262624', fg: '#DC2626' };
const WARN: Tint = { bg: '#D9770624', fg: '#B45309' };

const fmt = (n: number) => n.toLocaleString('en-IN');
const inr = (n: number) => `₹${n.toLocaleString('en-IN', { maximumFractionDigits: 2 })}`;

/** Fetches the overview; re-fetches when the header's client switcher changes. */
export function useDashboardOverview() {
  const { selectedAccountId } = useTenant();
  const [data, setData] = useState<DashboardOverview | null>(null);
  const [error, setError] = useState(false);

  const load = useCallback(() => {
    setError(false);
    dashboardService
      .overview()
      .then(setData)
      .catch(() => setError(true));
  }, []);

  useEffect(() => {
    setData(null);
    load();
  }, [load, selectedAccountId]);

  return { data, error, reload: load };
}

function Panel({ title, action, children }: { title: string; action?: ReactNode; children: ReactNode }) {
  return (
    <section className="rounded-2xl border bg-white p-5" style={{ borderColor: cardOutline, boxShadow: cardShadow }}>
      <div className="mb-4 flex items-center justify-between gap-3">
        <h3 className="text-sm font-semibold" style={{ color: indigo.ink }}>{title}</h3>
        {action}
      </div>
      {children}
    </section>
  );
}

/** A compact number tile; clickable when it leads somewhere. */
function Tile({ label, value, sub, icon: Icon, tint, to, onClick, emphasize }: {
  label: string;
  value: string;
  sub?: string;
  icon: LucideIcon;
  tint: Tint;
  to?: string;
  onClick?: () => void;
  emphasize?: boolean;
}) {
  const body = (
    <div className="flex items-start justify-between gap-3">
      <div className="min-w-0">
        <p className="text-[11px] font-semibold uppercase tracking-wide" style={{ color: indigo.muted }}>{label}</p>
        <p className={`mt-2 font-mono text-2xl font-semibold ${emphasize ? 'text-red-600' : ''}`} style={emphasize ? undefined : { color: indigo.ink }}>{value}</p>
        {sub && <p className="mt-1 text-xs" style={{ color: indigo.muted }}>{sub}</p>}
      </div>
      <span aria-hidden="true" className="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl" style={{ background: tint.bg, color: tint.fg }}>
        <Icon className="h-4 w-4" />
      </span>
    </div>
  );
  const cls = 'block w-full rounded-2xl border bg-white p-5 text-left transition';
  const style = { borderColor: cardOutline, boxShadow: cardShadow };
  if (to) return <Link to={to} className={`${cls} hover:-translate-y-0.5`} style={style}>{body}</Link>;
  if (onClick) return <button type="button" onClick={onClick} className={`${cls} hover:-translate-y-0.5`} style={style}>{body}</button>;
  return <div className={cls} style={style}>{body}</div>;
}

// ---- Super Admin ------------------------------------------------------------------

export function PlatformAttention({ platform, onOpenExpiring }: { platform: PlatformOverview; onOpenExpiring: () => void }) {
  const a = platform.attention;
  const total = a.pending_invoices + a.quota_requests + a.contact_messages + a.expiring_7d;
  return (
    <div>
      <div className="mb-3 flex items-center gap-2">
        <h2 className="text-sm font-semibold" style={{ color: indigo.ink }}>Needs attention</h2>
        <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${total > 0 ? 'bg-amber-100 text-amber-800' : 'bg-emerald-50 text-emerald-700'}`}>
          {total > 0 ? `${total} open` : 'All clear'}
        </span>
      </div>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Tile label="Pending invoices" value={fmt(a.pending_invoices)} sub="Waiting for payment" icon={FileClock} tint={a.pending_invoices ? WARN : NAV_TINTS.billing} to="/billing" />
        <Tile label="Quota top-up requests" value={fmt(a.quota_requests)} sub="Waiting for approval" icon={Zap} tint={a.quota_requests ? WARN : NAV_TINTS.chatbot} to="/admin/quota-requests" />
        <Tile label="Website messages" value={fmt(a.contact_messages)} sub="From the contact form" icon={Inbox} tint={a.contact_messages ? WARN : NAV_TINTS.send} to="/admin/website" />
        <Tile label="Expiring in 7 days" value={fmt(a.expiring_7d)} sub="Plans to renew" icon={CalendarClock} tint={a.expiring_7d ? DANGER : NAV_TINTS.team} onClick={onOpenExpiring} emphasize={a.expiring_7d > 0} />
      </div>
    </div>
  );
}

export function PlatformPlansAndTopClients({ platform }: { platform: PlatformOverview }) {
  const max = Math.max(1, ...platform.plan_split.map((p) => p.clients));
  const totalClients = platform.plan_split.reduce((s, p) => s + p.clients, 0);
  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <Panel title="Clients per plan" action={<span className="text-xs" style={{ color: indigo.muted }}>{fmt(totalClients)} clients</span>}>
        <ul className="space-y-3" data-testid="plan-split">
          {platform.plan_split.map((p) => (
            <li key={p.plan_key ?? 'none'}>
              <div className="flex items-center justify-between text-sm">
                <span className="flex items-center gap-2" style={{ color: indigo.ink }}>
                  <Layers className="h-3.5 w-3.5" style={{ color: indigo.muted }} /> {p.label}
                </span>
                <span className="font-mono font-semibold" style={{ color: indigo.ink }}>{fmt(p.clients)}</span>
              </div>
              <div className="mt-1.5 h-2 rounded-full bg-slate-100">
                <div className="h-2 rounded-full bg-emerald-500" style={{ width: `${(p.clients / max) * 100}%` }} />
              </div>
            </li>
          ))}
        </ul>
      </Panel>
      <Panel title="Busiest clients — last 30 days" action={<Link to="/admin/accounts" className="text-xs font-semibold text-emerald-700 hover:underline">All clients</Link>}>
        {platform.top_clients.length === 0 ? (
          <p className="py-6 text-center text-sm" style={{ color: indigo.muted }}>No messages in the last 30 days.</p>
        ) : (
          <ol className="divide-y divide-slate-100">
            {platform.top_clients.map((c, i) => (
              <li key={c.account_id} className="flex items-center gap-3 py-2.5 text-sm">
                <span className="flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full bg-emerald-50 text-xs font-bold text-emerald-700">{i + 1}</span>
                <span className="min-w-0 flex-1 truncate" style={{ color: indigo.ink }}>{c.company_name}</span>
                <span className="font-mono text-xs font-semibold" style={{ color: indigo.muted }}>{fmt(c.messages_30d)} msgs</span>
              </li>
            ))}
          </ol>
        )}
      </Panel>
    </div>
  );
}

// ---- Agent ------------------------------------------------------------------------

export function AgentBusinessOverview({ agent }: { agent: AgentOverview }) {
  return (
    <div className="space-y-4" data-testid="agent-overview">
      <h2 className="text-sm font-semibold" style={{ color: indigo.ink }}>Your business</h2>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Tile label="My clients" value={fmt(agent.clients_total)} sub={`${fmt(agent.clients_active)} active`} icon={Building2} tint={NAV_TINTS.accounts} to="/admin/accounts" />
        <Tile label="Commission this month" value={inr(agent.commission_month)} sub={`${inr(agent.commission_total)} all time`} icon={IndianRupee} tint={NAV_TINTS.billing} />
        <Tile label="Client messages" value={fmt(agent.messages_30d)} sub="Last 30 days, all clients" icon={TrendingUp} tint={NAV_TINTS.send} />
        <Tile label="Pending invoices" value={fmt(agent.pending_invoices)} sub="Your clients, unpaid" icon={FileClock} tint={agent.pending_invoices ? WARN : NAV_TINTS.chatbot} to="/billing" />
      </div>
      {agent.expiring.length > 0 && (
        <Panel title="Clients whose plan ends in 7 days">
          <ul className="divide-y divide-slate-100">
            {agent.expiring.map((c) => (
              <li key={c.account_id} className="flex items-center justify-between gap-3 py-2.5 text-sm">
                <span className="flex min-w-0 items-center gap-2" style={{ color: indigo.ink }}>
                  <AlertTriangle className="h-4 w-4 flex-shrink-0 text-amber-600" />
                  <span className="truncate">{c.company_name}</span>
                </span>
                <span className="text-xs" style={{ color: indigo.muted }}>
                  {c.expires_at ? new Date(c.expires_at).toLocaleDateString('en-IN', { day: '2-digit', month: 'short' }) : '—'}
                </span>
              </li>
            ))}
          </ul>
        </Panel>
      )}
    </div>
  );
}

// ---- Client (plan-aware) ----------------------------------------------------------

/** Only the widgets this client's plan includes. Renders nothing for a plan with none of them. */
export function PlanFeatureWidgets({ tenant }: { tenant: TenantOverview }) {
  const tiles: ReactNode[] = [];
  if (tenant.crm) {
    tiles.push(<Tile key="crm" label="CRM leads" value={fmt(tenant.crm.total)} sub={`${fmt(tenant.crm.new)} new · ${fmt(tenant.crm.converted)} converted`} icon={UserSearch} tint={NAV_TINTS.social} to="/crm/leads" />);
  }
  if (tenant.journeys) {
    tiles.push(<Tile key="journeys" label="Journeys" value={`${fmt(tenant.journeys.active)} live`} sub={`${fmt(tenant.journeys.running)} chats running · ${fmt(tenant.journeys.completed_30d)} done (30d)`} icon={Workflow} tint={NAV_TINTS.chatbot} to="/chatbot/journeys" />);
  }
  if (tenant.ai) {
    tiles.push(<Tile key="ai" label="AI credits" value={fmt(tenant.ai.available)} sub="Available to use" icon={tenant.ai.available > 0 ? Sparkles : Bot} tint={NAV_TINTS.analytics} emphasize={tenant.ai.available === 0} />);
  }
  if (tenant.ads) {
    tiles.push(<Tile key="ads" label="Ad spend (30d)" value={inr(tenant.ads.spend_30d)} sub={`${fmt(tenant.ads.leads_30d)} leads${tenant.ads.cpl_30d !== null ? ` · ${inr(tenant.ads.cpl_30d)} per lead` : ''}`} icon={Megaphone} tint={NAV_TINTS.send} to="/social/ads/dashboard" />);
  }
  if (tiles.length === 0) return null;

  return (
    <div data-testid="plan-widgets">
      <div className="mb-3 flex flex-wrap items-center gap-2">
        <h2 className="text-sm font-semibold" style={{ color: indigo.ink }}>In your plan</h2>
        {tenant.plan && <span className="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700">{tenant.plan.label}</span>}
      </div>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">{tiles}</div>
    </div>
  );
}

/** A nudge for plans without CRM/Journeys/AI: what upgrading unlocks. */
export function UpgradeHint({ tenant }: { tenant: TenantOverview }) {
  const missing = [
    !tenant.capabilities.crm && 'CRM',
    !tenant.capabilities.journey_automation && 'Journey Builder',
    !tenant.capabilities.ai && 'AI replies',
    !tenant.capabilities.ads && 'Meta Ads',
  ].filter(Boolean) as string[];
  if (missing.length === 0) return null;
  return (
    <Link
      to="/billing"
      className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-dashed border-emerald-300 bg-emerald-50/60 px-5 py-4 text-sm transition hover:bg-emerald-50"
      data-testid="upgrade-hint"
    >
      <span className="flex items-center gap-2 text-emerald-900">
        <Sparkles className="h-4 w-4 text-emerald-600" />
        Unlock {missing.join(', ')} with a bigger plan.
      </span>
      <span className="inline-flex items-center gap-1 font-semibold text-emerald-700">
        See plans <ArrowRight className="h-4 w-4" />
      </span>
    </Link>
  );
}

