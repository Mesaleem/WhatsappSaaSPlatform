/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 3.
 * Mirrors backend-api's App\Models\AdCampaign + AdCampaignController's
 * JSON shape.
 */

export type AdObjective = 'LEAD_GENERATION' | 'MESSAGES' | 'TRAFFIC';

export type AdCampaignStatus = 'ACTIVE' | 'PAUSED';

export const AD_OBJECTIVE_LABELS: Record<AdObjective, string> = {
  LEAD_GENERATION: 'Lead Generation',
  MESSAGES: 'Messages',
  TRAFFIC: 'Traffic',
};

export interface TargetingSpecs {
  /** ISO 3166-1 alpha-2 country codes (e.g. "IN", "US") — see MetaAdsService's disclosed country-only targeting gap. */
  countries: string[];
  age_min: number;
  age_max: number;
  interests?: string[];
}

export interface AdCreative {
  image_url: string | null;
  headline: string;
  primary_text: string;
}

/** POST /api/social/ads/launch — account_id is NOT sent; the tenant is resolved server-side (see AdCampaignController's docblock). */
export interface LaunchCampaignPayload {
  campaign_name: string;
  objective: AdObjective;
  daily_budget: number;
  cpl_threshold?: number | null;
  targeting_specs: TargetingSpecs;
  creative: AdCreative;
}

export interface AdCampaign {
  id: number;
  meta_campaign_id: string;
  name: string;
  objective: AdObjective;
  status: AdCampaignStatus;
  daily_budget: number;
  cpl_threshold: number | null;
  spend: number;
  impressions: number;
  leads: number;
  cpl: number | null;
  last_checked_at: string | null;
  auto_paused_at: string | null;
  auto_pause_reason: string | null;
  created_at: string | null;
}

export interface AdCampaignResponse {
  message: string;
  data: AdCampaign;
}
