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
  is_active: boolean;
  account_id: number | null;
  /** null only for Super Admin — global access, not tied to a tenant account. */
  account: Account | null;
  role: Role;
  /** Flattened permission names for fast client-side checks. */
  permissions: string[];
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
  error_code?: 'SUBSCRIPTION_EXPIRED' | 'ACCOUNT_SUSPENDED' | 'UNAUTHENTICATED' | string;
}
