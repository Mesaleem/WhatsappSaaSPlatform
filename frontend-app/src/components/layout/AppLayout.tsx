import { useEffect, useMemo, useState, type ComponentType } from 'react';
import { NavLink, Outlet, useLocation } from 'react-router-dom';
import {
  BarChart3,
  Bot,
  Building2,
  ChevronsLeft,
  ChevronsRight,
  ClipboardList,
  Code2,
  Contact,
  CreditCard,
  History,
  Inbox,
  LayoutDashboard,
  Megaphone,
  MessageSquare,
  QrCode,
  FileBarChart,
  Rocket,
  Send,
  Settings,
  Share2,
  Smartphone,
  Sparkles,
  Target,
  UserSearch,
  Users,
  Zap,
} from 'lucide-react';
import { useAuth } from '../../core/context/AuthContext';
import type { AccountModule } from '../../types/account';
import { indigo, NAV_TINTS, activeGradient, type Tint } from '../../theme/signalIndigo';
import Header from './Header';

interface NavItem {
  label: string;
  to: string;
  icon: ComponentType<{ className?: string }>;
  tint: Tint;
  /** Omit to show for any authenticated user (e.g. Dashboard, WhatsApp Setup). */
  permission?: string;
  /** Only shown to a user with a tenant account_id (hidden for Super Admin). */
  requiresAccount?: boolean;
  /** Only shown to Super Admin, regardless of permission. */
  superAdminOnly?: boolean;
  /**
   * Hidden from Super Admin even though they hold the permission (Super
   * Admin bypasses ordinary permission checks — see isNavItemVisible
   * below). Send Alert is the only item using this: Super Admin now
   * tests templates from Template Manager's "Send Template" action
   * (through their own scanned device), not the per-client Send Alert
   * flow.
   */
  hiddenForSuperAdmin?: boolean;
  /**
   * Absolute Super Admin Control — hidden for a non-Super-Admin whose
   * account has this module disabled (Account.allowed_modules). Super
   * Admin is never affected by this, even while a client is selected.
   */
  requiresModule?: AccountModule;
  /**
   * Social Media Marketing & Meta Ads Automation Expansion (Phase 1).
   * Hides this item for the listed exact role slugs, regardless of
   * `permission`. Needed ONLY for items with no `permission` gate of
   * their own (e.g. "WhatsApp Setup" is requiresAccount-only, so a
   * social_marketer would otherwise see it) — every other item in this
   * spec (Billing, Developer API) is already hidden for social_marketer
   * automatically, because that role simply isn't granted those
   * permissions (RolePermissionSeeder). Never checked for Super Admin
   * (superAdminOnly items aside, Super Admin bypasses ordinary gates).
   */
  hiddenForRoles?: string[];
}

/**
 * Single source of truth for both the sidebar's link list AND the header's
 * dynamic page title (matched by the longest `to` prefix of the current
 * path) — one array instead of two, so a new nav item can never desync
 * from the title lookup. Each item's `tint` is a deliberate, semantic
 * color (see theme/signalIndigo.ts) — not a decorative cycle.
 */
/**
 * Absolute Super Admin Control — every item below whose feature maps to
 * an Account::MODULES slug now carries `requiresModule` so a disabled
 * module is hidden regardless of the viewer's permission (this is the
 * fix for the reported "Developer API" bug: the permission check alone
 * was never enough, since a role can hold a permission while the tenant
 * account has the matching module turned off). 'Dashboard' is
 * deliberately exempt — it's the app's landing/fallback route (see
 * UnauthorizedPage's "Back to dashboard" link and the catch-all "*"
 * redirect in App.tsx), so gating it risks locking an account out with
 * nowhere left to land; disabling it was already a no-op everywhere else
 * in the app before this fix and remains one now, by design.
 */
/**
 * [Bug fix, disclosed - Sidebar/Social-Suite module gating gap]:
 * 'Social Accounts' was the only Social Suite nav item that actually
 * carried `requiresModule` (see the "closes a previously-disclosed gap"
 * comment on Account::MODULES' 'social_accounts' slug) - the other five
 * Social Suite items (Meta Ads Launcher, Social Inbox, Comment Rules,
 * Instant Lead CRM, Social Reports) were gated ONLY by `permission`, with
 * no `requiresModule` at all. RolePermissionSeeder grants their
 * underlying permissions (launch-meta-ads, manage-social-leads,
 * manage-comment-automation, view-social-analytics) to 'admin' /
 * 'social_marketer' UNCONDITIONALLY - a role grant, not a per-tenant
 * setting - so disabling the Social Media suite for a client's account
 * (Account.allowed_modules, via Module & Feature Access / applySocialPlan())
 * never hid these five items: the permission check alone was always
 * satisfied regardless of the tenant's module state. This is the
 * confirmed root cause of "customer still sees Social Media items after
 * Social Media is disabled" - NOT a permission cache or localStorage
 * staleness issue (Account's model cache already invalidates correctly
 * on save, /auth/me and login() already return a fresh
 * `account.allowed_modules` on every call, and this file's own
 * `hasModule`-driven useMemo already recomputes whenever `user` changes -
 * all verified by reading the full chain end-to-end before this fix).
 * Each item now carries its own SOCIAL_SUITE_MODULES slug, matching
 * 'Social Accounts' exactly.
 */
const NAV_ITEMS: NavItem[] = [
  { label: 'Dashboard', to: '/', icon: LayoutDashboard, tint: NAV_TINTS.dashboard },
  { label: 'WhatsApp Setup', to: '/settings/whatsapp', icon: QrCode, tint: NAV_TINTS.whatsapp, requiresAccount: true, requiresModule: 'whatsapp_setup', hiddenForRoles: ['social_marketer'] },
  { label: 'Send Alert', to: '/alerts/send', icon: Send, tint: NAV_TINTS.send, permission: 'send-messages', requiresModule: 'send_alert', hiddenForSuperAdmin: true },
  { label: 'Analytics', to: '/analytics', icon: BarChart3, tint: NAV_TINTS.analytics, permission: 'view-analytics', requiresModule: 'analytics' },
  // [New feature, disclosed]: "view-logs" (row-level recipient PII), not
  // "view-analytics" — see App.tsx's route comment for the full
  // reasoning. Message Logs Governance Fix: requiresModule was
  // 'analytics' (piggybacked on the Analytics page's gate, itself a
  // correction of the request's literal, nonexistent 'whatsapp' slug);
  // now its own 'message_logs' slug under WHATSAPP_SUITE_MODULES, so a
  // Super Admin can toggle it independently in "Module & Feature Access".
  { label: 'Message Logs', to: '/message-logs', icon: ClipboardList, tint: NAV_TINTS.analytics, permission: 'view-logs', requiresModule: 'message_logs' },
  { label: 'Chatbot Rules', to: '/chatbot', icon: Bot, tint: NAV_TINTS.chatbot, permission: 'manage-chatbot', requiresModule: 'chatbot' },
  // Module 5 — No-Code WhatsApp Journey Builder. Same permission/module
  // tier as Chatbot Rules directly above (see routes/api.php's docblock
  // for why this reuses that tier rather than a new permission slug).
  { label: 'Journey Builder', to: '/chatbot/journeys', icon: Target, tint: NAV_TINTS.chatbot, permission: 'manage-chatbot', requiresModule: 'chatbot' },
  // Group Messaging — Module-to-UI Sync architecture rule (explicit
  // instruction): gated by requiresModule: 'contact_groups' like every
  // other module-backed item in this list. [Disclosed, superseded
  // behavior]: this item previously omitted requiresModule on purpose,
  // specifically so ContactGroupsPage's locked-upsell card (for a
  // tenant without this paid addon) stayed reachable through normal
  // sidebar navigation. Adding requiresModule here is a real,
  // intentional behavior change, not a bugfix: a tenant WITHOUT
  // 'contact_groups' no longer sees this item in the sidebar at all,
  // so they can no longer discover the locked/upsell card by
  // browsing — only by a direct URL/bookmark (App.tsx's route still
  // has no `module` guard, left as-is, so that direct link still
  // renders the locked card rather than a 403). Icon kept as `Contact`
  // rather than the requested `Users` — 'Team Users' below already
  // uses the Users icon; reusing it here would make two different
  // sidebar items visually identical.
  { label: 'Contact Groups', to: '/contact-groups', icon: Contact, tint: NAV_TINTS.whatsapp, permission: 'send-messages', requiresModule: 'contact_groups' },
  { label: 'Billing & Plans', to: '/billing', icon: CreditCard, tint: NAV_TINTS.billing, permission: 'manage-subscriptions', requiresModule: 'billing' },
  { label: 'Developer API', to: '/developer', icon: Code2, tint: NAV_TINTS.developer, permission: 'manage-developer-settings', requiresModule: 'developer_api' },
  { label: 'Team Users', to: '/users', icon: Users, tint: NAV_TINTS.team, permission: 'manage-team', requiresModule: 'team_management' },
  // Social Media Marketing & Meta Ads Automation Expansion (Phase 1).
  // "Module & Feature Access" modal reorganization: 'social_accounts' is
  // now a real ACCOUNT_MODULES slug (Social Media Suite), closing the
  // gap disclosed in an earlier task — this nav item is gated the same
  // way every other module-backed item above it is.
  { label: 'Social Accounts', to: '/social/accounts', icon: Share2, tint: NAV_TINTS.social, permission: 'manage-social-accounts', requiresModule: 'social_accounts' },
  // Social Media Marketing & Meta Ads Automation Expansion (Phase 3).
  { label: 'Meta Ads Launcher', to: '/social/ads', icon: Rocket, tint: NAV_TINTS.social, permission: 'launch-meta-ads', requiresModule: 'meta_ads' },
  // Social Media Marketing & Meta Ads Automation Expansion (Phase 4).
  { label: 'Social Inbox', to: '/social/inbox', icon: Inbox, tint: NAV_TINTS.social, permission: 'manage-social-leads', requiresModule: 'social_inbox' },
  { label: 'Comment Rules', to: '/social/comment-rules', icon: MessageSquare, tint: NAV_TINTS.social, permission: 'manage-comment-automation', requiresModule: 'comment_automation' },
  // Social Media Marketing & Meta Ads Automation Expansion — Final Phase.
  { label: 'Instant Lead CRM', to: '/social/leads', icon: UserSearch, tint: NAV_TINTS.social, permission: 'manage-social-leads', requiresModule: 'lead_crm' },
  { label: 'Social Reports', to: '/social/reports', icon: FileBarChart, tint: NAV_TINTS.social, permission: 'view-social-analytics', requiresModule: 'reports' },
  { label: 'Manage Clients', to: '/admin/accounts', icon: Building2, tint: NAV_TINTS.accounts, superAdminOnly: true },
  // Dynamic Templates & Variables System — Super Admin Template Designer & Approval Panel.
  { label: 'Template Manager', to: '/admin/templates', icon: Sparkles, tint: NAV_TINTS.accounts, superAdminOnly: true },
  { label: 'Admin Gateway Settings', to: '/admin/billing/gateway-settings', icon: Settings, tint: NAV_TINTS.gateway, superAdminOnly: true },
  // Quota Exhaustion Request Workflow & Custom Invoice Generation —
  // Super Admin review/approval queue for Client Admin top-up requests.
  { label: 'Quota Top-Up Requests', to: '/admin/quota-requests', icon: Zap, tint: NAV_TINTS.billing, superAdminOnly: true },
  // Social Media Marketing & Meta Ads Automation Expansion (Phase 2).
  { label: 'Social Gateway Settings', to: '/admin/social-settings', icon: Settings, tint: NAV_TINTS.social, superAdminOnly: true },
  // Super Admin WhatsApp Device Integration — link/view/disconnect/reconnect any client's device from one screen.
  { label: 'Device Settings', to: '/admin/device-settings', icon: Smartphone, tint: NAV_TINTS.whatsapp, superAdminOnly: true },
  // Role-Based Login Audit Logging Architecture — held by all three
  // default roles (RolePermissionSeeder), scoped per-role server-side.
  { label: 'Audit Logs', to: '/audit-logs', icon: History, tint: NAV_TINTS.gateway, permission: 'view-audit-logs' },
  // Advanced Broadcast Engine + Mail Template Manager — super_admin
  // (via PERMISSIONS) and admin only.
  { label: 'Notifications', to: '/notifications', icon: Megaphone, tint: NAV_TINTS.chatbot, permission: 'manage-notifications', requiresModule: 'notifications' },
];

function isNavItemVisible(
  item: NavItem,
  opts: {
    isSuperAdmin: boolean;
    hasAccount: boolean;
    hasPermission: (p: string) => boolean;
    hasModule: (m: AccountModule) => boolean;
    hasRole: (roleName: string) => boolean;
  },
): boolean {
  if (item.superAdminOnly) return opts.isSuperAdmin;
  if (item.hiddenForSuperAdmin && opts.isSuperAdmin) return false;
  if (!opts.isSuperAdmin && item.hiddenForRoles?.some((role) => opts.hasRole(role))) return false;
  if (item.requiresAccount && !opts.hasAccount) return false;
  if (item.permission && !opts.isSuperAdmin && !opts.hasPermission(item.permission)) return false;
  if (item.requiresModule && !opts.isSuperAdmin && !opts.hasModule(item.requiresModule)) return false;
  return true;
}

/** Longest matching `to` prefix wins, so "/admin/billing/gateway-settings" doesn't fall through to "/billing"'s title. */
function resolvePageTitle(pathname: string): string {
  let best: NavItem | null = null;
  for (const item of NAV_ITEMS) {
    const matches = item.to === '/' ? pathname === '/' : pathname.startsWith(item.to);
    if (matches && (!best || item.to.length > best.to.length)) {
      best = item;
    }
  }
  return best?.label ?? 'Dashboard';
}

export default function AppLayout() {
  const { user, hasPermission, isSuperAdmin, hasModule, hasRole, refreshUser } = useAuth();
  const location = useLocation();
  const [isCollapsed, setIsCollapsed] = useState(false);

  const hasAccount = !!user?.account_id;
  const superAdmin = isSuperAdmin();

  const visibleItems = useMemo(
    () =>
      NAV_ITEMS.filter((item) =>
        isNavItemVisible(item, { isSuperAdmin: superAdmin, hasAccount, hasPermission, hasModule, hasRole }),
      ),
    [superAdmin, hasAccount, hasPermission, hasModule, hasRole],
  );

  // Session Re-hydration & Refresh (Module-to-UI Sync architecture
  // rule). AppLayout is a layout route (App.tsx wraps every
  // authenticated page in this one instance via <Outlet/>) — it mounts
  // exactly ONCE per session and stays mounted across every in-app
  // navigation, so this is the correct single place for this, not any
  // one page. `visibleItems` above is already fully reactive to
  // `hasModule`/`user` (a plain useMemo, no stale local copy) — the gap
  // was never that computation, it was that nothing re-fetched
  // /auth/me after the initial login, so a Super Admin's change made
  // in another tab/session was invisible here until a hard refresh
  // remounted the whole app. Two triggers, no polling: every in-app
  // navigation (covers "toggled it, then just used the sidebar"), and
  // the browser tab regaining focus (covers "toggled it in another
  // tab, then switched back to this one without navigating anywhere
  // in it"). [Disclosed limit]: a tab left open and idle, never
  // navigated and never refocused, will not update on its own — true
  // push/polling would be needed for that and was not asked for here.
  // [Disclosed]: previously lived as a Dashboard-only mount effect;
  // consolidated here so it applies to every module-gated nav item,
  // not just the ones Dashboard happens to render.
  useEffect(() => {
    void refreshUser();
  }, [location.pathname, refreshUser]);

  useEffect(() => {
    const onFocus = () => void refreshUser();
    window.addEventListener('focus', onFocus);
    return () => window.removeEventListener('focus', onFocus);
  }, [refreshUser]);

  const pageTitle = resolvePageTitle(location.pathname);

  return (
    <div className="flex h-screen overflow-hidden" style={{ background: indigo.canvas }}>
      {/* Sidebar */}
      <aside
        className={`flex flex-shrink-0 flex-col bg-white transition-all duration-200 ${isCollapsed ? 'w-16' : 'w-64'}`}
        style={{ borderRight: `1px solid ${indigo.border}` }}
      >
        <div className="flex h-14 items-center gap-2.5 px-4" style={{ borderBottom: `1px solid ${indigo.border}` }}>
          <div
            className="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-lg font-display text-sm font-bold text-white"
            style={{ background: activeGradient }}
          >
            W
          </div>
          {!isCollapsed && (
            <span className="truncate font-display text-sm font-bold" style={{ color: indigo.ink }}>
              WA SaaS Platform
            </span>
          )}
        </div>

        <nav className="flex-1 space-y-1 overflow-y-auto px-2.5 py-3">
          {visibleItems.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.to === '/'}
              className={({ isActive }) =>
                `flex items-center gap-3 rounded-xl px-2.5 py-2 text-sm font-medium transition ${
                  isActive ? 'font-semibold text-white' : 'hover:bg-[#FAFAFF]'
                }`
              }
              style={({ isActive }) => ({
                background: isActive ? activeGradient : undefined,
                color: isActive ? '#FFFFFF' : indigo.ink,
              })}
              title={isCollapsed ? item.label : undefined}
            >
              {({ isActive }) => (
                <>
                  <span
                    className="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-lg"
                    style={{
                      background: isActive ? 'rgba(255,255,255,.22)' : item.tint.bg,
                      color: isActive ? '#FFFFFF' : item.tint.fg,
                    }}
                  >
                    <item.icon className="h-4 w-4" />
                  </span>
                  {!isCollapsed && <span className="truncate">{item.label}</span>}
                </>
              )}
            </NavLink>
          ))}
        </nav>

        <div className="p-2" style={{ borderTop: `1px solid ${indigo.border}` }}>
          <button
            onClick={() => setIsCollapsed((v) => !v)}
            className="flex w-full items-center justify-center gap-2 rounded-lg px-3 py-2 text-xs font-medium hover:bg-[#FAFAFF]"
            style={{ color: indigo.muted }}
          >
            {isCollapsed ? <ChevronsRight className="h-4 w-4" /> : <ChevronsLeft className="h-4 w-4" />}
            {!isCollapsed && 'Collapse'}
          </button>
        </div>
      </aside>

      {/* Main column */}
      <div className="flex min-w-0 flex-1 flex-col">
        <Header pageTitle={pageTitle} />

        <main className="flex-1 overflow-y-auto">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
