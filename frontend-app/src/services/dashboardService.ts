import axiosInstance from '../core/api/axiosInstance';

/** GET /api/dashboard/overview — see DashboardOverviewController. */
export interface PlatformOverview {
  attention: { pending_invoices: number; quota_requests: number; contact_messages: number; expiring_7d: number };
  plan_split: { plan_key: string | null; label: string; clients: number }[];
  top_clients: { account_id: number; company_name: string; messages_30d: number }[];
}

export interface AgentOverview {
  clients_total: number;
  clients_active: number;
  commission_month: number;
  commission_total: number;
  pending_invoices: number;
  messages_30d: number;
  expiring: { account_id: number; company_name: string; expires_at: string | null }[];
}

export interface TenantOverview {
  account_id: number;
  plan: { key: string; label: string } | null;
  subscription: {
    status: string;
    billing_model: string;
    engine_type: string;
    expires_at: string | null;
    messages_left: number | null;
  } | null;
  capabilities: Record<string, boolean>;
  crm?: { total: number; new: number; contacted: number; converted: number; new_7d: number };
  journeys?: { active: number; total: number; running: number; completed_30d: number };
  ai?: { available: number; balance: number };
  ads?: { spend_30d: number; leads_30d: number; cpl_30d: number | null };
}

export interface DashboardOverview {
  scope: 'platform' | 'tenant';
  platform?: PlatformOverview;
  agent?: AgentOverview;
  tenant?: TenantOverview;
}

const dashboardService = {
  overview() {
    return axiosInstance.get<DashboardOverview>('/dashboard/overview').then((res) => res.data);
  },
};

export default dashboardService;
