import axiosInstance from '../core/api/axiosInstance';
import type { AnalyticsQuery, SocialAnalyticsDashboard, TopMetric, TopPost } from '../types/socialAnalytics';

const BASE = '/social/analytics';

const params = (query: AnalyticsQuery) =>
  query.range === 'custom' ? { range: 'custom', from: query.from, to: query.to } : { range: query.range };

/**
 * Phase 9 Task 5 — account-level social analytics (stored insights only;
 * never triggers a platform call). The selected client is attached by
 * axiosInstance (?account_id=) for a Super Admin.
 */
const socialAnalyticsService = {
  dashboard(query: AnalyticsQuery) {
    return axiosInstance.get<{ data: SocialAnalyticsDashboard }>(`${BASE}/dashboard`, { params: params(query) }).then((res) => res.data.data);
  },

  topPosts(query: AnalyticsQuery, metric: TopMetric) {
    return axiosInstance
      .get<{ data: { metric: TopMetric; posts: TopPost[] } }>(`${BASE}/top-posts`, { params: { ...params(query), metric } })
      .then((res) => res.data.data.posts);
  },
};

export default socialAnalyticsService;
