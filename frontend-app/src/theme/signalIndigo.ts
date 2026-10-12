/**
 * UI overhaul — "Signal Indigo" design tokens. Centralized so AppLayout's
 * sidebar and DashboardPage's quick actions/stat icons stay visually
 * consistent (e.g. "Send Alert" in the sidebar and "Send Payment Alert" as
 * a dashboard quick action share the same tint) without copy-pasting hex
 * values across files. Extend this file, not the page components, when
 * the palette needs to change.
 */

// 2026-10-12: the app now uses the public website's brand — emerald → teal
// accents, slate ink/borders. The export keeps its historical name so no
// import changes; the values are the WapHub brand.
// The values are CSS variables (see tailwind.config.js) so they follow the
// dashboard's chosen accent colour and light/dark mode.
export const indigo = {
  canvas: 'rgb(var(--app-canvas, 248 250 249))',
  ink: 'rgb(var(--slate-text-900, 15 23 42))',
  muted: 'rgb(var(--slate-text-500, 100 116 139))',
  border: 'rgb(var(--slate-line-200, 226 232 240))',
  track: 'rgb(var(--brand-100, 209 250 229))',
  accentFrom: 'rgb(var(--brand-500, 16 185 129))',
  accentTo: 'rgb(var(--brand-700, 4 120 87))',
  accentSolid: 'rgb(var(--brand-600, 5 150 105))',
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
  dashboard: { bg: 'rgb(var(--brand-500, 16 185 129) / 0.14)', fg: 'rgb(var(--brand-text-600, 5 150 105))' },
  whatsapp: { bg: '#0F8F7624', fg: '#0F8F76' },
  send: { bg: '#2563EB24', fg: '#2563EB' },
  analytics: { bg: '#8B5CF624', fg: '#8B5CF6' },
  chatbot: { bg: '#B4530924', fg: '#B45309' },
  billing: { bg: '#0E9F6E24', fg: '#0E9F6E' },
  developer: { bg: '#C0269C24', fg: '#C0269C' },
  team: { bg: '#0E7C9024', fg: '#0E7C90' },
  accounts: { bg: '#0F766E24', fg: '#0F766E' },
  gateway: { bg: 'rgb(var(--slate-500, 100 116 139) / 0.16)', fg: 'rgb(var(--slate-text-600, 71 85 105))' },
  // Social Media Marketing & Meta Ads Automation Expansion (Phase 1) —
  // a fifth semantic tint (Meta-adjacent blue) for the Social Accounts hub.
  social: { bg: '#1D4ED824', fg: '#1D4ED8' },
};

export const activeGradient = `linear-gradient(90deg, ${indigo.accentFrom}, ${indigo.accentTo})`;
/**
 * Strong outline for Dashboard / Analytics stat cards and panels (owner's choice,
 * 2026-10-12): the ink colour, so it reads dark on light mode and light on dark mode.
 */
export const cardOutline = 'rgb(var(--slate-text-800, 30 41 59))';

export const cardShadow ='0 4px 14px rgb(var(--brand-600, 5 150 105) / 0.07)';

/**
 * Role slug -> badge classes. Keys are the exact backend role slugs
 * (super_admin/admin/user — see RolePermissionSeeder). Shared by Header's
 * ProfileTrigger and ProfileModal.
 */
export const ROLE_BADGE_CLASS: Record<string, string> = {
  super_admin: 'bg-teal-50 text-teal-800 border-teal-200',
  admin: 'bg-emerald-50 text-emerald-800 border-emerald-200',
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
