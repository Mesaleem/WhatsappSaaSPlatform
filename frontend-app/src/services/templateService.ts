import axiosInstance from '../core/api/axiosInstance';
import type {
  AvailableTemplate,
  ClientApiKey,
  MessageTemplate,
  RegenerateClientApiKeyResponse,
  SaveMessageTemplatePayload,
  SendTemplateMessagePayload,
  SendTemplateMessageResponse,
  TestTemplateMessagePayload,
  TestTemplateMessageResponse,
} from '../types/templates';

const templateService = {
  // --- Super Admin Template Designer & Approval Panel ---
  list(filters: { status?: string; account_id?: number | null; search?: string } = {}) {
    return axiosInstance
      .get<{ data: MessageTemplate[] }>('/message-templates', { params: filters })
      .then((res) => res.data.data);
  },

  create(payload: SaveMessageTemplatePayload) {
    return axiosInstance
      .post<{ message: string; data: MessageTemplate }>('/message-templates', payload)
      .then((res) => res.data);
  },

  update(id: number, payload: Partial<SaveMessageTemplatePayload>) {
    return axiosInstance
      .put<{ message: string; data: MessageTemplate }>(`/message-templates/${id}`, payload)
      .then((res) => res.data);
  },

  approve(id: number) {
    return axiosInstance
      .patch<{ message: string; data: MessageTemplate }>(`/message-templates/${id}/approve`)
      .then((res) => res.data);
  },

  reject(id: number) {
    return axiosInstance
      .patch<{ message: string; data: MessageTemplate }>(`/message-templates/${id}/reject`)
      .then((res) => res.data);
  },

  remove(id: number) {
    return axiosInstance.delete<{ message: string }>(`/message-templates/${id}`).then((res) => res.data);
  },

  /**
   * POST /api/admin/templates/{id}/test — Strict 1-Template-Per-Client &
   * Testing Gate. Always sends through the Super Admin's own scanned
   * WhatsApp device (backend: Account::platformDevice()), never a
   * client's — see MessageTemplateController::test()'s docblock.
   */
  test(id: number, payload: TestTemplateMessagePayload) {
    return axiosInstance.post<TestTemplateMessageResponse>(`/admin/templates/${id}/test`, payload).then((res) => res.data);
  },

  // --- Client Admin Dynamic Form Engine ---
  available() {
    return axiosInstance.get<{ data: AvailableTemplate[] }>('/alerts/message-templates').then((res) => res.data.data);
  },

  sendTemplateMessage(payload: SendTemplateMessagePayload) {
    return axiosInstance.post<SendTemplateMessageResponse>('/alerts/send-template', payload).then((res) => res.data);
  },

  // --- Profile "Client API Key" section ---
  getApiKey() {
    return axiosInstance.get<{ data: ClientApiKey | null }>('/account/api-key').then((res) => res.data.data);
  },

  regenerateApiKey() {
    return axiosInstance.post<RegenerateClientApiKeyResponse>('/account/api-key/regenerate').then((res) => res.data);
  },
};

export default templateService;
