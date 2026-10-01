/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 3.
 * Mirrors backend-api's App\Models\AdCampaign + AdCampaignController's
 * JSON shape.
 */

/** Social/Ads Launcher Overhaul — Step 4 (Click-to-WhatsApp Ads). See backend AdCampaign::OBJECTIVES. */
export type AdObjective = 'LEAD_GENERATION' | 'MESSAGES' | 'TRAFFIC' | 'CLICK_TO_WHATSAPP';

/**
 * Phase 10 Task 2 — lifecycle states (backend AdCampaign::STATUSES). Only ACTIVE
 * and PAUSED can be paused/resumed; UNCONFIRMED means Meta never confirmed the
 * launch (it is never resent automatically); UNAVAILABLE means Meta reports the
 * campaign no longer exists or cannot be loaded.
 */
export type AdCampaignStatus = 'LAUNCHING' | 'ACTIVE' | 'PAUSED' | 'FAILED' | 'UNCONFIRMED' | 'UNAVAILABLE';

export const AD_OBJECTIVE_LABELS: Record<AdObjective, string> = {
  LEAD_GENERATION: 'Lead Generation',
  MESSAGES: 'Messages',
  TRAFFIC: 'Traffic',
  CLICK_TO_WHATSAPP: 'Click to WhatsApp',
};

/** A Meta targeting location from GET /social/ads/locations (backend MetaAdsService::searchLocations()). */
export interface AdLocation {
  key: string;
  name: string;
  type: 'country' | 'region' | 'city';
  country_code?: string | null;
  country_name?: string | null;
  region?: string | null;
}

/** Manual placements (backend MetaAdsService::PLACEMENTS). None selected = automatic placements. */
export type AdPlacement = 'facebook_feed' | 'facebook_stories' | 'facebook_reels' | 'instagram_feed' | 'instagram_stories' | 'instagram_reels';

export const AD_PLACEMENT_LABELS: Record<AdPlacement, string> = {
  facebook_feed: 'Facebook Feed',
  facebook_stories: 'Facebook Stories',
  facebook_reels: 'Facebook Reels',
  instagram_feed: 'Instagram Feed',
  instagram_stories: 'Instagram Stories',
  instagram_reels: 'Instagram Reels',
};

/** The ad's button (Meta call_to_action.type). */
export type AdCallToAction =
  | 'SIGN_UP' | 'LEARN_MORE' | 'APPLY_NOW' | 'GET_QUOTE' | 'SUBSCRIBE' | 'DOWNLOAD' | 'GET_OFFER' | 'BOOK_TRAVEL'
  | 'CONTACT_US' | 'SHOP_NOW' | 'MESSAGE_PAGE' | 'WHATSAPP_MESSAGE';

export const AD_CTA_LABELS: Record<AdCallToAction, string> = {
  SIGN_UP: 'Sign Up',
  LEARN_MORE: 'Learn More',
  APPLY_NOW: 'Apply Now',
  GET_QUOTE: 'Get Quote',
  SUBSCRIBE: 'Subscribe',
  DOWNLOAD: 'Download',
  GET_OFFER: 'Get Offer',
  BOOK_TRAVEL: 'Book Now',
  CONTACT_US: 'Contact Us',
  SHOP_NOW: 'Shop Now',
  MESSAGE_PAGE: 'Send Message',
  WHATSAPP_MESSAGE: 'Send WhatsApp Message',
};

/** Buttons available per objective — mirrors backend MetaAdsService::CALL_TO_ACTIONS (first = default). */
export const AD_CTAS_BY_OBJECTIVE: Record<AdObjective, AdCallToAction[]> = {
  LEAD_GENERATION: ['SIGN_UP', 'LEARN_MORE', 'APPLY_NOW', 'GET_QUOTE', 'SUBSCRIBE', 'DOWNLOAD', 'GET_OFFER', 'BOOK_TRAVEL', 'CONTACT_US'],
  TRAFFIC: ['LEARN_MORE', 'SHOP_NOW', 'SIGN_UP', 'APPLY_NOW', 'GET_QUOTE', 'BOOK_TRAVEL', 'CONTACT_US', 'DOWNLOAD', 'GET_OFFER', 'SUBSCRIBE'],
  MESSAGES: ['LEARN_MORE', 'MESSAGE_PAGE', 'SHOP_NOW'],
  CLICK_TO_WHATSAPP: ['WHATSAPP_MESSAGE'],
};

/** GET /social/ads/account — the connected Meta ad account, amounts in major units of its currency. */
export interface AdAccountInfo {
  name: string | null;
  currency: string | null;
  account_status: number | null;
  account_status_label: string | null;
  runnable: boolean | null;
  amount_spent: number | null;
  spend_cap: number | null;
  remaining_spend_cap: number | null;
  balance: number | null;
}

/** Money in the ad account's own currency (INR when not known). */
export function formatAdMoney(value: number, currency?: string | null): string {
  const code = currency || 'INR';
  try {
    return new Intl.NumberFormat(code === 'INR' ? 'en-IN' : undefined, { style: 'currency', currency: code }).format(value);
  } catch {
    return `${code} ${value.toFixed(2)}`;
  }
}

export interface TargetingSpecs {
  /** ISO 3166-1 alpha-2 country codes (e.g. "IN", "US"). Optional when `locations` are given. */
  countries?: string[];
  /** Countries / regions / cities picked through the location search. */
  locations?: Pick<AdLocation, 'key' | 'type' | 'name'>[];
  age_min: number;
  age_max: number;
  interests?: string[];
}

export interface AdCreative {
  image_url: string | null;
  headline: string;
  primary_text: string;
  call_to_action?: AdCallToAction;
}

/** POST /api/social/ads/launch — account_id is NOT sent; the tenant is resolved server-side (see AdCampaignController's docblock). */
export interface LaunchCampaignPayload {
  campaign_name: string;
  objective: AdObjective;
  daily_budget: number;
  cpl_threshold?: number | null;
  targeting_specs: TargetingSpecs;
  creative: AdCreative;
  /** Empty / omitted = automatic placements. */
  placements?: AdPlacement[];
}

export interface AdCampaign {
  id: number;
  /** Null until Meta returns the campaign id (a launch rejected/lost at the first step). */
  meta_campaign_id: string | null;
  name: string;
  objective: AdObjective;
  status: AdCampaignStatus;
  daily_budget: number;
  /** The ad account's currency at launch (null = not known). */
  currency?: string | null;
  cpl_threshold: number | null;
  spend: number;
  impressions: number;
  leads: number;
  cpl: number | null;
  last_checked_at: string | null;
  auto_paused_at: string | null;
  auto_pause_reason: string | null;
  /** Phase 10 Task 2 — safe summary of the last Meta failure (never Meta's raw text). */
  last_provider_error?: string | null;
  last_provider_error_at?: string | null;
  created_at: string | null;
}

export interface AdCampaignResponse {
  message: string;
  data: AdCampaign;
}
