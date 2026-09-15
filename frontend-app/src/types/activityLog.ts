/**
 * IMPLEMENT: Dynamic Route Master with Super-Admin Bypass & Global Audit
 * Tracking — requirement 3/4. Mirrors backend-api's ActivityLog +
 * ActivityLogController's JSON shape.
 *
 * DISCLOSED — distinct from, and unrelated to, `types/auditLog.ts`
 * (`LoginAuditLog`): that file covers ONLY login attempts (success/
 * failed). This file covers CRUD mutation activity (create/update/
 * delete/toggle) across the platform's models. See ActivityLogsPage.tsx.
 */

import type { PaginatedResponse } from './account';

export type ActivityActionType = 'create' | 'update' | 'delete' | 'toggle';

export interface ActivityLog {
  id: number;
  user: { id: number; name: string; email: string } | null;
  account: { id: number; company_name: string } | null;
  agent: { id: number; company_name: string } | null;
  module_name: string;
  action_type: ActivityActionType;
  route_path: string | null;
  ip_address: string | null;
  old_values: Record<string, unknown> | null;
  new_values: Record<string, unknown> | null;
  created_at: string;
}

/** GET /api/admin/activity-logs */
export type ActivityLogsResponse = PaginatedResponse<ActivityLog>;

export interface ActivityLogFilters {
  agent_id?: number;
  account_id?: number;
  module_name?: string;
  action_type?: ActivityActionType | '';
  from?: string;
  to?: string;
}
