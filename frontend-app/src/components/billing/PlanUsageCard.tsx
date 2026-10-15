import { Gauge } from 'lucide-react';
import { PLAN_LIMIT_LABELS, type PlanLimits } from '../../types/account';
import type { PlanUsage } from '../../types/billing';

const ORDER: (keyof PlanLimits)[] = [
  'max_users',
  'max_meta_templates',
  'max_flows',
  'max_broadcasts_per_month',
  'marketing_message_limit',
];

function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
}

/**
 * Plan restructure (2026-10-15) — the running plan's limits and how much of
 * each is used (GET /api/billing/plans → plan_usage). Unlimited limits are
 * left out; a limit of 0 reads "Not in your plan".
 */
export default function PlanUsageCard({ usage }: { usage: PlanUsage }) {
  const rows = ORDER.filter((key) => usage[key] && usage[key].limit !== null);
  if (rows.length === 0) return null;

  return (
    <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm" data-testid="plan-usage">
      <div className="flex items-center gap-2">
        <Gauge className="h-4 w-4 text-emerald-700" />
        <h2 className="text-base font-semibold text-slate-900">Plan usage</h2>
      </div>
      <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {rows.map((key) => {
          const { limit, used, resets_at: resetsAt } = usage[key];
          const max = limit ?? 0;
          const pct = max > 0 ? Math.min(100, Math.round((used / max) * 100)) : 100;
          const full = used >= max;
          return (
            <div key={key}>
              <div className="flex items-baseline justify-between gap-2 text-sm">
                <span className="text-slate-600">{PLAN_LIMIT_LABELS[key]}</span>
                <span className={`font-semibold ${full ? 'text-red-700' : 'text-slate-900'}`}>
                  {max === 0 ? 'Not in your plan' : `${used.toLocaleString('en-IN')} / ${max.toLocaleString('en-IN')}`}
                </span>
              </div>
              {max > 0 && (
                <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-label={PLAN_LIMIT_LABELS[key]} aria-valuenow={used} aria-valuemax={max}>
                  <div className={`h-full rounded-full ${full ? 'bg-red-500' : pct >= 80 ? 'bg-amber-500' : 'bg-emerald-600'}`} style={{ width: `${pct}%` }} />
                </div>
              )}
              {resetsAt && max > 0 && <p className="mt-1 text-xs text-slate-500">Renews on {formatDate(resetsAt)}</p>}
            </div>
          );
        })}
      </div>
    </section>
  );
}
