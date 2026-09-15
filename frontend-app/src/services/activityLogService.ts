import axiosInstance from '../core/api/axiosInstance';
import type { ActivityLogFilters, ActivityLogsResponse } from '../types/activityLog';

function cleanFilters(filters: ActivityLogFilters): Record<string, string | number> {
  const out: Record<string, string | number> = {};
  if (filters.agent_id) out.agent_id = filters.agent_id;
  if (filters.account_id) out.account_id = filters.account_id;
  if (filters.module_name) out.module_name = filters.module_name;
  if (filters.action_type) out.action_type = filters.action_type;
  if (filters.from) out.from = filters.from;
  if (filters.to) out.to = filters.to;
  return out;
}

/**
 * IMPLEMENT: Dynamic Route Master with Super-Admin Bypass & Global Audit
 * Tracking — requirement 4. See ActivityLogController's docblock for why
 * this is Super-Admin-only (permission:view-activity-logs), distinct
 * from the existing auditLogService.ts (login history).
 */
const activityLogService = {
  list(page: number, perPage: number, filters: ActivityLogFilters) {
    return axiosInstance
      .get<ActivityLogsResponse>('/admin/activity-logs', { params: { page, per_page: perPage, ...cleanFilters(filters) } })
      .then((res) => res.data);
  },

  listModules() {
    return axiosInstance.get<{ data: string[] }>('/admin/activity-logs/modules').then((res) => res.data.data);
  },
};

export default activityLogService;
