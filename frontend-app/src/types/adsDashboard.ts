/**
 * Phase 10 Task 3 — GET /api/social/ads/dashboard (backend AdsDashboardService).
 * Stored data only. A value that is not known is null with a status — never 0.
 */
import type { AdCampaignStatus, AdObjective } from './ads';

export type AdsRange = '7d' | '30d' | '90d' | 'custom';

export type AdsDashboardQuery = { range: Exclude<AdsRange, 'custom'> } | { range: 'custom'; from: string; to: string };

export type AdsMetricStatus = 'available' | 'not_fetched' | 'unavailable' | 'not_applicable';

export interface AdsMetric {
  value: number | null;
  status: AdsMetricStatus;
}

export interface AdsFunnelStage {
  stage: 'ads' | 'referrals' | 'conversations' | 'leads' | 'journeys' | 'conversions';
  label: string;
  count: number;
}

export interface AdsCampaignReportRow {
  id: number;
  name: string;
  objective: AdObjective;
  status: AdCampaignStatus;
  spend: number | null;
  spend_status: AdsMetricStatus;
  impressions: number | null;
  referrals: number;
  conversations: number;
  leads: number;
  journeys: number;
  conversions: number;
  conversion_rate: number | null;
  cost_per_lead: number | null;
  conversion_value: number | null;
  roas: number | null;
  last_checked_at: string | null;
}

export interface AdsDashboard {
  range: { from: string; to: string; days: number };
  /** The ad account currency stored at launch; null = unknown or mixed. */
  currency?: string | null;
  campaigns_summary: {
    total: number;
    active: number;
    paused: number;
    launching: number;
    failed: number;
    unconfirmed: number;
    unavailable: number;
  };
  spend: {
    total: AdsMetric;
    impressions: AdsMetric;
    campaigns_with_spend: number;
    daily: { date: string; spend: number | null }[];
  };
  attribution: {
    ads: number;
    referrals: number;
    conversations: number;
    leads: number;
    journeys: number;
    conversions: number;
    conversion_rate: AdsMetric;
    conversion_value: AdsMetric;
    unlinked_referrals: number;
    cost_per_lead: AdsMetric;
    roas: AdsMetric;
  };
  funnel: AdsFunnelStage[];
  campaigns: AdsCampaignReportRow[];
  campaigns_truncated: boolean;
  notes: Record<string, string>;
}
