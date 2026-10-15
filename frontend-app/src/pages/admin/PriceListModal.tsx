import { useState } from 'react';
import { Loader2, X } from 'lucide-react';
import planService from '../../services/planService';
import { inputClass } from '../../components/common/Card';
import { extractErrorMessage } from '../../utils/apiError';
import type { CustomPlanPriceList, PlanCapabilityOption, PlanEngineType } from '../../types/plan';

type NumberKey = Exclude<keyof CustomPlanPriceList, 'engine' | 'features'>;

const GROUPS: { title: string; fields: { key: NumberKey; label: string; step?: string }[] }[] = [
  {
    title: 'Platform & users',
    fields: [
      { key: 'base_fee', label: 'Platform fee / month' },
      { key: 'base_users_included', label: 'Users included in the fee', step: '1' },
      { key: 'per_user', label: 'Each extra user' },
      { key: 'unlimited_users', label: 'Unlimited users' },
    ],
  },
  {
    title: 'Meta messages (price per message)',
    fields: [
      { key: 'per_message', label: 'Utility / OTP / reply message', step: '0.01' },
      { key: 'per_marketing_message', label: 'Marketing message', step: '0.01' },
    ],
  },
  {
    title: 'Limits',
    fields: [
      { key: 'per_template', label: 'Each template' },
      { key: 'unlimited_templates', label: 'Unlimited templates' },
      { key: 'per_journey', label: 'Each journey / flow' },
      { key: 'unlimited_journeys', label: 'Unlimited journeys' },
      { key: 'per_broadcast', label: 'Each broadcast / month' },
      { key: 'unlimited_broadcasts', label: 'Unlimited broadcasts' },
    ],
  },
  {
    title: "Meta's charge to us (per message, before GST)",
    fields: [
      { key: 'meta_marketing_cost', label: 'Marketing', step: '0.0001' },
      { key: 'meta_utility_cost', label: 'Utility / authentication', step: '0.0001' },
      { key: 'gst_percent', label: 'GST %', step: '0.01' },
    ],
  },
];

const ENGINES: { key: PlanEngineType; label: string }[] = [
  { key: 'qr', label: 'QR (Baileys) — unlimited messages' },
  { key: 'meta', label: 'Meta Cloud API' },
  { key: 'none', label: 'No WhatsApp' },
];

/** Super Admin: the rates the Plans price calculator uses (owner, 2026-10-15). */
export default function PriceListModal({
  prices,
  capabilityOptions,
  onClose,
  onSaved,
}: {
  prices: CustomPlanPriceList;
  capabilityOptions: PlanCapabilityOption[];
  onClose: () => void;
  onSaved: (prices: CustomPlanPriceList) => void;
}) {
  const [draft, setDraft] = useState<CustomPlanPriceList>(() => structuredClone(prices));
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const num = (v: string) => (v.trim() === '' ? 0 : Number(v));

  const save = async () => {
    setSaving(true);
    setError(null);
    try {
      const res = await planService.updatePricing(draft);
      onSaved(res.data);
    } catch (err) {
      setError(extractErrorMessage(err, 'Could not save the rates.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/50 p-4" role="dialog" aria-modal="true" aria-label="Price list">
      <div className="flex max-h-[90vh] w-full max-w-3xl flex-col rounded-2xl bg-white shadow-xl">
        <div className="flex items-start justify-between border-b border-slate-200 px-5 py-4">
          <div>
            <h2 className="font-display text-base font-bold text-slate-900">Price list for custom plans</h2>
            <p className="text-xs text-slate-500">Monthly ₹ per item. The calculator adds these up; the price you save on a plan is what the client pays.</p>
          </div>
          <button onClick={onClose} className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100" aria-label="Close">
            <X className="h-4 w-4" />
          </button>
        </div>

        <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-4">
          {error && <p className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{error}</p>}

          <section>
            <h3 className="text-xs font-bold uppercase tracking-wide text-slate-500">WhatsApp engine</h3>
            <div className="mt-2 grid gap-3 sm:grid-cols-3">
              {ENGINES.map((e) => (
                <label key={e.key} className="block text-xs font-medium text-slate-700">
                  {e.label}
                  <input
                    type="number"
                    min={0}
                    className={inputClass}
                    aria-label={'Engine ' + e.key}
                    value={draft.engine[e.key] ?? 0}
                    onChange={(ev) => setDraft((d) => ({ ...d, engine: { ...d.engine, [e.key]: num(ev.target.value) } }))}
                  />
                </label>
              ))}
            </div>
          </section>

          <section>
            <h3 className="text-xs font-bold uppercase tracking-wide text-slate-500">Features</h3>
            <div className="mt-2 grid gap-3 sm:grid-cols-3">
              {capabilityOptions
                .filter((c) => c.slug !== 'whatsapp_send')
                .map((c) => (
                  <label key={c.slug} className="block text-xs font-medium text-slate-700">
                    {c.label}
                    <input
                      type="number"
                      min={0}
                      className={inputClass}
                      aria-label={'Feature ' + c.slug}
                      value={draft.features[c.slug] ?? 0}
                      onChange={(ev) => setDraft((d) => ({ ...d, features: { ...d.features, [c.slug]: num(ev.target.value) } }))}
                    />
                  </label>
                ))}
            </div>
          </section>

          {GROUPS.map((group) => (
            <section key={group.title}>
              <h3 className="text-xs font-bold uppercase tracking-wide text-slate-500">{group.title}</h3>
              <div className="mt-2 grid gap-3 sm:grid-cols-3">
                {group.fields.map((f) => (
                  <label key={f.key} className="block text-xs font-medium text-slate-700">
                    {f.label}
                    <input
                      type="number"
                      min={0}
                      step={f.step ?? '1'}
                      className={inputClass}
                      aria-label={f.label}
                      value={draft[f.key]}
                      onChange={(ev) => setDraft((d) => ({ ...d, [f.key]: num(ev.target.value) }))}
                    />
                  </label>
                ))}
              </div>
            </section>
          ))}
        </div>

        <div className="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
          <button onClick={onClose} className="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100">
            Cancel
          </button>
          <button
            onClick={() => void save()}
            disabled={saving}
            className="flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60"
          >
            {saving && <Loader2 className="h-4 w-4 animate-spin" />}
            Save rates
          </button>
        </div>
      </div>
    </div>
  );
}
