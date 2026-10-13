import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import ModuleAddonCard from './ModuleAddonCard';
import moduleAddonService, { type ModuleAddonOffer, type ModuleAddonRow } from '../../services/moduleAddonService';

vi.mock('../../services/moduleAddonService', () => ({ default: { list: vi.fn(), request: vi.fn() } }));

const native: ModuleAddonOffer = {
  module: 'whatsapp_groups', label: 'Native WhatsApp Groups', price: 99, term_months: 1, units_included: 1, is_active: true, is_free: false,
  tiers: [
    { from_units: 1, to_units: 5, price: 99 },
    { from_units: 6, to_units: null, price: 199 },
  ],
};

function list(offers: ModuleAddonOffer[], rows: ModuleAddonRow[] = []) {
  (moduleAddonService.list as Mock).mockResolvedValue({ offers, data: rows });
}

const renderCard = (props: Partial<React.ComponentProps<typeof ModuleAddonCard>> = {}) =>
  render(
    <MemoryRouter>
      <ModuleAddonCard module="whatsapp_groups" enabled={false} unitLabel="native groups" {...props} />
    </MemoryRouter>,
  );

describe('ModuleAddonCard', () => {
  beforeEach(() => vi.clearAllMocks());

  it('offers native groups to an account whose plan does not include them, priced by tier', async () => {
    list([native]);
    (moduleAddonService.request as Mock).mockResolvedValue({ message: 'Request sent.' });
    const user = userEvent.setup();
    renderCard();

    expect(await screen.findByText('Native WhatsApp Groups is a paid add-on')).toBeTruthy();
    const qty = screen.getByRole('spinbutton');
    await user.clear(qty);
    await user.type(qty, '7');
    expect(screen.getByText('₹199.00')).toBeTruthy();
    await user.click(screen.getByRole('button', { name: 'Request add-on' }));
    expect(moduleAddonService.request).toHaveBeenCalledWith('whatsapp_groups', undefined, undefined, 7);
  });

  it('stays hidden when the plan already includes it or the Super Admin made it free', async () => {
    list([native]);
    const { container } = renderCard({ includedInPlan: true });
    await new Promise((r) => setTimeout(r, 0));
    expect(container.innerHTML).toBe('');

    list([{ ...native, is_free: true }]);
    const second = renderCard();
    await new Promise((r) => setTimeout(r, 0));
    expect(second.container.innerHTML).toBe('');
  });

  it('turns into an upgrade while a paid term is running', async () => {
    const future = new Date(Date.now() + 10 * 86400000).toISOString();
    list([native], [{ id: 1, module: 'whatsapp_groups', label: 'x', status: 'paid', reason: null, invoice_id: 3, total_amount: 99, units: 5, term_starts_at: null, term_ends_at: future, created_at: null }]);
    renderCard({ includedInPlan: true });

    expect(await screen.findByText('Need more native groups?')).toBeTruthy();
    expect(screen.getByText(/Your current term includes/).textContent).toContain('5 native groups');
    expect((screen.getByRole('spinbutton') as HTMLInputElement).value).toBe('6');
    expect(screen.getByRole('button', { name: 'Request upgrade' })).toBeTruthy();
  });
});
