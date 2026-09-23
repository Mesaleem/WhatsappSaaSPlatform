/**
 * Module 2 — Auth / RBAC / Multi-Tenant type definitions.
 * Mirrors the JSON shape returned by backend-api AuthController & RoleController.
 */

import type { Account } from './account';

export interface Permission {
  id: number;
  name: string; // e.g. "manage-accounts", "manage-subscriptions", "send-messages",
  // "view-analytics", "manage-team", "manage-roles"
  guard_name: string;
}

export interface Role {
  id: number;
  name: string; // "super_admin" | "admin" | "user" | any dynamic role a Super Admin creates
  guard_name: string;
  permissions: Permission[];
}

export interface User {
  id: number;
  name: string;
  email: string;
  phone_number: string | null;
  is_active: boolean;
  account_id: number | null;
  /** null only for Super Admin — global access, not tied to a tenant account. */
  account: Account | null;
  /**
   * Dynamic Multi-Role Sidebar Aggregation refactor — a user can hold
   * more than one role (was a single `role: Role` object; AuthController::
   * formatUser() previously collapsed to roles.first(), an arbitrary
   * pick once a user has more than one assigned role). Check membership
   * with AuthContext's hasRole()/isSuperAdmin() rather than reading this
   * array directly.
   */
  roles: Role[];
  /** Flattened, already-deduplicated-and-unioned-across-all-roles permission names (see backend's User::getAllPermissions()). */
  permissions: string[];
  /**
   * Phase 1 Foundation, Task 9 — { capability_slug => granted }, from
   * AccessControlService::capabilityMap(). The backend has sent this on
   * /auth/me since Phase 1; Phase 5 Task 7 is the first consumer, so
   * this is the declaration catching up with the payload, not a new
   * field.
   *
   * Optional because an older cached /auth/me response may predate a
   * client refresh. UX only — never an authorization decision; see
   * src/journey/nodeEntitlement.ts.
   */
  capabilities?: Record<string, boolean>;
  /**
   * CRM — the Super Admin's own CRM account ("Platform (Super Admin)"),
   * the CRM target when no client is selected. Null/absent for every other
   * user, and for a Super Admin when it has not been created yet.
   */
  platform_crm_account?: { id: number; company_name: string } | null;
  created_at: string;
}

export interface LoginCredentials {
  email: string;
  password: string;
}

export interface LoginResponse {
  user: User;
  token: string;
  token_type: 'Bearer';
}

export interface MeResponse {
  user: User;
  permissions: string[];
  /** The user's account's *current subscription* status — null for Super Admin. */
  subscription_status: 'active' | 'expired' | 'exhausted' | null;
}

export interface AuthState {
  user: User | null;
  token: string | null;
  isAuthenticated: boolean;
  isLoading: boolean;
}

/** Shape of a Laravel validation / API error response. */
export interface ApiErrorResponse {
  message: string;
  errors?: Record<string, string[]>;
  error_code?:
    | 'SUBSCRIPTION_EXPIRED'
    | 'ACCOUNT_SUSPENDED'
    | 'CLIENT_ACCOUNT_SUSPENDED'
    | 'UNAUTHENTICATED'
    // Strict Bulk Messaging Limit & Tier-Based Cooldown -- the 429
    // MessageTemplateController::sendBulk() returns while an
    // account's bulk-dispatch cooldown is still active.
    | 'BULK_COOLDOWN_ACTIVE'
    | string;
  /** Present only alongside error_code === 'BULK_COOLDOWN_ACTIVE'. */
  cooldown_remaining_seconds?: number;
}
