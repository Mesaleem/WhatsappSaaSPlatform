/**
 * Public marketing website content (GET /api/public/site). Each top-level
 * key is one section the Super Admin edits in Website Content; the backend
 * defaults live in App\Support\SiteContentDefaults. Every field is treated
 * as optional on read, since a Super Admin may save a section with fields
 * removed.
 */
export interface SiteLink {
  label: string;
  url: string;
}

export interface TitledItem {
  title: string;
  description: string;
}

export interface SiteBrand {
  name: string;
  tagline: string;
  description: string;
  support_email: string;
  support_phone: string;
  address: string;
  business_hours?: string;
  signup_enabled: boolean;
  trial_days: number;
  trial_messages: number;
  social_links: SiteLink[];
}

export interface SiteHome {
  hero: { eyebrow: string; title: string; subtitle: string; primary_cta: string; secondary_cta: string };
  highlights: { value: string; label: string }[];
  features_title: string;
  features_subtitle: string;
  steps_title: string;
  steps: TitledItem[];
  industries_title: string;
  industries: TitledItem[];
  cta: { title: string; subtitle: string; button: string };
}

export interface SiteFeatureModule {
  icon: string;
  title: string;
  description: string;
  bullets: string[];
}

export interface SiteFeatures {
  title: string;
  subtitle: string;
  modules: SiteFeatureModule[];
}

export interface SiteAbout {
  title: string;
  subtitle: string;
  story_title: string;
  story: string[];
  mission_title: string;
  mission: string;
  values_title: string;
  values: TitledItem[];
}

export interface SitePricing {
  title: string;
  subtitle: string;
  notes: string[];
  cta: string;
  featured_plan: string;
  plan_features: { plan: string; features: string[] }[];
}

export interface SiteContact {
  title: string;
  subtitle: string;
  topics: string[];
  success: string;
  response_time?: string;
  side_title?: string;
  side_text?: string;
  side_points?: string[];
}

export interface SiteFaq {
  title: string;
  items: { question: string; answer: string }[];
}

export interface SiteLegalPage {
  title: string;
  updated: string;
  intro?: string;
  highlights?: string[];
  sections: { heading: string; body: string }[];
}

export interface SiteLegal {
  privacy: SiteLegalPage;
  terms: SiteLegalPage;
  data_deletion: SiteLegalPage;
}

export interface SiteContent {
  brand: Partial<SiteBrand>;
  home: Partial<SiteHome>;
  features: Partial<SiteFeatures>;
  about: Partial<SiteAbout>;
  pricing: Partial<SitePricing>;
  contact: Partial<SiteContact>;
  faq: Partial<SiteFaq>;
  legal: Partial<SiteLegal>;
}

export type SiteContentKey = keyof SiteContent;

export interface PublicPlan {
  key: string;
  label: string;
  description: string | null;
  engine_type: string;
  billing_model: string;
  total_allocated_messages: number | null;
  duration_days: number;
  price: string;
  tax_amount: string;
  total_amount: string;
  features: string[];
  /** Plan restructure (2026-10-15): users / templates / journeys / broadcasts / marketing caps (null = unlimited). */
  limits?: import('./account').PlanLimits;
}

export interface ContactPayload {
  name: string;
  email: string;
  phone?: string;
  company?: string;
  topic?: string;
  message: string;
  website?: string;
}

export interface RegisterPayload {
  company_name: string;
  name: string;
  email: string;
  phone?: string;
  password: string;
  password_confirmation: string;
  accept_terms: boolean;
  /** The 6-digit code emailed by sendSignupCode(). */
  email_code?: string;
}

export interface ContactMessageRow {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  company: string | null;
  topic: string | null;
  message: string;
  ip: string | null;
  handled_at: string | null;
  created_at: string;
}
