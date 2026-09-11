/**
 * Social Media Marketing & Meta Ads Automation Expansion — Final Phase.
 * Mirrors backend-api's AICopywriterController / CopywriterService JSON shape.
 */

export type AdCopyTone = 'Urgent' | 'High-Converting' | 'Professional';

export const AD_COPY_TONES: AdCopyTone[] = ['Urgent', 'High-Converting', 'Professional'];

export interface GenerateAdCopyPayload {
  product_name: string;
  target_industry: string;
  tone: AdCopyTone;
}

export interface AdCopyVariant {
  hook: string;
  caption: string;
  cta: string;
}

/** provider is 'openai' | 'anthropic' | 'template' — surfaced so the tenant/operator can tell which path served the copy. */
export interface GenerateAdCopyResult {
  provider: string;
  variants: AdCopyVariant[];
}
