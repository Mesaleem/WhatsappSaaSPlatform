import { useCallback, useEffect, useRef, useState } from 'react';
import { AxiosError } from 'axios';
import { AlertCircle, Building2, Camera, Globe, Link2, Loader2, Megaphone, Plug, Rss, Trash2 } from 'lucide-react';
import { indigo, activeGradient, cardShadow } from '../../theme/signalIndigo';
import { useAuth } from '../../core/context/AuthContext';
import { useTenant } from '../../core/context/TenantContext';
import socialService from '../../services/socialService';
import AssetSelectionModal from '../../components/social/AssetSelectionModal';
import {
  SOCIAL_ASSET_TYPE_LABELS,
  type OAuthPopupMessage,
  type OfferedAsset,
  type SocialAccount,
  type SocialAssetType,
} from '../../types/social';
import type { ApiErrorResponse } from '../../types/auth';

const ASSET_ICON: Record<SocialAssetType, typeof Globe> = {
  facebook_page: Globe,
  instagram: Camera,
  meta_ad_account: Megaphone,
  linkedin_page: Link2,
  youtube_channel: Rss,
};

const HEALTH_BADGE: Record<SocialAccount['health_status'], string> = {
  connected: 'bg-emerald-50 text-emerald-700 border-emerald-200',
  token_expired: 'bg-amber-50 text-amber-700 border-amber-200',
  reauth_required: 'bg-red-50 text-red-700 border-red-200',
};

const HEALTH_LABEL: Record<SocialAccount['health_status'], string> = {
  connected: 'Connected',
  token_expired: 'Token expired',
  reauth_required: 'Reconnect required',
};

function extractErrorMessage(err: unknown, fallback: string): string {
  const axiosErr = err as AxiosError<ApiErrorResponse>;
  return axiosErr.response?.data?.message ?? fallback;
}

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 1.
 * "Single-click Connect Meta Account" flow:
 *  1. Ask backend for the provider's OAuth URL (already carries our
 *     encrypted `state`) and open it in a popup — never navigate the SPA.
 *  2. Listen for the popup's postMessage (sent by
 *     SocialAuthController::callback()'s tiny HTML page) — success opens
 *     the Asset Selection Modal, error surfaces inline.
 *  3. Confirming the modal calls /social/accounts/bind, which redeems the
 *     one-time nonce server-side and persists the chosen assets.
 */
export default function SocialAccountsPage() {
  // Super Admin Multi-Tenant Scoping: this page is tenant-scoped data,
  // exactly like Send Alert / Analytics / Chatbot / Team Users, so it
  // follows the SAME established pattern those pages use — read the
  // Header's existing global "act as" selector (TenantContext /
  // ClientSwitcher) rather than building a second, page-local tenant
  // picker. A regular tenant user's own account_id is attached
  // automatically server-side (TenantIsolationMiddleware); a Super Admin
  // acts on whichever client the Header dropdown has selected, or none
  // in "All Clients (Global View)".
  const { isSuperAdmin } = useAuth();
  const { selectedAccountId } = useTenant();
  const superAdmin = isSuperAdmin();
  const noTenantSelected = superAdmin && selectedAccountId === null;

  const [accounts, setAccounts] = useState<SocialAccount[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isConnecting, setIsConnecting] = useState(false);
  const [pageError, setPageError] = useState<string | null>(null);

  const [pendingNonce, setPendingNonce] = useState<string | null>(null);
  const [offeredAssets, setOfferedAssets] = useState<OfferedAsset[] | null>(null);
  const [isBinding, setIsBinding] = useState(false);
  const [bindError, setBindError] = useState<string | null>(null);

  const popupRef = useRef<Window | null>(null);

  const loadAccounts = useCallback(() => {
    // No tenant to load for — Super Admin hasn't picked a client yet.
    // Not an error state (see the dedicated empty state below), so no
    // red banner, and nothing is fetched for a request the backend would
    // reject anyway (ResolvesTenantAccount::requireAccount() 422s here).
    if (noTenantSelected) {
      setAccounts([]);
      setIsLoading(false);
      return;
    }

    setIsLoading(true);
    setPageError(null);
    socialService
      .listAccounts()
      .then(setAccounts)
      .catch((err) => setPageError(extractErrorMessage(err, 'Could not load connected social accounts.')))
      .finally(() => setIsLoading(false));
  }, [noTenantSelected]);

  useEffect(() => {
    // selectedAccountId in the dependency array is what makes this page
    // refetch the instant a Super Admin switches clients in the Header —
    // same mechanism UsersPage/ChatbotPage/AnalyticsPage already rely on.
    loadAccounts();
  }, [loadAccounts, selectedAccountId]);

  useEffect(() => {
    const onMessage = (event: MessageEvent<OAuthPopupMessage>) => {
      const data = event.data;
      if (!data || (data.type !== 'social-oauth-success' && data.type !== 'social-oauth-error')) return;

      if (data.type === 'social-oauth-error') {
        setIsConnecting(false);
        setPageError(data.message);
        return;
      }

      setIsConnecting(false);
      setPendingNonce(data.nonce);
      setOfferedAssets(data.assets);
    };

    window.addEventListener('message', onMessage);
    return () => window.removeEventListener('message', onMessage);
  }, []);

  const connectMeta = useCallback(() => {
    if (noTenantSelected) return;

    setPageError(null);
    setIsConnecting(true);

    socialService
      .getOAuthRedirectUrl('meta')
      .then(({ url }) => {
        popupRef.current = window.open(url, 'social-oauth-popup', 'width=600,height=720');
        if (!popupRef.current) {
          setIsConnecting(false);
          setPageError('Your browser blocked the connect popup. Please allow popups for this site and try again.');
        }
      })
      .catch((err) => {
        setIsConnecting(false);
        setPageError(extractErrorMessage(err, 'Could not start the Meta connection. Please try again.'));
      });
  }, [noTenantSelected]);

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
        loadAccounts();
      })
      .catch((err) => {
        setIsBinding(false);
        setBindError(extractErrorMessage(err, 'Could not connect the selected asset(s). Please try again.'));
      });
  };

  const disconnect = (id: number) => {
    socialService
      .disconnect(id)
      .then(() => loadAccounts())
      .catch((err) => setPageError(extractErrorMessage(err, 'Could not disconnect this account.')));
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
          onClick={connectMeta}
          disabled={isConnecting || noTenantSelected}
          title={noTenantSelected ? 'Select a client above first' : undefined}
          className="flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
          style={{ background: activeGradient }}
        >
          {isConnecting ? <Loader2 className="h-4 w-4 animate-spin" /> : <Plug className="h-4 w-4" />}
          Connect Meta Account
        </button>
        </div>

        {noTenantSelected ? (
          // Not an error — a Super Admin has no tenant account of their own
          // (see SocialAuthController/ResolvesTenantAccount), so this is an
          // expected, actionable state: pick one from the Header's existing
          // client selector, not a new one on this page.
          <div className="flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm" style={{ color: indigo.muted }}>
            <Building2 className="mt-0.5 h-4 w-4 flex-shrink-0" />
            Select a client from the switcher at the top of the page to view or connect their social accounts.
          </div>
        ) : (
          <>
            {pageError && (
              <div className="mb-4 flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                <AlertCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
                {pageError}
              </div>
            )}

            <div className="rounded-2xl bg-white p-4" style={{ boxShadow: cardShadow, border: `1px solid ${indigo.border}` }}>
          {isLoading ? (
            <div className="flex items-center justify-center py-10">
              <Loader2 className="h-5 w-5 animate-spin" style={{ color: indigo.muted }} />
            </div>
          ) : accounts.length === 0 ? (
            <p className="py-8 text-center text-sm" style={{ color: indigo.muted }}>
              No social accounts connected yet.
            </p>
          ) : (
            <ul className="divide-y" style={{ borderColor: indigo.border }}>
              {accounts.map((account) => {
                const Icon = ASSET_ICON[account.asset_type];
                return (
                  <li key={account.id} className="flex items-center gap-3 py-3">
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
                        {SOCIAL_ASSET_TYPE_LABELS[account.asset_type]}
                      </p>
                    </div>
                    <span
                      className={`rounded-full border px-2.5 py-0.5 text-xs font-medium ${HEALTH_BADGE[account.health_status]}`}
                    >
                      {HEALTH_LABEL[account.health_status]}
                    </span>
                    <button
                      onClick={() => disconnect(account.id)}
                      className="rounded-lg p-2 hover:bg-slate-100"
                      aria-label="Disconnect"
                      title="Disconnect"
                    >
                      <Trash2 className="h-4 w-4 text-red-500" />
                    </button>
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
      </div>
    </div>
  );
}
