/**
 * Module 4 — WhatsApp (QR engine) connection status types.
 * Mirrors backend-api's WhatsAppController + qr-engine-service's
 * `connection:update` Socket.IO event payload.
 */

export type WhatsAppStatus = 'disconnected' | 'connecting' | 'connected';

export interface WhatsAppStatusResponse {
  status: WhatsAppStatus;
  last_connected_at: string | null;
}

/** Payload of the `connection:update` event emitted by qr-engine-service. */
export interface ConnectionUpdatePayload {
  status: WhatsAppStatus;
  /** Base64 data: URL PNG, present only while status === 'connecting'. */
  qr: string | null;
  error?: string;
}

/**
 * Super Admin WhatsApp Device Integration — one row of
 * GET /admin/whatsapp/devices (WhatsAppController::adminIndex()): every
 * tenant account's device status in one table, so a Super Admin doesn't
 * have to select each account one at a time just to see who's connected.
 */
export interface AdminWhatsAppDevice {
  account_id: number;
  company_name: string;
  status: WhatsAppStatus;
  last_connected_at: string | null;
}

/**
 * Module 5 — Meta Cloud API credential configuration types.
 * Mirrors backend-api's MetaConfigController JSON responses.
 */

export interface MetaConfigResponse {
  configured: boolean;
  meta_phone_number_id: string | null;
  meta_waba_id: string | null;
  /** Never the raw token — always masked server-side (bullet chars + last 4). */
  meta_access_token_masked: string | null;
  meta_webhook_verify_token: string | null;
  /** Absolute URL to paste into the Meta App Dashboard's webhook config. */
  webhook_url: string;
}

export interface SaveMetaConfigPayload {
  meta_phone_number_id: string;
  meta_waba_id: string;
  meta_access_token: string;
}

export interface TestMetaConnectionPayload {
  meta_phone_number_id: string;
  meta_access_token: string;
}

export interface TestMetaConnectionResult {
  success: boolean;
  verified_name?: string | null;
  display_phone_number?: string | null;
  error?: string | null;
}

/** Response of POST /whatsapp/meta-config (store) — includes save confirmation fields. */
export interface SaveMetaConfigResponse extends MetaConfigResponse {
  message: string;
  verified_name: string | null;
  display_phone_number: string | null;
}
