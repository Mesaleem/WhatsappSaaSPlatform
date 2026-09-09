import axiosInstance from '../core/api/axiosInstance';
import type { MailSettings, SendTestMailPayload, SendTestMailResponse, UpdateMailSettingsPayload } from '../types/mail';

/**
 * Dynamic System & Mail Configuration — Super Admin SMTP setup. Lives on
 * the same "Admin Gateway Settings" screen as gatewaySettingsService.ts,
 * gated by the same manage-billing-settings permission — kept in its own
 * service file because it hits a distinct controller
 * (Api\Admin\MailSettingsController).
 */
const mailSettingsService = {
  get() {
    return axiosInstance.get<{ data: MailSettings }>('/admin/billing/mail-settings').then((res) => res.data.data);
  },

  update(payload: UpdateMailSettingsPayload) {
    return axiosInstance
      .put<{ message: string; data: MailSettings }>('/admin/billing/mail-settings', payload)
      .then((res) => res.data);
  },

  sendTest(payload: SendTestMailPayload) {
    return axiosInstance
      .post<SendTestMailResponse>('/admin/billing/mail-settings/test', payload)
      .then((res) => res.data);
  },
};

export default mailSettingsService;
