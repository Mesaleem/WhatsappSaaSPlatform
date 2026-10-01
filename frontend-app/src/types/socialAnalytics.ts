/**
 * Phase 9 Task 5 — account-level social analytics. Mirrors backend-api's
 * SocialAnalyticsService (persisted insight snapshots only).
 */

export type AnalyticsRange = '7d' | '30d' | '90d' | 'custom';

/**
 * available   — the value is the sum of what posts reported (0 is a real zero)
 * unavailable — posts were fetched but none reported this metric
 * not_fetched — posts were published but no insights have been fetched yet
 * no_data     — nothing was published in the period
 */
export type AggregateStatus = 'available' | 'unavailable' | 'not_fetched' | 'no_data';

export interface AggregateMetric {
  value: number | null;
  status: AggregateStatus;
  posts_reporting: number;
}

export type AnalyticsMetricKey = 'impressions' | 'reach' | 'reactions' | 'comments' | 'shares' | 'saves' | 'clicks' | 'video_views' | 'video_avg_watch_time_ms';

export type EngagementComponent = 'reactions' | 'comments' | 'shares' | 'saves';

export interface MetricBlock {
  published: number;
  metrics: Record<AnalyticsMetricKey, AggregateMetric>;
  engagement: {
    value: number | null;
    status: AggregateStatus;
    components_included: EngagementComponent[];
    components_unavailable: EngagementComponent[];
  };
}

export interface Coverage {
  published: number;
  with_provider_id: number;
  without_provider_id: number;
  fetched: number;
  never_fetched: number;
  partial: number;
  reconnect_required: number;
  post_not_found: number;
  provider_error: number;
}

export interface TrendPoint {
  period: string;
  published: number;
  reach: number | null;
  impressions: number | null;
  video_views: number | null;
  engagement: number | null;
}

export type TopMetric = 'engagement' | 'reach' | 'impressions' | 'video_views';

export interface TopPost {
  post_id: number;
  platform: string;
  media_type: string | null;
  caption: string;
  published_at: string | null;
  insights_state: string;
  fetched_at: string | null;
  value: number;
  engagement: number | null;
  engagement_components: Record<EngagementComponent, number | null>;
  reach: number | null;
  impressions: number | null;
  video_views: number | null;
}

export interface SocialAnalyticsDashboard {
  range: { from: string; to: string; days: number; interval: 'day' | 'week'; timezone: string };
  summary: MetricBlock;
  coverage: Coverage;
  platforms: (MetricBlock & { platform: string; coverage: Coverage })[];
  freshness: { last_fetched_at: string | null; never_fetched: number };
  trend: { interval: 'day' | 'week'; points: TrendPoint[] };
  top_posts: TopPost[];
  status_summary: Record<'scheduled' | 'publishing' | 'pending' | 'failed' | 'reconnect_required' | 'cancelled', number>;
  connections: { facebook_pages: number; instagram_accounts: number; needing_reconnect: number };
}

export interface AnalyticsQuery {
  range: AnalyticsRange;
  from?: string;
  to?: string;
}
