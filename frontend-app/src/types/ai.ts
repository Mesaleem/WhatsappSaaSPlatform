/**
 * Social/Ads Launcher Overhaul — Step 1 (Gemini Pro Engine & Multi-Token
 * Prompt Refactor). Mirrors backend-api's AICopywriterController /
 * CopywriterService JSON shape.
 */

export type AdCopyTone = 'Urgent' | 'High-Converting' | 'Professional';

export const AD_COPY_TONES: AdCopyTone[] = ['Urgent', 'High-Converting', 'Professional'];

/**
 * Shapes tone/CTA framing server-side (see CopywriterService::userPrompt()):
 * organic copy optimizes for engagement (comment/share/save), paid copy
 * optimizes for conversion (a clear next step toward submitting details).
 */
export type TargetGoal = 'ORGANIC_POST' | 'PAID_LEAD_AD';

export const TARGET_GOAL_LABELS: Record<TargetGoal, string> = {
  ORGANIC_POST: 'Organic Post (free reach & engagement)',
  PAID_LEAD_AD: 'Paid Lead Ad (budget-backed conversions)',
};

/**
 * Renamed from the prior `product_name` (which the wizard actually
 * populated with the campaign's internal name, not a real business/
 * product name — see the module audit's gap analysis) and extended with
 * `offer_details` and `target_goal`, the 2 tokens that were missing
 * entirely before this refactor.
 */
export interface GenerateAdCopyPayload {
  business_name: string;
  target_industry: string;
  /** Free text, e.g. "50% off", "New 2BHK flat batch opening". Optional — an empty string is fine. */
  offer_details: string;
  target_goal: TargetGoal;
  tone: AdCopyTone;
}

export interface AdCopyVariant {
  hook: string;
  caption: string;
  cta: string;
}

/** provider is 'gemini' | 'openai' | 'anthropic' | 'template' — surfaced so the tenant/operator can tell which path served the copy. Gemini is now tried first. */
export interface GenerateAdCopyResult {
  provider: string;
  variants: AdCopyVariant[];
}
