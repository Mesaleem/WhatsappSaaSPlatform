import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react';
import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { ChevronDown, ChevronRight, Lightbulb, Loader2, RefreshCw, UserPlus } from 'lucide-react';
import siteAnalyticsService, { type SiteAnalyticsBreakdown, type SiteAnalyticsReport, type SiteVisitsPage } from '../../services/siteAnalyticsService';
import { describeApiError } from '../../utils/apiError';

/** Theme-following chart colours (tailwind.config.js CSS variables), so dark mode and accents apply. */
const BRAND = 'rgb(var(--brand-600))';
const GRID = 'rgb(var(--slate-line-200))';
const TICK = 'rgb(var(--slate-text-500))';

const PRESETS = [
  { label: 'Last 7 days', days: 7 },
  { label: 'Last 30 days', days: 30 },
  { label: 'Last 90 days', days: 90 },
];

function isoDay(d: Date): string {
  const p = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
}

function rangeFor(days: number) {
  const to = new Date();
  const from = new Date();
  from.setDate(to.getDate() - (days - 1));
  return { from: isoDay(from), to: isoDay(to) };
}

/** 95 → "1m 35s", 40 → "40s". */
export function formatDuration(seconds: number): string {
  if (seconds < 60) return `${seconds}s`;
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  if (m < 60) return s ? `${m}m ${s}s` : `${m}m`;
  return `${Math.floor(m / 60)}h ${m % 60}m`;
}

function shortDate(iso: string): string {
  return new Date(`${iso}T00:00:00`).toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
}

/** Plain-language pointers on what to improve, worked out from the report. */
export function improvementTips(r: SiteAnalyticsReport): string[] {
  const tips: string[] = [];
  const s = r.summary;
  if (s.visits === 0) return tips;
  if (s.bounce_rate >= 60) {
    tips.push(`${s.bounce_rate}% of visits saw only one page. Make the next step clearer on the landing pages (a visible "See pricing" or "Start free" button).`);
  }
  const weak = r.pages.filter((p) => p.views >= 5 && p.exits / p.views >= 0.5 && p.avg_seconds < 20)[0];
  if (weak) {
    tips.push(`Visitors leave ${weak.path} quickly (avg ${formatDuration(weak.avg_seconds)}, ${Math.round((100 * weak.exits) / weak.views)}% leave from it). Its opening lines may not answer what they came for.`);
  }
  const pricing = r.pages.find((p) => p.path === '/pricing');
  if (pricing && s.signups === 0 && pricing.views >= 5) {
    tips.push('People open Pricing but nobody signed up in this period. Check the plan text and the signup button on that page.');
  }
  if (!pricing && s.visits >= 10) {
    tips.push('Almost nobody reaches the Pricing page. Link to it from the home page hero and the features page.');
  }
  const best = [...r.sources].filter((x) => x.visits >= 3).sort((a, b) => (b.signups ?? 0) / b.visits - (a.signups ?? 0) / a.visits)[0];
  if (best && (best.signups ?? 0) > 0) {
    tips.push(`${best.name} brings your best visitors (${best.signups} signup${best.signups === 1 ? '' : 's'} from ${best.visits} visits). Put more effort there.`);
  }
  const mobile = r.devices.find((d) => d.name === 'mobile');
  if (mobile && mobile.visits / s.visits >= 0.5) {
    tips.push(`${Math.round((100 * mobile.visits) / s.visits)}% of visits are on a phone. Check every page on a small screen.`);
  }
  return tips;
}

function Stat({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
      <p className="text-xs font-medium text-slate-500">{label}</p>
      <p className="mt-1 text-2xl font-semibold text-slate-900">{value}</p>
      {hint && <p className="mt-0.5 text-xs text-slate-500">{hint}</p>}
    </div>
  );
}

function Card({ title, children, testId }: { title: string; children: ReactNode; testId?: string }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm" data-testid={testId}>
      <h2 className="text-sm font-semibold text-slate-900">{title}</h2>
      <div className="mt-3">{children}</div>
    </div>
  );
}

/** A share bar behind a row — the number beside it carries the value, the bar only the proportion. */
function Share({ value, max }: { value: number; max: number }) {
  return (
    <div className="h-1.5 w-full rounded-full bg-slate-100" aria-hidden="true">
      <div className="h-1.5 rounded-full bg-emerald-500" style={{ width: `${max > 0 ? Math.max(2, (100 * value) / max) : 0}%` }} />
    </div>
  );
}

function BreakdownTable({ rows, name, quality = false, empty }: { rows: SiteAnalyticsBreakdown[]; name: string; quality?: boolean; empty: string }) {
  if (rows.length === 0) return <p className="text-sm text-slate-500">{empty}</p>;
  const max = Math.max(...rows.map((r) => r.visits));
  return (
    <table className="w-full text-sm">
      <thead>
        <tr className="text-left text-xs text-slate-500">
          <th className="pb-2 font-medium">{name}</th>
          <th className="pb-2 text-right font-medium">Visits</th>
          {quality && <th className="pb-2 text-right font-medium">Avg time</th>}
          {quality && <th className="pb-2 text-right font-medium">Pages</th>}
          {quality && <th className="pb-2 text-right font-medium">Signups</th>}
        </tr>
      </thead>
      <tbody className="divide-y divide-slate-100">
        {rows.map((r) => (
          <tr key={r.name}>
            <td className="py-2 pr-3">
              <span className="block truncate capitalize text-slate-800">{r.name}</span>
              <Share value={r.visits} max={max} />
            </td>
            <td className="py-2 text-right tabular-nums text-slate-700">{r.visits.toLocaleString()}</td>
            {quality && <td className="py-2 text-right tabular-nums text-slate-700">{formatDuration(r.avg_seconds ?? 0)}</td>}
            {quality && <td className="py-2 text-right tabular-nums text-slate-700">{r.avg_pages ?? 0}</td>}
            {quality && <td className="py-2 text-right tabular-nums text-slate-700">{r.signups ?? 0}</td>}
          </tr>
        ))}
      </tbody>
    </table>
  );
}

/**
 * Super Admin — Website Analytics: who visited the public website, when, where they came from,
 * which pages they opened and how long they stayed, plus pointers on what to improve.
 */
export default function SiteAnalyticsPage() {
  const [range, setRange] = useState(() => rangeFor(30));
  const [preset, setPreset] = useState<number | null>(30);
  const [report, setReport] = useState<SiteAnalyticsReport | null>(null);
  const [visits, setVisits] = useState<SiteVisitsPage | null>(null);
  const [source, setSource] = useState<string>('');
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    siteAnalyticsService
      .report(range)
      .then(setReport)
      .catch((e: unknown) => setError(describeApiError(e, 'Could not load the website report.').message))
      .finally(() => setLoading(false));
  }, [range]);

  useEffect(() => {
    load();
  }, [load]);

  useEffect(() => {
    siteAnalyticsService
      .visits({ ...range, page, source })
      .then(setVisits)
      .catch(() => setVisits(null));
  }, [range, page, source]);

  const tips = useMemo(() => (report ? improvementTips(report) : []), [report]);
  const s = report?.summary;
  const pageMax = report ? Math.max(1, ...report.pages.map((p) => p.views)) : 1;

  const choosePreset = (days: number) => {
    setPreset(days);
    setPage(1);
    setRange(rangeFor(days));
  };

  return (
    <div className="p-6">
      <div className="w-full space-y-6">
        <div className="flex flex-wrap items-end justify-between gap-3">
          <div>
            <h1 className="text-xl font-semibold text-slate-900">Website Analytics</h1>
            <p className="mt-1 text-sm text-slate-500">Who visited your website, where they came from, which pages they read and for how long.</p>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            {PRESETS.map((p) => (
              <button
                key={p.days}
                type="button"
                onClick={() => choosePreset(p.days)}
                aria-pressed={preset === p.days}
                className={`rounded-lg border px-3 py-1.5 text-sm ${preset === p.days ? 'border-emerald-600 bg-emerald-50 font-semibold text-emerald-800' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'}`}
              >
                {p.label}
              </button>
            ))}
            <input
              type="date"
              aria-label="From"
              value={range.from}
              max={range.to}
              onChange={(e) => {
                setPreset(null);
                setPage(1);
                setRange((r) => ({ ...r, from: e.target.value }));
              }}
              className="rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-sm text-slate-700"
            />
            <input
              type="date"
              aria-label="To"
              value={range.to}
              min={range.from}
              onChange={(e) => {
                setPreset(null);
                setPage(1);
                setRange((r) => ({ ...r, to: e.target.value }));
              }}
              className="rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-sm text-slate-700"
            />
            <button type="button" onClick={load} aria-label="Reload" className="rounded-lg border border-slate-300 bg-white p-2 text-slate-600 hover:bg-slate-50">
              <RefreshCw className="h-4 w-4" />
            </button>
          </div>
        </div>

        {error && <p className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{error}</p>}

        {loading && !report ? (
          <div className="flex justify-center py-16">
            <Loader2 className="h-6 w-6 animate-spin text-emerald-600" />
          </div>
        ) : report && s ? (
          <>
            <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6" data-testid="site-analytics-summary">
              <Stat label="Visitors" value={s.visitors.toLocaleString()} hint={`${s.returning_visits.toLocaleString()} returning visits`} />
              <Stat label="Visits" value={s.visits.toLocaleString()} />
              <Stat label="Pages viewed" value={s.page_views.toLocaleString()} hint={s.visits ? `${(s.page_views / s.visits).toFixed(1)} per visit` : undefined} />
              <Stat label="Avg time on site" value={formatDuration(s.avg_duration_seconds)} />
              <Stat label="Bounce rate" value={`${s.bounce_rate}%`} hint="Left after one page" />
              <Stat label="Signups" value={s.signups.toLocaleString()} hint={s.visits ? `${((100 * s.signups) / s.visits).toFixed(1)}% of visits` : undefined} />
            </div>

            {tips.length > 0 && (
              <div className="rounded-xl border border-amber-200 bg-amber-50 p-5" data-testid="site-analytics-tips">
                <h2 className="flex items-center gap-2 text-sm font-semibold text-amber-900">
                  <Lightbulb className="h-4 w-4" /> What to improve
                </h2>
                <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-amber-900">
                  {tips.map((t) => (
                    <li key={t}>{t}</li>
                  ))}
                </ul>
              </div>
            )}

            <Card title="Visits per day">
              {report.daily.length === 0 ? (
                <p className="text-sm text-slate-500">No visits in this period yet.</p>
              ) : (
                <div className="h-64">
                  <ResponsiveContainer width="100%" height="100%">
                    <AreaChart data={report.daily} margin={{ top: 8, right: 8, left: -16, bottom: 0 }}>
                      <defs>
                        <linearGradient id="siteVisitsFill" x1="0" y1="0" x2="0" y2="1">
                          <stop offset="0%" stopColor={BRAND} stopOpacity={0.25} />
                          <stop offset="100%" stopColor={BRAND} stopOpacity={0} />
                        </linearGradient>
                      </defs>
                      <CartesianGrid stroke={GRID} vertical={false} />
                      <XAxis dataKey="date" tickFormatter={shortDate} tick={{ fontSize: 12, fill: TICK }} axisLine={false} tickLine={false} minTickGap={24} />
                      <YAxis allowDecimals={false} tick={{ fontSize: 12, fill: TICK }} axisLine={false} tickLine={false} />
                      <Tooltip
                        labelFormatter={(d) => shortDate(String(d))}
                        formatter={(v, n) => [Number(v).toLocaleString(), n === 'visits' ? 'Visits' : 'Visitors']}
                        cursor={{ stroke: GRID, strokeWidth: 1 }}
                      />
                      <Area type="monotone" dataKey="visits" stroke={BRAND} strokeWidth={2} fill="url(#siteVisitsFill)" dot={false} activeDot={{ r: 4 }} />
                    </AreaChart>
                  </ResponsiveContainer>
                </div>
              )}
            </Card>

            <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
              <Card title="Pages" testId="site-analytics-pages">
                {report.pages.length === 0 ? (
                  <p className="text-sm text-slate-500">No page views yet.</p>
                ) : (
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="text-left text-xs text-slate-500">
                        <th className="pb-2 font-medium">Page</th>
                        <th className="pb-2 text-right font-medium">Views</th>
                        <th className="pb-2 text-right font-medium">Avg time</th>
                        <th className="pb-2 text-right font-medium" title="Visits that ended on this page">Left here</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {report.pages.map((p) => (
                        <tr key={p.path}>
                          <td className="py-2 pr-3">
                            <span className="block truncate font-mono text-xs text-slate-800">{p.path}</span>
                            <Share value={p.views} max={pageMax} />
                          </td>
                          <td className="py-2 text-right tabular-nums text-slate-700">{p.views.toLocaleString()}</td>
                          <td className="py-2 text-right tabular-nums text-slate-700">{formatDuration(p.avg_seconds)}</td>
                          <td className="py-2 text-right tabular-nums text-slate-700">{p.exits.toLocaleString()}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                )}
              </Card>
              <Card title="Where visitors came from" testId="site-analytics-sources">
                <BreakdownTable rows={report.sources} name="Source" quality empty="No visits yet." />
              </Card>
            </div>

            <div className="grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-4">
              <Card title="First page opened">
                <BreakdownTable rows={report.landing_pages} name="Landing page" empty="No visits yet." />
              </Card>
              <Card title="Devices">
                <BreakdownTable rows={report.devices} name="Device" empty="No visits yet." />
              </Card>
              <Card title="Browsers">
                <BreakdownTable rows={report.browsers} name="Browser" empty="No visits yet." />
              </Card>
              <Card title="Countries">
                <BreakdownTable rows={report.countries} name="Country" empty="Shown when the site runs behind Cloudflare." />
              </Card>
            </div>

            <Card title="Visits" testId="site-analytics-visits">
              <div className="mb-3 flex flex-wrap items-center gap-2">
                <label className="text-sm text-slate-600" htmlFor="visit-source">
                  Source
                </label>
                <select
                  id="visit-source"
                  value={source}
                  onChange={(e) => {
                    setSource(e.target.value);
                    setPage(1);
                  }}
                  className="rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-sm text-slate-700"
                >
                  <option value="">All sources</option>
                  {report.sources.map((x) => (
                    <option key={x.name} value={x.name}>
                      {x.name}
                    </option>
                  ))}
                </select>
                {visits && <span className="text-xs text-slate-500">{visits.total.toLocaleString()} visits</span>}
              </div>
              {!visits || visits.data.length === 0 ? (
                <p className="text-sm text-slate-500">No visits in this period.</p>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[720px] text-sm">
                    <thead>
                      <tr className="text-left text-xs text-slate-500">
                        <th className="w-6 pb-2" />
                        <th className="pb-2 font-medium">When</th>
                        <th className="pb-2 font-medium">From</th>
                        <th className="pb-2 font-medium">Visitor</th>
                        <th className="pb-2 font-medium">Pages</th>
                        <th className="pb-2 text-right font-medium">Time on site</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {visits.data.map((v) => (
                        <VisitRow key={v.id} v={v} open={open === v.id} onToggle={() => setOpen(open === v.id ? null : v.id)} />
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
              {visits && visits.last_page > 1 && (
                <div className="mt-3 flex items-center justify-end gap-2 text-sm">
                  <button type="button" disabled={page <= 1} onClick={() => setPage(page - 1)} className="rounded-lg border border-slate-300 px-3 py-1 disabled:opacity-50">
                    Previous
                  </button>
                  <span className="text-slate-500">
                    Page {visits.current_page} of {visits.last_page}
                  </span>
                  <button type="button" disabled={page >= visits.last_page} onClick={() => setPage(page + 1)} className="rounded-lg border border-slate-300 px-3 py-1 disabled:opacity-50">
                    Next
                  </button>
                </div>
              )}
            </Card>
          </>
        ) : null}
      </div>
    </div>
  );
}

function VisitRow({ v, open, onToggle }: { v: SiteVisitsPage['data'][number]; open: boolean; onToggle: () => void }) {
  const campaign = [v.utm_medium, v.utm_campaign].filter(Boolean).join(' · ');
  return (
    <>
      <tr className="cursor-pointer align-top hover:bg-slate-50" onClick={onToggle}>
        <td className="py-2 text-slate-400">{open ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}</td>
        <td className="py-2 pr-3 text-slate-700">{new Date(v.started_at).toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}</td>
        <td className="py-2 pr-3">
          <span className="font-medium text-slate-800">{v.source}</span>
          {campaign && <span className="block text-xs text-slate-500">{campaign}</span>}
        </td>
        <td className="py-2 pr-3 text-slate-700">
          <span className="capitalize">{v.device}</span> · {v.browser} · {v.os}
          <span className="block text-xs text-slate-500">
            {[v.country, v.ip].filter(Boolean).join(' · ')}
            {v.is_returning ? ' · returning' : ' · new'}
          </span>
        </td>
        <td className="py-2 pr-3 text-slate-700">
          {v.page_count} · <span className="font-mono text-xs">{v.landing_path}</span>
          {v.exit_path && v.exit_path !== v.landing_path && <span className="font-mono text-xs"> → {v.exit_path}</span>}
          {v.signed_up && (
            <span className="ml-2 inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-800">
              <UserPlus className="h-3 w-3" /> Signed up
            </span>
          )}
        </td>
        <td className="py-2 text-right tabular-nums text-slate-700">{formatDuration(v.duration_seconds)}</td>
      </tr>
      {open && (
        <tr>
          <td />
          <td colSpan={5} className="pb-3">
            <ol className="space-y-1 rounded-lg bg-slate-50 p-3 text-xs">
              {v.page_views.map((p, i) => (
                <li key={p.id} className="flex justify-between gap-3">
                  <span className="truncate">
                    <span className="text-slate-400">{i + 1}.</span> <span className="font-mono text-slate-800">{p.path}</span>
                    <span className="ml-2 text-slate-500">{new Date(p.entered_at).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', second: '2-digit' })}</span>
                  </span>
                  <span className="tabular-nums text-slate-700">{formatDuration(p.duration_seconds)}</span>
                </li>
              ))}
              {v.referrer && <li className="truncate pt-1 text-slate-500">Referrer: {v.referrer}</li>}
            </ol>
          </td>
        </tr>
      )}
    </>
  );
}
