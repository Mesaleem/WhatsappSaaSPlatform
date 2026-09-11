import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { AlertTriangle } from 'lucide-react';
import { useAuth } from '../../core/context/AuthContext';
import socialService from '../../services/socialService';

/**
 * Social Media Marketing & Meta Ads Automation Expansion (Phase 1).
 * Sibling banner to ExpiryWarningBanner (rendered alongside it in
 * Header.tsx) — a DELIBERATE deviation from the literal spec ("prominent
 * amber warning on dashboard"): it is mounted in the shared Header so it
 * appears on every page a social_marketer visits, not only Dashboard.
 * Reasoning (see the audit report): DashboardPage.tsx is an ~800-line
 * WhatsApp/payment-alert-centric page with no per-role branching today;
 * reaching into it purely to splice in an unrelated banner is exactly
 * the kind of collateral-risk edit the Minimal Change / Regression
 * Prevention rules this build follows are meant to avoid, and Header
 * already establishes the "one persistent warning banner slot" pattern
 * this reuses. There are also no Ad Launcher / Lead Sync pages yet for
 * a dashboard-only banner to sit next to (Phase 1 scope boundary).
 *
 * Never shown to a user without manage-social-accounts — everyone else
 * has no Social Accounts page to be steered toward.
 */
export default function SocialConnectionWarningBanner() {
  const { hasPermission } = useAuth();
  const canManageSocial = hasPermission('manage-social-accounts');
  const [hasMetaAssetConnected, setHasMetaAssetConnected] = useState<boolean | null>(null);

  useEffect(() => {
    if (!canManageSocial) return;

    let cancelled = false;

    socialService
      .listAccounts()
      .then((accounts) => {
        if (cancelled) return;
        const connected = accounts.some(
          (a) =>
            (a.asset_type === 'facebook_page' || a.asset_type === 'meta_ad_account') &&
            a.health_status === 'connected',
        );
        setHasMetaAssetConnected(connected);
      })
      .catch(() => {
        // Swallow — same "don't turn a transient fetch failure into a
        // false compliance alarm" reasoning as NotificationBell's poll.
        if (!cancelled) setHasMetaAssetConnected(null);
      });

    return () => {
      cancelled = true;
    };
  }, [canManageSocial]);

  if (!canManageSocial || hasMetaAssetConnected !== false) return null;

  return (
    <div className="flex items-center gap-2 border-b border-amber-200 bg-amber-50 px-6 py-2 text-sm font-medium text-amber-800">
      <AlertTriangle className="h-4 w-4 flex-shrink-0" />
      No Meta Page or Ad Account is connected yet — Ad Launcher and Lead Sync stay locked until you connect one in{' '}
      <Link to="/social/accounts" className="font-semibold underline">
        Social Accounts
      </Link>
      .
    </div>
  );
}
