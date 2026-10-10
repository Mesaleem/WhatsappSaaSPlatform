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

/** provider is the central AI provider that served the copy ('openai' | 'anthropic' today; Phase 8 Task 6 removed the direct Gemini call) or 'template' — surfaced so the tenant/operator can tell which path served the copy. */
export interface GenerateAdCopyResult {
  provider: string;
  variants: AdCopyVariant[];
}

/**
 * Phase 8 Task 12 (continued) — Super Admin's platform-level AI provider/
 * model settings (AiGatewayController). Mirrors SocialProviderConfigRow's
 * shape/contract (types/social.ts) for the same reason that controller
 * mirrors SocialGatewayController: a DB-editable override ahead of
 * config('ai.*'), so a deprecated/renamed model or a rotated key is fixed
 * from this screen with no env change or redeploy.
 */
export type AiProvider = 'openai' | 'anthropic' | 'gemini' | 'groq';

export interface AiProviderSettingsRow {
  provider: AiProvider;
  /** null = no platform override; AI_ENABLED_PROVIDERS decides. true/false = an explicit Super Admin override either way. */
  is_enabled: boolean | null;
  model: string | null;
  allowed_models: string[];
  api_key_set: boolean;
  base_url: string | null;
  tokens_per_credit_override: number | null;
  notes: string | null;
  updated_by: number | null;
  updated_at: string | null;
}

/** POST /api/admin/ai/provider-settings/{provider} — partial update; an omitted api_key is left untouched. */
export interface UpdateAiProviderSettingsPayload {
  is_enabled?: boolean | null;
  model?: string | null;
  allowed_models?: string[] | null;
  api_key?: string | null;
  base_url?: string | null;
  tokens_per_credit_override?: number | null;
  notes?: string | null;
}

/**
 * Phase 8 Task 13 — "Generate with AI" draft for the Template Designer
 * (AITemplateController). account_id is REQUIRED: it names which
 * account's AI credits pay for the draft (AiAuthorizer has no "global
 * AI" path), independent of which account the eventually-SAVED template
 * belongs to — this endpoint never saves anything itself.
 */
export interface GenerateTemplateDraftPayload {
  account_id: number;
  purpose: string;
  category: 'MARKETING' | 'UTILITY' | 'AUTHENTICATION';
  tone?: string;
  variable_keys?: string[];
}

/** provider: the AI provider that served the draft, or 'template' — the deterministic, zero-external-call fallback used when every configured provider is unavailable/failed/timed out (never charged). */
export interface GenerateTemplateDraftResult {
  provider: string;
  title: string;
  template_body: string;
}

/**
 * Phase 8 Task 14 — "Generate with AI" draft for the Journey Builder
 * (AIJourneyController). account_id is REQUIRED for the same reason as
 * GenerateTemplateDraftPayload — AiAuthorizer has no "global AI" path.
 * This endpoint never saves a WhatsAppFlow; the caller loads graph_data
 * onto JourneyCanvasEditor's own canvas and the user reviews/saves it
 * through the existing Save/Publish flow.
 */
export interface GenerateJourneyDraftPayload {
  account_id: number;
  description: string;
  goal?: string;
}

/**
 * graph_data is intentionally typed loosely here (not imported from
 * ../types/journey) — JourneyCanvasEditor is the one place that knows
 * how to merge it into its own JourneyGraph/JourneyNode/JourneyEdge
 * shapes (adding the canvas-only 'end' node, etc.), and this file has no
 * reason to import the Journey Builder's types just to pass them through
 * unopened.
 */
export interface GenerateJourneyDraftResult {
  provider: string;
  graph_data: {
    nodes: Array<{ id: string; type: string; position: { x: number; y: number }; data: Record<string, unknown> }>;
    edges: Array<{ id: string; source: string; target: string; sourceHandle?: string }>;
  };
}
