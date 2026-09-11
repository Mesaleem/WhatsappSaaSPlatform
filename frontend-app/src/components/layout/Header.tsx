import { useState } from 'react';
import { Building2 } from 'lucide-react';
import { useAuth } from '../../core/context/AuthContext';
import { useTenant } from '../../core/context/TenantContext';
import { indigo, activeGradient } from '../../theme/signalIndigo';
import ProfileModal from './ProfileModal';
import NotificationBell from './NotificationBell';
import ExpiryWarningBanner from './ExpiryWarningBanner';
import SocialConnectionWarningBanner from './SocialConnectionWarningBanner';

function QuotaBadge() {
  const { user } = useAuth();
  const subscription = user?.account?.current_subscription;
  if (!subscription) return null;

  if (subscription.billing_model === 'unlimited') {
    return (
      <div className="hidden items-center gap-1.5 rounded-full border border-[#BFF0DE] bg-[#E6F8F1] px-3 py-1 text-xs font-semibold text-[#0E9F6E] sm:flex">
        Unlimited plan
      </div>
    );
  }

  const total = subscription.total_allocated_messages ?? 0;
  const used = subscription.used_messages;
  const remaining = Math.max(total - used, 0);
  const percentUsed = total > 0 ? Math.min(100, Math.round((used / total) * 100)) : 0;
  const barColor = percentUsed >= 90 ? 'bg-red-500' : percentUsed >= 70 ? 'bg-amber-500' : '';

  return (
    <div className="hidden min-w-[160px] flex-col gap-1 sm:flex">
      <div className="flex items-center justify-between text-[11px] font-medium" style={{ color: indigo.muted }}>
        <span>Quota</span>
        <span className="font-mono font-semibold" style={{ color: indigo.ink }}>
          {remaining} / {total} left
        </span>
      </div>
      <div className="h-1.5 w-full overflow-hidden rounded-full" style={{ background: indigo.track }}>
        <div
          className={`h-full rounded-full transition-all ${barColor}`}
          style={{ width: `${percentUsed}%`, background: barColor ? undefined : activeGradient }}
        />
      </div>
    </div>
  );
}

/**
 * Super Admin Multi-Tenant Scoping — global "act as" client selector.
 * Rendered ONLY for Super Admin (isSuperAdmin() — a regular tenant user
 * has no other accounts to switch between). Selecting a client scopes
 * every subsequent API call (see axiosInstance's request interceptor) to
 * that account_id; "All Clients (Global View)" clears the selection.
 */
function ClientSwitcher() {
  const { accounts, isLoadingAccounts, selectedAccountId, selectAccount } = useTenant();

  return (
    <div className="hidden items-center gap-2 sm:flex">
      <Building2 className="h-4 w-4 flex-shrink-0" style={{ color: indigo.muted }} />
      <select
        value={selectedAccountId ?? ''}
        onChange={(e) => selectAccount(e.target.value === '' ? null : Number(e.target.value))}
        disabled={isLoadingAccounts}
        aria-label="Select client"
        className="max-w-[220px] truncate rounded-lg border bg-white py-1.5 pl-2.5 pr-7 text-xs font-semibold outline-none disabled:opacity-60"
        style={{ borderColor: indigo.border, color: indigo.ink }}
      >
        <option value="">All Clients (Global View)</option>
        {accounts.map((account) => (
          <option key={account.id} value={account.id}>
            {account.company_name}
          </option>
        ))}
      </select>
    </div>
  );
}

/** Icon-only avatar trigger — initial letter, no name/chevron. Opens ProfileModal. */
function ProfileTrigger({ onClick }: { onClick: () => void }) {
  const { user } = useAuth();

  return (
    <button
      onClick={onClick}
      aria-label="Open profile"
      title={user?.name}
      className="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full font-display text-sm font-bold text-white transition hover:opacity-90"
      style={{ background: activeGradient }}
    >
      {(user?.name ?? '?').slice(0, 1).toUpperCase()}
    </button>
  );
}

export default function Header({ pageTitle }: { pageTitle: string }) {
  const { isSuperAdmin } = useAuth();
  const [isProfileOpen, setIsProfileOpen] = useState(false);

  return (
    <>
      <header
        className="flex h-14 flex-shrink-0 items-center justify-between bg-white px-6"
        style={{ borderBottom: `1px solid ${indigo.border}` }}
      >
      <div className="flex items-center gap-3">
        <h1 className="font-display text-base font-bold" style={{ color: indigo.ink }}>
          {pageTitle}
        </h1>
      </div>

      <div className="flex items-center gap-4">
        {isSuperAdmin() && <ClientSwitcher />}
        <QuotaBadge />
        <NotificationBell />
        <ProfileTrigger onClick={() => setIsProfileOpen(true)} />
      </div>

        {isProfileOpen && <ProfileModal onClose={() => setIsProfileOpen(false)} />}
      </header>
      <ExpiryWarningBanner />
      <SocialConnectionWarningBanner />
    </>
  );
}
