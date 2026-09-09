/**
 * Module 8 — Multi-Tenant Billing, Payment Gateway Integration & Automated
 * Invoicing type definitions. Mirrors PaymentGatewayController /
 * PaymentWebhookController / BillingController / GatewaySettingsController's
 * JSON shapes.
 */

import type { EngineType, BillingModel, PaymentMode } from './subscription';
import type { PaginatedResponse } from './account';

export type PaymentGateway = 'razorpay' | 'stripe';
export type InvoiceStatus = 'pending' | 'paid' | 'failed';
export type GatewayMode = 'test' | 'live';

/** GET /api/billing/plans — one entry per App\Support\PlanCatalog plan. */
export interface Plan {
  key: string;
  label: string;
  description: string;
  engine_type: EngineType;
  billing_model: BillingModel;
  total_allocated_messages: number | null;
  duration_days: number;
  /** Decimal-cast-style string (e.g. "499.00") — see subscription.ts's rate_per_message note. */
  price: string;
  tax_amount: string;
  total_amount: string;
}

export interface PlansResponse {
  plans: Plan[];
  /** Gateways that are both enabled AND fully configured — the only ones checkout can offer. */
  available_gateways: PaymentGateway[];
  tax_rate: number;
}

/** POST /api/billing/create-order */
export interface CreateOrderPayload {
  plan_key: string;
  gateway: PaymentGateway;
}

export interface CreateOrderResponse {
  invoice_id: number;
  invoice_number: string;
  gateway: PaymentGateway;
  order_id: string;
  /** Stripe only — the PaymentIntent's client_secret, needed by Stripe.js to confirm. */
  client_secret: string | null;
  /** Public Key ID / publishable key — safe for the browser, never the secret. */
  key_id: string | null;
  /** Smallest currency unit (paise for INR) — what both gateway SDKs expect. */
  amount: number;
  currency: string;
  plan: { key: string; label: string };
}

/** POST /api/billing/verify-payment */
export interface VerifyPaymentPayload {
  invoice_id: number;
  razorpay_order_id?: string;
  razorpay_payment_id?: string;
  razorpay_signature?: string;
  payment_intent_id?: string;
}

export interface VerifyPaymentResponse {
  message: string;
  invoice: Invoice;
  subscription: unknown; // Full Subscription shape — see subscription.ts; not re-typed here to avoid import cycles.
}

export interface Invoice {
  id: number;
  account_id: number;
  invoice_number: string;
  plan_key: string;
  plan_label: string;
  amount: string;
  tax_amount: string;
  total_amount: string;
  currency: string;
  payment_gateway: PaymentGateway;
  gateway_order_id: string | null;
  gateway_payment_id: string | null;
  status: InvoiceStatus;
  paid_at: string | null;
  created_at: string;
  updated_at: string;
}

/**
 * Razorpay Checkout.js's global constructor, attached to `window` once
 * https://checkout.razorpay.com/v1/checkout.js loads. Deliberately hand-
 * declared (no official npm types package is installed — see
 * loadRazorpayScript.ts) and kept to only the fields this app actually
 * passes/reads.
 */
export interface RazorpayCheckoutOptions {
  key: string;
  amount: number;
  currency: string;
  name: string;
  description?: string;
  order_id: string;
  handler: (response: RazorpaySuccessResponse) => void;
  modal?: { ondismiss?: () => void };
  theme?: { color?: string };
}

export interface RazorpaySuccessResponse {
  razorpay_order_id: string;
  razorpay_payment_id: string;
  razorpay_signature: string;
}

export interface RazorpayCheckoutInstance {
  open: () => void;
}

// -------------------------------------------------------------------------
// Super Admin — Payment Gateway Configuration ("Zero-Code Admin UI")
// -------------------------------------------------------------------------

/** GET /api/admin/billing/gateway-settings — secrets are NEVER returned, only *_secret_set booleans. */
export interface GatewaySettings {
  gateway: PaymentGateway;
  mode: GatewayMode;
  is_enabled: boolean;
  is_fully_configured: boolean;
  test_key_id: string | null;
  test_key_secret_set: boolean;
  test_webhook_secret_set: boolean;
  live_key_id: string | null;
  live_key_secret_set: boolean;
  live_webhook_secret_set: boolean;
}

/**
 * POST /api/admin/billing/gateway-settings/{gateway} — partial update.
 * Every field is optional: omitting a secret field leaves the previously
 * saved value untouched (see GatewaySettingsController::update()).
 */
export interface UpdateGatewaySettingsPayload {
  mode?: GatewayMode;
  is_enabled?: boolean;
  test_key_id?: string | null;
  test_key_secret?: string | null;
  test_webhook_secret?: string | null;
  live_key_id?: string | null;
  live_key_secret?: string | null;
  live_webhook_secret?: string | null;
}

// Re-exported for convenience where callers only need PaymentMode alongside billing types.
export type { PaymentMode };

// -------------------------------------------------------------------------
// Super Admin — Billing Overview (Client Billing Summary)
// -------------------------------------------------------------------------

export type ClientPaymentStatus = 'active' | 'overdue';

/**
 * One row per client. plan_label/amount are derived from the account's
 * current subscription — see BillingController::clientSummary()'s
 * docblock for why there's no separate stored "plan name"/cadence field.
 * All fields are null when the client has no current subscription yet.
 */
export interface ClientBillingSummaryRow {
  account_id: number;
  company_name: string;
  plan_label: string | null;
  amount: string | null;
  used_messages: number | null;
  total_allocated_messages: number | null;
  payment_status: ClientPaymentStatus;
  renewal_date: string | null;
}

/** GET /api/billing/client-summary — Super Admin only. */
export interface ClientBillingSummaryResponse extends PaginatedResponse<ClientBillingSummaryRow> {
  /** 'account' when the Header's client switcher has narrowed this to one client, else 'global'. */
  scope: 'account' | 'global';
}
