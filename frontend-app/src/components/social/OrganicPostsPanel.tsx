import { Fragment, useCallback, useEffect, useState } from 'react';
import { AlertCircle, BarChart3, Loader2, RefreshCw, RotateCcw, XCircle } from 'lucide-react';
import { TableCard } from '../common/Card';
import { indigo } from '../../theme/signalIndigo';
import organicPostService from '../../services/organicPostService';
import { extractErrorMessage } from '../../utils/apiError';
import { socialConnectionError, type SocialConnectionErrorInfo } from '../../utils/socialConnectionError';
import SocialReconnectNotice from './SocialReconnectNotice';
import OrganicPostInsights from './OrganicPostInsights';
import { formatInsightValue } from '../../types/organicInsights';
import type { InsightMetricKey, InsightsSummary, PostInsights } from '../../types/organicInsights';
import { formatScheduledAt, ORGANIC_PLATFORM_LABELS, ORGANIC_STATUS_LABELS } from '../../types/organic';
import type { OrganicPost, OrganicPostStatus } from '../../types/organic';

const STATUS_STYLES: Record<OrganicPostStatus, string> = {
  scheduled: 'bg-indigo-50 text-indigo-700',
  publishing: 'bg-amber-50 text-amber-700',
  pending: 'bg-amber-50 text-amber-700',
  published: 'bg-emerald-50 text-emerald-700',
  failed: 'bg-red-50 text-red-700',
  reconnect_required: 'bg-orange-50 text-orange-700',
  cancelled: 'bg-slate-100 text-slate-600',
};

/** Phase 9 Task 4 — account totals shown above the table. */
const SUMMARY_METRICS: { key: InsightMetricKey; label: string }[] = [
  { key: 'reach', label: 'Reach' },
  { key: 'reactions', label: 'Reactions / likes' },
  { key: 'comments', label: 'Comments' },
  { key: 'shares', label: 'Shares' },
  { key: 'video_views', label: 'Video views' },
];

/** Retrying these may post twice: the platform may already have published it. */
const OUTCOME_UNKNOWN = 'outcome_unknown';

/**
 * Phase 9 Task 3 — recent organic posts (manual and scheduled) with their
 * lifecycle status, and the two actions the backend allows: Cancel (a post
 * that has not started publishing) and Retry (a failed or reconnect-required
 * post; queued to send now). Buttons follow the server's can_cancel /
 * can_retry flags — the backend re-checks every action.
 */
export default function OrganicPostsPanel({
  refreshKey,
  canManageSocialAccounts = false,
  canViewInsights = false,
}: {
  refreshKey: number;
  canManageSocialAccounts?: boolean;
  /** Phase 9 Task 4 — view-social-analytics + social_accounts module + social capability. */
  canViewInsights?: boolean;
}) {
  const [posts, setPosts] = useState<OrganicPost[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [connectionError, setConnectionError] = useState<SocialConnectionErrorInfo | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  // Outcome-unknown posts need a second, explicit click before a retry.
  const [confirmRetryId, setConfirmRetryId] = useState<number | null>(null);
  // Phase 9 Task 4 — stored insights (the summary endpoint never calls the platform).
  const [summary, setSummary] = useState<InsightsSummary | null>(null);
  const [insightsError, setInsightsError] = useState<string | null>(null);
  const [expandedId, setExpandedId] = useState<number | null>(null);

  const load = useCallback(() => {
    setIsLoading(true);
    organicPostService
      .list()
      .then((data) => {
        setPosts(data);
        setError(null);
      })
      .catch((err: unknown) => setError(extractErrorMessage(err, 'Failed to load organic posts.')))
      .finally(() => setIsLoading(false));

    if (canViewInsights) {
      organicPostService
        .insightsSummary()
        .then((data) => {
          setSummary(data);
          setInsightsError(null);
        })
        .catch((err: unknown) => setInsightsError(extractErrorMessage(err, 'Insights could not be loaded.')));
    }
  }, [canViewInsights]);

  const insightsFor = (postId: number): PostInsights | undefined => summary?.posts.find((p) => p.post_id === postId);

  const updateInsights = (updated: PostInsights) =>
    setSummary((prev) => (prev ? { ...prev, posts: prev.posts.map((p) => (p.post_id === updated.post_id ? updated : p)) } : prev));

  useEffect(() => {
    load();
  }, [load, refreshKey]);

  const act = (post: OrganicPost, action: 'cancel' | 'retry') => {
    if (action === 'retry' && post.failure_code === OUTCOME_UNKNOWN && confirmRetryId !== post.id) {
      setConfirmRetryId(post.id);
      return;
    }

    setConfirmRetryId(null);
    setBusyId(post.id);
    setError(null);
    setConnectionError(null);

    const request = action === 'cancel' ? organicPostService.cancel(post.id) : organicPostService.retry(post.id);

    request
      .then((updated) => setPosts((prev) => prev.map((p) => (p.id === updated.id ? updated : p))))
      .catch((err: unknown) => {
        const connection = socialConnectionError(err);
        if (connection) {
          setConnectionError(connection);
        } else {
          setError(extractErrorMessage(err, action === 'cancel' ? 'Could not cancel the post.' : 'Could not retry the post.'));
          load();
        }
      })
      .finally(() => setBusyId(null));
  };

  return (
    <div className="mt-8" data-testid="organic-posts-panel">
      <div className="mb-2 flex items-center justify-between">
        <h2 className="font-display text-base font-bold" style={{ color: indigo.ink }}>
          Organic Posts
        </h2>
        <button
          type="button"
          onClick={load}
          className="flex items-center gap-1.5 rounded-lg px-2 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-100"
          aria-label="Refresh organic posts"
        >
          <RefreshCw className={`h-3.5 w-3.5 ${isLoading ? 'animate-spin' : ''}`} />
          Refresh
        </button>
      </div>

      {connectionError && (
        <div className="mb-3">
          <SocialReconnectNotice info={connectionError} canManageSocialAccounts={canManageSocialAccounts} />
        </div>
      )}
      {error && (
        <div className="mb-3 flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          <AlertCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
          {error}
        </div>
      )}

      {canViewInsights && summary && (
        <div className="mb-3 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6" data-testid="organic-insights-summary">
          <div className="rounded-xl border border-slate-200 bg-white px-3 py-2 shadow-sm">
            <p className="text-[11px] font-medium text-slate-500">Posts with insights</p>
            <p className="text-sm font-semibold text-slate-900">
              {summary.posts_with_insights} / {summary.posts_published}
            </p>
          </div>
          {SUMMARY_METRICS.map(({ key, label }) => {
            const total = summary.totals[key];
            return (
              <div key={key} className="rounded-xl border border-slate-200 bg-white px-3 py-2 shadow-sm" data-testid={`summary-${key}`}>
                <p className="text-[11px] font-medium text-slate-500">{label}</p>
                <p className="text-sm font-semibold text-slate-900">{total.value !== null ? formatInsightValue(key, total.value) : <span className="text-xs font-normal text-slate-400">Not available</span>}</p>
              </div>
            );
          })}
        </div>
      )}
      {canViewInsights && insightsError && <p className="mb-3 text-xs text-slate-500">{insightsError}</p>}

      <TableCard>
        <table className="min-w-full divide-y divide-slate-200 text-sm">
          <thead className="bg-slate-50">
            <tr>
              <th className="px-4 py-3 text-left font-semibold text-slate-600">Platform</th>
              <th className="px-4 py-3 text-left font-semibold text-slate-600">Caption</th>
              <th className="px-4 py-3 text-left font-semibold text-slate-600">Status</th>
              <th className="px-4 py-3 text-left font-semibold text-slate-600">When</th>
              <th className="px-4 py-3 text-right font-semibold text-slate-600">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && posts.length === 0 ? (
              <tr>
                <td colSpan={5} className="px-4 py-6 text-center text-slate-500">
                  <Loader2 className="mx-auto h-4 w-4 animate-spin" />
                </td>
              </tr>
            ) : posts.length === 0 ? (
              <tr>
                <td colSpan={5} className="px-4 py-6 text-center text-slate-500">
                  No organic posts yet.
                </td>
              </tr>
            ) : (
              posts.map((post) => {
                const insights = canViewInsights ? insightsFor(post.id) : undefined;
                const hasInsights = insights !== undefined && insights.state !== 'not_applicable';
                return (
                <Fragment key={post.id}>
                <tr data-testid={`organic-post-${post.id}`}>
                  <td className="px-4 py-3 text-slate-700">{ORGANIC_PLATFORM_LABELS[post.platform] ?? post.platform}</td>
                  <td className="max-w-xs truncate px-4 py-3 text-slate-700" title={post.caption}>
                    {post.caption}
                  </td>
                  <td className="px-4 py-3">
                    <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ${STATUS_STYLES[post.status] ?? ''}`}>
                      {ORGANIC_STATUS_LABELS[post.status] ?? post.status}
                    </span>
                    {post.error_message && post.status !== 'published' && (
                      <p className="mt-1 max-w-[260px] text-xs text-slate-500">{post.error_message}</p>
                    )}
                    {confirmRetryId === post.id && (
                      <p className="mt-1 max-w-[260px] text-xs font-medium text-amber-700" data-testid="organic-retry-warning">
                        This post may already be live. Check the page first — click Retry anyway to publish it again.
                      </p>
                    )}
                  </td>
                  <td className="px-4 py-3 text-xs text-slate-600">
                    {post.status === 'published'
                      ? formatScheduledAt(post.published_at)
                      : post.status === 'scheduled' && post.next_attempt_at
                        ? `Next attempt ${formatScheduledAt(post.next_attempt_at)}`
                        : formatScheduledAt(post.scheduled_at ?? post.created_at)}
                  </td>
                  <td className="whitespace-nowrap px-4 py-3 text-right">
                    {hasInsights && (
                      <button
                        type="button"
                        onClick={() => setExpandedId((id) => (id === post.id ? null : post.id))}
                        aria-expanded={expandedId === post.id}
                        className="ml-2 inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                      >
                        <BarChart3 className="h-3.5 w-3.5" />
                        Insights
                      </button>
                    )}
                    {post.can_cancel && (
                      <button
                        type="button"
                        onClick={() => act(post, 'cancel')}
                        disabled={busyId === post.id}
                        className="ml-2 inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-50 disabled:opacity-60"
                      >
                        <XCircle className="h-3.5 w-3.5" />
                        Cancel
                      </button>
                    )}
                    {post.can_retry && (
                      <button
                        type="button"
                        onClick={() => act(post, 'retry')}
                        disabled={busyId === post.id}
                        className="ml-2 inline-flex items-center gap-1 rounded-lg border border-indigo-200 px-2.5 py-1 text-xs font-semibold text-indigo-700 hover:bg-indigo-50 disabled:opacity-60"
                      >
                        <RotateCcw className="h-3.5 w-3.5" />
                        {confirmRetryId === post.id ? 'Retry anyway' : 'Retry'}
                      </button>
                    )}
                  </td>
                </tr>
                {hasInsights && expandedId === post.id && insights && (
                  <tr>
                    <td colSpan={5} className="px-4 pb-4">
                      <OrganicPostInsights initial={insights} canManageSocialAccounts={canManageSocialAccounts} onChange={updateInsights} />
                    </td>
                  </tr>
                )}
                </Fragment>
                );
              })
            )}
          </tbody>
        </table>
      </TableCard>
    </div>
  );
}
