import { Navigate, useLocation } from 'react-router-dom';
import type { ReactNode } from 'react';
import { useAuth } from '../context/AuthContext';
import type { AccountModule } from '../../types/account';

interface ProtectedRouteProps {
  children: ReactNode;
  /** Require this permission (checked against the user's flattened permission list). */
  permission?: string;
  /** Require this exact role name (e.g. "Super Admin"). */
  role?: string;
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
export function ProtectedRoute({ children, permission, role, module }: ProtectedRouteProps) {
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

  if (module && !hasModule(module)) {
    return <Navigate to="/unauthorized" state={{ reason: 'module' }} replace />;
  }

  return <>{children}</>;
}
