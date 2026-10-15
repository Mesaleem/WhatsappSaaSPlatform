import { AlertTriangle, Calculator, Settings2 } from 'lucide-react';
import { calculatePlanPrice, type PricingInput } from './planPricing';
import type { CustomPlanPriceList } from '../../types/plan';

const inr = (n: number) => (n < 0 ? '-₹' : '₹') + Math.abs(n).toLocaleString('en-IN', { maximumFractionDigits: 2 });

/**
 * Live price calculator in the Plans form (owner, 2026-10-15): each feature
 * and limit the Super Admin gives adds its line, the suggested price follows,
 * and — on the Meta engine — what Meta could charge us and the margin left at
 * the price actually entered.
 */
export default function PlanPriceCalculator({
  input,
  prices,
  currentPrice,
  onUsePrice,
  onEditRates,
}: {
  input: PricingInput;
  prices: CustomPlanPriceList | null;
  /** The price typed in the form (NaN/empty = none yet). */
  currentPrice: number | null;
  onUsePrice: (price: number) => void;
  onEditRates: () => void;
}) {
  if (!prices) {
    return <p className="text-xs text-slate-400">Loading the price list…</p>;
  }

  const result = calculatePlanPrice(input, prices);
  const price = currentPrice ?? 0;
  const margin = price - result.metaCost;
  const priced = currentPrice !== null && currentPrice > 0;

  return (
    <div className="rounded-xl border border-emerald-200 bg-emerald-50/40 p-4" data-testid="price-calculator">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="flex items-center gap-2 text-sm font-semibold text-slate-900">
          <Calculator className="h-4 w-4 text-emerald-700" />
          Price calculator
        </h3>
        <button
          type="button"
          onClick={onEditRates}
          className="flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50"
        >
          <Settings2 className="h-3.5 w-3.5" />
          Edit rates
        </button>
      </div>

      <table className="mt-3 w-full text-xs">
        <tbody className="divide-y divide-emerald-100">
          {result.lines.map((line) => (
            <tr key={line.key} data-testid={'price-line-' + line.key}>
              <td className="py-1.5 pr-2 font-medium text-slate-700">{line.label}</td>
              <td className="py-1.5 pr-2 text-slate-500">{line.detail}</td>
              <td className="py-1.5 text-right font-semibold text-slate-900">{line.amount === 0 ? '—' : inr(line.amount)}</td>
            </tr>
          ))}
        </tbody>
      </table>

      {result.warnings.map((w) => (
        <p key={w} className="mt-2 flex items-start gap-1.5 text-xs text-amber-700">
          <AlertTriangle className="mt-0.5 h-3.5 w-3.5 flex-shrink-0" />
          {w}
        </p>
      ))}

      <div className="mt-3 flex flex-wrap items-end justify-between gap-3 border-t border-emerald-200 pt-3">
        <div>
          <p className="text-xs text-slate-500">
            Suggested price{input.duration_days !== 30 ? ` for ${input.duration_days} days` : ' / month'}
          </p>
          <p className="font-display text-2xl font-bold text-slate-900" data-testid="price-suggested">
            {inr(result.suggested)}
          </p>
        </div>
        <button
          type="button"
          onClick={() => onUsePrice(result.suggested)}
          disabled={priced && price === result.suggested}
          className="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
        >
          Use this price
        </button>
      </div>

      {input.engine_type === 'meta' && result.metaCost > 0 && (
        <div className="mt-3 grid grid-cols-2 gap-2 text-xs" data-testid="price-margin">
          <div className="rounded-lg bg-white px-3 py-2 ring-1 ring-slate-200">
            <p className="text-slate-500">Meta cost (worst case, incl. GST)</p>
            <p className="font-semibold text-slate-900">{inr(result.metaCost)}</p>
          </div>
          <div className={`rounded-lg px-3 py-2 ring-1 ${!priced ? 'bg-white ring-slate-200' : margin < 0 ? 'bg-red-50 ring-red-200' : 'bg-white ring-emerald-200'}`}>
            <p className="text-slate-500">Margin at {priced ? inr(price) : 'your price'}</p>
            <p className={`font-semibold ${!priced ? 'text-slate-400' : margin < 0 ? 'text-red-700' : 'text-emerald-700'}`}>
              {priced ? `${inr(margin)} (${Math.round((margin / price) * 100)}%)` : 'Enter a price'}
            </p>
          </div>
        </div>
      )}
      {priced && price < result.suggested && (
        <p className="mt-2 text-xs text-slate-500">
          Your price is {inr(result.suggested - price)} below the suggested price ({Math.round(((result.suggested - price) / result.suggested) * 100)}% discount).
        </p>
      )}
    </div>
  );
}
