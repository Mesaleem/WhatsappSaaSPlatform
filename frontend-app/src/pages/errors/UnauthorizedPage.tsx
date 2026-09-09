import { ShieldAlert } from 'lucide-react';
import { Link, useLocation } from 'react-router-dom';

/**
 * Route Guard Protection — shared 403 card for both a missing-permission
 * block (ProtectedRoute's `permission`/`role` props) and a
 * disabled-feature block (`module` prop, backed by
 * AuthContext::hasModule). ProtectedRoute distinguishes the two via
 * router state (`{ reason: 'module' }`) rather than a query param, so a
 * refresh of this page after a module block doesn't leak the blocked
 * module slug into the URL/history.
 */
export default function UnauthorizedPage() {
  const location = useLocation();
  const isModuleBlock = (location.state as { reason?: string } | null)?.reason === 'module';

  return (
    <div className="flex min-h-screen w-full items-center justify-center bg-slate-50 px-6">
      <div className="w-full max-w-md rounded-xl border border-slate-200 bg-white p-8 text-center shadow-sm">
        <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-100">
          <ShieldAlert className="h-6 w-6 text-red-600" />
        </div>
        <h1 className="mt-4 text-lg font-semibold text-slate-900">
          {isModuleBlock ? 'This feature is disabled' : 'You don’t have access'}
        </h1>
        <p className="mt-2 text-sm text-slate-500">
          {isModuleBlock
            ? 'This feature has been turned off for your account. Contact your Super Admin to enable it.'
            : 'Your role doesn’t include the permission required for this page. Ask a Super Admin or Admin to grant it.'}
        </p>
        <Link
          to="/"
          className="mt-6 inline-block w-full rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800"
        >
          Back to dashboard
        </Link>
      </div>
    </div>
  );
}
