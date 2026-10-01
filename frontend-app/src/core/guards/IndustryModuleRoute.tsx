import { Navigate } from 'react-router-dom';
import type { ReactNode } from 'react';
import { hasIndustryModule } from '../../components/layout/industryNav';
import { useIndustryModuleKeys } from '../../components/layout/useIndustryModuleKeys';

/**
 * Phase 11 Task 2 — route guard for an industry module page, placed INSIDE ProtectedRoute (which checks
 * authentication and the permission). Admits only when the module key is usable for the account being
 * viewed (industry assigned + capability + module + permission + shipped — decided by the backend and
 * delivered as `industry_modules`). UX only: every API call is authorized again by `industry.guard`.
 * A Super Admin with no selected client has no keys, so never reaches the page by URL either.
 */
export function IndustryModuleRoute({ moduleKey, children }: { moduleKey: string; children: ReactNode }) {
  const { keys, isLoading } = useIndustryModuleKeys();

  if (isLoading) {
    return (
      <div className="flex h-64 w-full items-center justify-center" role="status" aria-label="Loading">
        <div className="h-8 w-8 animate-spin rounded-full border-2 border-slate-300 border-t-indigo-600" />
      </div>
    );
  }
  if (!hasIndustryModule(keys, moduleKey)) {
    return <Navigate to="/unauthorized" state={{ reason: 'industry' }} replace />;
  }

  return <>{children}</>;
}
