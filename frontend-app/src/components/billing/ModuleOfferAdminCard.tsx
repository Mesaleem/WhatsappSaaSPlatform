import { useEffect, useState, type FormEvent } from 'react';
import moduleAddonService, { type ModuleAddonOffer } from '../../services/moduleAddonService';

function errorMessage(err: unknown, fallback: string): string {
  const data = (err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response?.data;
  const first = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;
  return first ?? data?.message ?? fallback;
}

/**
 * Super Admin: edit the paid module offer that every client sees (price, term, the
 * units included, on sale or not). New requests use the new values at approval; invoices
 * already issued keep the price they were issued at.
 */
export default function ModuleOfferAdminCard() {
  const [offers, setOffers] = useState<ModuleAddonOffer[] | null>(null);
  const [draft, setDraft] = useState<Record<string, Omit<ModuleAddonOffer, 'module'>>>({});
  const [savingModule, setSavingModule] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    moduleAddonService
      .offers()
      .then((list) => {
        setOffers(list);
        setDraft(Object.fromEntries(list.map((o) => [o.module, { label: o.label, price: o.price, term_months: o.term_months, units_included: o.units_included, is_active: o.is_active }])));
      })
      .catch((err: unknown) => setError(errorMessage(err, 'Could not load the offers.')));
  }, []);

  const update = (module: string, patch: Partial<Omit<ModuleAddonOffer, 'module'>>) =>
    setDraft((d) => ({ ...d, [module]: { ...d[module], ...patch } }));

  const save = async (e: FormEvent<HTMLFormElement>, module: string) => {
    e.preventDefault();
    setSavingModule(module);
    setMessage(null);
    setError(null);
    try {
      const res = await moduleAddonService.updateOffer(module, draft[module]);
      setMessage(res.message);
      setOffers((list) => (list ?? []).map((o) => (o.module === module ? res.data : o)));
    } catch (err) {
      setError(errorMessage(err, 'Could not save this offer.'));
    } finally {
      setSavingModule(null);
    }
  };

  if (offers === null) return error ? <p className="mt-6 text-sm text-red-600">{error}</p> : null;

  const field = 'mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none';

  return (
    <div className="mt-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
      <p className="text-sm font-semibold text-slate-900">Paid module offers</p>
      <p className="mt-0.5 text-xs text-slate-500">Changes apply to new requests. Issued invoices keep their price.</p>

      {message && <p className="mt-3 text-sm text-emerald-700">{message}</p>}
      {error && <p className="mt-3 text-sm text-red-600">{error}</p>}

      {offers.map((offer) => {
        const d = draft[offer.module];
        if (!d) return null;
        return (
          <form key={offer.module} onSubmit={(e) => void save(e, offer.module)} className="mt-4 grid grid-cols-2 gap-3 rounded-lg border border-slate-200 p-4 md:grid-cols-6">
            <label className="col-span-2 text-xs font-medium text-slate-700 md:col-span-2">
              Name shown to clients
              <input className={field} value={d.label} maxLength={120} onChange={(e) => update(offer.module, { label: e.target.value })} required />
            </label>
            <label className="text-xs font-medium text-slate-700">
              Price (₹, incl. GST)
              <input className={field} type="number" min="0" step="0.01" value={d.price} onChange={(e) => update(offer.module, { price: Number(e.target.value) })} required />
            </label>
            <label className="text-xs font-medium text-slate-700">
              Term (months)
              <input className={field} type="number" min="1" max="12" value={d.term_months} onChange={(e) => update(offer.module, { term_months: Number(e.target.value) })} required />
            </label>
            <label className="text-xs font-medium text-slate-700">
              Units included
              <input className={field} type="number" min="0" value={d.units_included} onChange={(e) => update(offer.module, { units_included: Number(e.target.value) })} required />
            </label>
            <div className="flex flex-col justify-end gap-2">
              <label className="flex items-center gap-2 text-xs font-medium text-slate-700">
                <input type="checkbox" checked={d.is_active} onChange={(e) => update(offer.module, { is_active: e.target.checked })} />
                On sale
              </label>
              <button
                type="submit"
                disabled={savingModule === offer.module}
                className="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-60"
              >
                {savingModule === offer.module ? 'Saving…' : 'Save'}
              </button>
            </div>
          </form>
        );
      })}
    </div>
  );
}
