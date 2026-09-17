import axiosInstance from '../core/api/axiosInstance';
import type {
  AvailableTemplate,
  BulkCooldownStatusResponse,
  ClientApiKey,
  MessageTemplate,
  MyTemplateSummary,
  RegenerateClientApiKeyResponse,
  SaveMessageTemplatePayload,
  SendBulkTemplateMessagePayload,
  SendBulkTemplateMessageResponse,
  SendTemplateMessagePayload,
  SendTemplateMessageResponse,
  SubmitTemplateRequestPayload,
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

  /**
   * Tiered Template Approval Workflow -- `reason` is optional (matches
   * the backend's `sometimes` validation): Super Admin/Agent may reject
   * with just a click, same as before this feature, or supply a short
   * reason that's stored on the template and surfaced to the submitter.
   */
  reject(id: number, reason?: string) {
    return axiosInstance
      .patch<{ message: string; data: MessageTemplate }>(`/message-templates/${id}/reject`, {
        rejection_reason: reason ?? null,
      })
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

  /**
   * POST /api/alerts/message-templates/request -- Tiered Template
   * Approval Workflow for 3-Tier Hierarchy. Submitted for the caller's
   * OWN account (server-resolved); routed to pending_agent_review or
   * pending_admin_review purely by that account's own agent_id.
   * [Not yet wired to a page in this pass -- see the feature's summary.]
   */
  submitRequest(payload: SubmitTemplateRequestPayload) {
    return axiosInstance
      .post<{ message: string; data: MessageTemplate }>('/alerts/message-templates/request', payload)
      .then((res) => res.data);
  },

  /** GET /api/alerts/message-templates/mine -- "My Templates" list (any status), for a plain Client Admin/User. */
  mine() {
    return axiosInstance.get<{ data: MyTemplateSummary[] }>('/alerts/message-templates/mine').then((res) => res.data.data);
  },

  sendTemplateMessage(payload: SendTemplateMessagePayload) {
    return axiosInstance.post<SendTemplateMessageResponse>('/alerts/send-template', payload).then((res) => res.data);
  },

  /**
   * POST /api/alerts/send-template-bulk -- Anti-Spam Bulk Dispatch. One
   * call for the whole multi-recipient batch; the backend queues each
   * recipient as its own rate-limited, randomly-delayed job instead of
   * sending them all inline (see that endpoint's own docblock).
   */
  sendBulkTemplateMessage(payload: SendBulkTemplateMessagePayload) {
    return axiosInstance
      .post<SendBulkTemplateMessageResponse>('/alerts/send-template-bulk', payload)
      .then((res) => res.data);
  },

  /**
   * GET /api/alerts/bulk-cooldown-status -- Strict Bulk Messaging Limit
   * & Tier-Based Cooldown. Read-only; call on mount so the Send Alert
   * page's countdown banner/Upgrade CTA can show up front.
   */
  getBulkCooldownStatus() {
    return axiosInstance.get<BulkCooldownStatusResponse>('/alerts/bulk-cooldown-status').then((res) => res.data);
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
