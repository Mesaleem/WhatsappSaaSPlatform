import { useEffect, useState } from 'react';
import { ExternalLink, Loader2, Megaphone, Plus } from 'lucide-react';
import socialService from '../../services/socialService';
import { extractErrorMessage } from '../../utils/apiError';
import type { OfferedAsset } from '../../types/social';

const META_BILLING_URL = 'https://business.facebook.com/billing_hub/';

/**
 * Ads Phase C (2026-10-15) — inside the connect flow, a client with no Meta ad
 * account creates one here (in one of their Business portfolios, INR, India time)
 * instead of going to Meta Business Suite. The new account is offered and
 * pre-selected in the same connect attempt. Only a card / funds still need Meta.
 */
export default function CreateAdAccountPanel({
  nonce,
  defaultName,
  startOpen,
  onCreated,
}: {
  nonce: string;
  defaultName: string;
  startOpen: boolean;
  onCreated: (asset: OfferedAsset) => void;
}) {
  const [open, setOpen] = useState(startOpen);
  const [businesses, setBusinesses] = useState<{ id: string; name: string }[] | null>(null);
  const [businessId, setBusinessId] = useState('');
  const [name, setName] = useState(defaultName);
  const [isCreating, setIsCreating] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [created, setCreated] = useState<OfferedAsset | null>(null);

  useEffect(() => {
    if (!open || businesses !== null) return;
    Promise.resolve()
      .then(() => socialService.listMetaBusinesses(nonce))
      .then((rows) => {
        setBusinesses(rows);
        if (rows.length === 1) setBusinessId(rows[0].id);
      })
      .catch((err: unknown) => {
        setBusinesses([]);
        setError(extractErrorMessage(err, 'Could not load your Business portfolios.'));
      });
  }, [open, businesses, nonce]);

  const create = () => {
    if (!businessId || name.trim().length < 2) {
      setError('Choose a Business portfolio and enter a name (at least 2 characters).');
      return;
    }
    setError(null);
    setIsCreating(true);
    socialService
      .createMetaAdAccount(nonce, businessId, name.trim())
      .then((asset) => {
        setCreated(asset);
        onCreated(asset);
      })
      .catch((err: unknown) => setError(extractErrorMessage(err, 'Could not create the ad account.')))
      .finally(() => setIsCreating(false));
  };

  if (created) {
    return (
      <div className="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2.5 text-xs text-emerald-900" data-testid="ad-account-created">
        <p className="font-semibold">“{created.name}” was created in INR (India time) and is selected below.</p>
        <p className="mt-1">
          Before ads can run, add a payment method to it in Meta (Meta does not let apps add cards).{' '}
          <a href={META_BILLING_URL} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-0.5 font-semibold underline">
            Open Meta billing <ExternalLink className="h-3 w-3" />
          </a>
        </p>
      </div>
    );
  }

  if (!open) {
    return (
      <button type="button" onClick={() => setOpen(true)} className="mt-3 inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-700 hover:text-emerald-800">
        <Plus className="h-3.5 w-3.5" /> Create a new ad account
      </button>
    );
  }

  return (
    <div className="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm" data-testid="create-ad-account">
      <p className="flex items-center gap-1.5 font-semibold text-slate-800">
        <Megaphone className="h-4 w-4 text-emerald-700" /> Create a Meta ad account
      </p>
      <p className="mt-0.5 text-xs text-slate-500">Created in your Business portfolio, in Indian Rupees (INR) on India time. No need to open Meta.</p>
      {businesses === null ? (
        <p className="mt-2 inline-flex items-center gap-1 text-xs text-slate-500">
          <Loader2 className="h-3.5 w-3.5 animate-spin" /> Loading your Business portfolios…
        </p>
      ) : businesses.length === 0 ? (
        <p className="mt-2 text-xs text-slate-600">
          No Business portfolio was found for this Facebook login. Create one at business.facebook.com (free), then connect again.
        </p>
      ) : (
        <div className="mt-2 space-y-2">
          <label className="block text-xs font-medium text-slate-700">
            Business portfolio
            <select value={businessId} onChange={(e) => setBusinessId(e.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-sm">
              <option value="">Choose…</option>
              {businesses.map((b) => (
                <option key={b.id} value={b.id}>
                  {b.name}
                </option>
              ))}
            </select>
          </label>
          <label className="block text-xs font-medium text-slate-700">
            Ad account name
            <input value={name} onChange={(e) => setName(e.target.value)} maxLength={100} className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-sm" />
          </label>
          <button
            type="button"
            onClick={create}
            disabled={isCreating}
            className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700 disabled:opacity-60"
          >
            {isCreating ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Plus className="h-3.5 w-3.5" />}
            {isCreating ? 'Creating…' : 'Create ad account'}
          </button>
        </div>
      )}
      {error && <p className="mt-2 text-xs text-red-600" role="alert">{error}</p>}
    </div>
  );
}
