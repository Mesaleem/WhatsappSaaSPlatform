import { lazy, Suspense } from 'react';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { AuthProvider } from './core/context/AuthContext';
import { TenantProvider } from './core/context/TenantContext';
import { ProtectedRoute } from './core/guards/ProtectedRoute';
import AppLayout from './components/layout/AppLayout';
import { IndustryModuleRoute } from './core/guards/IndustryModuleRoute';
import WebsiteLayout from './website/WebsiteLayout';
import { SiteContentProvider } from './website/SiteContentContext';
import { loadAboutPage, loadContactPage, loadFeaturesPage, loadHomePage, loadLegalPage, loadPricingPage, loadSignupPage } from './website/pageLoaders';

// Every page is its own chunk: a visitor downloads only the code of the page it opens
// (the public website no longer pulls in the whole dashboard). See RouteFallback below.
const LoginPage = lazy(() => import('./pages/auth/LoginPage'));
const ForgotPasswordPage = lazy(() => import('./pages/auth/ForgotPasswordPage'));
const ResetPasswordPage = lazy(() => import('./pages/auth/ResetPasswordPage'));
const DashboardPage = lazy(() => import('./pages/DashboardPage'));
const AccountsPage = lazy(() => import('./pages/admin/AccountsPage'));
const TemplateManagerPage = lazy(() => import('./pages/admin/TemplateManagerPage'));
const PlanManagementPage = lazy(() => import('./pages/admin/PlanManagementPage'));
const ActivityLogsPage = lazy(() => import('./pages/admin/ActivityLogsPage'));
const WhatsAppSetupPage = lazy(() => import('./pages/settings/WhatsAppSetupPage'));
const SendAlertPage = lazy(() => import('./pages/alerts/SendAlertPage'));
const AnalyticsPage = lazy(() => import('./pages/analytics/AnalyticsPage'));
const MessageLogsPage = lazy(() => import('./pages/analytics/MessageLogsPage'));
const BillingPage = lazy(() => import('./pages/billing/BillingPage'));
const AddOnsPage = lazy(() => import('./pages/billing/AddOnsPage'));
const GatewaySettingsPage = lazy(() => import('./pages/admin/GatewaySettingsPage'));
const QuotaRequestsPage = lazy(() => import('./pages/admin/QuotaRequestsPage'));
const ApiAccessPage = lazy(() => import('./pages/admin/ApiAccessPage'));
const AdminDeviceSettingsPage = lazy(() => import('./pages/admin/AdminDeviceSettingsPage'));
const DeveloperPage = lazy(() => import('./pages/developer/DeveloperPage'));
const ChatbotPage = lazy(() => import('./pages/chatbot/ChatbotPage'));
const JourneyBuilderPage = lazy(() => import('./pages/whatsapp/JourneyBuilderPage'));
const ContactGroupsPage = lazy(() => import('./pages/whatsapp/ContactGroupsPage'));
const UsersPage = lazy(() => import('./pages/users/UsersPage'));
const TeamPermissionsPage = lazy(() => import('./pages/team/TeamPermissionsPage'));
const AuditLogsPage = lazy(() => import('./pages/audit/AuditLogsPage'));
const NotificationsPage = lazy(() => import('./pages/notifications/NotificationsPage'));
const SocialAccountsPage = lazy(() => import('./pages/social/SocialAccountsPage'));
const MetaAdsPage = lazy(() => import('./pages/social/MetaAdsPage'));
const AdsDashboardPage = lazy(() => import('./pages/social/AdsDashboardPage'));
const AdminSocialSettingsPage = lazy(() => import('./pages/admin/AdminSocialSettingsPage'));
const AdminAiSettingsPage = lazy(() => import('./pages/admin/AdminAiSettingsPage'));
const SocialInboxPage = lazy(() => import('./pages/social/SocialInboxPage'));
const WhatsAppInboxPage = lazy(() => import('./pages/whatsapp/WhatsAppInboxPage'));
const CommentRulesPage = lazy(() => import('./pages/social/CommentRulesPage'));
const LeadsPage = lazy(() => import('./pages/social/LeadsPage'));
const SocialReportsPage = lazy(() => import('./pages/social/SocialReportsPage'));
const SocialAnalyticsPage = lazy(() => import('./pages/social/SocialAnalyticsPage'));
const CrmLeadsPage = lazy(() => import('./pages/crm/CrmLeadsPage'));
const CrmLeadDetailPage = lazy(() => import('./pages/crm/CrmLeadDetailPage'));
const CrmPipelinePage = lazy(() => import('./pages/crm/CrmPipelinePage'));
const CrmContactsPage = lazy(() => import('./pages/crm/CrmContactsPage'));
const CrmContactDetailPage = lazy(() => import('./pages/crm/CrmContactDetailPage'));
const CrmTagsPage = lazy(() => import('./pages/crm/CrmTagsPage'));
const CrmAnalyticsPage = lazy(() => import('./pages/crm/CrmAnalyticsPage'));
// CRM Sales Phase 1.
const CrmCompaniesPage = lazy(() => import('./pages/crm/CrmCompaniesPage'));
const CrmCompanyDetailPage = lazy(() => import('./pages/crm/CrmCompanyDetailPage'));
const CrmDealsPage = lazy(() => import('./pages/crm/CrmDealsPage'));
const CrmDealDetailPage = lazy(() => import('./pages/crm/CrmDealDetailPage'));
const CrmTasksPage = lazy(() => import('./pages/crm/CrmTasksPage'));
// CRM Sales Phases 2–6.
const CrmDocumentsPage = lazy(() => import('./pages/crm/sales/CrmDocumentsPage'));
const CrmDocumentEditorPage = lazy(() => import('./pages/crm/sales/CrmDocumentEditorPage'));
const CrmDocumentDetailPage = lazy(() => import('./pages/crm/sales/CrmDocumentDetailPage'));
const CrmPaymentsPage = lazy(() => import('./pages/crm/sales/CrmPaymentsPage'));
const CrmProductsPage = lazy(() => import('./pages/crm/sales/CrmProductsPage'));
const CrmSalesSettingsPage = lazy(() => import('./pages/crm/sales/CrmSalesSettingsPage'));
const CrmSalesDashboardPage = lazy(() => import('./pages/crm/sales/CrmSalesDashboardPage'));
const PublicDocumentPage = lazy(() => import('./pages/public/PublicDocumentPage'));
const EducationPage = lazy(() => import('./pages/education/EducationPage'));
const SubscriptionExpiredPage = lazy(() => import('./pages/errors/SubscriptionExpiredPage'));
const UnauthorizedPage = lazy(() => import('./pages/errors/UnauthorizedPage'));
const WebsiteContentPage = lazy(() => import('./pages/admin/WebsiteContentPage'));
const SiteAnalyticsPage = lazy(() => import('./pages/admin/SiteAnalyticsPage'));
// Website pages share their loaders with the prefetch in website/pageLoaders.ts.
const HomePage = lazy(loadHomePage);
const FeaturesPage = lazy(loadFeaturesPage);
const PricingPage = lazy(loadPricingPage);
const AboutPage = lazy(loadAboutPage);
const ContactPage = lazy(loadContactPage);
const LegalPage = lazy(loadLegalPage);
const SignupPage = lazy(loadSignupPage);

export default function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <TenantProvider>
        <Suspense fallback={<RouteFallback />}>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          {/* CRM Sales Phases 3–4 — a business's customer opens / pays a document from its link (no login). */}
          <Route path="/d/:token" element={<PublicDocumentPage />} />
          <Route path="/forgot-password" element={<ForgotPasswordPage />} />
          <Route path="/reset-password" element={<ResetPasswordPage />} />
          <Route path="/subscription-expired" element={<SubscriptionExpiredPage />} />
          <Route path="/unauthorized" element={<UnauthorizedPage />} />

          {/* Public marketing website. Guests who open "/" are sent to /home by ProtectedRoute. */}
          <Route element={<WebsiteLayout />}>
            <Route path="/home" element={<HomePage />} />
            <Route path="/features" element={<FeaturesPage />} />
            <Route path="/pricing" element={<PricingPage />} />
            <Route path="/about" element={<AboutPage />} />
            <Route path="/contact" element={<ContactPage />} />
            <Route path="/privacy" element={<LegalPage page="privacy" />} />
            <Route path="/terms" element={<LegalPage page="terms" />} />
            <Route path="/data-deletion" element={<LegalPage page="data_deletion" />} />
          </Route>
          {/* Full-screen split layout like /login, still fed by the website content. */}
          <Route path="/signup" element={<SiteContentProvider><SignupPage /></SiteContentProvider>} />

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
              path="/whatsapp/inbox"
              element={
                <ProtectedRoute permission="send-messages" module="send_alert">
                  <WhatsAppInboxPage />
                </ProtectedRoute>
              }
            />
            <Route
              path="/settings/whatsapp"
              element={
                <ProtectedRoute permission="manage-whatsapp-setup" module="whatsapp_setup">
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
              path="/admin/api-access"
              element={
                <ProtectedRoute strictRole="super_admin">
                  <ApiAccessPage />
                </ProtectedRoute>
              }
            />
            {/* Route Master removed (plan restructure, 2026-10-15): a client's menu modules come from its plan. */}
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
              path="/add-ons"
              element={
                <ProtectedRoute permission="manage-subscriptions" module="billing">
                  <AddOnsPage />
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
                <ProtectedRoute anyPermission={['manage-chatbot', 'whatsapp.view']} module="chatbot">
                  <ChatbotPage />
                </ProtectedRoute>
              }
            />
            <Route
              path="/chatbot/journeys"
              element={
                <ProtectedRoute anyPermission={['manage-chatbot', 'whatsapp.view']} module="chatbot" capability="journey_automation">
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
                <ProtectedRoute permission="manage-social-accounts" module="social_accounts" capability="social">
                  <SocialAccountsPage />
                </ProtectedRoute>
              }
            />
            {/* Social Media Marketing & Meta Ads Automation Expansion (Phase 3). */}
            {/* Phase 10 Task 2 — also the `ads` capability, as the backend requires (capability.guard:ads). */}
            {/* Phase 10 Task 3 — any Ads permission the backend accepts for reads (launch-meta-ads | social_ads.view). */}
            <Route
              path="/social/ads"
              element={
                <ProtectedRoute anyPermission={['launch-meta-ads', 'social_ads.view']} module="meta_ads" capability="ads">
                  <MetaAdsPage />
                </ProtectedRoute>
              }
            />
            <Route
              path="/social/ads/dashboard"
              element={
                <ProtectedRoute anyPermission={['launch-meta-ads', 'social_ads.view']} module="meta_ads" capability="ads">
                  <AdsDashboardPage />
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
            {/* Phase 8 Task 12 (continued) — platform AI provider/model settings. */}
            <Route
              path="/admin/ai-settings"
              element={
                <ProtectedRoute permission="manage-ai-settings">
                  <AdminAiSettingsPage />
                </ProtectedRoute>
              }
            />
            <Route
              path="/admin/website"
              element={
                <ProtectedRoute strictRole="super_admin">
                  <WebsiteContentPage />
                </ProtectedRoute>
              }
            />
            {/* Website visitor analytics — Super Admin only. */}
            <Route
              path="/admin/website/analytics"
              element={
                <ProtectedRoute strictRole="super_admin">
                  <SiteAnalyticsPage />
                </ProtectedRoute>
              }
            />
            {/* Social Media Marketing & Meta Ads Automation Expansion (Phase 4). */}
            <Route
              path="/social/inbox"
              element={
                <ProtectedRoute permission="manage-social-leads" module="social_inbox" capability="social">
                  <SocialInboxPage />
                </ProtectedRoute>
              }
            />
            <Route
              path="/social/comment-rules"
              element={
                <ProtectedRoute permission="manage-comment-automation" module="comment_automation" capability="social">
                  <CommentRulesPage />
                </ProtectedRoute>
              }
            />
            {/* Social Media Marketing & Meta Ads Automation Expansion (Final Phase). */}
            <Route
              path="/social/leads"
              element={
                <ProtectedRoute permission="manage-social-leads" module="lead_crm" capability="social">
                  <LeadsPage />
                </ProtectedRoute>
              }
            />
            {/* Phase 9 Task 5 — Social Analytics dashboard: the same three gates as
                GET /api/social/analytics/* (view-social-analytics, NOT the management
                permission; social_accounts module; social capability). */}
            <Route
              path="/social/analytics"
              element={
                <ProtectedRoute permission="view-social-analytics" module="social_accounts" capability="social">
                  <SocialAnalyticsPage />
                </ProtectedRoute>
              }
            />
            <Route
              path="/social/reports"
              element={
                <ProtectedRoute permission="view-social-analytics" module="reports" capability="social">
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
            {/* CRM Sales Phase 1 — same three gates as every CRM page (and every /api/crm/* route). */}
            {(
              [
                ['/crm/companies', <CrmCompaniesPage />],
                ['/crm/companies/:id', <CrmCompanyDetailPage />],
                ['/crm/deals', <CrmDealsPage />],
                ['/crm/deals/:id', <CrmDealDetailPage />],
                ['/crm/tasks', <CrmTasksPage />],
              ] as const
            ).map(([path, page]) => (
              <Route
                key={path}
                path={path}
                element={
                  <ProtectedRoute permission="manage-crm" module="lead_crm" capability="crm">
                    {page}
                  </ProtectedRoute>
                }
              />
            ))}
            {/* CRM Sales Phases 2–6 — the CRM gates plus the documents / payments permission (the backend's too). */}
            {(
              [
                ['/crm/sales', <CrmSalesDashboardPage />, 'manage-crm-documents'],
                ['/crm/documents', <CrmDocumentsPage />, 'manage-crm-documents'],
                ['/crm/documents/new', <CrmDocumentEditorPage />, 'manage-crm-documents'],
                ['/crm/documents/:id', <CrmDocumentDetailPage />, 'manage-crm-documents'],
                ['/crm/documents/:id/edit', <CrmDocumentEditorPage />, 'manage-crm-documents'],
                ['/crm/products', <CrmProductsPage />, 'manage-crm-documents'],
                ['/crm/settings', <CrmSalesSettingsPage />, 'manage-crm-documents'],
                ['/crm/payments', <CrmPaymentsPage />, 'manage-crm-payments'],
              ] as const
            ).map(([path, page, permission]) => (
              <Route
                key={path}
                path={path}
                element={
                  <ProtectedRoute permission={permission} module="lead_crm" capability="crm">
                    {page}
                  </ProtectedRoute>
                }
              />
            ))}
            {/* Phase 11 Task 2 — Education. Permission here; industry assigned + capability + module + shipped come
                from the backend's industry_modules keys (IndustryModuleRoute). API calls are re-authorized by industry.guard. */}
            <Route
              path="/education"
              element={
                <ProtectedRoute permission="view-education">
                  <IndustryModuleRoute moduleKey="education.students">
                    <EducationPage />
                  </IndustryModuleRoute>
                </ProtectedRoute>
              }
            />
          </Route>

          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
        </Suspense>
        </TenantProvider>
      </AuthProvider>
    </BrowserRouter>
  );
}

/** Shown for the moment a page's code chunk is downloading. */
function RouteFallback() {
  return (
    <div className="flex min-h-[50vh] w-full items-center justify-center" role="status" aria-label="Loading">
      <div className="h-8 w-8 animate-spin rounded-full border-2 border-slate-200 border-t-emerald-600" />
    </div>
  );
}
