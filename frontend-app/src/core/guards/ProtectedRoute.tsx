import { Navigate, useLocation } from 'react-router-dom';
import type { ReactNode } from 'react';
import { useAuth } from '../context/AuthContext';
import type { AccountModule } from '../../types/account';

interface ProtectedRouteProps {
  children: ReactNode;
  /** Require this permission (checked against the user's flattened permission list). */
  permission?: string;
  /**
   * Require this exact role name (e.g. "Super Admin"). Like `permission`
   * and `module` above, THIS BYPASSES FOR SUPER ADMIN — kept for any
   * future route that wants "this role, or Super Admin acting on its
   * behalf." Use `strictRole` below instead for a route that must be
   * genuinely unreachable by Super Admin.
   */
  role?: string;
  /**
   * Super Admin / Client Admin RBAC boundary refactor — require this
   * EXACT role name and, unlike `role` above, does NOT bypass for Super
   * Admin. Some surfaces (the /team/permissions Granular Permission
   * Matrix) must be truly unreachable by Super Admin, not just absent
   * from the sidebar, per that refactor's spec: "Hide/Remove the
   * Granular Permission Matrix screen from the Super Admin view
   * completely."
   */
  strictRole?: string;
  /**
   * Route Guard Protection — require this Account::MODULES slug to be
   * enabled for the current tenant account (see AuthContext::hasModule).
   * Mirrors AppLayout's `requiresModule` nav-item guard so a disabled
   * module can never be reached by typing its URL directly, even by a
   * role that still holds the matching `permission`. Always passes for
   * Super Admin.
   */
  module?: AccountModule;
}

/**
 * Wraps a route element and enforces, in order:
 *  1. Authentication (redirect -> /login, preserving intended destination)
 *  2. Optional permission / role requirement (redirect -> /unauthorized)
 *
 * Read-Only Subscription Expired Mode: this guard NO LONGER redirects an
 * expired/suspended account to /subscription-expired — every route stays
 * reachable in read-only form (see AuthContext::isReadOnly(), which
 * individual pages use to disable their own mutation controls, and
 * SubscriptionGuardMiddleware, which enforces the same split server-side
 * for POST/PUT/PATCH/DELETE). There is deliberately no `isReadOnly()`
 * check anywhere in this file — a full-screen route lock is exactly the
 * behavior this mode replaces, so this guard's only remaining job is
 * authentication and permission/role gating.
 */
export function ProtectedRoute({ children, permission, role, strictRole, module }: ProtectedRouteProps) {
  const { isAuthenticated, isLoading, user, hasPermission, hasRole, isSuperAdmin, hasModule } = useAuth();
  const location = useLocation();

  if (isLoading) {
    return (
      <div className="flex h-screen w-full items-center justify-center bg-slate-50">
        <div className="h-8 w-8 animate-spin rounded-full border-2 border-slate-300 border-t-indigo-600" />
      </div>
    );
  }

  if (!isAuthenticated || !user) {
    return <Navigate to="/login" state={{ from: location }} replace />;
  }

  const superAdmin = isSuperAdmin();

  if (permission && !superAdmin && !hasPermission(permission)) {
    return <Navigate to="/unauthorized" replace />;
  }

  if (role && !superAdmin && !hasRole(role)) {
    return <Navigate to="/unauthorized" replace />;
  }

  if (strictRole && !hasRole(strictRole)) {
    return <Navigate to="/unauthorized" replace />;
  }

  if (module && !hasModule(module)) {
    return <Navigate to="/unauthorized" state={{ reason: 'module' }} replace />;
  }

  return <>{children}</>;
}
