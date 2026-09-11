/**
 * UI overhaul — Team Users page type definitions. Mirrors
 * TeamController's JSON shape.
 */

import type { Role } from './auth';

export interface TeamUser {
  id: number;
  account_id: number;
  name: string;
  email: string;
  phone_number: string | null;
  is_active: boolean;
  /** Only id/name are selected server-side. Multi-Role Assignment — already a full array (unaffected by that refactor; only the WRITE side, role_name -> role_names, changed). */
  roles: Array<Pick<Role, 'id' | 'name'>>;
  created_at: string;
  /**
   * Super Admin Multi-Tenant Scoping: present ONLY in the cross-tenant
   * ('global') response — TeamController::index() eager-loads
   * account:id,company_name only on that branch, so the tenant-scoped
   * response never carries this field.
   */
  account?: { id: number; company_name: string } | null;
}

/** GET /api/team/users response. 'account' = one tenant (unchanged shape); 'global' = every tenant, Super Admin only (see TeamController::index()). */
export type TeamUsersScope = 'account' | 'global';

/**
 * POST /api/team/users. Multi-Role Assignment — role_name (string)
 * replaced with role_names (string[], min 1); TeamController::store()
 * already passes this straight to Spatie's assignRole(), which accepts
 * an array natively.
 */
export interface InviteTeamUserPayload {
  name: string;
  email: string;
  phone_number?: string | null;
  password: string;
  role_names: string[];
}

/** PATCH /api/team/users/{id} — Client Management & Team Users refactor: Edit User (no password/is_active). Multi-Role Assignment — see InviteTeamUserPayload. */
export interface UpdateTeamUserPayload {
  name: string;
  email: string;
  phone_number?: string | null;
  role_names: string[];
}

export interface AssignableRole {
  id: number;
  name: string;
}
