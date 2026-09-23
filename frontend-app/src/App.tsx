import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { AuthProvider } from './core/context/AuthContext';
import { TenantProvider } from './core/context/TenantContext';
import { ProtectedRoute } from './core/guards/ProtectedRoute';
import AppLayout from './components/layout/AppLayout';
import LoginPage from './pages/auth/LoginPage';
import DashboardPage from './pages/DashboardPage';
import AccountsPage from './pages/admin/AccountsPage';
import TemplateManagerPage from './pages/admin/TemplateManagerPage';
import RouteMasterPage from './pages/admin/RouteMasterPage';
import PlanManagementPage from './pages/admin/PlanManagementPage';
import ActivityLogsPage from './pages/admin/ActivityLogsPage';
import WhatsAppSetupPage from './pages/settings/WhatsAppSetupPage';
import SendAlertPage from './pages/alerts/SendAlertPage';
import AnalyticsPage from './pages/analytics/AnalyticsPage';
import MessageLogsPage from './pages/analytics/MessageLogsPage';
import BillingPage from './pages/billing/BillingPage';
import GatewaySettingsPage from './pages/admin/GatewaySettingsPage';
import QuotaRequestsPage from './pages/admin/QuotaRequestsPage';
import AdminDeviceSettingsPage from './pages/admin/AdminDeviceSettingsPage';
import DeveloperPage from './pages/developer/DeveloperPage';
import ChatbotPage from './pages/chatbot/ChatbotPage';
import JourneyBuilderPage from './pages/whatsapp/JourneyBuilderPage';
import ContactGroupsPage from './pages/whatsapp/ContactGroupsPage';
import UsersPage from './pages/users/UsersPage';
import TeamPermissionsPage from './pages/team/TeamPermissionsPage';
import AuditLogsPage from './pages/audit/AuditLogsPage';
import NotificationsPage from './pages/notifications/NotificationsPage';
import SocialAccountsPage from './pages/social/SocialAccountsPage';
import MetaAdsPage from './pages/social/MetaAdsPage';
import AdminSocialSettingsPage from './pages/admin/AdminSocialSettingsPage';
import SocialInboxPage from './pages/social/SocialInboxPage';
import CommentRulesPage from './pages/social/CommentRulesPage';
import LeadsPage from './pages/social/LeadsPage';
import SocialReportsPage from './pages/social/SocialReportsPage';
import CrmLeadsPage from './pages/crm/CrmLeadsPage';
import CrmLeadDetailPage from './pages/crm/CrmLeadDetailPage';
import CrmPipelinePage from './pages/crm/CrmPipelinePage';
import CrmContactsPage from './pages/crm/CrmContactsPage';
import CrmContactDetailPage from './pages/crm/CrmContactDetailPage';
import CrmTagsPage from './pages/crm/CrmTagsPage';
import CrmAnalyticsPage from './pages/crm/CrmAnalyticsPage';
import SubscriptionExpiredPage from './pages/errors/SubscriptionExpiredPage';
import UnauthorizedPage from './pages/errors/UnauthorizedPage';

export default function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <TenantProvider>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route path="/subscription-expired" element={<SubscriptionExpiredPage />} />
          <Route path="/unauthorized" element={<UnauthorizedPage />} />

          {/*
            UI overhaul — persistent App Shell. This is a path-less "layout
            route": ProtectedRoute here only enforces auth + active
            subscription (no `permission` prop) and renders AppLayout, which
            renders the sidebar/header ONCE and an <Outlet /> for whichever
            child route below matches. Every route in this codebase now lives
            here instead of standing alone, which is the actual fix for the
            reported bug (the navbar "disappearing" on navigation was every
            page being its own full-screen root with no shared parent to
            persist across route changes).

            Route PATHS are deliberately unchanged from before this module
            (still "/", "/settings/whatsapp", "/alerts/send", etc.) rather
            than renamed to "/dashboard", "/whatsapp", "/send-alert" — the
            reported defect was the missing persistent shell, not the URLs,
            and renaming them would break any bookmarked/shared links for no
            functional gain. Per-route permission gating is preserved exactly
            as it was: each child route below still wraps its page in its own
            ProtectedRoute with that page's permission, which is a harmless,
            idempotent re-check (auth/subscription already passed to get here)
            rather than a new access-control mechanism.
          */}
          <Route
            element={
              <ProtectedRoute>
                <AppLayout />
              </ProtectedRoute>
            }
          >
            <Route path="/" element={<DashboardPage />} />
            <Route
              path="/settings/whatsapp"
              element={
                <ProtectedRoute module="whatsapp_setup">
                  <WhatsAppSetupPage />
                </ProtectedRoute>
              }
            />
            <Route
              path="/admin/accounts"
              element={
                <ProtectedRoute permission="manage-accounts">
                  <AccountsPage />
                </ProtectedRoute>
              }
            />
            <Route
              path="/admin/templates"
              element={
                <ProtectedRoute permission="manage-templates">
                  <TemplateManagerPage />
                </ProtectedRoute>
              }
            />
            {/* BUILD: Fully Dynamic Categorized Route Master & Nested
                Permission Matrix UI. role="super_admin" (not
                permission="manage-accounts") deliberately: manage-accounts
                is ALSO held by every Agent, but this CRUD console must be
                Super-Admin-only — role bypasses for a real Super Admin
                and otherwise requires the exact role name, which no
                Agent holds, so this is effectively "Super Admin only"
                without adding a strictRole-shaped one-off. */}
            {/*
              Phase 5 Task 12 — Super Admin plan management.
              strictRole, not role: `role` bypasses for Super Admin but
              this page must be genuinely unreachable by anyone else,
              and the backend refuses a non-Super-Admin call anyway
              (PlanManagementController::assertSuperAdmin). Route
              hiding is UX; that 403 is the security boundary.
            */}
            <Route
              path="/admin/plans"
              element={
                <ProtectedRoute strictRole="super_admin">
                  <PlanManagementPage />
                </ProtectedRoute>
              }
            />
            <Route
              path="/admin/route-master"
              element={
                <ProtectedRoute role="super_admin">
                  <RouteMasterPage />
                </ProtectedRoute>
              }
            />
            {/* IMPLEMENT: Dynamic Route Master with Super-Admin Bypass &
                Global Audit Tracking — requirement 4. permission=
                "view-activity-logs" (a NEW permission only super_admin
                holds — see RolePermissionSeeder), not role="super_admin":
                this stays reachable by any future role someone
                deliberately grants that permission to, consistent with
                how every other permission-gated route in this file
                already works, while still being Super-Admin-only today
                by construction (no other DEFAULT_ROLES entry lists it). */}
            <Route
              path="/admin/audit-logs"
              element={
                <ProtectedRoute permission="view-activity-logs">
                  <ActivityLogsPage />
                </ProtectedRoute>
              }
            />
            <Route
              path="/alerts/send"
              element={
                <ProtectedRoute permission="send-messages" module="send_alert">
                  <SendAlertPage />
                </ProtectedRoute>
              }
            />
            <Route
              path="/analytics"
              element={
                <ProtectedRoute permission="view-analytics" module="analytics">
                  <AnalyticsPage />
                </ProtectedRoute>
              }
            />
            {/*
              [New feature, disclosed]: gated on "view-logs" (the same
              permission the pre-existing /alerts/logs grid uses — see
              MessageLogController's route group in routes/api.php) rather
              than "view-analytics", since this exposes the same row-level
              PII (recipient phone numbers) that permission was created to
              restrict. Message Logs Governance Fix: module was "analytics"
              (itself a prior correction of the request's literal,
              nonexistent 'whatsapp' slug — see Account::MODULES in the
              backend); it's now its own "message_logs" slug, independently
              assignable per tenant under the WhatsApp Messaging Suite in
              "Module & Feature Access" (see types/account.ts's
              WHATSAPP_SUITE_MODULES).
            */}
            <Route
              path="/message-logs"
              element={
                <ProtectedRoute permission="view-logs" module="message_logs">
                  <MessageLogsPage />
                </ProtectedRoute>
              }
            />
            <Route
              path="/billing"
              element={
                <ProtectedRoute permission="manage-subscriptions" module="billing">
                  <BillingPage />
                </ProtectedRoute>
              }
            />
            <Route
              path="/admin/billing/gateway-settings"
              element={
                <ProtectedRoute permission="manage-billing-settings">
                  <GatewaySettingsPage />
                </ProtectedRoute>
              }
            />
            <Route
              path="/admin/quota-requests"
              element={
                // Agent-Routed Quota Top-Up Requests — manage-accounts,
                // not manage-billing-settings, so an Agent (who already
                // holds manage-accounts) can reach its own Sub-Clients'
                // requests here; QuotaRequestController scopes the data.
                <ProtectedRoute permission="manage-accounts">
                  <QuotaRequestsPage />
                </ProtectedRoute>
              }
            />
            {/* WhatsApp production-readiness audit fix: was
                permission="manage-accounts", which Agent also holds (for
                its own Sub-Client management) — that let an Agent open
                this page directly by URL even though the sidebar already
                hides it (superAdminOnly). strictRole matches this route's
                real backend gate now (role:super_admin on
                /admin/whatsapp/devices and /admin/whatsapp/self-device/*
                — see routes/api.php), and — unlike `permission`/`role` —
                does not bypass for any caller who isn't ACTUALLY Super
                Admin. */}
            <Route
              path="/admin/device-settings"
              element={
                <ProtectedRoute strictRole="super_admin">
                  <AdminDeviceSettingsPage />
                </ProtectedRoute>
              }
            />
            <Route
              path="/developer"
              element={
                <ProtectedRoute permission="manage-developer-settings" module="developer_api">
                  <DeveloperPage />
                </ProtectedRoute>
              }
            />
            <Route
              path="/chatbot"
              element={
                <ProtectedRoute permission="manage-chatbot" module="chatbot">
                  <ChatbotPage />
                </ProtectedRoute>
              }
            />
            <Route
              path="/chatbot/journeys"
              element={
                <ProtectedRoute permission="manage-chatbot" module="chatbot">
                  <JourneyBuilderPage />
                </ProtectedRoute>
              }
            />
            {/*
              [Disclosed, superseded]: this route previously omitted
              `module`, deliberately, specifically so ContactGroupsPage's
              own locked-upsell card ('Custom Contact Groups are locked
              under your current plan...') would still render for a
              tenant without this paid addon, instead of ProtectedRoute
              redirecting to /unauthorized before the page ever mounted.
              Per the "Strict Route Protection" architecture rule
              (explicit instruction — every module-gated feature's route
              MUST carry a matching `module` guard, no exceptions), that
              trade-off is now resolved in favor of strict enforcement:
              `module="contact_groups"` added below. CONSEQUENCE: the
              locked-upsell branch inside ContactGroupsPage.tsx is now
              unreachable dead code — a non-subscribed tenant is
              redirected to /unauthorized before that branch can ever
              render, the same as every other module-gated route. Left
              in place rather than deleted, since removing a feature
              wasn't what was asked here.
            */}
            <Route
              path="/contact-groups"
              element={
                <ProtectedRoute permission="send-messages" module="contact_groups">
                  <ContactGroupsPage />
                </ProtectedRoute>
              }
            />
            <Route
              path="/users"
              element={
                <ProtectedRoute permission="manage-team" module="team_management">
                  <UsersPage />
                </ProtectedRoute>
              }
            />
            {/* Client Admin Granular Permission Matrix — Super Admin / Client
                Admin RBAC boundary refactor: exclusively the Client Admin's
                workspace now. `strictRole="admin"` (unlike the plain `role`
                prop) does NOT bypass for Super Admin, so this route is
                genuinely unreachable by Super Admin — not merely hidden
                from a nav link — matching the spec's "Hide/Remove the
                Granular Permission Matrix screen from the Super Admin view
                completely." It also excludes 'social_marketer' (which
                still holds manage-team for the plain Team Users page
                above), since the spec restricts this specific screen to
                the 'admin' role alone. */}
            <Route
              path="/team/permissions"
              element={
                <ProtectedRoute permission="manage-team" module="team_management" strictRole="admin">
                  <TeamPermissionsPage />
                </ProtectedRoute>
              }
            />
            <Route
              path="/audit-logs"
              element={
                <ProtectedRoute permission="view-audit-logs">
                  <AuditLogsPage />
                </ProtectedRoute>
              }
            />
            <Route
              path="/notifications"
              element={
                <ProtectedRoute permission="manage-notifications" module="notifications">
                  <NotificationsPage />
                </ProtectedRoute>
              }
            />
            {/* Social Media Marketing & Meta Ads Automation Expansion (Phase 1). */}
            <Route
              path="/social/accounts"
              element={
                <ProtectedRoute permission="manage-social-accounts" module="social_accounts">
                  <SocialAccountsPage />
                </ProtectedRoute>
              }
            />
            {/* Social Media Marketing & Meta Ads Automation Expansion (Phase 3). */}
            <Route
              path="/social/ads"
              element={
                <ProtectedRoute permission="launch-meta-ads" module="meta_ads">
                  <MetaAdsPage />
                </ProtectedRoute>
              }
            />
            {/* Social Media Marketing & Meta Ads Automation Expansion (Phase 2). */}
            <Route
              path="/admin/social-settings"
              element={
                <ProtectedRoute permission="manage-social-settings">
                  <AdminSocialSettingsPage />
                </ProtectedRoute>
              }
            />
            {/* Social Media Marketing & Meta Ads Automation Expansion (Phase 4). */}
            <Route
              path="/social/inbox"
              element={
                <ProtectedRoute permission="manage-social-leads" module="social_inbox">
                  <SocialInboxPage />
                </ProtectedRoute>
              }
            />
            <Route
              path="/social/comment-rules"
              element={
                <ProtectedRoute permission="manage-comment-automation" module="comment_automation">
                  <CommentRulesPage />
                </ProtectedRoute>
              }
            />
            {/* Social Media Marketing & Meta Ads Automation Expansion (Final Phase). */}
            <Route
              path="/social/leads"
              element={
                <ProtectedRoute permission="manage-social-leads" module="lead_crm">
                  <LeadsPage />
                </ProtectedRoute>
              }
            />
            <Route
              path="/social/reports"
              element={
                <ProtectedRoute permission="view-social-analytics" module="reports">
                  <SocialReportsPage />
                </ProtectedRoute>
              }
            />
            {/*
              Phase 6 CRM Task 8 — the CRM frontend. Every route carries the
              same three gates the backend puts on /api/crm/*: manage-crm,
              the lead_crm module and the crm capability. These guards are
              UX; tenant.isolation, module.guard, permission and
              capability.guard on the API are the boundary.
            */}
            <Route
              path="/crm/leads"
              element={
                <ProtectedRoute permission="manage-crm" module="lead_crm" capability="crm">
                  <CrmLeadsPage />
                </ProtectedRoute>
              }
            />
            <Route
              path="/crm/leads/:id"
              element={
                <ProtectedRoute permission="manage-crm" module="lead_crm" capability="crm">
                  <CrmLeadDetailPage />
                </ProtectedRoute>
              }
            />
            <Route
              path="/crm/pipeline"
              element={
                <ProtectedRoute permission="manage-crm" module="lead_crm" capability="crm">
                  <CrmPipelinePage />
                </ProtectedRoute>
              }
            />
            <Route
              path="/crm/contacts"
              element={
                <ProtectedRoute permission="manage-crm" module="lead_crm" capability="crm">
                  <CrmContactsPage />
                </ProtectedRoute>
              }
            />
            <Route
              path="/crm/contacts/:id"
              element={
                <ProtectedRoute permission="manage-crm" module="lead_crm" capability="crm">
                  <CrmContactDetailPage />
                </ProtectedRoute>
              }
            />
            <Route
              path="/crm/tags"
              element={
                <ProtectedRoute permission="manage-crm" module="lead_crm" capability="crm">
                  <CrmTagsPage />
                </ProtectedRoute>
              }
            />
            <Route
              path="/crm/analytics"
              element={
                <ProtectedRoute permission="manage-crm" module="lead_crm" capability="crm">
                  <CrmAnalyticsPage />
                </ProtectedRoute>
              }
            />
          </Route>

          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
        </TenantProvider>
      </AuthProvider>
    </BrowserRouter>
  );
}
