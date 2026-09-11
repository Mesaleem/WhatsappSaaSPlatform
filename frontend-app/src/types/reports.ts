/**
 * Social Media Marketing & Meta Ads Automation Expansion — Final Phase.
 * Mirrors backend-api's SocialReportController::buildReportData() shape.
 * See that controller's docblock for why total_leads_generated and
 * meta_attributed_leads are two distinct, deliberately unblended numbers.
 */

export interface ReportPeriod {
  from: string;
  to: string;
  label: string;
}

export interface TopCampaignMetric {
  name: string;
  spend: number;
  leads: number;
  cpl: number | null;
}

export interface DailyMetricPoint {
  date: string;
  spend: number;
  leads: number;
}

export interface SocialReportSummary {
  period: ReportPeriod;
  total_ad_spend: number;
  total_leads_generated: number;
  meta_attributed_leads: number;
  average_cpl: number | null;
  combined_impressions: number;
  top_campaigns: TopCampaignMetric[];
  daily_series: DailyMetricPoint[];
}

/** 'YYYY-MM' — omit for the current month, matching the backend's default. */
export interface ReportPeriodParams {
  month?: string;
}
