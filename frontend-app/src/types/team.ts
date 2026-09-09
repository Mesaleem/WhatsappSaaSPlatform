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
  is_active: boolean;
  /** Only id/name are selected server-side. */
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

/** POST /api/team/users */
export interface InviteTeamUserPayload {
  name: string;
  email: string;
  password: string;
  role_name: string;
}

export interface AssignableRole {
  id: number;
  name: string;
}
