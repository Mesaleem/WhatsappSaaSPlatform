import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi, beforeEach } from 'vitest';

const auth = { superAdmin: false };
vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({ isSuperAdmin: () => auth.superAdmin, user: { id: 1 } }),
}));
const status = vi.fn();
vi.mock('../../services/aiCreditService', () => ({
  default: { status: () => status() },
  AI_CREDITS_CHANGED_EVENT: 'ai-credits-changed',
}));

import AiCreditChip from './AiCreditChip';

const renderChip = () =>
  render(
    <MemoryRouter>
      <AiCreditChip />
    </MemoryRouter>,
  );

describe('AiCreditChip', () => {
  beforeEach(() => {
    auth.superAdmin = false;
    status.mockReset();
  });

  it("shows today's free credits and the wallet", async () => {
    status.mockResolvedValue({ unlimited: false, daily: { per_day: 10, used: 2, remaining: 8, resets_at: '' }, wallet_available: 120 });
    renderChip();

    const chip = await screen.findByLabelText(/8 of 10 free credits left today/);
    expect(chip.textContent).toContain('8/10');
    expect(chip.textContent).toContain('120');
  });

  it('re-reads the balance after an AI action', async () => {
    status.mockResolvedValue({ unlimited: false, daily: { per_day: 10, used: 0, remaining: 10, resets_at: '' }, wallet_available: 0 });
    renderChip();
    await screen.findByLabelText(/10 of 10/);

    status.mockResolvedValue({ unlimited: false, daily: { per_day: 10, used: 3, remaining: 7, resets_at: '' }, wallet_available: 0 });
    window.dispatchEvent(new Event('ai-credits-changed'));

    await screen.findByLabelText(/7 of 10/);
  });

  it('is hidden for the Super Admin (unlimited)', async () => {
    auth.superAdmin = true;
    const { container } = renderChip();

    await waitFor(() => expect(container.textContent).toBe(''));
    expect(status).not.toHaveBeenCalled();
  });
});
