/**
 * Module 3 — Account Provisioning type definitions.
 * Mirrors app/Models/Account.php + AccountController's JSON shape.
 */

import type { BillingModel, EngineType, PaymentMode, Subscription } from './subscription';

export type AccountStatus = 'active' | 'suspended' | 'expired';

/**
 * 3-Tier Hierarchy & Agent-Client Scope Engine (Phase 1/2). Mirrors
 * backend-api's accounts.account_type enum exactly. 'super_admin' is
 * included for schema completeness only — the platform's actual Super
 * Admin is a User with account_id=null (see types/auth.ts's User),
 * never an Account row; no Account in this app is expected to carry
 * this value in practice.
 */
export type AccountType = 'super_admin' | 'agent' | 'client';

/** The minimal Agent-account summary AccountController eager-loads onto a Sub-Client's `agent` relation. */
export interface AccountAgentSummary {
  id: number;
  company_name: string;
  account_type: AccountType;
}

/**
 * Super Admin Client Provisioning, Client Admin Mapping, User Limits,
 * Granular Permission Matrix & Adaptive Dashboards refactor — the
 * client's coarse business-category, DISTINCT from ACCOUNT_MODULES below
 * (that's the granular per-feature sidebar checklist; this drives which
 * widget set DashboardPage.tsx renders). Mirrors backend-api's
 * Account::MODULE_ASSIGNMENTS exactly.
 */
export const MODULE_ASSIGNMENTS = ['whatsapp_messaging', 'social_media', 'both'] as const;
export type ModuleAssignment = (typeof MODULE_ASSIGNMENTS)[number];
export const MODULE_ASSIGNMENT_LABELS: Record<ModuleAssignment, string> = {
  whatsapp_messaging: 'WhatsApp Messaging Only',
  social_media: 'Social Media Only',
  both: 'Both (WhatsApp + Social Media)',
};

/**
 * Absolute Super Admin Control — canonical module/feature slugs a Super
 * Admin can start/stop per client. Mirrors backend-api's
 * Account::MODULES exactly; keep both lists in sync by hand.
 *
 * 'device_settings' and 'social_accounts' are new in the "Module &
 * Feature Access" 3-category refactor (see CORE_COMMON_MODULES /
 * WHATSAPP_SUITE_MODULES / SOCIAL_SUITE_MODULES below). 'device_settings'
 * maps to AppLayout's "Device Settings" nav item, which is
 * `superAdminOnly: true` with no `requiresModule` — same precedent as
 * 'templates' (Template Manager): the checklist row exists for
 * completeness but toggling it has no client-visible effect, since a
 * Client Admin can never reach that route regardless. 'social_accounts'
 * closes a previously-disclosed gap: AppLayout's "Social Accounts" nav
 * item now has `requiresModule: 'social_accounts'`.
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
  'notifications',
  'meta_ads',
  'social_inbox',
  'lead_crm',
  'comment_automation',
  'reports',
  'templates',
  'device_settings',
  'social_accounts',
  // Message Logs Governance Fix — previously gated on 'analytics'
  // (Core Common), so it couldn't be toggled independently of the
  // Analytics page. Now its own slug, under the WhatsApp Suite.
  'message_logs',
  // Group Messaging Step 1 — Custom Contact Groups, a paid addon.
  'contact_groups',
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
  team_management: 'Team Users',
  notifications: 'Notifications',
  meta_ads: 'Meta Ads Launcher',
  social_inbox: 'Unified Social Inbox',
  lead_crm: 'Instant Lead CRM',
  comment_automation: 'Comment Rules',
  reports: 'Social Reports',
  templates: 'Template Manager',
  device_settings: 'Device Settings',
  social_accounts: 'Social Accounts',
  message_logs: 'Message Logs & Audit Trail',
  contact_groups: 'Custom Contact Groups (Paid Addon)',
};

/**
 * "Module & Feature Access" modal reorganization — 3 categories replacing
 * the old COMBINED_MODULE_CHECKLIST (had zero consumers, confirmed via
 * grep before removal). 'notifications' and 'developer_api' are
 * deliberately absent from all three: 'notifications' is removed from
 * this checklist's UI per spec ("excluding Notifications") but remains a
 * valid ACCOUNT_MODULES slug (AppLayout's Notifications nav item still
 * gates on it, and it stays untouched by every toggle/preset below,
 * which only ever add/remove the specific slugs they name);
 * 'developer_api' was never part of the spec's checklist and is left
 * exactly as unmanaged as it already was.
 *
 * Core Common Features (Shared) — pre-checked by default in Create mode,
 * included by every Quick Plan preset, and not gated by a Master
 * Category checkbox (they're the shared baseline, not a suite to
 * enable/disable as a block).
 */
export const CORE_COMMON_MODULES: AccountModule[] = ['dashboard', 'analytics', 'billing', 'team_management'];

/** WhatsApp Messaging Suite — gated by its own Master Category checkbox. */
export const WHATSAPP_SUITE_MODULES: AccountModule[] = [
  'whatsapp_setup',
  'send_alert',
  'chatbot',
  'templates',
  'device_settings',
  // Message Logs Governance Fix — re-homed here from 'analytics'
  // (Core Common) so a Super Admin can toggle it independently. See
  // Account::MODULES in the backend for the other half.
  'message_logs',
  // Group Messaging Step 1 — see Account::MODULES in the backend.
  'contact_groups',
];

/** Social Media Suite — gated by its own Master Category checkbox. */
export const SOCIAL_SUITE_MODULES: AccountModule[] = [
  'social_accounts',
  'meta_ads',
  'lead_crm',
  'social_inbox',
  'comment_automation',
  'reports',
];

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
  /** 3-Tier Hierarchy & Agent-Client Scope Engine (Phase 1). Defaults to 'client' for every account created before this phase. */
  account_type: AccountType;
  /** 3-Tier Hierarchy (Phase 1). Null for a direct/platform client and for an Agent account itself. */
  agent_id: number | null;
  /** 3-Tier Hierarchy (Phase 1) — populated on GET /api/admin/accounts and /api/admin/accounts/{id} responses; absent (not null) elsewhere. */
  agent?: AccountAgentSummary | null;
  /**
   * Absolute Super Admin Control. null = every module enabled (the
   * default for every account until a Super Admin explicitly narrows
   * it) — see Account::hasModuleEnabled() on the backend.
   */
  allowed_modules: AccountModule[] | null;
  /**
   * 3-Tier Hierarchy & Agent-Client Scope Engine (Phase 2) — Hierarchical
   * Module Delegation Engine: the live, hierarchy-capped set of modules
   * this account can actually use right now (may be narrower than
   * allowed_modules if this account's Agent has since lost a module —
   * see Account::effectiveModules() on the backend). Present only on
   * /auth/me and GET /api/admin/accounts/{id} (AuthContext::hasModule()
   * falls back to allowed_modules when this is absent, e.g. on the
   * accounts list).
   */
  effective_modules?: AccountModule[];
  /** White-Label Automated PDF Reporting — Final Phase. Both nullable; unset = no branding applied (SimplePdfWriter::renderBrandedReport() falls back to a default accent color and omits the logo line). */
  logo_url: string | null;
  brand_accent_color: string | null;
  /** Super Admin Client Provisioning refactor — User Limits. null = unlimited. */
  max_users_limit: number | null;
  /** Super Admin Client Provisioning refactor — drives DashboardPage.tsx's widget set. */
  module_assignment: ModuleAssignment;
  created_at: string;
  updated_at: string;
}

/** GET /api/admin/accounts/{id} — adds full subscription history. */
export interface AccountDetail extends Account {
  subscriptions: Subscription[]; // ordered newest starts_at first
  /**
   * 3-Tier Hierarchy & Agent-Client Scope Engine (Phase 4) — Agent Quota
   * Pool & Allocation: how much of the viewing Agent's own pool is still
   * unallocated (computed as if THIS account's current allocation were
   * freed up first — see AccountController::show()). Present only when
   * the viewer is an Agent; absent (not null) for Super Admin, who has
   * no pool ceiling of their own. null = the Agent's own pool has no
   * ceiling (their subscription is 'unlimited' or uncapped).
   */
  agent_remaining_pool?: number | null;
}

/** PUT /api/admin/accounts/{id}/quota — 3-Tier Hierarchy (Phase 4). */
export interface UpdateQuotaPayload {
  total_allocated_messages: number;
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

  /**
   * 3-Tier Hierarchy & Agent-Client Scope Engine (Phase 3 UI) — Super
   * Admin only; AccountController::store() force-derives both fields
   * for any non-Super-Admin caller regardless of what is sent (see its
   * docblock), so these are omitted entirely by CreateAccountModal for
   * an Agent caller creating their own Sub-Client. Omit/undefined =
   * 'client' with no agent_id (today's existing default behavior,
   * unchanged).
   */
  account_type?: AccountType;
  /** Optional — a direct/platform client has no agent_id. Only meaningful when account_type is 'client'. */
  agent_id?: number | null;

  admin_name: string;
  admin_email: string;
  /** Corrected Unified Client & Admin User Creation — Section 2. */
  admin_phone?: string;
  admin_password: string;

  engine_type: EngineType;
  billing_model: BillingModel;
  rate_per_message?: number;
  total_allocated_messages?: number;
  price_paid: number;
  payment_mode: PaymentMode;
  starts_at: string;
  expires_at: string;

  /** Client Module Accessibility Checklist. Omit/null = every module enabled. */
  allowed_modules?: AccountModule[] | null;

  /** Super Admin Client Provisioning refactor — Section 1. */
  max_users_limit?: number | null;
  module_assignment: ModuleAssignment;
}

/** PUT /api/admin/accounts/{id} — account-level fields only. */
export interface UpdateAccountPayload {
  company_name?: string;
  primary_phone?: string | null;
  status?: AccountStatus;
  /** White-Label Automated PDF Reporting — Final Phase. */
  logo_url?: string | null;
  brand_accent_color?: string | null;
  /** Super Admin Client Provisioning refactor — editable post-creation too (see AccountController::update()'s docblock). */
  max_users_limit?: number | null;
  module_assignment?: ModuleAssignment;
  /**
   * 3-Tier Hierarchy & Agent-Client Scope Engine — Existing-Account
   * Conversion. Super-Admin-only (AccountController::update() silently
   * drops both for any other caller). Converting to 'agent' always
   * clears agent_id server-side, regardless of what's sent here;
   * converting an 'agent' back to 'client' is refused (422) while it
   * still has its own Sub-Clients.
   */
  account_type?: AccountType;
  agent_id?: number | null;
}

/** PATCH /api/admin/accounts/{id}/permissions */
export interface UpdateAccountPermissionsPayload {
  /** null resets the account to "every module enabled". */
  allowed_modules: AccountModule[] | null;
}
