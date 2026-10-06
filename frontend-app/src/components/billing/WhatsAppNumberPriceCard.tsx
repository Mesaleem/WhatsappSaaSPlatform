import { useEffect, useState, type FormEvent } from 'react';
import whatsappService from '../../services/whatsappService';
import { extractErrorMessage } from '../../utils/apiError';

/**
 * Super Admin: the price of one extra WhatsApp number (GST included). New purchases use it;
 * invoices already issued keep the price they were issued at.
 */
export default function WhatsAppNumberPriceCard() {
  const [price, setPrice] = useState<string>('');
  const [saved, setSaved] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    whatsappService
      .addonPrice()
      .then((res) => setPrice(String(res.price)))
      .catch(() => setError('Could not load the extra number price.'));
  }, []);

  const save = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    setBusy(true);
    setSaved(null);
    setError(null);
    try {
      const res = await whatsappService.setAddonPrice(Number(price));
      setSaved(res.message);
    } catch (err) {
      setError(extractErrorMessage(err, 'Could not save the price.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <form onSubmit={(e) => void save(e)} className="mt-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
      <p className="text-sm font-semibold text-slate-900">Extra WhatsApp number price</p>
      <p className="mt-0.5 text-xs text-slate-500">
        Charged per extra number, for one month, GST included. New purchases use this price; invoices already issued keep theirs.
      </p>
      <div className="mt-4 flex flex-wrap items-end gap-3">
        <label className="text-xs font-medium text-slate-700">
          Price per number (₹)
          <span className="text-red-500" aria-hidden="true"> *</span><input
            type="number"
            min={1}
            step="0.01"
            value={price}
            onChange={(e) => setPrice(e.target.value)}
            required
            className="mt-1 block w-40 rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none"
          />
        </label>
        <button
          type="submit"
          disabled={busy || !price}
          className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-60"
        >
          {busy ? 'Saving…' : 'Save price'}
        </button>
      </div>
      {saved && <p className="mt-3 text-sm text-emerald-700">{saved}</p>}
      {error && <p className="mt-3 text-sm text-red-600">{error}</p>}
    </form>
  );
}
