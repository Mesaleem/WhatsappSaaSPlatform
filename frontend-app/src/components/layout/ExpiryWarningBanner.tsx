import { AlertTriangle } from 'lucide-react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../core/context/AuthContext';

export default function ExpiryWarningBanner() {
  const { isSuperAdmin, isReadOnly, hasPermission, hasModule } = useAuth();
  // Final hardening §23 — the read-only notice explains every disabled
  // mutation button, so it also carries the way out: Billing for a user who
  // may manage the subscription, "contact your administrator" otherwise.
  const canRenew = hasPermission('manage-subscriptions') && hasModule('billing');

  if (isSuperAdmin()) return null;

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

  // The 7-day countdown lives in the top bar now (ExpiryHeaderChip).
  return null;
}
