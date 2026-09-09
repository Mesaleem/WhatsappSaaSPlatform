import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { AuthProvider } from './core/context/AuthContext';
import { TenantProvider } from './core/context/TenantContext';
import { ProtectedRoute } from './core/guards/ProtectedRoute';
import AppLayout from './components/layout/AppLayout';
import LoginPage from './pages/auth/LoginPage';
import DashboardPage from './pages/DashboardPage';
import AccountsPage from './pages/admin/AccountsPage';
import TemplateManagerPage from './pages/admin/TemplateManagerPage';
import WhatsAppSetupPage from './pages/settings/WhatsAppSetupPage';
import SendAlertPage from './pages/alerts/SendAlertPage';
import AnalyticsPage from './pages/analytics/AnalyticsPage';
import BillingPage from './pages/billing/BillingPage';
import GatewaySettingsPage from './pages/admin/GatewaySettingsPage';
import AdminDeviceSettingsPage from './pages/admin/AdminDeviceSettingsPage';
import DeveloperPage from './pages/developer/DeveloperPage';
import ChatbotPage from './pages/chatbot/ChatbotPage';
import UsersPage from './pages/users/UsersPage';
import AuditLogsPage from './pages/audit/AuditLogsPage';
import NotificationsPage from './pages/notifications/NotificationsPage';
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
              path="/admin/device-settings"
              element={
                <ProtectedRoute permission="manage-accounts">
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
              path="/users"
              element={
                <ProtectedRoute permission="manage-team" module="team_management">
                  <UsersPage />
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
                <ProtectedRoute permission="manage-notifications">
                  <NotificationsPage />
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
