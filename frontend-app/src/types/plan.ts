/**
 * Phase 5 Task 12 — Super Admin Plan Management.
 *
 * Mirrors App\Http\Controllers\Api\PlanManagementController. Every field
 * here is rendered from the API response; nothing about a plan — its
 * name, price, quota, engine or capability bundle — is hardcoded on the
 * frontend.
 *
 * Not to be confused with the CUSTOMER-facing plan shape in
 * types/billing.ts, which carries only what a buyer may see. This one is
 * the administrative view and is reachable by Super Admin alone.
 */

import type { PlanLimits } from './account';

/** 'none' = no WhatsApp engine (e.g. the CRM-only plan). */
export type PlanEngineType = 'qr' | 'meta' | 'none';

export type PlanBillingModel = 'flat_quota' | 'per_message' | 'unlimited';

export interface ManagedPlan {
  slug: string;
  label: string;
  price: number;
  duration_days: number;
  description: string | null;
  engine_type: PlanEngineType;
  billing_model: PlanBillingModel;
  rate_per_message: string | number | null;
  total_allocated_messages: number | null;
  /**
   * Phase 8 Task 2 — AI credits allocated to a subscriber for every
   * purchased period (0 = none). Only a plan that sells `ai` may include any.
   */
  included_credits: number;
  /** Purchasable right now. Deactivating blocks NEW purchases only. */
  is_active: boolean;
  /** Capability slugs this plan bundles, from plan_entitlements. */
  capabilities: string[];
  /**
   * Phase 4 Task 2 / Task 10 — the plan_entitlements.usage_limit pivot
   * per bundled capability slug. null = no concrete limit configured
   * for that capability (never "unlimited" — see
   * InstallationAllowanceResolver). A capability not present in
   * `capabilities` never has a key here.
   */
  capability_limits: Record<string, number | null>;
  /** How many accounts have ever paid for this plan — server-computed. */
  accounts: number;
  /** Plan restructure (2026-10-15): listed on the website and Billing page. */
  is_public?: boolean;
  limits?: PlanLimits;
  /** Set for a custom plan: the one client that sees and buys it. */
  account?: { id: number; company_name: string | null } | null;
}

export interface PlanCapabilityOption {
  slug: string;
  label: string;
  category: string | null;
  /**
   * Providers that explicitly CANNOT run this capability, from
   * provider_capabilities. Used only to show an informational warning —
   * the backend refuses an incompatible pairing regardless of what this
   * UI displays, so it is never a security boundary here.
   */
  unsupported_providers: string[];
}

export interface ManagedPlansResponse {
  data: ManagedPlan[];
  available_capabilities: PlanCapabilityOption[];
}

/** Create: every billing dimension is required by the backend. */
export interface CreatePlanPayload {
  slug: string;
  label: string;
  price: number;
  duration_days: number;
  engine_type: PlanEngineType;
  billing_model: PlanBillingModel;
  description?: string | null;
  rate_per_message?: number | null;
  total_allocated_messages?: number | null;
  /** Phase 8 Task 2 — AI credits per purchased period (0 = none). */
  included_credits?: number;
  is_active?: boolean;
  capabilities?: string[];
  /**
   * Phase 4 Task 2 / Task 10 — slug => usage_limit (null clears it).
   * A plan that includes `external_api` must also include
   * `api_installations` with a concrete, non-negative value here — the
   * backend rejects the save otherwise.
   */
  capability_limits?: Record<string, number | null>;
  /** Plan restructure (2026-10-15): null = unlimited, 0 = not included. */
  max_users?: number | null;
  max_meta_templates?: number | null;
  max_flows?: number | null;
  max_broadcasts_per_month?: number | null;
  marketing_message_limit?: number | null;
  is_public?: boolean;
  /** Create only: makes this a custom plan for that client (never public). */
  account_id?: number | null;
}

/**
 * Update: a partial by design. An ABSENT key is left unchanged by the
 * server, and that is load-bearing — `capabilities: []` clears the
 * bundle while omitting the key leaves it alone. Those are different
 * requests, so this type must never be filled in with defaults.
 */
export type UpdatePlanPayload = Partial<CreatePlanPayload>;

export interface UpdatePlanResult {
  message: string;
  data: {
    slug: string;
    included_credits?: number;
    capabilities: string[];
    bundle_changed: boolean;
    added: string[];
    removed: string[];
  };
}

/**
 * Custom-plan price list (GET/PUT /api/admin/custom-plan-pricing, 2026-10-15) —
 * what each feature and limit unit adds to a plan's suggested price, and
 * Meta's per-message cost for the margin line. Amounts in ₹ per 30 days,
 * except the per-message prices/costs.
 */
export interface CustomPlanPriceList {
  base_fee: number;
  base_users_included: number;
  per_user: number;
  unlimited_users: number;
  engine: Record<PlanEngineType, number>;
  per_message: number;
  per_marketing_message: number;
  features: Record<string, number>;
  per_template: number;
  unlimited_templates: number;
  per_journey: number;
  unlimited_journeys: number;
  per_broadcast: number;
  unlimited_broadcasts: number;
  meta_marketing_cost: number;
  meta_utility_cost: number;
  gst_percent: number;
}
