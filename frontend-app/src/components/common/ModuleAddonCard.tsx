import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import moduleAddonService, { type ModuleAddonOffer, type ModuleAddonRow, type ModuleAddonTier } from '../../services/moduleAddonService';

const STATUS_TEXT: Record<ModuleAddonRow['status'], string> = {
  requested: 'Waiting for approval',
  invoiced: 'Invoice sent. Waiting for payment',
  paid: 'Active',
  expired: 'Term ended',
  rejected: 'Not approved',
};

function formatDate(value: string | null | undefined): string {
  if (!value) return '—';
  return new Date(value).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
}

/** The tier that holds a number of units (client-side display only; the server prices the request). */
function tierFor(tiers: ModuleAddonTier[], units: number): ModuleAddonTier | null {
  return tiers.find((t) => units >= t.from_units && (t.to_units === null || units <= t.to_units)) ?? null;
}

function tierLabel(t: ModuleAddonTier, unit: string): string {
  return t.to_units === null ? `${t.from_units}+ ${unit}` : `${t.from_units}–${t.to_units} ${unit}`;
}

interface ModuleAddonCardProps {
  /** The module this card offers, for example 'contact_groups'. */
  module: string;
  /** The client account the request is for (the Super Admin or agent selected account). */
  accountId?: number;
  /** Whether the module is already on for this account. */
  enabled: boolean;
  /** What one unit is called, for example 'groups'. */
  unitLabel?: string;
}

/**
 * A paid module add-on the account does not include. The client says how many units it
 * needs, the price comes from the tier that holds that number, and the request goes to the
 * Super Admin or agent, who approves it and sends an invoice. Nothing is charged here.
 */
export default function ModuleAddonCard({ module, accountId, enabled, unitLabel = 'units' }: ModuleAddonCardProps) {
  const [offer, setOffer] = useState<ModuleAddonOffer | null>(null);
  const [rows, setRows] = useState<ModuleAddonRow[]>([]);
  const [units, setUnits] = useState('1');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);

  const load = () =>
    moduleAddonService.list(accountId).then((res) => {
      setOffer(res.offers.find((o) => o.module === module) ?? null);
      setRows(res.data.filter((r) => r.module === module));
    });

  useEffect(() => {
    void load().catch(() => setOffer(null));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [module, accountId]);

  if (!offer || enabled) return null;

  const tiers = offer.tiers ?? [];
  const count = Number.parseInt(units, 10);
  const tier = Number.isFinite(count) && count > 0 ? tierFor(tiers, count) : null;
  const open = rows.find((r) => r.status === 'requested' || r.status === 'invoiced');
  const latest = open ?? rows[0];

  const request = async () => {
    setBusy(true);
    setMessage(null);
    try {
      const res = await moduleAddonService.request(module, undefined, accountId, count);
      setMessage(res.message);
      await load();
    } catch (err) {
      const data = (err as { response?: { data?: { message?: string } } })?.response?.data;
      setMessage(data?.message ?? 'Could not send the request. Please try again.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="mb-4 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="text-sm text-slate-700">
          <p className="font-medium text-slate-900">{offer.label} is a paid add-on</p>
          {tiers.length > 0 ? (
            <p className="mt-0.5 text-xs text-slate-500">
              {tiers.map((t) => `${tierLabel(t, unitLabel)}: ₹${Number(t.price).toFixed(2)}`).join(' · ')} (GST included) per {offer.term_months} month
              {offer.term_months === 1 ? '' : 's'}.
            </p>
          ) : (
            <p className="mt-0.5 text-xs text-slate-500">
              ₹{offer.price.toFixed(2)} (GST included) for {offer.term_months} month{offer.term_months === 1 ? '' : 's'} from payment.
            </p>
          )}
          {open && (
            <p className="mt-1 text-xs font-medium text-indigo-700">
              Already requested on {formatDate(open.created_at)}. {open.status === 'requested' ? 'Waiting for approval.' : 'Approved, waiting for payment.'}
            </p>
          )}
          {!open && latest && (
            <p className="mt-1 text-xs font-medium text-indigo-700">
              Last request {formatDate(latest.created_at)}: {STATUS_TEXT[latest.status]}
              {latest.decided_at ? ` (decided ${formatDate(latest.decided_at)})` : ''}
            </p>
          )}
          {open?.status === 'invoiced' && (
            <p className="mt-1 text-xs text-slate-600">Your invoice is on the Billing page, where you can pay it.</p>
          )}
          {rows.length > 0 && (
            <Link to="/add-ons" className="mt-1 inline-block text-xs font-medium text-indigo-700 underline">
              View add-on history
            </Link>
          )}
        </div>

        {tiers.length > 0 ? (
          <div className="flex flex-col items-end gap-2">
            <label className="text-xs font-medium text-slate-700">
              How many {unitLabel} do you need?
              <input
                type="number"
                min={1}
                value={units}
                onChange={(e) => setUnits(e.target.value)}
                disabled={!!open}
                className="ml-2 w-20 rounded-lg border border-slate-300 px-2 py-1 text-sm"
              />
            </label>
            <p className="text-xs text-slate-600">
              {tier ? (
                <>
                  Price: <strong>₹{Number(tier.price).toFixed(2)}</strong> (GST included)
                </>
              ) : (
                'Enter a number of at least 1.'
              )}
            </p>
            <button
              type="button"
              onClick={() => void request()}
              disabled={busy || !!open || !tier}
              title={open ? 'A request for this add-on is already open.' : 'Ask your Super Admin or agent for this add-on.'}
              className="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-slate-300"
            >
              {open ? 'Requested' : 'Request add-on'}
            </button>
          </div>
        ) : (
          <button
            type="button"
            onClick={() => void request()}
            disabled={busy || !!open}
            title={open ? 'A request for this add-on is already open.' : 'Ask your Super Admin or agent for this add-on.'}
            className="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-slate-300"
          >
            {open ? 'Requested' : 'Request add-on'}
          </button>
        )}
      </div>
      {message && <p className="mt-2 text-xs text-slate-600">{message}</p>}
    </div>
  );
}
