import axiosInstance from '../core/api/axiosInstance';
import { AI_CREDITS_CHANGED_EVENT } from './aiCreditService';
import type {
  GenerateAdCopyPayload,
  GenerateAdCopyResult,
  GenerateJourneyDraftPayload,
  GenerateJourneyDraftResult,
  GenerateTemplateDraftPayload,
  GenerateTemplateDraftResult,
} from '../types/ai';

export type AiWritingLanguage = 'english' | 'hindi' | 'hinglish';

const BASE = '/social/ai';

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Final Phase.
 * Axios service for the AI Ad Copywriter (embedded in MetaAdsPage's
 * LaunchWizardModal — "✨ Generate with AI").
 */
const aiService = {
  /**
   * Phase 8 Task 6 — generation is metered AI (it consumes the account's AI
   * credits). One Idempotency-Key per generation: a retry of THIS request
   * reuses it and can never be charged twice; a new click is a new key.
   * `idempotencyKey` is exposed only so a caller that retries can pass the
   * same key again.
   */
  generate(payload: GenerateAdCopyPayload, idempotencyKey: string = newIdempotencyKey()) {
    return axiosInstance
      .post<{ data: GenerateAdCopyResult }>(`${BASE}/generate`, payload, { headers: { 'Idempotency-Key': idempotencyKey } })
      .then((res) => creditsChanged(res.data.data));
  },

  /**
   * Phase 8 Task 13 — "Generate with AI" for the Template Designer. Same
   * metered-AI/idempotency contract as generate() above, a distinct
   * endpoint (own controller, own credit-charge path) — hits
   * /message-templates/ai-generate, not /social/ai/generate. Returns a
   * DRAFT only; TemplateManagerPage.tsx fills the existing title/
   * template_body fields with the result and the user still reviews and
   * saves through the normal Save Template button.
   */
  generateTemplateDraft(payload: GenerateTemplateDraftPayload, idempotencyKey: string = newIdempotencyKey('template')) {
    return axiosInstance
      .post<{ data: GenerateTemplateDraftResult }>('/message-templates/ai-generate', payload, {
        headers: { 'Idempotency-Key': idempotencyKey },
      })
      .then((res) => creditsChanged(res.data.data));
  },

  /**
   * Phase 8 Task 14 — "Generate with AI" for the Journey Builder. Same
   * metered-AI/idempotency contract, hits /whatsapp/flows/ai-generate.
   * Returns a DRAFT graph only; JourneyBuilderPage.tsx loads it onto the
   * existing canvas and the user still reviews and saves it through the
   * normal Save/Publish flow.
   */
  generateJourneyDraft(payload: GenerateJourneyDraftPayload, idempotencyKey: string = newIdempotencyKey('journey')) {
    return axiosInstance
      .post<{ data: GenerateJourneyDraftResult }>('/whatsapp/flows/ai-generate', payload, {
        headers: { 'Idempotency-Key': idempotencyKey },
      })
      .then((res) => creditsChanged(res.data.data));
  },

  /**
   * Basic AI writing for every client (2026-10-14) — charged to the client's own AI credits
   * (free daily allowance first). Drafts only; nothing is saved or sent.
   */
  requestTemplateDraft(payload: { purpose: string; category?: string; tone?: string; variable_keys?: string[] }, idempotencyKey: string = newIdempotencyKey('reqtpl')) {
    return axiosInstance
      .post<{ data: GenerateTemplateDraftResult }>('/alerts/message-templates/ai-generate', payload, { headers: { 'Idempotency-Key': idempotencyKey } })
      .then((res) => creditsChanged(res.data.data));
  },

  writeMessage(payload: { purpose?: string; draft?: string; tone?: string; language?: AiWritingLanguage }, idempotencyKey: string = newIdempotencyKey('msg')) {
    return axiosInstance
      .post<{ data: { provider: string; text: string } }>('/alerts/ai-write', payload, { headers: { 'Idempotency-Key': idempotencyKey } })
      .then((res) => creditsChanged(res.data.data));
  },

  leadFollowUp(leadId: number, payload: { goal?: string; tone?: string; language?: AiWritingLanguage }, idempotencyKey: string = newIdempotencyKey('lead')) {
    return axiosInstance
      .post<{ data: { provider: string; text: string } }>(`/crm/leads/${leadId}/ai-follow-up`, payload, { headers: { 'Idempotency-Key': idempotencyKey } })
      .then((res) => creditsChanged(res.data.data));
  },
};

/** Tells the header's AI credit chip to re-read the balance after a metered AI call. */
function creditsChanged<T>(data: T): T {
  if (typeof window !== 'undefined') window.dispatchEvent(new Event(AI_CREDITS_CHANGED_EVENT));
  return data;
}

function newIdempotencyKey(prefix: string = 'adcopy'): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return `${prefix}-${crypto.randomUUID()}`;
  }
  return `${prefix}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
}

export default aiService;
