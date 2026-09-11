import { useState } from 'react';
import { Camera, CheckCircle2, Globe, Link2, Megaphone, Rss, X } from 'lucide-react';
import { indigo, activeGradient } from '../../theme/signalIndigo';
import { SOCIAL_ASSET_TYPE_LABELS, type OfferedAsset, type SocialAssetType } from '../../types/social';

const ASSET_ICON: Record<SocialAssetType, typeof Globe> = {
  facebook_page: Globe,
  instagram: Camera,
  meta_ad_account: Megaphone,
  linkedin_page: Link2,
  youtube_channel: Rss,
};

interface AssetSelectionModalProps {
  assets: OfferedAsset[];
  isSubmitting: boolean;
  errorMessage: string | null;
  onCancel: () => void;
  onConfirm: (selected: OfferedAsset[]) => void;
}

/**
 * Social Media Marketing & Meta Ads Automation Expansion (Phase 1).
 * Shown after a successful OAuth popup round-trip
 * (SocialAccountsPage's `window.addEventListener('message', ...)`
 * handler). Lets the client pick which of the assets Meta returned
 * (Pages / linked Instagram accounts / Ad Accounts) to actually bind to
 * their tenant account — the OAuth grant itself does not imply binding
 * everything it can see.
 */
export default function AssetSelectionModal({
  assets,
  isSubmitting,
  errorMessage,
  onCancel,
  onConfirm,
}: AssetSelectionModalProps) {
  const [selectedKeys, setSelectedKeys] = useState<Set<string>>(new Set());

  const keyFor = (asset: OfferedAsset) => `${asset.asset_type}:${asset.provider_id}`;

  const toggle = (asset: OfferedAsset) => {
    setSelectedKeys((prev) => {
      const next = new Set(prev);
      const key = keyFor(asset);
      if (next.has(key)) {
        next.delete(key);
      } else {
        next.add(key);
      }
      return next;
    });
  };

  const selectedAssets = assets.filter((a) => selectedKeys.has(keyFor(a)));

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl">
        <div className="mb-4 flex items-start justify-between">
          <div>
            <h2 className="font-display text-base font-bold" style={{ color: indigo.ink }}>
              Select assets to connect
            </h2>
            <p className="mt-1 text-sm" style={{ color: indigo.muted }}>
              Choose the Facebook Page, Instagram account, or Ad Account you want this platform to use.
            </p>
          </div>
          <button onClick={onCancel} className="rounded-lg p-1 hover:bg-slate-100" aria-label="Close">
            <X className="h-4 w-4" style={{ color: indigo.muted }} />
          </button>
        </div>

        {assets.length === 0 ? (
          <p className="rounded-xl bg-slate-50 px-3 py-4 text-center text-sm" style={{ color: indigo.muted }}>
            No connectable Pages or Ad Accounts were found on this Meta login. Make sure you're an admin of at
            least one Facebook Page or Ad Account, then try again.
          </p>
        ) : (
          <div className="max-h-72 space-y-2 overflow-y-auto">
            {assets.map((asset) => {
              const Icon = ASSET_ICON[asset.asset_type];
              const key = keyFor(asset);
              const isSelected = selectedKeys.has(key);
              return (
                <button
                  key={key}
                  type="button"
                  onClick={() => toggle(asset)}
                  className={`flex w-full items-center gap-3 rounded-xl border px-3 py-2.5 text-left transition ${
                    isSelected ? 'border-blue-300 bg-blue-50' : 'border-slate-200 hover:bg-slate-50'
                  }`}
                >
                  <span className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-white">
                    {asset.avatar_url ? (
                      <img src={asset.avatar_url} alt="" className="h-8 w-8 rounded-lg object-cover" />
                    ) : (
                      <Icon className="h-4 w-4" style={{ color: indigo.muted }} />
                    )}
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-medium" style={{ color: indigo.ink }}>
                      {asset.name ?? asset.provider_id}
                    </span>
                    <span className="block text-xs" style={{ color: indigo.muted }}>
                      {SOCIAL_ASSET_TYPE_LABELS[asset.asset_type]}
                    </span>
                  </span>
                  {isSelected && <CheckCircle2 className="h-5 w-5 flex-shrink-0 text-blue-600" />}
                </button>
              );
            })}
          </div>
        )}

        {errorMessage && (
          <p className="mt-3 rounded-lg bg-red-50 px-3 py-2 text-xs font-medium text-red-700">{errorMessage}</p>
        )}

        <div className="mt-5 flex items-center justify-end gap-2">
          <button
            onClick={onCancel}
            disabled={isSubmitting}
            className="rounded-lg px-3 py-2 text-sm font-medium hover:bg-slate-100 disabled:opacity-50"
            style={{ color: indigo.muted }}
          >
            Cancel
          </button>
          <button
            onClick={() => onConfirm(selectedAssets)}
            disabled={isSubmitting || selectedAssets.length === 0}
            className="rounded-lg px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
            style={{ background: activeGradient }}
          >
            {isSubmitting ? 'Connecting…' : `Connect ${selectedAssets.length || ''}`.trim()}
          </button>
        </div>
      </div>
    </div>
  );
}
