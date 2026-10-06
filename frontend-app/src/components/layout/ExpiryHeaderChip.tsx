import { AlertTriangle } from 'lucide-react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../core/context/AuthContext';

/** Whole calendar days from today to the term's end (negative once it has ended). */
export function calendarDaysRemaining(expiresAtIso: string): number {
  const now = new Date();
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const expiry = new Date(expiresAtIso);
  const expiryDay = new Date(expiry.getFullYear(), expiry.getMonth(), expiry.getDate());
  return Math.round((expiryDay.getTime() - today.getTime()) / 86_400_000);
}

/** The countdown text: "Expires in 3 days", "Expires today", or "Expired 2 days ago". */
export function describeExpiry(days: number): string {
  if (days > 0) return `Expires in ${days} day${days === 1 ? '' : 's'}`;
  if (days === 0) return 'Expires today';
  const ago = -days;
  return `Expired ${ago} day${ago === 1 ? '' : 's'} ago`;
}

/**
 * Red countdown and Renew button in the top bar. Shown from 7 days before the plan term ends, and after it has ended,
 * until the term is renewed (a renewed term ends later than the window, so the chip goes away on its own).
 * Never shown to Super Admin, who has no account of their own to renew.
 */
export default function ExpiryHeaderChip() {
  const { user, isSuperAdmin, hasPermission, hasModule } = useAuth();

  if (isSuperAdmin()) return null;

  const expiresAt = user?.account?.current_subscription?.expires_at;
  if (!expiresAt) return null;

  const days = calendarDaysRemaining(expiresAt);
  if (days > 7) return null;

  const canRenew = hasPermission('manage-subscriptions') && hasModule('billing');

  return (
    <div
      role="status"
      data-testid="expiry-chip"
      className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700"
    >
      <AlertTriangle className="h-3.5 w-3.5 flex-shrink-0" />
      <span>{describeExpiry(days)}</span>
      {canRenew ? (
        <Link to="/billing" className="rounded-md bg-red-600 px-2 py-0.5 text-white hover:bg-red-700">
          Renew
        </Link>
      ) : (
        <span className="font-normal">Ask your admin to renew</span>
      )}
    </div>
  );
}
