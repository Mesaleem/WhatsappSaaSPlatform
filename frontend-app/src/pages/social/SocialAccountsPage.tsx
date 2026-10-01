import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { AlertCircle, Camera, CheckCircle2, Globe, Info, Link2, Loader2, Megaphone, Plug, RefreshCw, Rss, ShieldCheck, Trash2 } from 'lucide-react';
import { indigo, activeGradient, cardShadow } from '../../theme/signalIndigo';
import { useAuth } from '../../core/context/AuthContext';
import { useTenant } from '../../core/context/TenantContext';
import socialService from '../../services/socialService';
import AssetSelectionModal from '../../components/social/AssetSelectionModal';
import ConfirmModal from '../../components/common/ConfirmModal';
import { GateNoticeModal, SelectClientNotice, type GateNoticeAction } from '../../components/common/ActionGate';
import { safeReturnTo, useClientGate, useUpgradePath } from '../../components/common/actionGateHooks';
import { describeApiError, extractErrorMessage } from '../../utils/apiError';
import {
  SOCIAL_ASSET_TYPE_LABELS,
  connectionStatusOf,
  type OAuthPopupMessage,
  type OfferedAsset,
  type SocialAccount,
  type SocialAssetType,
  type SocialConnectionStatus,
} from '../../types/social';
import DismissibleAlert from '../../components/common/DismissibleAlert';

const ASSET_ICON: Record<SocialAssetType, typeof Globe> = {
  facebook_page: Globe,
  instagram: Camera,
  meta_ad_account: Megaphone,
  linkedin_page: Link2,
  youtube_channel: Rss,
};

const STATUS_BADGE: Record<SocialConnectionStatus, string> = {
  connected: 'bg-emerald-50 text-emerald-700 border-emerald-200',
  expired: 'bg-amber-50 text-amber-700 border-amber-200',
  revoked: 'bg-red-50 text-red-700 border-red-200',
};

const STATUS_LABEL: Record<SocialConnectionStatus, string> = {
  connected: 'Connected',
  expired: 'Expired',
  revoked: 'Access revoked',
};

/** Phase 9 Task 2 — shown when the backend stored no reason (e.g. an older row). */
const DEFAULT_REASON: Record<Exclude<SocialConnectionStatus, 'connected'>, string> = {
  expired: 'The access token has expired. Reconnect to keep using this account.',
  revoked: 'The provider no longer accepts this connection (access or a permission was removed). Reconnect and grant access again.',
};

/** A background refresh on focus/visibility runs at most this often (no polling loop). */
const QUIET_REFRESH_MIN_MS = 60_000;

/** "5 minutes ago" style, for the last confirmed check of a connection. */
function checkedAgo(iso: string | null | undefined, now: number = Date.now()): string | null {
  if (!iso) return null;
  const at = new Date(iso).getTime();
  if (Number.isNaN(at)) return null;
  const minutes = Math.max(0, Math.round((now - at) / 60_000));
  if (minutes < 1) return 'just now';
  if (minutes < 60) return `${minutes} minute${minutes === 1 ? '' : 's'} ago`;
  const hours = Math.round(minutes / 60);
  if (hours < 24) return `${hours} hour${hours === 1 ? '' : 's'} ago`;
  const days = Math.round(hours / 24);
  return `${days} day${days === 1 ? '' : 's'} ago`;
}

/** The connect flow's client-side states (the stored per-connection states come from the API). */
type ConnectPhase = 'idle' | 'connecting' | 'failed' | 'cancelled';

/**
 * Social Accounts — connect and manage a client's social connections.
 *
 * Phase 9 Task 1 (provider-agnostic foundation) + final hardening §23:
 *  - Connect is always clickable. A Super Admin in Global View picks the
 *    client first (useClientGate). Before the popup opens, GET
 *    /social/providers says whether the provider is configured on the
 *    platform and enabled for this account; if not, the click explains who
 *    can fix it instead of failing later.
 *  - The flow's states are explicit: connecting (popup open — the button
 *    shows "Connecting…"), failed (the callback reported an error →
 *    recoverable, "Try again"), cancelled (the user declined or closed the
 *    consent screen). A popup closed without reporting back returns to a
 *    usable state; only messages from the popup this page opened are
 *    accepted (event.source), and they never carry a token.
 *  - Each connection shows its lifecycle status (connected / expired /
 *    revoked, with the provider's reason), "Check" (asks the provider),
 *    "Reconnect" (for expired/revoked) and "Disconnect" (confirmed, then a
 *    disconnecting state).
 *  - `?returnTo=` (in-app paths only) lets a flow that sent the user here
 *    for a prerequisite continue after binding.
 * The backend authorizes every step (permission, module, `social`
 * capability, target account, provider flags, OAuth state); nothing here
 * is a security boundary.
 */
export default function SocialAccountsPage() {
  const { user, isSuperAdmin } = useAuth();
  const { selectedAccountId } = useTenant();
  const superAdmin = isSuperAdmin();
  // Owner request (2026-09-30): with its own Platform account a Super Admin needs no client here.
  const noTenantSelected = superAdmin && selectedAccountId === null && !user?.platform_crm_account;
  const { guard, openPicker, picker } = useClientGate({ platformFallback: true });
  const upgrade = useUpgradePath();
  const [searchParams] = useSearchParams();
  const returnTo = safeReturnTo(searchParams.get('returnTo'));

  const [accounts, setAccounts] = useState<SocialAccount[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [pageError, setPageError] = useState<string | null>(null);
  const [notEntitled, setNotEntitled] = useState(false);

  const [phase, setPhase] = useState<ConnectPhase>('idle');
  const [phaseMessage, setPhaseMessage] = useState<string | null>(null);
  const [notice, setNotice] = useState<{ title: string; message: string; action: GateNoticeAction | null } | null>(null);
  const [justConnected, setJustConnected] = useState(false);

  const [pendingNonce, setPendingNonce] = useState<string | null>(null);
  const [offeredAssets, setOfferedAssets] = useState<OfferedAsset[] | null>(null);
  const [isBinding, setIsBinding] = useState(false);
  const [bindError, setBindError] = useState<string | null>(null);

  const [checkingId, setCheckingId] = useState<number | null>(null);
  const [confirmDisconnect, setConfirmDisconnect] = useState<SocialAccount | null>(null);
  const [disconnectingId, setDisconnectingId] = useState<number | null>(null);
  const [rowMessage, setRowMessage] = useState<{ id: number; text: string } | null>(null);

  const popupRef = useRef<Window | null>(null);
  const isConnecting = phase === 'connecting';
  const lastLoadedAt = useRef(0);

  const loadAccounts = useCallback(() => {
    // Super Admin without a client: an expected, actionable state (below), not an error.
    if (noTenantSelected) {
      setAccounts([]);
      setIsLoading(false);
      return;
    }

    setIsLoading(true);
    setPageError(null);
    setNotEntitled(false);
    lastLoadedAt.current = Date.now();
    socialService
      .listAccounts()
      .then(setAccounts)
      .catch((err) => {
        const described = describeApiError(err, 'Could not load connected social accounts.');
        if (described.status === 403 && described.error_code === 'CAPABILITY_NOT_ENTITLED') {
          setNotEntitled(true);
        } else {
          setPageError(described.message);
        }
      })
      .finally(() => setIsLoading(false));
  }, [noTenantSelected]);

  useEffect(() => {
    // selectedAccountId: refetch the instant a Super Admin switches clients.
    loadAccounts();
  }, [loadAccounts, selectedAccountId]);

  /*
   * Phase 9 Task 2 — the scheduled backend check is the source of truth. When
   * the user comes back to this tab (focus / visibility), the statuses are
   * re-read quietly (no spinner, no error banner), at most once a minute, so
   * a connection the scheduler marked expired/revoked shows without a
   * reload. No timer/polling loop. Skipped while a connect flow is open.
   */
  useEffect(() => {
    if (noTenantSelected || notEntitled) return undefined;

    const refreshQuietly = () => {
      if (document.visibilityState === 'hidden' || isConnecting || offeredAssets !== null) return;
      if (Date.now() - lastLoadedAt.current < QUIET_REFRESH_MIN_MS) return;
      lastLoadedAt.current = Date.now();
      socialService
        .listAccounts()
        .then(setAccounts)
        .catch(() => undefined);
    };

    window.addEventListener('focus', refreshQuietly);
    document.addEventListener('visibilitychange', refreshQuietly);
    return () => {
      window.removeEventListener('focus', refreshQuietly);
      document.removeEventListener('visibilitychange', refreshQuietly);
    };
  }, [noTenantSelected, notEntitled, isConnecting, offeredAssets]);

  useEffect(() => {
    const onMessage = (event: MessageEvent<OAuthPopupMessage>) => {
      const data = event.data;
      if (!data || (data.type !== 'social-oauth-success' && data.type !== 'social-oauth-error' && data.type !== 'social-oauth-cancelled')) return;
      // Only the popup this page opened may report a result.
      if (popupRef.current !== null && event.source !== null && event.source !== popupRef.current) return;

      if (data.type === 'social-oauth-cancelled') {
        setPhase('cancelled');
        setPhaseMessage(data.message);
        return;
      }

      if (data.type === 'social-oauth-error') {
        setPhase('failed');
        setPhaseMessage(data.message);
        return;
      }

      if (data.assets.length === 0) {
        setPhase('failed');
        setPhaseMessage('The provider returned no Page, Instagram account or Ad Account that this account may connect. Check the assets you granted, or ask a Super Admin which platforms are enabled for this account.');
        return;
      }

      setPhase('idle');
      setPhaseMessage(null);
      setPendingNonce(data.nonce);
      setOfferedAssets(data.assets);
    };

    window.addEventListener('message', onMessage);
    return () => window.removeEventListener('message', onMessage);
  }, []);

  // A popup the user closed (or that never reported back) must not leave the
  // page stuck in "connecting".
  useEffect(() => {
    if (!isConnecting) return;
    const timer = window.setInterval(() => {
      if (popupRef.current && popupRef.current.closed) {
        window.clearInterval(timer);
        // Grace period: the callback page posts its message just before closing.
        window.setTimeout(() => {
          setPhase((current) => {
            if (current === 'connecting') {
              setPhaseMessage('The connection window was closed before the connection finished. Nothing was connected.');
              return 'cancelled';
            }
            return current;
          });
        }, 800);
      }
    }, 500);
    return () => window.clearInterval(timer);
  }, [isConnecting]);

  const openPopup = useCallback(() => {
    setPhase('connecting');
    setPhaseMessage(null);
    setJustConnected(false);

    socialService
      .getOAuthRedirectUrl('meta')
      .then(({ url }) => {
        popupRef.current = window.open(url, 'social-oauth-popup', 'width=600,height=720');
        if (!popupRef.current) {
          setPhase('failed');
          setPhaseMessage('Your browser blocked the connection window. Allow pop-ups for this site and try again.');
        }
      })
      .catch((err) => {
        setPhase('failed');
        setPhaseMessage(extractErrorMessage(err, 'Could not start the connection. Please try again.'));
      });
  }, []);

  /**
   * Preflight: is the provider configured on the platform and enabled for
   * this account? If not, explain who can fix it. If the check itself
   * fails, go ahead — the backend refuses with its own reason.
   */
  const connectMeta = useCallback(() => {
    setPageError(null);
    socialService
      .listProviders()
      .then((providers) => {
        const meta = providers.find((p) => p.key === 'meta');
        if (meta && !meta.configured) {
          setNotice({
            title: 'Meta is not set up on this platform yet',
            message: superAdmin
              ? 'The platform Meta app (App ID, secret and redirect URL) has not been configured or is switched off. Configure it in Social Gateway Settings, then connect again.'
              : 'The platform administrator has not configured the Meta app yet. Ask your Super Admin to set it up, then connect again.',
            action: superAdmin ? { label: 'Open Social Gateway Settings', to: '/admin/social-settings' } : null,
          });
          return;
        }
        if (meta && !meta.enabled_for_account) {
          setNotice({
            title: 'Facebook / Instagram is not enabled for this account',
            message: superAdmin
              ? "Enable Facebook and/or Instagram for this client in the account's settings under Accounts, then connect again."
              : 'Your Super Admin has not enabled Facebook or Instagram for this account. Ask them to enable it, then connect again.',
            action: superAdmin ? { label: 'Open Accounts', to: '/admin/accounts' } : null,
          });
          return;
        }
        openPopup();
      })
      .catch((err) => {
        const described = describeApiError(err, '');
        if (described.status === 403 && described.error_code === 'CAPABILITY_NOT_ENTITLED') {
          setNotEntitled(true);
          return;
        }
        openPopup();
      });
  }, [openPopup, superAdmin]);

  const startConnect = () => guard(connectMeta, 'connect a social account');

  const cancelAssetSelection = () => {
    setPendingNonce(null);
    setOfferedAssets(null);
    setBindError(null);
  };

  const confirmAssetSelection = (selected: OfferedAsset[]) => {
    if (!pendingNonce || selected.length === 0) return;

    setIsBinding(true);
    setBindError(null);

    socialService
      .bindAccounts({
        nonce: pendingNonce,
        selections: selected.map((a) => ({ asset_type: a.asset_type, provider_id: a.provider_id })),
      })
      .then(() => {
        setIsBinding(false);
        cancelAssetSelection();
        setJustConnected(true);
        loadAccounts();
      })
      .catch((err) => {
        setIsBinding(false);
        setBindError(extractErrorMessage(err, 'Could not connect the selected asset(s). Please try again.'));
      });
  };

  const checkConnection = (account: SocialAccount) => {
    setCheckingId(account.id);
    setRowMessage(null);
    socialService
      .checkConnection(account.id)
      .then(({ data, check }) => {
        setAccounts((prev) => prev.map((a) => (a.id === data.id ? data : a)));
        setRowMessage({
          id: account.id,
          text: check.status === 'connected' ? 'The connection is working.' : check.reason ?? 'The connection needs attention.',
        });
      })
      .catch((err) => setRowMessage({ id: account.id, text: extractErrorMessage(err, 'Could not check this connection.') }))
      .finally(() => setCheckingId(null));
  };

  const disconnect = () => {
    const target = confirmDisconnect;
    if (!target) return;
    setConfirmDisconnect(null);
    setDisconnectingId(target.id);
    socialService
      .disconnect(target.id)
      .then(() => setAccounts((prev) => prev.filter((a) => a.id !== target.id)))
      .catch((err) => setPageError(extractErrorMessage(err, 'Could not disconnect this account.')))
      .finally(() => setDisconnectingId(null));
  };

  return (
    <div className="p-6">
      <div className="w-full">
        <div className="mb-6 flex items-center justify-between">
          <div>
            <h1 className="font-display text-lg font-bold" style={{ color: indigo.ink }}>
              Social Accounts
            </h1>
            <p className="mt-1 text-sm" style={{ color: indigo.muted }}>
              Connect your Facebook Page, Instagram account, and Meta Ad Account to unlock Ad Launcher and Lead Sync.
            </p>
          </div>
          <button
            onClick={startConnect}
            // Only while a connection is already in progress (a second click would open a second window).
            disabled={isConnecting}
            aria-busy={isConnecting}
            title={isConnecting ? 'Finish or close the connection window to continue.' : noTenantSelected ? 'You will be asked which client to connect it for.' : undefined}
            data-testid="connect-meta-account"
            className="flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
            style={{ background: activeGradient }}
          >
            {isConnecting ? <Loader2 className="h-4 w-4 animate-spin" /> : <Plug className="h-4 w-4" />}
            {isConnecting ? 'Connecting…' : 'Connect Meta Account'}
          </button>
        </div>

        {picker}

        {noTenantSelected ? (
          <SelectClientNotice message="Social accounts belong to a client. Select one to view or connect their social accounts." onSelect={() => openPicker('view or connect social accounts')} />
        ) : notEntitled ? (
          <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800" data-testid="social-not-entitled">
            <span className="flex items-start gap-2">
              <Info className="mt-0.5 h-4 w-4 flex-shrink-0" />
              This account&apos;s plan does not include Social Media, so social accounts cannot be viewed or connected. {upgrade.hint}
            </span>
            {upgrade.action && (
              <Link to={upgrade.action.to} className="rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700">
                {upgrade.action.label}
              </Link>
            )}
          </div>
        ) : (
          <>
            {pageError && (
              <DismissibleAlert className="mb-4 flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                <AlertCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
                {pageError}
              </DismissibleAlert>
            )}

            {isConnecting && (
              <div className="mb-4 flex items-center gap-2 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-800" data-testid="social-connecting">
                <Loader2 className="h-4 w-4 flex-shrink-0 animate-spin" />
                Finish signing in in the connection window. Closing it cancels the connection.
              </div>
            )}

            {phase === 'failed' && phaseMessage && (
              <DismissibleAlert className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" data-testid="social-connect-failed">
                <span className="flex items-start gap-2">
                  <AlertCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
                  {phaseMessage}
                </span>
                <button type="button" onClick={startConnect} className="rounded-lg border border-red-300 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100">
                  Try again
                </button>
              </DismissibleAlert>
            )}

            {phase === 'cancelled' && phaseMessage && (
              <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600" data-testid="social-connect-cancelled">
                <span className="flex items-start gap-2">
                  <Info className="mt-0.5 h-4 w-4 flex-shrink-0" />
                  {phaseMessage}
                </span>
                <button type="button" onClick={startConnect} className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                  Connect again
                </button>
              </div>
            )}

            {justConnected && (
              <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" data-testid="social-connected">
                <span className="flex items-center gap-2">
                  <CheckCircle2 className="h-4 w-4 flex-shrink-0" />
                  Account connected.
                </span>
                {returnTo && (
                  <Link to={returnTo} className="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700" data-testid="social-continue">
                    Continue where you left off
                  </Link>
                )}
              </div>
            )}

            <div className="rounded-2xl bg-white p-4" style={{ boxShadow: cardShadow, border: `1px solid ${indigo.border}` }}>
              {isLoading ? (
                <div className="flex items-center justify-center py-10">
                  <Loader2 className="h-5 w-5 animate-spin" style={{ color: indigo.muted }} />
                </div>
              ) : accounts.length === 0 ? (
                <div className="py-8 text-center text-sm" style={{ color: indigo.muted }} data-testid="social-empty">
                  <p>No social accounts connected yet.</p>
                  <p className="mt-1">Connect your Meta account to choose the Facebook Page, Instagram account and Ad Account to use.</p>
                  <button
                    type="button"
                    onClick={startConnect}
                    disabled={isConnecting}
                    className="mt-4 inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
                    style={{ background: activeGradient }}
                  >
                    <Plug className="h-4 w-4" />
                    Connect Meta Account
                  </button>
                </div>
              ) : (
                <ul className="divide-y" style={{ borderColor: indigo.border }}>
                  {accounts.map((account) => {
                    const Icon = ASSET_ICON[account.asset_type] ?? Globe;
                    const status = connectionStatusOf(account);
                    const lastChecked = checkedAgo(account.status_checked_at);
                    const needsReconnect = status !== 'connected';
                    const busy = disconnectingId === account.id;

                    return (
                      <li key={account.id} className="py-3" data-testid={`social-account-${account.id}`}>
                        <div className="flex flex-wrap items-center gap-3">
                          <span className="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-slate-50">
                            {account.avatar_url ? (
                              <img src={account.avatar_url} alt="" className="h-9 w-9 rounded-lg object-cover" />
                            ) : (
                              <Icon className="h-4 w-4" style={{ color: indigo.muted }} />
                            )}
                          </span>
                          <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-medium" style={{ color: indigo.ink }}>
                              {account.name ?? account.provider_id}
                            </p>
                            <p className="text-xs" style={{ color: indigo.muted }}>
                              {SOCIAL_ASSET_TYPE_LABELS[account.asset_type] ?? account.asset_type}
                              {account.token_expires_at && status === 'connected' ? ` · access until ${new Date(account.token_expires_at).toLocaleDateString()}` : ''}
                              {lastChecked && status === 'connected' ? (
                                <span data-testid={`social-last-checked-${account.id}`}>{` · last checked ${lastChecked}`}</span>
                              ) : null}
                            </p>
                          </div>
                          <span className={`rounded-full border px-2.5 py-0.5 text-xs font-medium ${STATUS_BADGE[status]}`} data-testid={`social-status-${account.id}`}>
                            {busy ? 'Disconnecting…' : STATUS_LABEL[status]}
                          </span>
                          {needsReconnect && (
                            <button
                              type="button"
                              onClick={startConnect}
                              disabled={isConnecting || busy}
                              className="flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-white disabled:opacity-60"
                              style={{ background: activeGradient }}
                              data-testid={`social-reconnect-${account.id}`}
                            >
                              <RefreshCw className="h-3.5 w-3.5" />
                              Reconnect
                            </button>
                          )}
                          <button
                            type="button"
                            onClick={() => checkConnection(account)}
                            disabled={checkingId === account.id || busy}
                            aria-busy={checkingId === account.id}
                            className="flex items-center gap-1 rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60"
                            title="Ask the provider whether this connection still works"
                          >
                            {checkingId === account.id ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <ShieldCheck className="h-3.5 w-3.5" />}
                            Check
                          </button>
                          <button
                            onClick={() => setConfirmDisconnect(account)}
                            disabled={busy}
                            className="rounded-lg p-2 hover:bg-slate-100 disabled:opacity-60"
                            aria-label="Disconnect"
                            title="Disconnect"
                          >
                            {busy ? <Loader2 className="h-4 w-4 animate-spin text-red-500" /> : <Trash2 className="h-4 w-4 text-red-500" />}
                          </button>
                        </div>
                        {needsReconnect && (
                          <p className="mt-1 pl-12 text-xs text-amber-700" data-testid={`social-status-reason-${account.id}`}>
                            {account.status_reason ?? DEFAULT_REASON[status as 'expired' | 'revoked']}
                          </p>
                        )}
                        {rowMessage?.id === account.id && (
                          <p className="mt-1 pl-12 text-xs" style={{ color: indigo.muted }} data-testid={`social-row-message-${account.id}`}>
                            {rowMessage.text}
                          </p>
                        )}
                      </li>
                    );
                  })}
                </ul>
              )}
            </div>
          </>
        )}

        {offeredAssets !== null && (
          <AssetSelectionModal
            assets={offeredAssets}
            isSubmitting={isBinding}
            errorMessage={bindError}
            onCancel={cancelAssetSelection}
            onConfirm={confirmAssetSelection}
          />
        )}

        {confirmDisconnect && (
          <ConfirmModal
            title="Disconnect this account?"
            message={`${confirmDisconnect.name ?? confirmDisconnect.provider_id} will be disconnected and its stored access removed. Features that use it (ads, posts, lead sync) stop working until it is connected again. This does not revoke the app in your Facebook settings.`}
            confirmLabel="Disconnect"
            variant="danger"
            onConfirm={disconnect}
            onCancel={() => setConfirmDisconnect(null)}
          />
        )}

        {notice && <GateNoticeModal testId="social-provider-notice" title={notice.title} message={notice.message} action={notice.action} onClose={() => setNotice(null)} />}
      </div>
    </div>
  );
}
