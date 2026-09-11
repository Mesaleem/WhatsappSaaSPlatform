/**
 * UI overhaul — "Signal Indigo" design tokens. Centralized so AppLayout's
 * sidebar and DashboardPage's quick actions/stat icons stay visually
 * consistent (e.g. "Send Alert" in the sidebar and "Send Payment Alert" as
 * a dashboard quick action share the same tint) without copy-pasting hex
 * values across files. Extend this file, not the page components, when
 * the palette needs to change.
 */

export const indigo = {
  canvas: '#FAFAFC',
  ink: '#201A4D',
  muted: '#726C99',
  border: '#EAE8F7',
  track: '#EDEBFA',
  accentFrom: '#4F46E5',
  accentTo: '#7C3AED',
  accentSolid: '#4F46E5',
  success: '#0E9F6E',
  successSoft: '#E6F8F1',
  warn: '#D97706',
  warnSoft: '#FFF3E0',
  danger: '#DC2626',
  dangerSoft: '#FDECEC',
};

export interface Tint {
  bg: string;
  fg: string;
}

/**
 * One semantic tint per functional area of the product — deliberately
 * chosen (not cycled) so the color itself carries meaning: connection
 * (teal), outbound sends (sky), insight (violet), automation (amber),
 * money (emerald), external access (fuchsia), people (cyan), platform
 * admin (slate).
 */
export const NAV_TINTS: Record<string, Tint> = {
  dashboard: { bg: '#EEECFB', fg: '#5B4FE0' },
  whatsapp: { bg: '#E1F5F1', fg: '#0F8F76' },
  send: { bg: '#E3F0FF', fg: '#2563EB' },
  analytics: { bg: '#F1E9FE', fg: '#8B5CF6' },
  chatbot: { bg: '#FEF3D9', fg: '#B45309' },
  billing: { bg: '#E6F8F1', fg: '#0E9F6E' },
  developer: { bg: '#FCE7F6', fg: '#C0269C' },
  team: { bg: '#E0F7FA', fg: '#0E7C90' },
  accounts: { bg: '#EDE9FE', fg: '#6D28D9' },
  gateway: { bg: '#F1F0F4', fg: '#57536B' },
  // Social Media Marketing & Meta Ads Automation Expansion (Phase 1) —
  // a fifth semantic tint (Meta-adjacent blue) for the Social Accounts hub.
  social: { bg: '#DBEAFE', fg: '#1D4ED8' },
};

export const activeGradient = `linear-gradient(90deg, ${indigo.accentFrom}, ${indigo.accentTo})`;
export const cardShadow = '0 4px 14px rgba(79, 70, 229, 0.07)';

/**
 * Role slug -> badge classes. Keys are the exact backend role slugs
 * (super_admin/admin/user — see RolePermissionSeeder). Shared by Header's
 * ProfileTrigger and ProfileModal.
 */
export const ROLE_BADGE_CLASS: Record<string, string> = {
  super_admin: 'bg-violet-50 text-violet-700 border-violet-200',
  admin: 'bg-[#EEECFB] text-[#5B4FE0] border-[#DCD8F7]',
  user: 'bg-slate-100 text-slate-600 border-slate-200',
  // Social Media Marketing & Meta Ads Automation Expansion (Phase 1).
  social_marketer: 'bg-blue-50 text-blue-700 border-blue-200',
};

/**
 * Role slug -> human-readable label. Falls back to a generic title-case of
 * the raw slug for any dynamic role a Super Admin creates later via Role
 * Management (RoleController) — those aren't in this static map.
 */
export const ROLE_LABEL: Record<string, string> = {
  super_admin: 'Super Admin',
  admin: 'Admin',
  // Client Management, Team Users, Dynamic RBAC Sidebar & Global Table
  // Filters refactor — the 'user' role (no rename of the underlying role
  // slug — RolePermissionSeeder, RoleController and every existing
  // Rule::exists('roles','name') check all keep working unmodified) is
  // relabeled "Team Member" everywhere it is displayed, matching the new
  // Create User modal's role picker language.
  user: 'Team Member',
  // Social Media Marketing & Meta Ads Automation Expansion (Phase 1).
  social_marketer: 'Social Marketer',
};

export function roleLabel(roleName: string): string {
  return (
    ROLE_LABEL[roleName] ??
    roleName
      .split(/[-_]/)
      .filter(Boolean)
      .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
      .join(' ')
  );
}
