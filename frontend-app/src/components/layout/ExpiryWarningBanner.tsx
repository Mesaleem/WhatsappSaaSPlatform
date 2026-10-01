import { AlertTriangle } from 'lucide-react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../core/context/AuthContext';

/**
 * 7-Day Advance Alert Banner + Read-Only Mode notice, rendered in the Top
 * Navbar (see Header.tsx). Never shown to Super Admin (no tenant account
 * of their own to warn about). Two distinct states, mutually exclusive:
 *
 *  - RED — the account's subscription is already expired/suspended:
 *    AuthContext::isReadOnly() is true. Explains, at the page level, why
 *    mutation buttons across the app are disabled — a tooltip on each
 *    disabled button alone isn't discoverable enough on its own.
 *  - YELLOW — the subscription is still active but expires within the
 *    next 7 days: the advance warning this feature is named for.
 *
 * days-remaining is computed client-side from the already-loaded
 * `user.account.current_subscription.expires_at` (present in every
 * /auth/me response) — no separate endpoint needed, and it's a whole
 * CALENDAR-day count (midnight-to-midnight), matching
 * AccountController::expiringSoon()'s server-side calculation, so this
 * banner's "X days" always agrees with the Super Admin Dashboard's
 * "Expiring in 7 Days" list for the same account.
 */
function calendarDaysRemaining(expiresAtIso: string): number {
  const now = new Date();
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const expiry = new Date(expiresAtIso);
  const expiryDay = new Date(expiry.getFullYear(), expiry.getMonth(), expiry.getDate());
  return Math.round((expiryDay.getTime() - today.getTime()) / 86_400_000);
}

export default function ExpiryWarningBanner() {
  const { user, isSuperAdmin, isReadOnly, hasPermission, hasModule } = useAuth();
  // Final hardening §23 — the read-only notice explains every disabled
  // mutation button, so it also carries the way out: Billing for a user who
  // may manage the subscription, "contact your administrator" otherwise.
  const canRenew = hasPermission('manage-subscriptions') && hasModule('billing');

  if (isSuperAdmin()) return null;

  const account = user?.account;
  const subscription = account?.current_subscription;

  if (isReadOnly()) {
    return (
      <div className="flex flex-wrap items-center gap-2 border-b border-red-200 bg-red-50 px-6 py-2 text-sm font-medium text-red-800" data-testid="read-only-banner">
        <AlertTriangle className="h-4 w-4 flex-shrink-0" />
        Your subscription is expired or suspended. You can still view your data — actions are disabled until it's
        renewed.
        {canRenew ? (
          <Link to="/billing" className="rounded-md border border-red-300 bg-white px-2.5 py-1 text-xs font-semibold text-red-800 hover:bg-red-100">
            Renew subscription
          </Link>
        ) : (
          <span className="text-xs font-normal">Contact your account administrator to renew it.</span>
        )}
      </div>
    );
  }

  if (!subscription || subscription.status !== 'active') return null;

  const daysRemaining = calendarDaysRemaining(subscription.expires_at);
  if (daysRemaining < 0 || daysRemaining > 7) return null;

  return (
    <div className="flex items-center gap-2 border-b border-amber-200 bg-amber-50 px-6 py-2 text-sm font-medium text-amber-800">
      <AlertTriangle className="h-4 w-4 flex-shrink-0" />
      Your subscription expires in {daysRemaining} day{daysRemaining === 1 ? '' : 's'}. Renew to avoid service
      interruption.
      {canRenew && (
        <Link to="/billing" className="rounded-md border border-amber-300 bg-white px-2.5 py-1 text-xs font-semibold text-amber-800 hover:bg-amber-100">
          Renew now
        </Link>
      )}
    </div>
  );
}
