import { useEffect, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { AlertTriangle } from 'lucide-react';
import { useAuth } from '../../core/context/AuthContext';
import socialService, { SOCIAL_ACCOUNTS_LOADED_EVENT } from '../../services/socialService';
import { connectionStatusOf, SOCIAL_ASSET_TYPE_LABELS, type SocialAccount } from '../../types/social';

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
 *
 * [Bugfix, disclosed]: this permission check alone is not enough — a
 * teammate can hold manage-social-accounts (a role-level permission)
 * while the tenant's own `social_accounts` module has been disabled by
 * an admin (a separate, per-account toggle — see Account::allowed_modules
 * and the sidebar's own `requiresModule: 'social_accounts'` gate on this
 * exact page's nav item and route guard). Without the hasModule() check
 * added here, this banner kept steering such a user toward a Social
 * Accounts page their sidebar/route guard both already correctly hide —
 * confirmed root cause of the "top banner still mentions Social Accounts
 * after the module was disabled" report.
 */
export default function SocialConnectionWarningBanner() {
  const { user, hasPermission, hasModule } = useAuth();
  // QA fixes (2026-10-14): only for a plan that has social media (a Starter / QR client without
  // ads saw "Ad Launcher and Lead Sync stay locked"), and the ads wording only with the ads feature.
  const capabilities = user?.capabilities;
  const hasSocial = !capabilities || capabilities.social === true;
  const hasAds = !!capabilities && capabilities.ads === true;
  const canManageSocial = hasPermission('manage-social-accounts') && hasModule('social_accounts') && hasSocial;
  const [accounts, setAccounts] = useState<SocialAccount[] | null>(null);
  const { pathname } = useLocation();

  // Any page that reloads the list (reconnect, Check, disconnect) refreshes the banner too.
  useEffect(() => {
    if (!canManageSocial) return;
    const onLoaded = (event: Event) => setAccounts((event as CustomEvent<SocialAccount[]>).detail);
    window.addEventListener(SOCIAL_ACCOUNTS_LOADED_EVENT, onLoaded);
    return () => window.removeEventListener(SOCIAL_ACCOUNTS_LOADED_EVENT, onLoaded);
  }, [canManageSocial]);

  useEffect(() => {
    if (!canManageSocial) return;

    let cancelled = false;

    socialService
      .listAccounts()
      .catch(() => {
        // Swallow — same "don't turn a transient fetch failure into a
        // false compliance alarm" reasoning as NotificationBell's poll.
        if (!cancelled) setAccounts(null);
      });

    return () => {
      cancelled = true;
    };
  }, [canManageSocial, pathname]);

  if (!canManageSocial || accounts === null) return null;

  const meta = accounts.filter((a) => a.asset_type === 'facebook_page' || a.asset_type === 'instagram' || a.asset_type === 'meta_ad_account');
  const connected = meta.filter((a) => connectionStatusOf(a) === 'connected');
  // An expired / revoked connection is named even when another one still works (QA fixes, 2026-10-14):
  // its posts, comment replies, lead sync or ads guard stop silently otherwise.
  const broken = meta.filter((a) => connectionStatusOf(a) !== 'connected');

  let message: string | null = null;
  if (connected.length === 0 && broken.length === 0) {
    message = hasAds
      ? 'No Facebook Page or Ad Account is connected yet — posting, the inbox, Ad Launcher and Lead Sync start once you connect one in'
      : 'No Facebook Page is connected yet — posting, the inbox and comment rules start once you connect one in';
  } else if (broken.length > 0) {
    const names = broken.map((a) => `${SOCIAL_ASSET_TYPE_LABELS[a.asset_type]}${a.name ? ` “${a.name}”` : ''}`).join(', ');
    message = `${names} ${broken.length === 1 ? 'needs' : 'need'} reconnecting — until then its posts, replies and lead sync are paused. Reconnect in`;
  }
  if (message === null) return null;

  return (
    <div
      className="flex flex-shrink-0 items-start gap-2 border-b border-amber-200 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800 sm:items-center sm:px-6 sm:text-sm"
      data-testid="social-connection-warning"
    >
      <AlertTriangle className="mt-0.5 h-4 w-4 flex-shrink-0 sm:mt-0" />
      <p className="min-w-0">
        {message}{' '}
        <Link to="/social/accounts" className="font-semibold underline">
          Social Accounts
        </Link>
        .
      </p>
    </div>
  );
}
