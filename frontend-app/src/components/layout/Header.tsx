import TourButton from './TourButton';
import { useState } from 'react';
import { Building2, Menu, RefreshCw, Zap } from 'lucide-react';
import { useAuth } from '../../core/context/AuthContext';
import { useTenant } from '../../core/context/TenantContext';
import { indigo, activeGradient } from '../../theme/signalIndigo';
import QuotaTopUpModal from '../billing/QuotaTopUpModal';
import AvatarMenu from './AvatarMenu';
import NotificationBell from './NotificationBell';
import ThemePicker from './ThemePicker';
import { useOptionalTheme } from '../../core/theme/ThemeContext';
import ExpiryWarningBanner from './ExpiryWarningBanner';
import ExpiryHeaderChip from './ExpiryHeaderChip';
import SocialConnectionWarningBanner from './SocialConnectionWarningBanner';
import AiCreditChip from './AiCreditChip';

/**
 * Global Quota Top-Up Access — the header's quota indicator renders on
 * EVERY tenant page (unlike the "Request Extra Quota" button, which only
 * previously existed on DashboardPage/BillingPage), so it was possible
 * to be sitting at 90%+ usage on, say, WhatsApp Setup or Send Alert and
 * have no top-up action in reach without navigating away first. Same
 * server-mirrored gate as those two (QuotaRequestController::store():
 * only a flat_quota plan at 90%+ usage can request a top-up), reusing
 * the same QuotaTopUpModal so submission/validation/routing (Agent vs
 * Super Admin) behaves identically everywhere it appears.
 */
function QuotaBadge() {
  const { user } = useAuth();
  const subscription = user?.account?.current_subscription;
  const [isTopUpOpen, setIsTopUpOpen] = useState(false);
  const [toast, setToast] = useState<string | null>(null);

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
  // per_message: total/used/percentUsed above are already meaningful
  // for it too (total_allocated_messages is the server-computed
  // floor(price_paid / rate) — see AccountController), so this badge's
  // existing math needs no change, only the gate and modal below.
  const canRequestTopUp =
    (subscription.billing_model === 'flat_quota' || subscription.billing_model === 'per_message') && percentUsed >= 90;

  return (
    <>
      <div className="hidden min-w-[160px] flex-col gap-1 sm:flex">
        <div className="flex items-center justify-between text-[11px] font-medium" style={{ color: indigo.muted }}>
          <span>Quota</span>
          <span className="flex items-center gap-1.5">
            <span className="font-mono font-semibold" style={{ color: indigo.ink }}>
              {remaining} / {total} left
            </span>
            {canRequestTopUp && (
              <button
                type="button"
                onClick={() => setIsTopUpOpen(true)}
                title={subscription.billing_model === 'per_message' ? 'Add Funds' : 'Request Extra Quota'}
                aria-label={subscription.billing_model === 'per_message' ? 'Add Funds' : 'Request Extra Quota'}
                className="flex h-4 w-4 items-center justify-center rounded-full bg-red-500 text-white hover:bg-red-600"
              >
                <Zap className="h-2.5 w-2.5" />
              </button>
            )}
          </span>
        </div>
        <div className="h-1.5 w-full overflow-hidden rounded-full" style={{ background: indigo.track }}>
          <div
            className={`h-full rounded-full transition-all ${barColor}`}
            style={{ width: `${percentUsed}%`, background: barColor ? undefined : activeGradient }}
          />
        </div>
        {toast && <div className="text-[10px] font-medium text-emerald-600">{toast}</div>}
      </div>

      {isTopUpOpen && (
        <QuotaTopUpModal
          billingModel={subscription.billing_model === 'per_message' ? 'per_message' : 'flat_quota'}
          onClose={() => setIsTopUpOpen(false)}
          onSubmitted={(message) => {
            setIsTopUpOpen(false);
            setToast(message);
            setTimeout(() => setToast(null), 4000);
          }}
        />
      )}
    </>
  );
}

/**
 * Multi-Tenant "Act As" Client Switcher — rendered for Super Admin
 * (every tenant, "All Clients (Global View)" = no selection) AND for an
 * Agent (only its own Sub-Clients, "My Agent Account" = acting as
 * itself). Selecting a client scopes every subsequent API call (see
 * axiosInstance's request interceptor) to that account_id;
 * TenantIsolationMiddleware enforces the same ownership boundary
 * server-side for an Agent caller, so this UI never has to duplicate
 * that check — it just lists whatever `accounts` the context resolved.
 */
function ClientSwitcher({ fullWidth = false }: { fullWidth?: boolean }) {
  const { isSuperAdmin } = useAuth();
  const { accounts, isLoadingAccounts, selectedAccountId, selectAccount } = useTenant();
  const emptyOptionLabel = isSuperAdmin() ? 'All Clients (Global View)' : 'My Agent Account';

  return (
    <div className={fullWidth ? 'flex w-full items-center gap-2' : 'hidden items-center gap-2 sm:flex'}>
      <Building2 className="h-4 w-4 flex-shrink-0" style={{ color: indigo.muted }} />
      <select
        value={selectedAccountId ?? ''}
        onChange={(e) => selectAccount(e.target.value === '' ? null : Number(e.target.value))}
        disabled={isLoadingAccounts}
        aria-label="Select client"
        className={`${fullWidth ? 'min-w-0 flex-1' : 'max-w-[220px]'} truncate rounded-lg border bg-white py-1.5 pl-2.5 pr-7 text-xs font-semibold outline-none disabled:opacity-60`}
        style={{ borderColor: indigo.border, color: indigo.ink }}
      >
        <option value="">{emptyOptionLabel}</option>
        {accounts.map((account) => (
          <option key={account.id} value={account.id}>
            {account.company_name}
          </option>
        ))}
      </select>
    </div>
  );
}

export default function Header({
  pageTitle,
  onOpenMenu,
  menuOpen = false,
  onRefresh,
}: {
  pageTitle: string;
  /** Opens the sidebar drawer on small screens (AppLayout). */
  onOpenMenu?: () => void;
  menuOpen?: boolean;
  /** Re-loads the current page's data (AppLayout re-mounts the page). */
  onRefresh?: () => void;
}) {
  const { canSwitchClients } = useTenant();
  const theme = useOptionalTheme();
  const [spinning, setSpinning] = useState(false);

  const refresh = () => {
    if (!onRefresh) return;
    onRefresh();
    setSpinning(true);
    window.setTimeout(() => setSpinning(false), 700);
  };

  return (
    <>
      <header
        className="flex h-14 flex-shrink-0 items-center justify-between gap-2 bg-white px-3 sm:px-6"
        style={{ borderBottom: `1px solid ${indigo.border}` }}
      >
      <div className="flex min-w-0 items-center gap-2 sm:gap-3">
        {onOpenMenu && (
          <button
            type="button"
            onClick={onOpenMenu}
            className="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg text-slate-600 hover:bg-slate-100 lg:hidden"
            aria-label="Open menu"
            aria-controls="app-sidebar"
            aria-expanded={menuOpen}
          >
            <Menu className="h-5 w-5" />
          </button>
        )}
        <h1 className="truncate font-display text-base font-bold" style={{ color: indigo.ink }}>
          {pageTitle}
        </h1>
      </div>

      <div className="flex flex-shrink-0 items-center gap-1.5 sm:gap-4">
        {canSwitchClients && <ClientSwitcher />}
        <ExpiryHeaderChip />
        <QuotaBadge />
        <AiCreditChip />
        {onRefresh && (
          <button
            type="button"
            onClick={refresh}
            disabled={spinning}
            aria-label="Refresh this page"
            title="Refresh this page"
            className="flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-slate-800 disabled:opacity-70"
          >
            <RefreshCw className={`h-5 w-5 ${spinning ? 'animate-spin' : ''}`} />
          </button>
        )}
        {theme && <ThemePicker />}
        <TourButton />
        <NotificationBell />
        <AvatarMenu />
      </div>

      </header>
      {canSwitchClients && (
        <div className="flex-shrink-0 border-b bg-white px-3 py-2 sm:hidden" style={{ borderColor: indigo.border }}>
          <ClientSwitcher fullWidth />
        </div>
      )}
      <ExpiryWarningBanner />
      <SocialConnectionWarningBanner />
    </>
  );
}
