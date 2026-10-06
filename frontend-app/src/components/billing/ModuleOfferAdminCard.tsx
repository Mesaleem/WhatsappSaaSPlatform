import { useEffect, useState, type FormEvent } from 'react';
import moduleAddonService, { type ModuleAddonOffer, type ModuleAddonTier } from '../../services/moduleAddonService';

function errorMessage(err: unknown, fallback: string): string {
  const data = (err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response?.data;
  const first = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;
  return first ?? data?.message ?? fallback;
}

/** A tier row as typed: strings, so a half-typed number does not fight the input. */
interface TierDraft {
  from_units: string;
  /** Empty = this many and above. */
  to_units: string;
  price: string;
}

function toDrafts(tiers: ModuleAddonTier[] = []): TierDraft[] {
  return tiers.map((t) => ({
    from_units: String(t.from_units),
    to_units: t.to_units === null ? '' : String(t.to_units),
    price: String(t.price),
  }));
}

/**
 * Super Admin: edit the paid module offer that every client sees (price, term, the
 * units included, on sale or not), and the unit tiers that price a request by how many
 * units it needs. New requests use the new values at approval; invoices already issued
 * keep the price they were issued at.
 */
export default function ModuleOfferAdminCard() {
  const [offers, setOffers] = useState<ModuleAddonOffer[] | null>(null);
  const [draft, setDraft] = useState<Record<string, Omit<ModuleAddonOffer, 'module'>>>({});
  const [tierDraft, setTierDraft] = useState<Record<string, TierDraft[]>>({});
  const [savingModule, setSavingModule] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    moduleAddonService
      .offers()
      .then((list) => {
        setOffers(list);
        setDraft(Object.fromEntries(list.map((o) => [o.module, { label: o.label, price: o.price, term_months: o.term_months, units_included: o.units_included, is_active: o.is_active }])));
        setTierDraft(Object.fromEntries(list.map((o) => [o.module, toDrafts(o.tiers)])));
      })
      .catch((err: unknown) => setError(errorMessage(err, 'Could not load the offers.')));
  }, []);

  const update = (module: string, patch: Partial<Omit<ModuleAddonOffer, 'module'>>) =>
    setDraft((d) => ({ ...d, [module]: { ...d[module], ...patch } }));

  const updateTier = (module: string, index: number, patch: Partial<TierDraft>) =>
    setTierDraft((d) => ({
      ...d,
      [module]: (d[module] ?? []).map((row, i) => (i === index ? { ...row, ...patch } : row)),
    }));

  const addTier = (module: string) =>
    setTierDraft((d) => {
      const rows = d[module] ?? [];
      const last = rows[rows.length - 1];
      // The new tier starts one unit above the last one; the last open-ended tier is closed first.
      const nextFrom = last ? String((Number(last.to_units) || Number(last.from_units)) + 1) : '1';
      return { ...d, [module]: [...rows, { from_units: nextFrom, to_units: '', price: '' }] };
    });

  const removeTier = (module: string, index: number) =>
    setTierDraft((d) => ({ ...d, [module]: (d[module] ?? []).filter((_, i) => i !== index) }));

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

  const saveTiers = async (e: FormEvent<HTMLFormElement>, module: string) => {
    e.preventDefault();
    setSavingModule(module);
    setMessage(null);
    setError(null);
    try {
      const tiers = (tierDraft[module] ?? []).map((row) => ({
        from_units: Number(row.from_units),
        to_units: row.to_units.trim() === '' ? null : Number(row.to_units),
        price: Number(row.price),
      }));
      const res = await moduleAddonService.updateTiers(module, tiers);
      setMessage(res.message);
      // Reload so the rows show exactly what was stored (sorted, as the server keeps them).
      const fresh = (await moduleAddonService.offers()).find((o) => o.module === module);
      if (fresh) {
        setOffers((list) => (list ?? []).map((o) => (o.module === module ? fresh : o)));
        setTierDraft((d) => ({ ...d, [module]: toDrafts(fresh.tiers) }));
      }
    } catch (err) {
      setError(errorMessage(err, 'Could not save the tiers.'));
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
        const rows = tierDraft[offer.module] ?? [];
        const busy = savingModule === offer.module;
        return (
          <div key={offer.module} className="mt-4 space-y-3">
            <form onSubmit={(e) => void save(e, offer.module)} className="grid grid-cols-2 gap-3 rounded-lg border border-slate-200 p-4 md:grid-cols-6">
              <label className="col-span-2 text-xs font-medium text-slate-700 md:col-span-2">
                Name shown to clients
                <span className="text-red-500" aria-hidden="true"> *</span><input className={field} value={d.label} maxLength={120} onChange={(e) => update(offer.module, { label: e.target.value })} required />
              </label>
              <label className="text-xs font-medium text-slate-700">
                Price (₹, incl. GST)
                <span className="text-red-500" aria-hidden="true"> *</span><input className={field} type="number" min="0" step="0.01" value={d.price} onChange={(e) => update(offer.module, { price: Number(e.target.value) })} required />
              </label>
              <label className="text-xs font-medium text-slate-700">
                Term (months)
                <span className="text-red-500" aria-hidden="true"> *</span><input className={field} type="number" min="1" max="12" value={d.term_months} onChange={(e) => update(offer.module, { term_months: Number(e.target.value) })} required />
              </label>
              <label className="text-xs font-medium text-slate-700">
                Units included
                <span className="text-red-500" aria-hidden="true"> *</span><input className={field} type="number" min="0" value={d.units_included} onChange={(e) => update(offer.module, { units_included: Number(e.target.value) })} required />
              </label>
              <div className="flex flex-col justify-end gap-2">
                <label className="flex items-center gap-2 text-xs font-medium text-slate-700">
                  <input type="checkbox" checked={d.is_active} onChange={(e) => update(offer.module, { is_active: e.target.checked })} />
                  On sale
                </label>
                <button
                  type="submit"
                  disabled={busy}
                  className="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-60"
                >
                  {busy ? 'Saving…' : 'Save'}
                </button>
              </div>
            </form>

            <form onSubmit={(e) => void saveTiers(e, offer.module)} className="rounded-lg border border-slate-200 p-4">
              <p className="text-xs font-semibold text-slate-900">Price by units</p>
              <p className="mt-0.5 text-xs text-slate-500">
                A client's request is priced by the tier its number of units falls in. Leave "up to" empty on the last tier to make it open-ended. Tiers must not overlap.
              </p>

              {rows.length === 0 ? (
                <p className="mt-3 text-xs text-slate-500">No tiers. Requests use the flat price above.</p>
              ) : (
                <div className="mt-3 space-y-2">
                  {rows.map((row, index) => (
                    <div key={index} className="grid grid-cols-2 items-end gap-2 md:grid-cols-5">
                      <label className="text-xs font-medium text-slate-700">
                        From
                        <span className="text-red-500" aria-hidden="true"> *</span><input className={field} type="number" min="1" value={row.from_units} onChange={(e) => updateTier(offer.module, index, { from_units: e.target.value })} required />
                      </label>
                      <label className="text-xs font-medium text-slate-700">
                        Up to (empty = and above)
                        <input className={field} type="number" min="1" value={row.to_units} onChange={(e) => updateTier(offer.module, index, { to_units: e.target.value })} />
                      </label>
                      <label className="text-xs font-medium text-slate-700">
                        Price (₹, incl. GST)
                        <span className="text-red-500" aria-hidden="true"> *</span><input className={field} type="number" min="0" step="0.01" value={row.price} onChange={(e) => updateTier(offer.module, index, { price: e.target.value })} required />
                      </label>
                      <div className="col-span-2 md:col-span-1">
                        <button
                          type="button"
                          onClick={() => removeTier(offer.module, index)}
                          className="rounded-lg border border-red-200 px-3 py-2 text-xs font-medium text-red-600 hover:bg-red-50"
                        >
                          Remove
                        </button>
                      </div>
                    </div>
                  ))}
                </div>
              )}

              <div className="mt-3 flex flex-wrap gap-2">
                <button type="button" onClick={() => addTier(offer.module)} className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50">
                  Add tier
                </button>
                <button
                  type="submit"
                  disabled={busy || rows.length === 0}
                  className="rounded-lg bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-700 disabled:opacity-60"
                >
                  {busy ? 'Saving…' : 'Save tiers'}
                </button>
              </div>
            </form>
          </div>
        );
      })}
    </div>
  );
}
