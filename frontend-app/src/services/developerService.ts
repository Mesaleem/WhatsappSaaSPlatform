import axiosInstance from '../core/api/axiosInstance';
import type {
  ApiKey,
  ApiKeysResponse,
  CreateApiKeyPayload,
  CreateApiKeyResponse,
  RegenerateApiKeySecretResponse,
  CreateWebhookPayload,
  CreateWebhookResponse,
  WebhookDelivery,
  WebhooksResponse,
  WebhookTestResponse,
} from '../types/developer';

const developerService = {
  listApiKeys() {
    return axiosInstance.get<ApiKeysResponse>('/developer/api-keys').then((res) => res.data);
  },

  /**
   * Developer Portal & UI Action Restoration — accountId lets a Super
   * Admin generate a key for any specific tenant directly from the
   * Create API Key modal's Account Selector, independent of whatever
   * client (if any) is currently selected in the header switcher: an
   * explicit params.account_id here overrides the axios interceptor's
   * own ?account_id= attachment (see axiosInstance's docblock).
   */
  createApiKey(payload: CreateApiKeyPayload, accountId?: number) {
    return axiosInstance
      .post<CreateApiKeyResponse>('/developer/api-keys', payload, accountId ? { params: { account_id: accountId } } : undefined)
      .then((res) => res.data);
  },

  revokeApiKey(id: number) {
    return axiosInstance.delete<{ message: string; api_key: ApiKey }>(`/developer/api-keys/${id}`).then((res) => res.data);
  },

  /**
   * Developer API Platform for WhatsApp Group Creation & Unified
   * Messaging — backfills/rotates a key's dual-factor secret without
   * touching the key itself (see ApiKeyController::regenerateSecret()'s
   * docblock).
   */
  regenerateApiKeySecret(id: number) {
    return axiosInstance
      .post<RegenerateApiKeySecretResponse>(`/developer/api-keys/${id}/regenerate-secret`)
      .then((res) => res.data);
  },

  listWebhooks() {
    return axiosInstance.get<WebhooksResponse>('/developer/webhooks').then((res) => res.data);
  },

  /**
   * Developer Portal & UI Action Restoration — same Account Selector
   * override pattern as createApiKey() above: an explicit accountId
   * (from the Add Webhook modal's Account Selector, Super Admin only)
   * overrides the axios interceptor's own ?account_id= attachment, since
   * webhook_subscriptions.account_id is a NOT NULL FK just like
   * api_keys.account_id — there is no schema-level "global" webhook.
   */
  createWebhook(payload: CreateWebhookPayload, accountId?: number) {
    return axiosInstance
      .post<CreateWebhookResponse>('/developer/webhooks', payload, accountId ? { params: { account_id: accountId } } : undefined)
      .then((res) => res.data);
  },

  deleteWebhook(id: number) {
    return axiosInstance.delete<{ message: string }>(`/developer/webhooks/${id}`).then((res) => res.data);
  },

  testWebhook(id: number) {
    return axiosInstance.post<WebhookTestResponse>(`/developer/webhooks/${id}/test`).then((res) => res.data);
  },

  getDeliveries(id: number) {
    return axiosInstance
      .get<{ data: WebhookDelivery[] }>(`/developer/webhooks/${id}/deliveries`)
      .then((res) => res.data.data);
  },
};

export default developerService;
