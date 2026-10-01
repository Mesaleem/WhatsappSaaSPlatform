import axiosInstance from '../core/api/axiosInstance';
import type { GenerateAdCopyPayload, GenerateAdCopyResult } from '../types/ai';

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
      .then((res) => res.data.data);
  },
};

function newIdempotencyKey(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return `adcopy-${crypto.randomUUID()}`;
  }
  return `adcopy-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
}

export default aiService;
