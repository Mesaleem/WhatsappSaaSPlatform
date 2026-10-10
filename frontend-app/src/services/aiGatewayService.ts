import axiosInstance from '../core/api/axiosInstance';
import type { AiProvider, AiProviderSettingsRow, UpdateAiProviderSettingsPayload } from '../types/ai';

/**
 * Phase 8 Task 12 (continued) — Super Admin platform AI provider/model
 * settings ("Zero-Code Admin UI"). Separate service file from any
 * tenant-facing AI usage, mirroring socialGatewayService.ts: this hits a
 * distinct, platform-level, manage-ai-settings-gated controller
 * (Api\Admin\AiGatewayController).
 */
const aiGatewayService = {
  list() {
    return axiosInstance
      .get<{ data: AiProviderSettingsRow[] }>('/admin/ai/provider-settings')
      .then((res) => res.data.data);
  },

  update(provider: AiProvider, payload: UpdateAiProviderSettingsPayload) {
    return axiosInstance
      .post<{ message: string; data: AiProviderSettingsRow }>(
        `/admin/ai/provider-settings/${provider}`,
        payload,
      )
      .then((res) => res.data);
  },
};

export default aiGatewayService;
