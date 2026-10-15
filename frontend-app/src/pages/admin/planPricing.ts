import type { PlanLimits } from '../../types/account';
import type { CustomPlanPriceList, PlanEngineType, PlanBillingModel } from '../../types/plan';

/**
 * Custom-plan price calculator (owner, 2026-10-15). Turns the plan being
 * built (engine, messages, features, limits, duration) into a suggested
 * price, line by line, from the Super Admin's price list
 * (GET /api/admin/custom-plan-pricing) — plus what Meta could charge us in
 * the worst case (every allowed marketing message sent), so the margin at
 * the chosen price is visible. Advisory only: the price saved on the plan
 * is what the client pays.
 */

export interface PricingInput {
  engine_type: PlanEngineType;
  billing_model: PlanBillingModel;
  total_allocated_messages: number | null;
  duration_days: number;
  capabilities: string[];
  limits: PlanLimits;
  /** capability slug => label, for the line names. */
  capabilityLabels?: Record<string, string>;
}

export interface PriceLine {
  key: string;
  label: string;
  detail: string;
  amount: number;
  /** true = for the whole plan term (the message quota); false = per 30 days (scaled by the duration). */
  perTerm?: boolean;
}

export interface PriceBreakdown {
  lines: PriceLine[];
  /** Sum of the lines for a 30-day plan. */
  monthly: number;
  /** For the plan's duration, rounded to whole rupees. */
  suggested: number;
  /** Meta's charges to us for the duration if the client uses the whole quota, incl. GST. 0 when not on Meta. */
  metaCost: number;
  warnings: string[];
}

const ENGINE_LABEL: Record<PlanEngineType, string> = {
  qr: 'WhatsApp by QR (unlimited messages)',
  meta: 'Official Meta WhatsApp API',
  none: 'No WhatsApp',
};

const inr = (n: number) => '₹' + n.toLocaleString('en-IN', { maximumFractionDigits: 2 });

export function calculatePlanPrice(input: PricingInput, prices: CustomPlanPriceList): PriceBreakdown {
  const lines: PriceLine[] = [];
  const warnings: string[] = [];
  const add = (key: string, label: string, detail: string, amount: number, perTerm = false) =>
    lines.push({ key, label, detail, amount: Math.round(amount * 100) / 100, perTerm });

  add('base', 'Platform', `Dashboard, billing, team · ${prices.base_users_included} users included`, prices.base_fee);

  const enginePrice = prices.engine[input.engine_type] ?? 0;
  if (input.engine_type !== 'none' || enginePrice > 0) {
    add('engine', 'WhatsApp engine', ENGINE_LABEL[input.engine_type], enginePrice);
  }

  // Meta messages: we pay Meta, so every message is priced; marketing costs Meta far more.
  let metaCost = 0;
  if (input.engine_type === 'meta') {
    const total = input.total_allocated_messages;
    if (input.billing_model === 'unlimited' || total === null) {
      warnings.push('Unlimited Meta messages cannot be priced: Meta charges for every message. Set a message quota.');
    } else {
      const marketing = Math.min(input.limits.marketing_message_limit ?? total, total);
      const other = Math.max(0, total - marketing);
      add('messages', 'Messages', `${other.toLocaleString('en-IN')} × ${inr(prices.per_message)}`, other * prices.per_message, true);
      add('marketing', 'Marketing messages', `${marketing.toLocaleString('en-IN')} × ${inr(prices.per_marketing_message)}`, marketing * prices.per_marketing_message, true);
      if (input.limits.marketing_message_limit === null) {
        warnings.push('No marketing cap: priced as if every message could be marketing. Set a marketing limit to lower the price.');
      }
      const gst = 1 + prices.gst_percent / 100;
      metaCost = (marketing * prices.meta_marketing_cost + other * prices.meta_utility_cost) * gst;
    }
  }

  for (const slug of input.capabilities) {
    if (slug === 'whatsapp_send') continue; // part of the engine
    const amount = prices.features[slug] ?? 0;
    add('feature:' + slug, input.capabilityLabels?.[slug] ?? slug, amount > 0 ? 'Feature' : 'Included', amount);
  }

  const units = (
    key: string,
    label: string,
    value: number | null,
    perUnit: number,
    unlimited: number,
    free = 0,
  ) => {
    if (value === null) {
      add(key, label, 'Unlimited', unlimited);
    } else if (value > free) {
      add(key, label, `${value - free} × ${inr(perUnit)}${free ? ` (after ${free} included)` : ''}`, (value - free) * perUnit);
    }
  };

  units('users', 'Team users', input.limits.max_users, prices.per_user, prices.unlimited_users, prices.base_users_included);
  // Templates and broadcasts cost us money only on Meta; on QR they are included.
  if (input.engine_type === 'meta') {
    units('templates', 'Message templates', input.limits.max_meta_templates, prices.per_template, prices.unlimited_templates);
    units('broadcasts', 'Broadcasts / month', input.limits.max_broadcasts_per_month, prices.per_broadcast, prices.unlimited_broadcasts);
  }
  if (input.capabilities.includes('journey_automation')) {
    units('journeys', 'Journeys / Flows', input.limits.max_flows, prices.per_journey, prices.unlimited_journeys);
  }

  // Features and limits are monthly prices, scaled to the plan's duration; the message quota
  // (and Meta's cost for it) belongs to the whole term.
  const factor = Math.max(1, input.duration_days || 30) / 30;
  const monthlyPart = lines.filter((l) => !l.perTerm).reduce((sum, l) => sum + l.amount, 0);
  const termPart = lines.filter((l) => l.perTerm).reduce((sum, l) => sum + l.amount, 0);

  return {
    lines,
    monthly: Math.round((monthlyPart + termPart) * 100) / 100,
    suggested: Math.round(monthlyPart * factor + termPart),
    metaCost: Math.round(metaCost),
    warnings,
  };
}
