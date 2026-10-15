import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { calculatePlanPrice, type PricingInput } from './planPricing';
import { DEFAULT_PRICES } from './planPricing.fixtures';
import PlanPriceCalculator from './PlanPriceCalculator';

const noLimits = { max_users: null, max_meta_templates: null, max_flows: null, max_broadcasts_per_month: null, marketing_message_limit: null };

const crmOnly: PricingInput = {
  engine_type: 'none',
  billing_model: 'unlimited',
  total_allocated_messages: null,
  duration_days: 30,
  capabilities: ['crm'],
  limits: { ...noLimits, max_users: 3 },
};

const metaGrowth: PricingInput = {
  engine_type: 'meta',
  billing_model: 'flat_quota',
  total_allocated_messages: 5000,
  duration_days: 30,
  capabilities: ['whatsapp_send', 'journey_automation', 'external_api'],
  limits: { max_users: 3, max_meta_templates: 10, max_flows: 3, max_broadcasts_per_month: 0, marketing_message_limit: 2500 },
};

describe('calculatePlanPrice', () => {
  it('prices a CRM-only plan line by line: platform + CRM + one extra user', () => {
    const r = calculatePlanPrice(crmOnly, DEFAULT_PRICES);

    expect(r.lines.map((l) => [l.key, l.amount])).toEqual([
      ['base', 299],
      ['feature:crm', 500],
      ['users', 100],
    ]);
    expect(r.suggested).toBe(899);
    expect(r.metaCost).toBe(0);
  });

  it('prices Meta messages, marketing separately, and shows Meta’s worst-case cost', () => {
    const r = calculatePlanPrice(metaGrowth, DEFAULT_PRICES);

    const line = (key: string) => r.lines.find((l) => l.key === key)?.amount;
    expect(line('messages')).toBe(500); // 2,500 × ₹0.20
    expect(line('marketing')).toBe(2750); // 2,500 × ₹1.10
    expect(line('feature:journey_automation')).toBe(300);
    expect(line('journeys')).toBe(150);
    expect(r.lines.some((l) => l.key === 'feature:whatsapp_send')).toBe(false); // part of the engine
    expect(r.lines.some((l) => l.key === 'broadcasts')).toBe(false); // 0 broadcasts = nothing to price
    expect(r.suggested).toBe(4798);
    // (2,500 × 0.8631 + 2,500 × 0.115) × 1.18
    expect(r.metaCost).toBe(2885);
  });

  it('adding a feature or raising a limit raises the price; removing lowers it', () => {
    const base = calculatePlanPrice(metaGrowth, DEFAULT_PRICES).suggested;
    const withAds = calculatePlanPrice({ ...metaGrowth, capabilities: [...metaGrowth.capabilities, 'ads'] }, DEFAULT_PRICES).suggested;
    const moreUsers = calculatePlanPrice({ ...metaGrowth, limits: { ...metaGrowth.limits, max_users: 10 } }, DEFAULT_PRICES).suggested;
    const noJourneys = calculatePlanPrice({ ...metaGrowth, capabilities: ['whatsapp_send', 'external_api'] }, DEFAULT_PRICES).suggested;

    expect(withAds - base).toBe(500);
    expect(moreUsers - base).toBe(700);
    expect(noJourneys).toBe(base - 300 - 150);
  });

  it('warns when Meta messages are unlimited or marketing is uncapped', () => {
    expect(calculatePlanPrice({ ...metaGrowth, billing_model: 'unlimited' }, DEFAULT_PRICES).warnings[0]).toMatch(/cannot be priced/);
    const uncapped = calculatePlanPrice({ ...metaGrowth, limits: { ...metaGrowth.limits, marketing_message_limit: null } }, DEFAULT_PRICES);
    expect(uncapped.warnings[0]).toMatch(/No marketing cap/);
    expect(uncapped.lines.find((l) => l.key === 'marketing')?.amount).toBe(5500);
  });

  it('scales monthly lines by duration but not the message quota', () => {
    const r = calculatePlanPrice({ ...metaGrowth, duration_days: 60 }, DEFAULT_PRICES);
    // 4,798 = 3,250 messages (whole term) + 1,548 monthly → 3,250 + 2 × 1,548
    expect(r.suggested).toBe(3250 + 2 * 1548);
  });
});

describe('PlanPriceCalculator', () => {
  it('shows the breakdown, uses the suggested price and shows the margin at the typed price', async () => {
    const user = userEvent.setup();
    const onUsePrice = vi.fn();
    const { rerender } = render(
      <PlanPriceCalculator input={metaGrowth} prices={DEFAULT_PRICES} currentPrice={null} onUsePrice={onUsePrice} onEditRates={vi.fn()} />,
    );

    expect(screen.getByTestId('price-suggested').textContent).toBe('₹4,798');
    expect(within(screen.getByTestId('price-line-marketing')).getByText('₹2,750')).toBeTruthy();
    await user.click(screen.getByRole('button', { name: 'Use this price' }));
    expect(onUsePrice).toHaveBeenCalledWith(4798);

    rerender(<PlanPriceCalculator input={metaGrowth} prices={DEFAULT_PRICES} currentPrice={4999} onUsePrice={onUsePrice} onEditRates={vi.fn()} />);
    expect(screen.getByTestId('price-margin').textContent).toContain('₹2,114 (42%)');

    rerender(<PlanPriceCalculator input={metaGrowth} prices={DEFAULT_PRICES} currentPrice={2500} onUsePrice={onUsePrice} onEditRates={vi.fn()} />);
    expect(screen.getByTestId('price-margin').textContent).toContain('-₹385');
    expect(screen.getByText(/below the suggested price/)).toBeTruthy();
  });
});
