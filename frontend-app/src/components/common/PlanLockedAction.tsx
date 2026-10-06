import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import moduleAddonService, { type ModuleAddonOffer, type ModuleAddonRow, type ModuleAddonTier } from '../../services/moduleAddonService';

interface PlanLockedActionProps {
  /** The module the locked feature belongs to, for example 'contact_groups'. */
  module: string;
  /** What the feature is called for the client, for example 'Native WhatsApp Groups'. */
  featureLabel: string;
  /** The account the request is for, when a Super Admin or agent is acting for a client. */
  accountId?: number;
}

/** The tier that holds this many groups. Only for display: the server prices the request. */
function tierFor(tiers: ModuleAddonTier[], units: number): ModuleAddonTier | null {
  return tiers.find((t) => units >= t.from_units && (t.to_units === null || units <= t.to_units)) ?? null;
}

function tierText(t: ModuleAddonTier): string {
  return t.to_units === null ? `${t.from_units} or more groups` : `${t.from_units} to ${t.to_units} groups`;
}

function money(value: number): string {
  return `₹${value.toFixed(2)}`;
}

/**
 * Shown where a feature is not in the client's plan. The client says how many groups it needs,
 * sees the price for that count, and sends the request. The Super Admin or an agent then approves
 * it and sends the invoice. While a request is open, the client sees its status instead.
 */
export default function PlanLockedAction({ module, featureLabel, accountId }: PlanLockedActionProps) {
  const [offer, setOffer] = useState<ModuleAddonOffer | null>(null);
  const [open, setOpen] = useState<ModuleAddonRow | null>(null);
  const [units, setUnits] = useState('1');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = () =>
    moduleAddonService.list(accountId).then((res) => {
      setOffer(res.offers.find((o) => o.module === module) ?? null);
      setOpen(res.data.find((r) => r.module === module && (r.status === 'requested' || r.status === 'invoiced')) ?? null);
    });

  useEffect(() => {
    void load().catch(() => setError('We could not load this add-on. Please try again.'));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [module, accountId]);

  const tiers = offer?.tiers ?? [];
  const count = Number.parseInt(units, 10);
  const tier = tiers.length > 0 && Number.isFinite(count) && count > 0 ? tierFor(tiers, count) : null;

  const request = async () => {
    setBusy(true);
    setMessage(null);
    setError(null);
    try {
      const res = await moduleAddonService.request(module, undefined, accountId, tiers.length > 0 ? count : undefined);
      setMessage(res.message);
      await load();
    } catch (err) {
      const data = (err as { response?: { data?: { message?: string } } })?.response?.data;
      setError(data?.message ?? 'We could not send your request. Please try again.');
    } finally {
      setBusy(false);
    }
  };

  if (offer === null && error === null) {
    return <p className="mt-2 text-xs text-slate-500">Loading…</p>;
  }

  return (
    <div className="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-3 text-xs text-amber-900">
      <p className="font-medium">{featureLabel} is not included in your plan.</p>

      {open ? (
        <p className="mt-2 text-amber-900">
          {open.status === 'requested'
            ? `Your request was sent on ${new Date(open.created_at ?? '').toLocaleDateString('en-IN')} and is waiting for approval.`
            : 'Your request has been approved. Pay the invoice on the Billing page to start the add-on.'}
        </p>
      ) : offer ? (
        <>
          <p className="mt-1">
            {tiers.length > 0
              ? 'Choose how many groups you need. The price depends on that number.'
              : `You can add it for ${money(offer.price)} (GST included) for ${offer.term_months} month${offer.term_months === 1 ? '' : 's'}.`}
          </p>

          {tiers.length > 0 && (
            <div className="mt-3 flex flex-wrap items-end gap-3">
              <label className="text-xs font-medium text-slate-800">
                Number of groups
                <input
                  type="number"
                  min={1}
                  value={units}
                  onChange={(e) => setUnits(e.target.value)}
                  className="ml-2 w-20 rounded-lg border border-amber-300 bg-white px-2 py-1 text-sm text-slate-900"
                />
              </label>
              <p className="text-xs text-slate-800">
                {tier ? (
                  <>
                    <strong>{money(Number(tier.price))}</strong> for {offer.term_months} month{offer.term_months === 1 ? '' : 's'}, GST included
                    <span className="text-amber-800"> ({tierText(tier)})</span>
                  </>
                ) : (
                  'Enter at least 1 group.'
                )}
              </p>
            </div>
          )}

          <div className="mt-3 flex flex-wrap items-center gap-2">
            <button
              type="button"
              onClick={() => void request()}
              disabled={busy || (tiers.length > 0 && !tier)}
              className="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700 disabled:opacity-60"
            >
              {busy ? 'Sending…' : `Request ${featureLabel}`}
            </button>
            <Link to="/billing" className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
              View plans
            </Link>
          </div>
        </>
      ) : null}

      {message && <p className="mt-2 text-slate-800">{message}</p>}
      {error && <p className="mt-2 text-red-700">{error}</p>}
    </div>
  );
}
