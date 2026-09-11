/**
 * Client Admin Granular Permission Matrix (TeamController::MANAGED_PERMISSIONS
 * on the backend — keep both lists in sync by hand). Two groups, matching
 * the spec's checklist exactly: WhatsApp Module (5 actions — 'Send Messages'
 * reuses the existing `send-messages` permission rather than a new slug,
 * see the backend docblock) and Social Ads Module (4 actions —
 * 'social_ads.delete_rules' has no corresponding backend action yet;
 * disclosed in the audit report, not invented here).
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

export type MatrixPermission =
  | (typeof WHATSAPP_MATRIX_PERMISSIONS)[number]
  | (typeof SOCIAL_ADS_MATRIX_PERMISSIONS)[number];

export const MATRIX_PERMISSION_LABELS: Record<MatrixPermission, string> = {
  'whatsapp.view': 'View',
  'whatsapp.create': 'Create',
  'whatsapp.edit': 'Edit',
  'whatsapp.delete': 'Delete',
  'send-messages': 'Send Messages',
  'social_ads.view': 'View',
  'social_ads.launch': 'Launch Ads',
  'social_ads.edit_budget': 'Edit Budget',
  'social_ads.delete_rules': 'Delete Rules',
};

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
