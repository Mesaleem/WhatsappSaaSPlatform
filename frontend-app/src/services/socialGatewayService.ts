import axiosInstance from '../core/api/axiosInstance';
import type { SocialProvider, SocialProviderConfigRow, UpdateSocialProviderConfigPayload } from '../types/social';

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 2.
 * Super Admin — platform Meta/LinkedIn/Google OAuth App credential vault
 * ("Zero-Code Admin UI"). Separate service file from socialService.ts
 * (the tenant-scoped Social Accounts hub) because this hits a distinct,
 * platform-level, manage-social-settings-gated controller
 * (Api\Admin\SocialGatewayController) — same split as
 * gatewaySettingsService.ts vs billingService.ts.
 */
const socialGatewayService = {
  list() {
    return axiosInstance
      .get<{ data: SocialProviderConfigRow[] }>('/admin/social/provider-configs')
      .then((res) => res.data.data);
  },

  update(provider: SocialProvider, payload: UpdateSocialProviderConfigPayload) {
    return axiosInstance
      .post<{ message: string; data: SocialProviderConfigRow }>(
        `/admin/social/provider-configs/${provider}`,
        payload,
      )
      .then((res) => res.data);
  },
};

export default socialGatewayService;
