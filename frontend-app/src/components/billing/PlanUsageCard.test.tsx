import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import PlanUsageCard from './PlanUsageCard';
import type { PlanUsage } from '../../types/billing';

const usage: PlanUsage = {
  max_users: { limit: 5, used: 2 },
  max_meta_templates: { limit: 30, used: 30 },
  max_flows: { limit: null, used: 4 },
  max_broadcasts_per_month: { limit: 0, used: 0, resets_at: null },
  marketing_message_limit: { limit: 4000, used: 1200, resets_at: '2026-11-13T00:00:00+05:30' },
};

describe('PlanUsageCard', () => {
  it('lists each limited item with its use, skips unlimited ones and marks a full one', () => {
    render(<PlanUsageCard usage={usage} />);

    expect(screen.getByText('2 / 5')).toBeTruthy();
    expect(screen.getByText('30 / 30').className).toContain('text-red-700');
    expect(screen.getByText('Not in your plan')).toBeTruthy();
    expect(screen.getByText('1,200 / 4,000')).toBeTruthy();
    expect(screen.getByText(/Renews on/)).toBeTruthy();
    // Journeys are unlimited on this plan: not listed.
    expect(screen.queryByText('Journeys / Flows')).toBeNull();
  });

  it('renders nothing when every limit is unlimited', () => {
    const unlimited = Object.fromEntries(Object.keys(usage).map((k) => [k, { limit: null, used: 0 }])) as PlanUsage;
    const { container } = render(<PlanUsageCard usage={unlimited} />);
    expect(container.innerHTML).toBe('');
  });
});
