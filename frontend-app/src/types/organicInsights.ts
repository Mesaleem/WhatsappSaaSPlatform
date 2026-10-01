/**
 * Phase 9 Task 4 — organic post insights. Mirrors backend-api's
 * PostInsightsService::present() and OrganicPostInsightsController::summary().
 */

export type InsightMetricKey =
  | 'impressions'
  | 'reach'
  | 'reactions'
  | 'comments'
  | 'shares'
  | 'saves'
  | 'clicks'
  | 'video_views'
  | 'video_avg_watch_time_ms';

export type InsightsState =
  | 'not_applicable'
  | 'not_fetched'
  | 'ok'
  | 'partial'
  | 'post_not_found'
  | 'reconnect_required'
  | 'rate_limited'
  | 'provider_error';

export type UnavailableReason = 'not_supported' | 'permission' | 'unsupported_metric' | 'not_reported';

/** `available` with value 0 is a real zero; `unavailable` / `not_fetched` carry no value. */
export interface InsightMetric {
  value: number | null;
  status: 'available' | 'unavailable' | 'not_fetched';
  reason: UnavailableReason | null;
}

export interface PostInsights {
  post_id: number;
  platform: string;
  post_status: string;
  state: InsightsState;
  not_applicable_reason: string | null;
  fetched_at: string | null;
  last_attempted_at: string | null;
  next_refresh_at: string | null;
  error_code: string | null;
  error_message: string | null;
  refresh_in_progress: boolean;
  can_refresh: boolean;
  refresh_available_at: string | null;
  reconnect_path?: string;
  metrics: Record<InsightMetricKey, InsightMetric>;
}

export interface InsightsSummary {
  posts_published: number;
  posts_with_insights: number;
  last_fetched_at: string | null;
  totals: Record<InsightMetricKey, { value: number | null; posts: number }>;
  posts: PostInsights[];
}

export type RefreshOutcome = 'refreshed' | 'recently_refreshed' | 'backing_off' | 'in_progress' | 'not_due';

export const INSIGHT_METRICS: { key: InsightMetricKey; label: string }[] = [
  { key: 'impressions', label: 'Impressions' },
  { key: 'reach', label: 'Reach' },
  { key: 'reactions', label: 'Reactions / likes' },
  { key: 'comments', label: 'Comments' },
  { key: 'shares', label: 'Shares' },
  { key: 'saves', label: 'Saves' },
  { key: 'clicks', label: 'Clicks' },
  { key: 'video_views', label: 'Video views' },
  { key: 'video_avg_watch_time_ms', label: 'Avg. watch time' },
];

export const UNAVAILABLE_REASON_LABELS: Record<UnavailableReason, string> = {
  not_supported: 'Not offered for this type of post',
  permission: 'Needs the insights permission — reconnect the account with insights access',
  unsupported_metric: 'Not available for this post',
  not_reported: 'Not reported by the platform',
};

export const formatInsightValue = (key: InsightMetricKey, value: number) =>
  key === 'video_avg_watch_time_ms' ? `${(value / 1000).toFixed(1)} s` : value.toLocaleString();
