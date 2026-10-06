import { useEffect, useState } from 'react';
import moduleAddonService, { type ModuleAddonOffer, type ModuleAddonRow } from '../../services/moduleAddonService';

const STATUS_TEXT: Record<ModuleAddonRow['status'], string> = {
  requested: 'Waiting for approval',
  invoiced: 'Invoice sent. Waiting for payment',
  paid: 'Active',
  expired: 'Term ended',
  rejected: 'Not approved',
};

interface ModuleAddonCardProps {
  /** The module this card offers, for example 'contact_groups'. */
  module: string;
  /** The client account the request is for (the Super Admin or agent selected account). */
  accountId?: number;
  /** Whether the module is already on for this account. */
  enabled: boolean;
}

/**
 * Shows a paid module add-on the account does not include: the price and term, and a
 * Request button. The request goes to the Super Admin or the agent, who approves it and
 * sends an invoice. Nothing is charged here.
 */
export default function ModuleAddonCard({ module, accountId, enabled }: ModuleAddonCardProps) {
  const [offer, setOffer] = useState<ModuleAddonOffer | null>(null);
  const [rows, setRows] = useState<ModuleAddonRow[]>([]);
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

  const open = rows.find((r) => r.status === 'requested' || r.status === 'invoiced');
  const latest = open ?? rows[0];

  const request = async () => {
    setBusy(true);
    setMessage(null);
    try {
      const res = await moduleAddonService.request(module, undefined, accountId);
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
    <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3">
      <div className="text-sm text-slate-700">
        <p className="font-medium text-slate-900">{offer.label} is a paid add-on</p>
        <p className="text-xs text-slate-500">
          ₹{offer.price.toFixed(2)} (GST included) for {offer.term_months} month{offer.term_months === 1 ? '' : 's'} from payment
          {offer.units_included > 0 && ` · includes ${offer.units_included} group chats`}.
          {latest && <span className="ml-2 font-medium text-indigo-700">Status: {STATUS_TEXT[latest.status]}</span>}
        </p>
        {latest?.status === 'invoiced' && (
          <p className="mt-1 text-xs text-slate-600">Your invoice is on the Billing page, where you can pay it.</p>
        )}
        {message && <p className="mt-1 text-xs text-slate-600">{message}</p>}
      </div>
      <button
        type="button"
        onClick={() => void request()}
        disabled={busy || !!open}
        title={open ? 'A request for this add-on is already open.' : 'Ask your Super Admin or agent for this add-on.'}
        className="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-slate-300"
      >
        {open ? 'Requested' : 'Request add-on'}
      </button>
    </div>
  );
}
