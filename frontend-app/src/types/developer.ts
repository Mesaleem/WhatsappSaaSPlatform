/**
 * Module 9 — Developer API Portal, API Key Management & Custom Outbound
 * Webhooks type definitions. Mirrors ApiKeyController /
 * WebhookSubscriptionController's JSON shapes.
 */

export interface ApiKey {
  id: number;
  account_id: number;
  name: string;
  /** e.g. "wasaas_live_a1b2c3d4..." — a non-sensitive slice for identification only; the full key is never stored or returned again. */
  key_prefix: string;
  last_used_at: string | null;
  expires_at: string | null;
  /** Non-null once revoked — the key row is kept (audit trail), just deactivated. */
  revoked_at: string | null;
  created_at: string;
  updated_at: string;
  /**
   * Graceful Super Admin Fallback: present ONLY in the cross-tenant
   * ('global') response — ApiKeyController::index() eager-loads
   * account:id,company_name only on that branch.
   */
  account?: { id: number; company_name: string } | null;
}

/** GET /api/developer/api-keys response. 'account' = one tenant; 'global' = every tenant, Super Admin only. */
export type DeveloperScope = 'account' | 'global';

export interface ApiKeysResponse {
  data: ApiKey[];
  scope: DeveloperScope;
}

/** POST /api/developer/api-keys */
export interface CreateApiKeyPayload {
  name: string;
  expires_at?: string | null;
}

export interface CreateApiKeyResponse {
  message: string;
  /** Shown exactly ONCE — never retrievable again after this response. */
  plain_text_key: string;
  api_key: ApiKey;
}

export type WebhookEvent = 'message.sent' | 'message.failed' | 'message.delivered';

export const WEBHOOK_EVENTS: WebhookEvent[] = ['message.sent', 'message.failed', 'message.delivered'];

export interface WebhookSubscription {
  id: number;
  account_id: number;
  url: string;
  events: WebhookEvent[];
  is_active: boolean;
  /** The secret itself is never returned after creation — only whether one is set. */
  secret_set: boolean;
  created_at: string;
  updated_at: string;
  /** Graceful Super Admin Fallback: present only in the 'global' response — see ApiKey.account. */
  account?: { id: number; company_name: string } | null;
}

export interface WebhooksResponse {
  data: WebhookSubscription[];
  scope: DeveloperScope;
}

/** POST /api/developer/webhooks */
export interface CreateWebhookPayload {
  url: string;
  /** Omit to have the server generate a cryptographically random one. */
  secret?: string;
  events: WebhookEvent[];
  is_active?: boolean;
}

export interface CreateWebhookResponse {
  message: string;
  /** Shown exactly ONCE — never retrievable again after this response. */
  plain_text_secret: string;
  webhook: WebhookSubscription;
}

/** POST /api/developer/webhooks/{id}/test */
export interface WebhookTestResponse {
  message: string;
  success: boolean;
  status_code: number | null;
}

/** GET /api/developer/webhooks/{id}/deliveries */
export interface WebhookDelivery {
  id: number;
  webhook_subscription_id: number;
  event: string;
  payload: Record<string, unknown>;
  response_code: number | null;
  status: 'success' | 'failed';
  attempt: number;
  created_at: string;
  updated_at: string;
}
