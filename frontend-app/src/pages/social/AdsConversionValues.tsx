import { useEffect, useRef, useState } from 'react';
import { AlertCircle } from 'lucide-react';
import { TableCard, inputClass } from '../../components/common/Card';
import { useCrmQuery } from '../../components/crm/crmHooks';
import adsConversionValueService, { type ConvertedAttribution } from '../../services/adsConversionValueService';
import { formatAdMoney } from '../../types/ads';
import { describeApiError } from '../../utils/apiError';
import DismissibleAlert from '../../components/common/DismissibleAlert';

/**
 * Phase 10 Task 5 — converted, ad-attributed leads and their conversion value.
 * A value is optional: "Not recorded" (null) is never shown as 0, while a recorded 0 is shown as 0.
 * Editing is offered only to users the backend will accept (CRM + Ads); the backend re-checks everything.
 * Every request is keyed by client, so switching client clears the list and a late answer for the
 * previous client is discarded (shared useCrmQuery loader); a save that finishes after a client
 * switch never touches the new client's view.
 */
export default function AdsConversionValues({
  accountKey,
  canEdit,
  defaultCurrency,
  onChanged,
}: {
  accountKey: number | null;
  canEdit: boolean;
  defaultCurrency: string | null;
  onChanged: () => void;
}) {
  const { data, error, isLoading, reload } = useCrmQuery<ConvertedAttribution[]>(
    JSON.stringify({ account: accountKey, converted: true }),
    () => adsConversionValueService.listConverted(),
    'Failed to load converted leads.',
  );

  const [editing, setEditing] = useState<number | null>(null);
  const [amount, setAmount] = useState('');
  const [currency, setCurrency] = useState('');
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  // The page mounts this with key = client, so a client switch remounts it: the list, any half-typed
  // edit and any in-flight save belong to the previous client and are dropped with the old instance.
  const mounted = useRef(true);
  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
    };
  }, []);

  const open = (row: ConvertedAttribution) => {
    setEditing(row.id);
    setAmount(row.conversion_value === null ? '' : String(row.conversion_value));
    setCurrency(row.conversion_currency ?? defaultCurrency ?? 'INR');
    setFormError(null);
  };

  const save = async (row: ConvertedAttribution, clear: boolean) => {
    if (row.crm_lead_id === null) return;
    const trimmed = amount.trim();
    const numeric = Number(trimmed);
    if (!clear) {
      if (trimmed === '' || !Number.isFinite(numeric) || numeric < 0) return setFormError('Enter a value of 0 or more.');
      if (!/^\d+(\.\d{1,2})?$/.test(trimmed)) return setFormError('Use at most 2 decimal places.');
      if (!/^[A-Za-z]{3}$/.test(currency.trim())) return setFormError('Enter a 3-letter currency code, e.g. INR.');
    }
    setBusy(true);
    setFormError(null);
    try {
      await adsConversionValueService.setValue(row.crm_lead_id, clear ? null : numeric, clear ? null : currency.trim().toUpperCase());
      if (!mounted.current) return; // the client changed while saving
      setEditing(null);
      reload();
      onChanged();
    } catch (err) {
      if (!mounted.current) return;
      setFormError(describeApiError(err, 'The conversion value could not be saved.').message);
    } finally {
      if (mounted.current) setBusy(false);
    }
  };

  return (
    <section data-testid="ads-conversion-values">
      <h2 className="mb-2 text-base font-semibold text-slate-900">Conversion values</h2>
      <p className="mb-2 text-xs text-slate-500">
        Converted leads that came from your ads. A value is optional — until one is recorded it stays "Not recorded" (never 0), and ROAS is only shown when values exist.
      </p>
      {error && (
        <DismissibleAlert className="mb-2 flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
          <AlertCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
          {error}
        </DismissibleAlert>
      )}
      <TableCard>
        <table className="min-w-full divide-y divide-slate-200 text-sm" data-testid="ads-conversion-table">
          <thead className="bg-slate-50">
            <tr>
              {['Lead', 'Campaign / ad', 'Converted', 'Value', ''].map((h) => (
                <th key={h || 'actions'} className="whitespace-nowrap px-4 py-3 text-left font-semibold text-slate-600">
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading ? (
              <tr>
                <td colSpan={5} className="px-4 py-6 text-center text-slate-500">
                  Loading…
                </td>
              </tr>
            ) : !data || data.length === 0 ? (
              <tr>
                <td colSpan={5} className="px-4 py-6 text-center text-slate-500" data-testid="ads-conversion-empty">
                  No converted leads from ads yet.
                </td>
              </tr>
            ) : (
              data.map((row) => (
                <tr key={row.id} data-testid={`conversion-row-${row.id}`}>
                  <td className="px-4 py-3 text-slate-700">{row.crm_lead_id !== null ? `#${row.crm_lead_id}` : '—'}{row.contact_phone ? <span className="ml-2 text-xs text-slate-500">{row.contact_phone}</span> : null}</td>
                  <td className="px-4 py-3 text-slate-700">{row.campaign?.name ?? row.source_id ?? '—'}</td>
                  <td className="px-4 py-3 text-slate-700">{row.converted_at ? row.converted_at.slice(0, 10) : '—'}</td>
                  <td className="px-4 py-3 text-slate-700" data-testid={`conversion-value-${row.id}`}>
                    {editing === row.id ? (
                      <div className="flex flex-wrap items-center gap-2">
                        <input aria-label="Conversion value" type="number" min="0" step="0.01" className={`${inputClass} w-28`} value={amount} onChange={(e) => setAmount(e.target.value)} />
                        <input aria-label="Currency" maxLength={3} className={`${inputClass} w-16 uppercase`} value={currency} onChange={(e) => setCurrency(e.target.value)} />
                      </div>
                    ) : row.conversion_value === null ? (
                      <span className="text-slate-400">Not recorded</span>
                    ) : (
                      formatAdMoney(row.conversion_value, row.conversion_currency)
                    )}
                    {editing === row.id && formError && (
                      <DismissibleAlert as="p" className="mt-1 text-xs text-red-600 flex items-start justify-between gap-2" role="alert">
                        {formError}
                      </DismissibleAlert>
                    )}
                  </td>
                  <td className="px-4 py-3 text-right">
                    {canEdit && row.crm_lead_id !== null && (
                      editing === row.id ? (
                        <span className="flex justify-end gap-2">
                          <button type="button" disabled={busy} onClick={() => save(row, false)} className="rounded-md bg-indigo-600 px-3 py-1 text-xs font-semibold text-white disabled:opacity-50">
                            Save
                          </button>
                          {row.conversion_value !== null && (
                            <button type="button" disabled={busy} onClick={() => save(row, true)} className="rounded-md border border-slate-300 px-3 py-1 text-xs font-semibold text-slate-700 disabled:opacity-50">
                              Clear value
                            </button>
                          )}
                          <button type="button" disabled={busy} onClick={() => setEditing(null)} className="rounded-md px-3 py-1 text-xs font-semibold text-slate-600">
                            Cancel
                          </button>
                        </span>
                      ) : (
                        <button type="button" onClick={() => open(row)} className="rounded-md border border-slate-300 px-3 py-1 text-xs font-semibold text-slate-700">
                          {row.conversion_value === null ? 'Add value' : 'Edit value'}
                        </button>
                      )
                    )}
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </TableCard>
      {!canEdit && <p className="mt-2 text-xs text-slate-500" data-testid="ads-conversion-readonly">Recording a value needs CRM access in addition to Ads.</p>}
    </section>
  );
}
