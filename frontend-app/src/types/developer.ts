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
  /**
   * Developer API Platform for WhatsApp Group Creation & Unified
   * Messaging — dual-factor secret's prefix, same non-sensitive-slice
   * convention as key_prefix. Null for a key that has never had a
   * secret provisioned (every key created before this feature; see
   * ApiKeyController::regenerateSecret()'s docblock) — used here only
   * to decide whether to show "Generate Secret" or "Regenerate Secret".
   */
  secret_prefix: string | null;
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
  /** Authorized-server binding summary (ApiKeyBindingService::summary()); absent on old API responses. */
  server_binding?: ServerBindingSummary;
}

/** Public API authorized-server binding. */
export type IpPolicy = 'NONE' | 'SINGLE_IP' | 'IP_ALLOWLIST';
export type BindingStatus = 'unbound' | 'pending_activation' | 'active' | 'revoked' | 'disabled';

export interface ServerBinding {
  id: number;
  status: 'pending_activation' | 'active' | 'revoked';
  label: string | null;
  ip_policy: IpPolicy;
  authorized_ips: string[];
  registered_ip: string | null;
  registered_at: string | null;
  last_success_ip: string | null;
  last_success_at: string | null;
  credential_issued: boolean;
  revoked_at: string | null;
  created_at: string | null;
}

export interface ServerChangeRequest {
  id: number;
  api_key_id: number;
  status: 'pending' | 'approved' | 'rejected';
  current_label: string | null;
  current_ip: string | null;
  requested_label: string | null;
  requested_ip_policy: IpPolicy;
  requested_ips: string[];
  reason: string;
  decision_note: string | null;
  decided_at: string | null;
  created_at: string | null;
}

export interface ServerBindingSummary {
  status: BindingStatus;
  enforced: boolean;
  /** true => this key cannot call /api/v1/* until its owner registers an authorized server (legacy key, no binding yet). */
  binding_required?: boolean;
  binding: ServerBinding | null;
  access_disabled: boolean;
  access_disabled_reason: string | null;
  /** True when the authorized server has no installation credential yet (after an approval/rebind) — the owner must issue one. */
  credential_pending: boolean;
  pending_change_request: ServerChangeRequest | null;
  last_change_request: ServerChangeRequest | null;
  /** Phase 4 Task 12 — cooldown/legacy visibility. Optional: absent on any older cached response shape. */
  cooldown_until?: string | null;
  in_cooldown?: boolean;
  /** True while this key still depends on the account's legacy authorized_server_ip (no live binding yet) — stays true even past an expired deadline. */
  legacy_ip_dependent?: boolean;
  legacy_authorized_ip?: string | null;
  legacy_deadline?: string | null;
}

/** Fields the buyer supplies when a key is created / a legacy key is registered. */
export interface ServerBindingPayload {
  acknowledge_server_binding: boolean;
  server_label?: string;
  ip_policy?: Exclude<IpPolicy, 'NONE'>;
  authorized_ips?: string[];
}

export interface RequestServerChangePayload {
  reason: string;
  requested_label?: string;
  ip_policy?: Exclude<IpPolicy, 'NONE'>;
  requested_ips: string[];
}

/** GET /api/developer/api-keys response. 'account' = one tenant; 'global' = every tenant, Super Admin only. */
export type DeveloperScope = 'account' | 'global';

export interface ApiKeysResponse {
  data: ApiKey[];
  scope: DeveloperScope;
  /** The licence warning shown at key creation. */
  server_binding_warning?: string;
}

/** POST /api/developer/api-keys */
export interface CreateApiKeyPayload extends ServerBindingPayload {
  name: string;
  expires_at?: string | null;
}

export interface CreateApiKeyResponse {
  message: string;
  /** Shown exactly ONCE — never retrievable again after this response. */
  plain_text_key: string;
  /**
   * Developer API Platform for WhatsApp Group Creation & Unified
   * Messaging — the dual-factor secret, issued alongside every new key.
   * Shown exactly ONCE, same one-time-reveal contract as plain_text_key.
   */
  plain_text_secret: string;
  /** Shown exactly ONCE. Sent by the authorized server in the `X-Client-Installation` header. */
  installation_credential: string;
  installation_header: string;
  warning: string;
  api_key: ApiKey;
}

/** POST /api/developer/api-keys/{id}/server-binding (legacy key registration) */
export interface RegisterServerResponse {
  message: string;
  installation_credential: string;
  installation_header: string;
  data: ServerBindingSummary;
}

/** POST /api/developer/api-keys/{id}/installation-credential */
export interface InstallationCredentialResponse {
  message: string;
  installation_credential: string;
  installation_header: string;
}

/**
 * Developer API Platform for WhatsApp Group Creation & Unified
 * Messaging — POST /api/developer/api-keys/{id}/regenerate-secret response.
 */
export interface RegenerateApiKeySecretResponse {
  message: string;
  plain_text_secret: string;
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

/** Super Admin API-access console (GET /api/admin/api-access). */
export interface AdminApiAccessRow {
  id: number;
  name: string;
  key_prefix: string;
  account_id: number;
  account_name: string | null;
  revoked_at: string | null;
  last_used_at: string | null;
  server_binding: ServerBindingSummary;
  /** Phase 4 Task 12 — same account-scoped allowance InstallationAllowanceResolver/countLiveInstallations() already compute; not a second source of truth. */
  installation_usage?: { live: number; allowance: number; at_or_over_allowance: boolean } | null;
}

export interface AdminChangeRequestRow extends ServerChangeRequest {
  account_id: number;
  account_name: string | null;
  key_name: string | null;
  key_prefix: string | null;
}

export interface ApiSecurityEvent {
  id: number;
  event: string;
  ip: string | null;
  binding_id: number | null;
  context: Record<string, unknown> | null;
  created_at: string;
}
