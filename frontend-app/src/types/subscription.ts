/**
 * Module 3 — Dynamic Subscription & Billing Configurator type definitions.
 * Mirrors app/Models/Subscription.php + AccountController's JSON shape.
 */

export type EngineType = 'qr' | 'meta';
export type BillingModel = 'flat_quota' | 'per_message' | 'unlimited';
export type PaymentMode = 'cash' | 'razorpay' | 'stripe';
export type SubscriptionStatus = 'active' | 'expired' | 'exhausted';

export interface Subscription {
  id: number;
  account_id: number;
  engine_type: EngineType;
  billing_model: BillingModel;
  /**
   * Laravel's `decimal` cast serializes as a STRING (e.g. "0.2000"), not a
   * number — this avoids float rounding on money/rate values. Only null
   * when billing_model !== 'per_message'.
   */
  rate_per_message: string | null;
  /** null for 'unlimited'; a positive integer for 'flat_quota' / 'per_message'. */
  total_allocated_messages: number | null;
  used_messages: number;
  /** Also a decimal-cast string — see rate_per_message. */
  price_paid: string;
  payment_mode: PaymentMode;
  starts_at: string; // ISO 8601
  expires_at: string; // ISO 8601
  status: SubscriptionStatus;
  /**
   * Backend-computed (Subscription::spentAmount() accessor, appended to
   * every Subscription JSON payload) -- `used_messages * rate_per_message`,
   * rounded to 2dp. A plain JSON number (accessor returns a PHP float via
   * round(), not a decimal-cast attribute, so unlike price_paid/
   * rate_per_message this is NOT a string). null unless
   * billing_model === 'per_message'.
   */
  spent_amount: number | null;
  /**
   * Backend-computed (Subscription::remainingBalance() accessor) --
   * `price_paid - spent_amount`, floored at 0. Same string-vs-number note
   * as spent_amount. Deliberately NOT derived from total_allocated_messages
   * (see that accessor's docblock) -- always read this directly rather
   * than recomputing it client-side.
   */
  remaining_balance: number | null;
  created_at: string;
  updated_at: string;
}

/** PUT /api/admin/accounts/{id}/subscription */
export interface UpdateSubscriptionPayload {
  engine_type?: EngineType;
  billing_model?: BillingModel;
  rate_per_message?: number | null;
  total_allocated_messages?: number | null;
  price_paid?: number;
  payment_mode?: PaymentMode;
  starts_at?: string;
  expires_at?: string;
}
