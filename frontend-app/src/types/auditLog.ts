/**
 * Role-Based Login Audit Logging Architecture — mirrors LoginAuditLog +
 * AuditLogController's JSON shape. See that controller's docblock for the
 * per-role scoping rules (super_admin: any/all accounts, admin: their own
 * account, user: only their own login rows).
 */

import type { PaginatedResponse } from './account';

export type LoginAuditStatus = 'success' | 'failed';

export interface LoginAuditLog {
  id: number;
  user: { id: number; name: string; email: string } | null;
  account: { id: number; company_name: string } | null;
  role: string | null;
  email: string | null;
  ip_address: string | null;
  user_agent: string | null;
  status: LoginAuditStatus;
  logged_in_at: string;
  created_at: string;
}

/** GET /api/audit-logs */
export interface AuditLogsResponse extends PaginatedResponse<LoginAuditLog> {
  /** 'account' when narrowed to one client (Super Admin's switcher, or always for admin/user), else 'global'. */
  scope: 'account' | 'global';
}

export interface AuditLogFilters {
  search?: string;
  status?: LoginAuditStatus | '';
  role?: string;
  from?: string;
  to?: string;
}
