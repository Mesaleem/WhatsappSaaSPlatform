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

export type PlanEngineType = 'qr' | 'meta';

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
  /** How many accounts have ever paid for this plan — server-computed. */
  accounts: number;
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
