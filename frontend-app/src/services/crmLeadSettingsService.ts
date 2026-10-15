import axiosInstance from '../core/api/axiosInstance';

/** Lead routing (QA fixes, 2026-10-14) — backend CrmLeadSettingController. */
export interface CrmLeadSettings {
  assign_mode: 'none' | 'owner' | 'round_robin';
  notify_assignee: boolean;
  whatsapp_inbound_leads: boolean;
}

const crmLeadSettingsService = {
  get() {
    return axiosInstance.get<{ data: CrmLeadSettings }>('/crm/lead-settings').then((res) => res.data.data);
  },
  save(payload: CrmLeadSettings) {
    return axiosInstance.put<{ message: string; data: CrmLeadSettings }>('/crm/lead-settings', payload).then((res) => res.data);
  },
};

export default crmLeadSettingsService;
