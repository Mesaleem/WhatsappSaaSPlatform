import axiosInstance from '../core/api/axiosInstance';
import type { GatewaySettings, PaymentGateway, UpdateGatewaySettingsPayload } from '../types/billing';

/**
 * Super Admin — platform Razorpay/Stripe merchant credential vault
 * ("Zero-Code Admin UI"). Separate service file from billingService.ts
 * because this hits a distinct, platform-level, manage-billing-settings-
 * gated controller (Api\Admin\GatewaySettingsController), not the tenant-
 * scoped billing endpoints.
 */
const gatewaySettingsService = {
  list() {
    return axiosInstance
      .get<{ data: GatewaySettings[] }>('/admin/billing/gateway-settings')
      .then((res) => res.data.data);
  },

  update(gateway: PaymentGateway, payload: UpdateGatewaySettingsPayload) {
    return axiosInstance
      .post<{ message: string; data: GatewaySettings }>(`/admin/billing/gateway-settings/${gateway}`, payload)
      .then((res) => res.data);
  },
};

export default gatewaySettingsService;
