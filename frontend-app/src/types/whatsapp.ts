/**
 * Module 4 — WhatsApp (QR engine) connection status types.
 * Mirrors backend-api's WhatsAppController + qr-engine-service's
 * `connection:update` Socket.IO event payload.
 */

export type WhatsAppStatus = 'disconnected' | 'connecting' | 'connected';

export interface WhatsAppStatusResponse {
  status: WhatsAppStatus;
  last_connected_at: string | null;
  /** Linked WhatsApp number (digits, country code first). Null unless connected. */
  phone_number?: string | null;
  /** The default number slot; the connect modal uses it to receive that slot's events. */
  number_id?: number | null;
}

/** One WhatsApp number slot of an account (GET /api/whatsapp/numbers). */
export type WhatsAppNumberStatus = 'pending_payment' | 'unlinked' | 'linked' | 'paused';

export interface WhatsAppNumberRow {
  id: number;
  phone_number: string;
  is_included: boolean;
  is_default: boolean;
  status: WhatsAppNumberStatus;
  locked: boolean;
  /** Server rule: linked, paid, every number linked, subscription active. */
  can_set_default: boolean;
  term_ends_at: string | null;
}

export interface WhatsAppNumbersResponse {
  data: WhatsAppNumberRow[];
  /** True once the plan + add-ons are paid: numbers and the default cannot change. */
  locked: boolean;
}

/** An add-on purchase invoice (POST /api/whatsapp/numbers/purchase). */
export interface AddonInvoice {
  id: number;
  invoice_number: string;
  status: 'pending' | 'paid' | 'failed';
  /** GST-inclusive total. No tax line is shown. */
  total_amount: number;
  currency: string;
  items: { description: string; quantity: number; amount: number }[];
  numbers: { id: number; phone_number: string; status: WhatsAppNumberStatus; term_ends_at: string | null }[];
}

/** Payload of the `connection:update` event emitted by qr-engine-service. */
export interface ConnectionUpdatePayload {
  status: WhatsAppStatus;
  /** Base64 data: URL PNG, present only while status === 'connecting'. */
  qr: string | null;
  /**
   * 8-character code from WhatsApp for phone-number login, present only after
   * a start-session call with a phone number. Shown to the user as-is.
   */
  pairing_code?: string | null;
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
  /**
   * Phase 4 Task 2 — live connection state for the Meta provider, set to
   * 'connected' by the backend once credentials verify against the Graph
   * API. Carries no credential material. Optional because a cached
   * response from before this field existed simply omits it.
   */
  connection_status?: 'connected' | 'connecting' | 'disconnected' | string;
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
