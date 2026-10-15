import type { AccountModule } from './account';

/**
 * Client Admin Granular Permission Matrix (TeamController::MANAGED_PERMISSIONS
 * on the backend — keep both lists in sync by hand).
 *
 * QA fixes (2026-10-14): the matrix covers every tenant feature a team member
 * can be given, not only chatbot and ads. Each group (and each permission)
 * names the modules it needs; the page shows only what the client's plan has.
 * 'social_ads.delete_rules' still has no backend action (kept as before).
 */

export const WHATSAPP_MATRIX_PERMISSIONS = [
  'whatsapp.view',
  'whatsapp.create',
  'whatsapp.edit',
  'whatsapp.delete',
  'send-messages',
] as const;

export const SOCIAL_ADS_MATRIX_PERMISSIONS = [
  'social_ads.view',
  'social_ads.launch',
  'social_ads.edit_budget',
  'social_ads.delete_rules',
] as const;

export const WHATSAPP_ADMIN_MATRIX_PERMISSIONS = ['manage-whatsapp-setup', 'manage-chatbot', 'view-logs'] as const;

export const CRM_MATRIX_PERMISSIONS = ['manage-crm', 'view-all-crm', 'manage-crm-documents', 'manage-crm-payments'] as const;

export const SOCIAL_MATRIX_PERMISSIONS = [
  'manage-social-accounts',
  'manage-social-leads',
  'manage-comment-automation',
  'view-social-analytics',
] as const;

export type MatrixPermission =
  | (typeof WHATSAPP_MATRIX_PERMISSIONS)[number]
  | (typeof SOCIAL_ADS_MATRIX_PERMISSIONS)[number]
  | (typeof WHATSAPP_ADMIN_MATRIX_PERMISSIONS)[number]
  | (typeof CRM_MATRIX_PERMISSIONS)[number]
  | (typeof SOCIAL_MATRIX_PERMISSIONS)[number];

export const MATRIX_PERMISSION_LABELS: Record<MatrixPermission, string> = {
  'whatsapp.view': 'View rules',
  'whatsapp.create': 'Create rules',
  'whatsapp.edit': 'Edit rules',
  'whatsapp.delete': 'Delete rules',
  'send-messages': 'Send Messages',
  'social_ads.view': 'View',
  'social_ads.launch': 'Launch Ads',
  'social_ads.edit_budget': 'Edit Budget',
  'social_ads.delete_rules': 'Delete Rules',
  'manage-whatsapp-setup': 'Connect / disconnect WhatsApp',
  'manage-chatbot': 'Chatbot & journeys',
  'view-logs': 'Message logs',
  'manage-crm': 'Leads, deals & tasks',
  'view-all-crm': "See everyone's leads",
  'manage-crm-documents': 'Quotes & invoices',
  'manage-crm-payments': 'Payments',
  'manage-social-accounts': 'Social accounts & posts',
  'manage-social-leads': 'Social inbox & leads',
  'manage-comment-automation': 'Comment rules',
  'view-social-analytics': 'Social analytics',
};

export interface MatrixGroup {
  key: string;
  title: string;
  /** The group shows when the account has ANY of these modules. */
  modules: AccountModule[];
  permissions: readonly MatrixPermission[];
  hint?: string;
}

export const MATRIX_GROUPS: MatrixGroup[] = [
  { key: 'whatsapp', title: 'WhatsApp', modules: ['chatbot', 'send_alert'], permissions: WHATSAPP_MATRIX_PERMISSIONS },
  {
    key: 'whatsapp_admin',
    title: 'WhatsApp setup & logs',
    modules: ['whatsapp_setup', 'chatbot', 'message_logs'],
    permissions: WHATSAPP_ADMIN_MATRIX_PERMISSIONS,
  },
  {
    key: 'crm',
    title: 'CRM',
    modules: ['lead_crm'],
    permissions: CRM_MATRIX_PERMISSIONS,
    hint: "Without “See everyone's leads” a member sees only the leads, deals and tasks assigned to them.",
  },
  {
    key: 'social',
    title: 'Social media',
    modules: ['social_accounts', 'social_inbox', 'comment_automation', 'reports'],
    permissions: SOCIAL_MATRIX_PERMISSIONS,
  },
  { key: 'ads', title: 'Social Ads', modules: ['meta_ads'], permissions: SOCIAL_ADS_MATRIX_PERMISSIONS },
];

/** GET/PATCH /api/team/users/{id}/permissions response shape. */
export interface TeamMemberPermissions {
  user_id: number;
  /** Directly granted to this user — what the matrix checkboxes control. */
  direct: MatrixPermission[];
  /** Every managed permission the user actually has (direct OR via role). */
  effective: MatrixPermission[];
}

/** PATCH /api/team/users/{id}/permissions payload. */
export interface UpdateTeamMemberPermissionsPayload {
  permissions: MatrixPermission[];
}
