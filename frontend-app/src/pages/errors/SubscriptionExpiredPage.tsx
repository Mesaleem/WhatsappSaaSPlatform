import { AlertTriangle } from 'lucide-react';
import { Link, Navigate } from 'react-router-dom';
import { useAuth } from '../../core/context/AuthContext';

/**
 * Read-Only Subscription Expired Mode: nothing redirects here anymore
 * (see ProtectedRoute's docblock) — an expired/suspended account now
 * stays fully readable in-app, with mutation controls disabled
 * page-by-page instead of a full-screen lock. This route is kept only as
 * a directly-navigable informational page (e.g. a stale bookmark from
 * before this change), not as an enforcement mechanism, so its copy and
 * actions reflect that: the account is NOT locked out, and "Sign out" is
 * one option among others rather than the only way forward.
 *
 * A Super Admin is never subscription-blocked (see AuthContext::isReadOnly()),
 * but this route sits OUTSIDE the app shell — App.tsx renders it
 * standalone so it also works from a bare, unauthenticated deep link.
 * That means a Super Admin who manually types/bookmarks this URL would
 * otherwise see it unconditionally; bounce them straight back to the
 * dashboard instead.
 */
export default function SubscriptionExpiredPage() {
  const { logout, user, isSuperAdmin } = useAuth();

  if (isSuperAdmin()) {
    return <Navigate to="/" replace />;
  }

  return (
    <div className="flex min-h-screen w-full items-center justify-center bg-slate-50 px-6">
      <div className="w-full max-w-md rounded-xl border border-slate-200 bg-white p-8 text-center shadow-sm">
        <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-amber-100">
          <AlertTriangle className="h-6 w-6 text-amber-600" />
        </div>
        <h1 className="mt-4 text-lg font-semibold text-slate-900">Subscription inactive</h1>
        <p className="mt-2 text-sm text-slate-500">
          {user?.account?.company_name ? `The subscription for ${user.account.company_name}` : 'Your account’s subscription'}{' '}
          is expired or suspended. You can still view your dashboard, analytics, logs, and chatbot rules — actions
          like sending alerts, adding users, or saving settings are disabled until it's renewed. Contact your
          account owner or platform support to restore full access.
        </p>
        <div className="mt-6 flex flex-col gap-2">
          <Link
            to="/"
            className="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-700"
          >
            Go to Dashboard
          </Link>
          <button
            onClick={() => void logout()}
            className="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
          >
            Sign out
          </button>
        </div>
      </div>
    </div>
  );
}
