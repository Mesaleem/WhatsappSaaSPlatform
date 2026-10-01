import { useMemo, useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { AlertCircle, Loader2, RefreshCw } from 'lucide-react';
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { useAuth } from '../../core/context/AuthContext';
import { useTenant } from '../../core/context/TenantContext';
import { SelectClientNotice } from '../../components/common/ActionGate';
import { useClientGate } from '../../components/common/actionGateHooks';
import { TableCard, inputClass } from '../../components/common/Card';
import { useCrmQuery } from '../../components/crm/crmHooks';
import socialAnalyticsService from '../../services/socialAnalyticsService';
import organicPostService from '../../services/organicPostService';
import { extractErrorMessage } from '../../utils/apiError';
import { indigo } from '../../theme/signalIndigo';
import { formatScheduledAt, ORGANIC_PLATFORM_LABELS } from '../../types/organic';
import type { OrganicPlatform } from '../../types/organic';
import type {
  AggregateMetric,
  AggregateStatus,
  AnalyticsQuery,
  AnalyticsRange,
  EngagementComponent,
  MetricBlock,
  SocialAnalyticsDashboard,
  TopMetric,
  TopPost,
  TrendPoint,
} from '../../types/socialAnalytics';
import DismissibleAlert from '../../components/common/DismissibleAlert';

const RANGES: { value: AnalyticsRange; label: string }[] = [
  { value: '7d', label: 'Last 7 days' },
  { value: '30d', label: 'Last 30 days' },
  { value: '90d', label: 'Last 90 days' },
  { value: 'custom', label: 'Custom range' },
];

/** What a card shows instead of a number — never a misleading 0. */
const STATUS_TEXT: Record<Exclude<AggregateStatus, 'available'>, string> = {
  unavailable: 'Not available',
  not_fetched: 'Not fetched yet',
  no_data: 'No data',
};

const COMPONENT_LABELS: Record<EngagementComponent, string> = { reactions: 'reactions / likes', comments: 'comments', shares: 'shares', saves: 'saves' };

const TREND_METRICS: { key: keyof Omit<TrendPoint, 'period'>; label: string }[] = [
  { key: 'published', label: 'Posts published' },
  { key: 'reach', label: 'Reach' },
  { key: 'impressions', label: 'Impressions' },
  { key: 'engagement', label: 'Engagement' },
  { key: 'video_views', label: 'Video views' },
];

const TOP_METRICS: { value: TopMetric; label: string }[] = [
  { value: 'engagement', label: 'Engagement' },
  { value: 'reach', label: 'Reach' },
  { value: 'impressions', label: 'Impressions' },
  { value: 'video_views', label: 'Video views' },
];

const platformLabel = (platform: string) => ORGANIC_PLATFORM_LABELS[platform as OrganicPlatform] ?? platform;

function formatWatchTime(ms: number) {
  return `${(ms / 1000).toFixed(1)} s`;
}

function MetricValue({ metric, format }: { metric: { value: number | null; status: AggregateStatus }; format?: (v: number) => string }) {
  if (metric.status === 'available' && metric.value !== null) {
    return <>{format ? format(metric.value) : metric.value.toLocaleString()}</>;
  }
  return <span className="text-sm font-normal text-slate-400">{STATUS_TEXT[metric.status === 'available' ? 'unavailable' : metric.status]}</span>;
}

function Card({ label, testId, children, sub }: { label: string; testId: string; children: ReactNode; sub?: string }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm" data-testid={testId}>
      <p className="text-xs font-medium uppercase tracking-wide" style={{ color: indigo.muted }}>
        {label}
      </p>
      <p className="mt-1.5 text-xl font-semibold" style={{ color: indigo.ink }}>
        {children}
      </p>
      {sub && (
        <p className="mt-1 text-xs" style={{ color: indigo.muted }}>
          {sub}
        </p>
      )}
    </div>
  );
}

function reportingNote(metric: AggregateMetric, published: number) {
  return metric.status === 'available' && metric.posts_reporting < published ? `Reported by ${metric.posts_reporting} of ${published} posts` : undefined;
}

function engagementNote(block: MetricBlock) {
  const e = block.engagement;
  if (e.status !== 'available') return 'Reactions, comments, shares and saves';
  const included = e.components_included.map((c) => COMPONENT_LABELS[c]).join(', ');
  return e.components_unavailable.length > 0 ? `${included} (not available: ${e.components_unavailable.map((c) => COMPONENT_LABELS[c]).join(', ')})` : included;
}

/**
 * Phase 9 Task 5 — account-level Social Analytics dashboard. Everything
 * shown comes from the insights already stored for each published post
 * (GET /api/social/analytics/*); opening or filtering this page never asks
 * Meta for anything. Access follows view-social-analytics (plus the
 * social_accounts module and the social capability) — not the management
 * permission. A Super Admin picks a client first; switching client reloads
 * everything.
 */
export default function SocialAnalyticsPage() {
  const { user, isSuperAdmin, hasPermission, hasModule } = useAuth();
  const { selectedAccountId } = useTenant();
  const superAdmin = isSuperAdmin();
  // Owner request (2026-09-30): with its own Platform account a Super Admin needs no client here.
  const noTenantSelected = superAdmin && selectedAccountId === null && !user?.platform_crm_account;
  const { openPicker, picker } = useClientGate({ platformFallback: true });
  // Only offer the Social Accounts link to someone who can open that page (same gates as its route).
  const canManageSocialAccounts =
    superAdmin || (hasPermission('manage-social-accounts') && hasModule('social_accounts') && (user?.capabilities ? Boolean(user.capabilities.social) : true));

  const [range, setRange] = useState<AnalyticsRange>('30d');
  const [customFrom, setCustomFrom] = useState('');
  const [customTo, setCustomTo] = useState('');
  const [trendMetric, setTrendMetric] = useState<keyof Omit<TrendPoint, 'period'>>('published');
  const [topMetric, setTopMetric] = useState<TopMetric>('engagement');
  const [refreshingId, setRefreshingId] = useState<number | null>(null);
  // Tagged with the client/period it belongs to, so it never shows after a switch.
  const [refreshNoteState, setRefreshNoteState] = useState<{ key: string | null; text: string } | null>(null);

  const customError =
    range === 'custom' && (!customFrom || !customTo)
      ? 'Choose a start and an end date.'
      : range === 'custom' && customTo < customFrom
        ? 'The end date must be on or after the start date.'
        : null;

  const query = useMemo<AnalyticsQuery | null>(
    () => (customError ? null : range === 'custom' ? { range, from: customFrom, to: customTo } : { range }),
    [range, customFrom, customTo, customError],
  );

  // Keyed by client + period: switching the selected client (or period) refetches everything,
  // and a late answer for the previous key is discarded (shared useCrmQuery loader).
  const baseKey = noTenantSelected || !query ? null : JSON.stringify({ account: selectedAccountId, query });
  const dashboardQuery = useCrmQuery<SocialAnalyticsDashboard>(baseKey, () => socialAnalyticsService.dashboard(query as AnalyticsQuery), 'Failed to load social analytics.');
  const topQuery = useCrmQuery<TopPost[]>(
    baseKey && topMetric !== 'engagement' ? `${baseKey}:${topMetric}` : null,
    () => socialAnalyticsService.topPosts(query as AnalyticsQuery, topMetric),
    'Failed to load top posts.',
  );
  const data = dashboardQuery.data;
  const isLoading = dashboardQuery.isLoading;
  const error = dashboardQuery.error ?? topQuery.error;
  const load = dashboardQuery.reload;
  const refreshNote = refreshNoteState && refreshNoteState.key === baseKey ? refreshNoteState.text : null;
  const setRefreshNote = (text: string | null) => setRefreshNoteState(text === null ? null : { key: baseKey, text });

  const refreshPost = (postId: number) => {
    setRefreshingId(postId);
    setRefreshNote(null);
    organicPostService
      .refreshInsights(postId)
      .then(({ outcome }) => {
        if (outcome === 'refreshed') {
          load();
        } else {
          setRefreshNote(
            outcome === 'recently_refreshed'
              ? 'That post was refreshed a few minutes ago — the stored figures are current.'
              : outcome === 'backing_off'
                ? 'The platform asked us to slow down — that post will be refreshed automatically.'
                : 'A refresh of that post is already running.',
          );
        }
      })
      .catch((err: unknown) => setRefreshNote(extractErrorMessage(err, 'Could not refresh insights for that post.')))
      .finally(() => setRefreshingId(null));
  };

  // Engagement ranking comes with the dashboard; other metrics are fetched on demand.
  const shownTop = topMetric === 'engagement' ? (data?.top_posts ?? []) : (topQuery.data ?? []);
  const summary = data?.summary;
  const noConnections = data !== null && data.connections.facebook_pages + data.connections.instagram_accounts === 0;

  return (
    <div className="p-6">
      <div className="w-full space-y-6">
        <div className="flex flex-wrap items-end justify-between gap-3">
          <div>
            <h1 className="text-xl font-semibold text-slate-900">Social Analytics</h1>
            <p className="mt-1 text-sm text-slate-500">Results of your published Facebook and Instagram posts, from the insights stored for each post.</p>
          </div>
          {!noTenantSelected && (
            <div className="flex flex-wrap items-end gap-2" data-testid="analytics-range">
              <label className="text-xs font-medium text-slate-600">
                Period
                <select aria-label="Period" className={`${inputClass} w-auto`} value={range} onChange={(e) => setRange(e.target.value as AnalyticsRange)}>
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
          <SelectClientNotice message="Social analytics belong to a client. Select one to view their analytics." onSelect={() => openPicker('view social analytics')} />
        ) : (
          <>
            {customError && range === 'custom' && <p className="text-sm text-slate-500">{customError}</p>}
            {error && (
              <DismissibleAlert className="flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
                <AlertCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
                {error}
              </DismissibleAlert>
            )}
            {isLoading && !data && (
              <div className="flex items-center gap-2 text-sm text-slate-500">
                <Loader2 className="h-4 w-4 animate-spin" /> Loading analytics…
              </div>
            )}

            {data && summary && (
              <>
                <p className="text-xs text-slate-500" data-testid="analytics-freshness">
                  {data.range.from} to {data.range.to} · Insights last refreshed {data.freshness.last_fetched_at ? formatScheduledAt(data.freshness.last_fetched_at) : '— never'}
                  {data.freshness.never_fetched > 0 && ` · ${data.freshness.never_fetched} published post(s) not fetched yet`} · stored insights refresh automatically every 30 minutes
                </p>

                {noConnections && (
                  <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600" data-testid="analytics-no-connections">
                    No Facebook Page or Instagram account is connected.{' '}
                    {canManageSocialAccounts ? (
                      <Link to="/social/accounts" className="font-semibold text-indigo-700 underline">
                        Connect one in Social Accounts
                      </Link>
                    ) : (
                      'Ask a team member with access to Social Accounts to connect one.'
                    )}
                  </div>
                )}
                {data.connections.needing_reconnect > 0 && (
                  <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800" data-testid="analytics-reconnect">
                    {data.connections.needing_reconnect} connected account(s) need to be reconnected before their insights can be refreshed.{' '}
                    {canManageSocialAccounts && (
                      <Link to="/social/accounts" className="font-semibold underline">
                        Open Social Accounts
                      </Link>
                    )}
                  </div>
                )}
                {summary.published === 0 && (
                  <div className="rounded-xl border border-slate-200 bg-white px-4 py-6 text-center text-sm text-slate-500" data-testid="analytics-empty">
                    No posts were published in this period.
                  </div>
                )}
                {summary.published > 0 && data.coverage.fetched === 0 && (
                  <p className="text-sm text-slate-500" data-testid="analytics-not-fetched">
                    Insights have not been fetched for any of these posts yet.
                  </p>
                )}

                <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                  <Card label="Published posts" testId="card-published">
                    {summary.published.toLocaleString()}
                  </Card>
                  <Card label="Reach" testId="card-reach" sub={reportingNote(summary.metrics.reach, summary.published)}>
                    <MetricValue metric={summary.metrics.reach} />
                  </Card>
                  <Card label="Impressions" testId="card-impressions" sub={reportingNote(summary.metrics.impressions, summary.published)}>
                    <MetricValue metric={summary.metrics.impressions} />
                  </Card>
                  <Card label="Engagement" testId="card-engagement" sub={engagementNote(summary)}>
                    <MetricValue metric={summary.engagement} />
                  </Card>
                  <Card label="Video views" testId="card-video-views" sub={reportingNote(summary.metrics.video_views, summary.published)}>
                    <MetricValue metric={summary.metrics.video_views} />
                  </Card>
                  <Card label="Avg. watch time" testId="card-watch-time" sub="View-weighted across videos">
                    <MetricValue metric={summary.metrics.video_avg_watch_time_ms} format={formatWatchTime} />
                  </Card>
                </div>

                <div className="grid grid-cols-2 gap-3 md:grid-cols-5" data-testid="engagement-components">
                  {(['reactions', 'comments', 'shares', 'saves', 'clicks'] as const).map((key) => (
                    <Card key={key} label={key === 'reactions' ? 'Reactions / likes' : key.charAt(0).toUpperCase() + key.slice(1)} testId={`card-${key}`}>
                      <MetricValue metric={summary.metrics[key]} />
                    </Card>
                  ))}
                </div>

                {data.platforms.length > 0 && (
                  <section>
                    <h2 className="mb-2 text-base font-semibold text-slate-900">By platform</h2>
                    <TableCard>
                      <table className="min-w-full divide-y divide-slate-200 text-sm" data-testid="platform-breakdown">
                        <thead className="bg-slate-50">
                          <tr>
                            {['Platform', 'Published', 'Reach', 'Impressions', 'Engagement', 'Video views', 'Insights fetched'].map((h) => (
                              <th key={h} className="px-4 py-3 text-left font-semibold text-slate-600">
                                {h}
                              </th>
                            ))}
                          </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                          {data.platforms.map((row) => (
                            <tr key={row.platform} data-testid={`platform-${row.platform}`}>
                              <td className="px-4 py-3 font-medium text-slate-800">{platformLabel(row.platform)}</td>
                              <td className="px-4 py-3">{row.published}</td>
                              <td className="px-4 py-3">
                                <MetricValue metric={row.metrics.reach} />
                              </td>
                              <td className="px-4 py-3">
                                <MetricValue metric={row.metrics.impressions} />
                              </td>
                              <td className="px-4 py-3">
                                <MetricValue metric={row.engagement} />
                              </td>
                              <td className="px-4 py-3">
                                <MetricValue metric={row.metrics.video_views} />
                              </td>
                              <td className="px-4 py-3 text-slate-600">
                                {row.coverage.fetched} / {row.published}
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </TableCard>
                  </section>
                )}

                <section>
                  <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-base font-semibold text-slate-900">Trend</h2>
                    <select aria-label="Trend metric" className={`${inputClass} mt-0 w-auto`} value={trendMetric} onChange={(e) => setTrendMetric(e.target.value as typeof trendMetric)}>
                      {TREND_METRICS.map((m) => (
                        <option key={m.key} value={m.key}>
                          {m.label}
                        </option>
                      ))}
                    </select>
                  </div>
                  <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="analytics-trend">
                    <p className="mb-2 text-xs text-slate-500">
                      {data.trend.interval === 'week' ? 'Weekly' : 'Daily'} — {trendMetric === 'published' ? 'posts published in each period' : 'lifetime results of the posts published in each period'}; gaps mean no post in that period reported the metric.
                    </p>
                    <div className="h-64">
                      <ResponsiveContainer width="100%" height="100%">
                        <LineChart data={data.trend.points}>
                          <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
                          <XAxis dataKey="period" tick={{ fontSize: 11 }} tickFormatter={(p: string) => p.slice(5)} />
                          <YAxis allowDecimals={false} tick={{ fontSize: 11 }} />
                          <Tooltip />
                          <Line type="monotone" dataKey={trendMetric} name={TREND_METRICS.find((m) => m.key === trendMetric)?.label} stroke="#4f46e5" strokeWidth={2} dot={false} connectNulls={false} />
                        </LineChart>
                      </ResponsiveContainer>
                    </div>
                  </div>
                </section>

                <section>
                  <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-base font-semibold text-slate-900">Top posts</h2>
                    <select aria-label="Rank by" className={`${inputClass} mt-0 w-auto`} value={topMetric} onChange={(e) => setTopMetric(e.target.value as TopMetric)}>
                      {TOP_METRICS.map((m) => (
                        <option key={m.value} value={m.value}>
                          By {m.label.toLowerCase()}
                        </option>
                      ))}
                    </select>
                  </div>
                  {refreshNote && <p className="mb-2 text-xs text-slate-600">{refreshNote}</p>}
                  {topQuery.isLoading && <Loader2 className="mb-2 h-4 w-4 animate-spin text-slate-400" />}
                  <TableCard>
                    <table className="min-w-full divide-y divide-slate-200 text-sm" data-testid="top-posts">
                      <thead className="bg-slate-50">
                        <tr>
                          {['Post', 'Platform', 'Published', TOP_METRICS.find((m) => m.value === topMetric)?.label ?? '', ''].map((h, i) => (
                            <th key={i} className="px-4 py-3 text-left font-semibold text-slate-600">
                              {h}
                            </th>
                          ))}
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-100">
                        {shownTop.length === 0 ? (
                          <tr>
                            <td colSpan={5} className="px-4 py-6 text-center text-slate-500">
                              No post in this period reported this metric.
                            </td>
                          </tr>
                        ) : (
                          shownTop.map((post) => (
                            <tr key={post.post_id} data-testid={`top-post-${post.post_id}`}>
                              <td className="max-w-sm truncate px-4 py-3 text-slate-700" title={post.caption}>
                                {post.caption}
                              </td>
                              <td className="px-4 py-3 text-slate-700">{platformLabel(post.platform)}</td>
                              <td className="px-4 py-3 text-xs text-slate-600">{formatScheduledAt(post.published_at)}</td>
                              <td className="px-4 py-3 font-semibold text-slate-900">{post.value.toLocaleString()}</td>
                              <td className="whitespace-nowrap px-4 py-3 text-right">
                                <button
                                  type="button"
                                  onClick={() => refreshPost(post.post_id)}
                                  disabled={refreshingId === post.post_id}
                                  title={post.fetched_at ? `Insights from ${formatScheduledAt(post.fetched_at)}` : undefined}
                                  className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-60"
                                >
                                  {refreshingId === post.post_id ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <RefreshCw className="h-3.5 w-3.5" />}
                                  Refresh
                                </button>
                              </td>
                            </tr>
                          ))
                        )}
                      </tbody>
                    </table>
                  </TableCard>
                </section>

                {Object.values(data.status_summary).some((n) => n > 0) && (
                  <p className="text-xs text-slate-500" data-testid="status-summary">
                    Not included above (created in this period, not published):{' '}
                    {Object.entries(data.status_summary)
                      .filter(([, n]) => n > 0)
                      .map(([status, n]) => `${n} ${status.replace('_', ' ')}`)
                      .join(', ')}
                    .
                  </p>
                )}
              </>
            )}
          </>
        )}
      </div>
    </div>
  );
}
