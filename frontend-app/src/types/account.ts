/**
 * Module 3 — Account Provisioning type definitions.
 * Mirrors app/Models/Account.php + AccountController's JSON shape.
 */

import type { BillingModel, EngineType, PaymentMode, Subscription } from './subscription';

export type AccountStatus = 'active' | 'suspended' | 'expired';

/**
 * Absolute Super Admin Control — canonical module/feature slugs a Super
 * Admin can start/stop per client. Mirrors backend-api's
 * Account::MODULES exactly; keep both lists in sync by hand.
 */
export const ACCOUNT_MODULES = [
  'dashboard',
  'whatsapp_setup',
  'send_alert',
  'analytics',
  'chatbot',
  'billing',
  'developer_api',
  'team_management',
] as const;

export type AccountModule = (typeof ACCOUNT_MODULES)[number];

/**
 * Dynamic Navigation Engine — every module here is now hidden from the
 * sidebar and route-guarded (403) client-side when disabled (see
 * AppLayout's `requiresModule` + ProtectedRoute's `module` prop, both
 * backed by AuthContext::hasModule), except 'dashboard' which is
 * deliberately exempt as the app's landing/fallback route. Server-side
 * API enforcement, however, still exists ONLY for 'team_management' (see
 * backend-api's TeamController::store() / Account::hasModuleEnabled()) —
 * disabling any other module purely hides the UI entry points; a direct
 * API call against that feature's endpoints is not yet blocked
 * server-side. Extending server-side enforcement to the rest is a
 * disclosed follow-up, not part of this fix's scope.
 */
export const ACCOUNT_MODULE_LABELS: Record<AccountModule, string> = {
  dashboard: 'Dashboard',
  whatsapp_setup: 'WhatsApp Setup',
  send_alert: 'Send Alert',
  analytics: 'Analytics',
  chatbot: 'Chatbot Rules',
  billing: 'Billing & Plans',
  developer_api: 'Developer API',
  team_management: 'Team User Creation',
};

export interface AccountOwner {
  id: number;
  name: string;
  email: string;
  account_id: number | null;
}

export interface Account {
  id: number;
  company_name: string;
  primary_phone: string | null;
  /** Admin-level status — independent of the subscription's own status. */
  status: AccountStatus;
  current_subscription: Subscription | null;
  owner?: AccountOwner | null;
  /**
   * Absolute Super Admin Control. null = every module enabled (the
   * default for every account until a Super Admin explicitly narrows
   * it) — see Account::hasModuleEnabled() on the backend.
   */
  allowed_modules: AccountModule[] | null;
  created_at: string;
  updated_at: string;
}

/** GET /api/admin/accounts/{id} — adds full subscription history. */
export interface AccountDetail extends Account {
  subscriptions: Subscription[]; // ordered newest starts_at first
}

/** Shape of a Laravel paginate() response. */
export interface PaginatedResponse<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

/** POST /api/admin/accounts */
export interface CreateAccountPayload {
  company_name: string;
  primary_phone?: string;
  status?: AccountStatus;

  admin_name: string;
  admin_email: string;
  admin_password: string;

  engine_type: EngineType;
  billing_model: BillingModel;
  rate_per_message?: number;
  total_allocated_messages?: number;
  price_paid: number;
  payment_mode: PaymentMode;
  starts_at: string;
  expires_at: string;
}

/** PUT /api/admin/accounts/{id} — account-level fields only. */
export interface UpdateAccountPayload {
  company_name?: string;
  primary_phone?: string | null;
  status?: AccountStatus;
}

/** PATCH /api/admin/accounts/{id}/permissions */
export interface UpdateAccountPermissionsPayload {
  /** null resets the account to "every module enabled". */
  allowed_modules: AccountModule[] | null;
}
