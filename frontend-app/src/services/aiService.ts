import axiosInstance from '../core/api/axiosInstance';
import type { GenerateAdCopyPayload, GenerateAdCopyResult } from '../types/ai';

const BASE = '/social/ai';

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Final Phase.
 * Axios service for the AI Ad Copywriter (embedded in MetaAdsPage's
 * LaunchWizardModal — "✨ Generate with AI").
 */
const aiService = {
  generate(payload: GenerateAdCopyPayload) {
    return axiosInstance
      .post<{ data: GenerateAdCopyResult }>(`${BASE}/generate`, payload)
      .then((res) => res.data.data);
  },
};

export default aiService;
