import { useMemo, useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { AlertCircle, Loader2 } from 'lucide-react';
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { useAuth } from '../../core/context/AuthContext';
import { useTenant } from '../../core/context/TenantContext';
import { SelectClientNotice } from '../../components/common/ActionGate';
import { useClientGate } from '../../components/common/actionGateHooks';
import { TableCard, inputClass } from '../../components/common/Card';
import { useCrmQuery } from '../../components/crm/crmHooks';
import AdsConversionValues from './AdsConversionValues';
import adsDashboardService from '../../services/adsDashboardService';
import { indigo } from '../../theme/signalIndigo';
import { AD_OBJECTIVE_LABELS, formatAdMoney } from '../../types/ads';
import type { AdsDashboard, AdsDashboardQuery, AdsMetric, AdsMetricStatus, AdsRange } from '../../types/adsDashboard';
import DismissibleAlert from '../../components/common/DismissibleAlert';

/**
 * Phase 10 Task 3 — Ads Dashboard. Account-level Ads reporting from stored
 * data (launcher campaigns, their stored daily insights, click-to-WhatsApp
 * attribution). Same gates as the backend (Ads permission, meta_ads module,
 * ads capability, selected client for a Super Admin). Every request is keyed
 * by client + period, so switching client clears the old data and a late
 * answer for the previous client is discarded (shared useCrmQuery loader).
 */

const RANGES: { value: AdsRange; label: string }[] = [
  { value: '7d', label: 'Last 7 days' },
  { value: '30d', label: 'Last 30 days' },
  { value: '90d', label: 'Last 90 days' },
  { value: 'custom', label: 'Custom range' },
];

/** What a card shows instead of a number — never a misleading 0. */
const STATUS_TEXT: Record<Exclude<AdsMetricStatus, 'available'>, string> = {
  not_fetched: 'Not fetched yet',
  unavailable: 'Not available',
  not_applicable: 'Not applicable',
};

const CAMPAIGN_STATUS_LABEL: Record<string, string> = {
  LAUNCHING: 'Launching',
  ACTIVE: 'Active',
  PAUSED: 'Paused',
  FAILED: 'Failed',
  UNCONFIRMED: 'Unconfirmed',
  UNAVAILABLE: 'Unavailable',
};

const percent = (v: number) => `${(v * 100).toFixed(1)}%`;
const times = (v: number) => `${v.toFixed(2)}×`;

function Unknown({ status }: { status: AdsMetricStatus }) {
  return <span className="text-sm font-normal text-slate-400">{STATUS_TEXT[status === 'available' ? 'unavailable' : status]}</span>;
}

function MetricValue({ metric, format }: { metric: AdsMetric; format: (v: number) => string }) {
  return metric.status === 'available' && metric.value !== null ? <>{format(metric.value)}</> : <Unknown status={metric.status} />;
}

/** A table cell for a nullable figure: null means "not known", shown as a dash with the reason in its title. */
function Cell({ value, format, reason }: { value: number | null; format: (v: number) => string; reason: string }) {
  return value === null ? (
    <span className="text-slate-400" title={reason}>
      —
    </span>
  ) : (
    <>{format(value)}</>
  );
}

function Card({ label, testId, children, sub }: { label: string; testId: string; children: ReactNode; sub?: ReactNode }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm" data-testid={testId}>
      <p className="text-xs font-medium uppercase tracking-wide" style={{ color: indigo.muted }}>
        {label}
      </p>
      <p className="mt-1.5 text-xl font-semibold" style={{ color: indigo.ink }}>
        {children}
      </p>
      {sub && <p className="mt-1 text-xs text-slate-500">{sub}</p>}
    </div>
  );
}

function Funnel({ stages }: { stages: AdsDashboard['funnel'] }) {
  const max = Math.max(1, ...stages.map((s) => s.count));
  return (
    <ol className="space-y-2" data-testid="ads-funnel" aria-label="Attribution funnel">
      {stages.map((s) => (
        <li key={s.stage} className="grid grid-cols-[9rem_1fr_4rem] items-center gap-3 text-sm" data-testid={`funnel-${s.stage}`}>
          <span className="text-slate-600">{s.label}</span>
          <span className="h-3 rounded-full" style={{ background: indigo.track }} aria-hidden="true">
            <span className="block h-3 rounded-full" style={{ width: `${(s.count / max) * 100}%`, background: indigo.accentSolid, minWidth: s.count > 0 ? 4 : 0 }} />
          </span>
          <span className="text-right font-semibold tabular-nums" style={{ color: indigo.ink }}>
            {s.count.toLocaleString()}
          </span>
        </li>
      ))}
    </ol>
  );
}

const SPEND_REASON: Record<AdsMetricStatus, string> = {
  available: '',
  not_fetched: 'No insights stored for this campaign in the period yet',
  unavailable: 'Meta reports this campaign no longer exists or cannot be loaded',
  not_applicable: 'This launch never reached Meta',
};

export default function AdsDashboardPage() {
  const { user, isSuperAdmin, hasPermission, hasModule, isReadOnly } = useAuth();
  const { selectedAccountId } = useTenant();
  const superAdmin = isSuperAdmin();
  // Owner request (2026-09-30): with its own Platform account a Super Admin needs no client here.
  const noTenantSelected = superAdmin && selectedAccountId === null && !user?.platform_crm_account;
  const { openPicker, picker } = useClientGate({ platformFallback: true });
  // Phase 10 Task 5 — recording a conversion value needs CRM access on top of Ads (the backend re-checks both);
  // the route is also behind the lead_crm module; a Super Admin must have a client selected. An absent capability map is not an invented denial (same as ProtectedRoute).
  const hasCrmCapability = user?.capabilities ? Boolean(user.capabilities.crm) : true;
  const readOnly = typeof isReadOnly === 'function' && isReadOnly();
  const canEditValues = !readOnly && (superAdmin ? selectedAccountId !== null : hasPermission('manage-crm') && hasModule('lead_crm') && hasCrmCapability);

  const [range, setRange] = useState<AdsRange>('30d');
  const [customFrom, setCustomFrom] = useState('');
  const [customTo, setCustomTo] = useState('');

  const customError =
    range === 'custom' && (!customFrom || !customTo)
      ? 'Choose a start and an end date.'
      : range === 'custom' && customTo < customFrom
        ? 'The end date must be on or after the start date.'
        : null;

  const query = useMemo<AdsDashboardQuery | null>(
    () => (customError ? null : range === 'custom' ? { range, from: customFrom, to: customTo } : { range }),
    [range, customFrom, customTo, customError],
  );

  const key = noTenantSelected || !query ? null : JSON.stringify({ account: selectedAccountId, query });
  const { data, error, isLoading, reload } = useCrmQuery<AdsDashboard>(key, () => adsDashboardService.dashboard(query as AdsDashboardQuery), 'Failed to load the Ads dashboard.');

  const hasSpend = data !== null && data.spend.daily.some((d) => d.spend !== null);
  const nothingYet = data !== null && data.campaigns_summary.total === 0 && data.attribution.referrals === 0;
  // Owner request (2026-09-30): amounts in the ad account currency stored at launch (INR when not known).
  const money = (v: number) => formatAdMoney(v, data?.currency);
  const s = data?.campaigns_summary;
  const a = data?.attribution;

  return (
    <div className="p-6">
      <div className="w-full space-y-6">
        <div className="flex flex-wrap items-end justify-between gap-3">
          <div>
            <h1 className="text-xl font-semibold text-slate-900">Ads Dashboard</h1>
            <p className="mt-1 text-sm text-slate-500">Spend, campaign results and click-to-WhatsApp attribution, from the data stored for your Meta ads.</p>
          </div>
          {!noTenantSelected && (
            <div className="flex flex-wrap items-end gap-2" data-testid="ads-range">
              <label className="text-xs font-medium text-slate-600">
                Period
                <select aria-label="Period" className={`${inputClass} w-auto`} value={range} onChange={(e) => setRange(e.target.value as AdsRange)}>
                  {RANGES.map((r) => (
                    <option key={r.value} value={r.value}>
                      {r.label}
                    </option>
                  ))}
                </select>
              </label>
              {range === 'custom' && (
                <>
                  <label className="text-xs font-medium text-slate-600">
                    From
                    <input type="date" aria-label="From date" className={`${inputClass} w-auto`} value={customFrom} onChange={(e) => setCustomFrom(e.target.value)} />
                  </label>
                  <label className="text-xs font-medium text-slate-600">
                    To
                    <input type="date" aria-label="To date" className={`${inputClass} w-auto`} value={customTo} onChange={(e) => setCustomTo(e.target.value)} />
                  </label>
                </>
              )}
            </div>
          )}
        </div>

        {picker}
        {noTenantSelected ? (
          <SelectClientNotice message="Ads reporting belongs to a client. Select one to view their Ads dashboard." onSelect={() => openPicker('view the Ads dashboard')} />
        ) : (
          <>
            {customError && <p className="text-sm text-slate-500">{customError}</p>}
            {error && (
              <DismissibleAlert className="flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
                <AlertCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
                {error}
              </DismissibleAlert>
            )}
            {isLoading && (
              <div className="flex items-center gap-2 text-sm text-slate-500" data-testid="ads-dashboard-loading">
                <Loader2 className="h-4 w-4 animate-spin" /> Loading the Ads dashboard…
              </div>
            )}

            {data && s && a && (
              <>
                <p className="text-xs text-slate-500" data-testid="ads-dashboard-range">
                  {data.range.from} to {data.range.to} · {data.notes.source}
                </p>

                {nothingYet && (
                  <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600" data-testid="ads-dashboard-empty">
                    No campaigns and no click-to-WhatsApp referrals yet.{' '}
                    <Link to="/social/ads" className="font-semibold text-indigo-700 underline">
                      Open the Meta Ads Launcher
                    </Link>
                  </div>
                )}

                <section className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4" data-testid="ads-summary">
                  <Card label="Total spend" testId="card-spend" sub={`${data.spend.campaigns_with_spend} campaign(s) with stored insights`}>
                    <MetricValue metric={data.spend.total} format={money} />
                  </Card>
                  <Card label="Campaigns" testId="card-campaigns" sub={`${s.active} active · ${s.paused} paused`}>
                    {s.total.toLocaleString()}
                  </Card>
                  <Card label="Needs attention" testId="card-attention" sub={`${s.failed} failed · ${s.unconfirmed} unconfirmed · ${s.unavailable} unavailable`}>
                    {(s.failed + s.unconfirmed + s.unavailable).toLocaleString()}
                  </Card>
                  <Card label="Impressions" testId="card-impressions">
                    <MetricValue metric={data.spend.impressions} format={(v) => v.toLocaleString()} />
                  </Card>
                  <Card label="Referrals" testId="card-referrals" sub={`${a.conversations.toLocaleString()} conversation(s)`}>
                    {a.referrals.toLocaleString()}
                  </Card>
                  <Card label="CRM leads" testId="card-leads" sub={`${a.journeys.toLocaleString()} journey start(s)`}>
                    {a.leads.toLocaleString()}
                  </Card>
                  <Card label="Conversions" testId="card-conversions" sub={<>Rate: <MetricValue metric={a.conversion_rate} format={percent} /></>}>
                    {a.conversions.toLocaleString()}
                  </Card>
                  <Card label="Cost per lead" testId="card-cpl">
                    <MetricValue metric={a.cost_per_lead} format={money} />
                  </Card>
                  <Card
                    label="Cost per conversion"
                    testId="card-cpc"
                    sub={a.cost_per_conversion?.status === 'not_applicable' ? 'No conversions in the period' : undefined}
                  >
                    <MetricValue metric={a.cost_per_conversion ?? { value: null, status: 'unavailable' }} format={money} />
                  </Card>
                  <Card
                    label="Conversion value"
                    testId="card-value"
                    sub={
                      a.conversion_value_issue === 'mixed_currency'
                        ? 'Recorded in different currencies — not added together'
                        : a.valued_conversions !== undefined && a.conversions > 0
                          ? `${a.valued_conversions} of ${a.conversions} conversion(s) have a value`
                          : undefined
                    }
                  >
                    <MetricValue metric={a.conversion_value} format={(v) => formatAdMoney(v, a.conversion_value_currency ?? data.currency)} />
                  </Card>
                  <Card label="ROAS" testId="card-roas" sub={a.roas_issue === 'currency_mismatch' ? 'Value and spend are in different currencies' : undefined}>
                    <MetricValue metric={a.roas} format={times} />
                  </Card>
                </section>

                <section>
                  <h2 className="mb-2 text-base font-semibold text-slate-900">Daily spend</h2>
                  <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="ads-spend-trend">
                    {hasSpend ? (
                      <>
                        <p className="mb-2 text-xs text-slate-500">Stored spend per day; gaps mean no insights were stored for that day.</p>
                        <div className="h-64">
                          <ResponsiveContainer width="100%" height="100%">
                            <LineChart data={data.spend.daily}>
                              <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" vertical={false} />
                              <XAxis dataKey="date" tick={{ fontSize: 11 }} tickFormatter={(d: string) => d.slice(5)} />
                              <YAxis tick={{ fontSize: 11 }} tickFormatter={(v: number) => money(v)} />
                              <Tooltip formatter={(v) => (typeof v === 'number' ? money(v) : 'No data')} />
                              <Line type="monotone" dataKey="spend" name="Spend" stroke={indigo.accentSolid} strokeWidth={2} dot={{ r: 3 }} connectNulls={false} />
                            </LineChart>
                          </ResponsiveContainer>
                        </div>
                      </>
                    ) : (
                      <p className="py-8 text-center text-sm text-slate-500" data-testid="ads-spend-empty">
                        {s.total === 0 ? 'No campaigns yet, so there is no spend to show.' : 'No spend stored for this period yet — insights are recorded for launcher campaigns every 15 minutes.'}
                      </p>
                    )}
                  </div>
                </section>

                <section>
                  <h2 className="mb-2 text-base font-semibold text-slate-900">Attribution funnel</h2>
                  <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p className="mb-3 text-xs text-slate-500">
                      Ad → Referral → Conversation → Lead → Journey → Conversion, for referrals received in the period. Each stage is counted on its own; not every referral goes on to the next stage.
                    </p>
                    {a.referrals === 0 ? (
                      <p className="py-4 text-center text-sm text-slate-500" data-testid="ads-funnel-empty">
                        No click-to-WhatsApp referrals in this period.
                      </p>
                    ) : (
                      <Funnel stages={data.funnel} />
                    )}
                    {a.unlinked_referrals > 0 && (
                      <p className="mt-3 text-xs text-slate-500" data-testid="ads-unlinked">
                        {a.unlinked_referrals.toLocaleString()} referral(s) came from ads not created by the launcher — they count in the funnel but carry no spend.
                      </p>
                    )}
                  </div>
                </section>

                <section>
                  <h2 className="mb-2 text-base font-semibold text-slate-900">Campaign performance</h2>
                  <TableCard>
                    <table className="min-w-full divide-y divide-slate-200 text-sm" data-testid="ads-campaign-table">
                      <thead className="bg-slate-50">
                        <tr>
                          {['Campaign', 'Status', 'Spend', 'Impressions', 'Referrals', 'Conversations', 'Leads', 'Journeys', 'Conversions', 'Conv. rate', 'Cost / lead', 'Cost / conv.', 'Conv. value', 'ROAS'].map((h) => (
                            <th key={h} className="whitespace-nowrap px-4 py-3 text-left font-semibold text-slate-600">
                              {h}
                            </th>
                          ))}
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-100">
                        {data.campaigns.length === 0 ? (
                          <tr>
                            <td colSpan={14} className="px-4 py-8 text-center text-slate-500" data-testid="ads-campaigns-empty">
                              No campaigns yet.
                            </td>
                          </tr>
                        ) : (
                          data.campaigns.map((c) => (
                            <tr key={c.id}>
                              <td className="px-4 py-3">
                                <p className="font-medium text-slate-900">{c.name}</p>
                                <p className="text-xs text-slate-500">{AD_OBJECTIVE_LABELS[c.objective] ?? c.objective}</p>
                              </td>
                              <td className="px-4 py-3 text-slate-700">{CAMPAIGN_STATUS_LABEL[c.status] ?? c.status}</td>
                              <td className="px-4 py-3 text-slate-700">
                                {c.spend !== null ? money(c.spend) : <span className="text-xs text-slate-400" title={SPEND_REASON[c.spend_status]}>{STATUS_TEXT[c.spend_status === 'available' ? 'unavailable' : c.spend_status]}</span>}
                              </td>
                              <td className="px-4 py-3 tabular-nums text-slate-700"><Cell value={c.impressions} format={(v) => v.toLocaleString()} reason="No insights stored for this campaign in the period" /></td>
                              <td className="px-4 py-3 tabular-nums text-slate-700">{c.referrals}</td>
                              <td className="px-4 py-3 tabular-nums text-slate-700">{c.conversations}</td>
                              <td className="px-4 py-3 tabular-nums text-slate-700">{c.leads}</td>
                              <td className="px-4 py-3 tabular-nums text-slate-700">{c.journeys}</td>
                              <td className="px-4 py-3 tabular-nums text-slate-700">{c.conversions}</td>
                              <td className="px-4 py-3 text-slate-700"><Cell value={c.conversion_rate} format={percent} reason="No referrals in the period" /></td>
                              <td className="px-4 py-3 text-slate-700"><Cell value={c.cost_per_lead} format={money} reason="Needs stored spend and at least one lead" /></td>
                              <td className="px-4 py-3 text-slate-700"><Cell value={c.cost_per_conversion ?? null} format={money} reason="Needs stored spend and at least one conversion" /></td>
                              <td className="px-4 py-3 text-slate-700">
                                <Cell
                                  value={c.conversion_value}
                                  format={(v) => formatAdMoney(v, c.conversion_value_currency ?? data.currency)}
                                  reason={c.conversion_value_issue === 'mixed_currency' ? 'Recorded in different currencies — not added together' : 'No conversion value recorded'}
                                />
                              </td>
                              <td className="px-4 py-3 text-slate-700">
                                <Cell
                                  value={c.roas}
                                  format={times}
                                  reason={c.conversion_value_issue === 'currency_mismatch' ? 'Value and spend are in different currencies' : 'Needs stored spend and a recorded conversion value'}
                                />
                              </td>
                            </tr>
                          ))
                        )}
                      </tbody>
                    </table>
                  </TableCard>
                  {data.campaigns_truncated && <p className="mt-2 text-xs text-slate-500">Showing the {data.campaigns.length} most recent campaigns; totals above include all of them.</p>}
                </section>

                <section>
                  <h2 className="mb-2 text-base font-semibold text-slate-900">Daily conversions</h2>
                  <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="ads-conversion-daily">
                    {(data.conversion_daily ?? []).some((d) => d.conversions > 0) ? (
                      <table className="min-w-full text-sm">
                        <thead>
                          <tr className="text-left text-xs uppercase tracking-wide text-slate-500">
                            <th className="py-1 pr-4">Day</th>
                            <th className="py-1 pr-4">Conversions</th>
                            <th className="py-1">Value</th>
                          </tr>
                        </thead>
                        <tbody>
                          {(data.conversion_daily ?? [])
                            .filter((d) => d.conversions > 0)
                            .map((d) => (
                              <tr key={d.date} data-testid={`conversion-day-${d.date}`}>
                                <td className="py-1 pr-4 text-slate-700">{d.date}</td>
                                <td className="py-1 pr-4 tabular-nums text-slate-700">{d.conversions}</td>
                                <td className="py-1 tabular-nums text-slate-700">
                                  <Cell value={d.conversion_value} format={(v) => formatAdMoney(v, a.conversion_value_currency ?? data.currency)} reason="No conversion value recorded for this day" />
                                </td>
                              </tr>
                            ))}
                        </tbody>
                      </table>
                    ) : (
                      <p className="py-4 text-center text-sm text-slate-500" data-testid="ads-conversion-daily-empty">
                        No conversions recorded in this period.
                      </p>
                    )}
                  </div>
                </section>

                {canEditValues && <AdsConversionValues key={selectedAccountId ?? 'own'} accountKey={selectedAccountId} canEdit defaultCurrency={data.currency ?? null} onChanged={reload} />}

                <ul className="list-disc space-y-1 pl-5 text-xs text-slate-500" data-testid="ads-notes">
                  {['campaign_status', 'spend', 'conversion', 'conversion_value', 'conversion_daily', 'cost_per_lead'].map((k) => (data.notes[k] ? <li key={k}>{data.notes[k]}</li> : null))}
                </ul>
              </>
            )}
          </>
        )}
      </div>
    </div>
  );
}
