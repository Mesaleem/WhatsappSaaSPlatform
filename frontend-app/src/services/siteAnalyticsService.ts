import axiosInstance from '../core/api/axiosInstance';

/** Website visitor analytics (Super Admin). */

export interface SiteAnalyticsBreakdown {
  name: string;
  visits: number;
  avg_seconds?: number;
  avg_pages?: number;
  signups?: number;
}

export interface SiteAnalyticsReport {
  from: string;
  to: string;
  summary: {
    visits: number;
    visitors: number;
    page_views: number;
    avg_duration_seconds: number;
    bounce_rate: number;
    signups: number;
    returning_visits: number;
  };
  daily: { date: string; visits: number; visitors: number }[];
  pages: { path: string; views: number; visits: number; avg_seconds: number; exits: number }[];
  landing_pages: SiteAnalyticsBreakdown[];
  sources: SiteAnalyticsBreakdown[];
  devices: SiteAnalyticsBreakdown[];
  browsers: SiteAnalyticsBreakdown[];
  countries: SiteAnalyticsBreakdown[];
}

export interface SiteVisitRow {
  id: number;
  visitor_id: string;
  is_returning: boolean;
  ip: string | null;
  country: string | null;
  device: string | null;
  browser: string | null;
  os: string | null;
  referrer: string | null;
  source: string;
  utm_source: string | null;
  utm_medium: string | null;
  utm_campaign: string | null;
  landing_path: string;
  exit_path: string | null;
  page_count: number;
  duration_seconds: number;
  signed_up: boolean;
  started_at: string;
  last_seen_at: string;
  page_views: { id: number; path: string; title: string | null; duration_seconds: number; entered_at: string }[];
}

export interface SiteVisitsPage {
  data: SiteVisitRow[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

const siteAnalyticsService = {
  report(range: { from: string; to: string }) {
    return axiosInstance.get<SiteAnalyticsReport>('/admin/site-analytics', { params: range }).then((res) => res.data);
  },

  visits(params: { from: string; to: string; page?: number; source?: string | null }) {
    return axiosInstance
      .get<SiteVisitsPage>('/admin/site-analytics/visits', { params: { ...params, source: params.source || undefined } })
      .then((res) => res.data);
  },
};

export default siteAnalyticsService;
