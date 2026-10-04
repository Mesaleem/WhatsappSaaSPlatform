import { render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import MyTemplatesModal from './MyTemplatesModal';
import templateService from '../../services/templateService';

vi.mock('../../services/templateService', () => ({ default: { mine: vi.fn() } }));
const svc = vi.mocked(templateService);

const row = (id: number, title: string, is_global: boolean) => ({
  id, title, template_code: `T_${id}`, industry_type: null, status: 'approved' as const, rejection_reason: null, created_at: '2026-10-01T00:00:00Z', is_global,
});

beforeEach(() => vi.clearAllMocks());

describe('MyTemplatesModal', () => {
  it('groups Global Templates and My Templates', async () => {
    svc.mine.mockResolvedValue([row(1, 'Welcome Customer', true), row(2, 'Vihan Reminder', false)]);
    render(<MyTemplatesModal onClose={() => undefined} />);

    const global = await screen.findByRole('region', { name: 'Global Templates' });
    const mine = screen.getByRole('region', { name: 'My Templates' });
    expect(within(global).getByText('Welcome Customer')).toBeInTheDocument();
    expect(within(global).getByText('Shared by the platform')).toBeInTheDocument();
    expect(within(mine).getByText('Vihan Reminder')).toBeInTheDocument();
    expect(within(mine).queryByText('Welcome Customer')).not.toBeInTheDocument();
  });

  it('omits an empty group', async () => {
    svc.mine.mockResolvedValue([row(2, 'Only Mine', false)]);
    render(<MyTemplatesModal onClose={() => undefined} />);
    await screen.findByText('Only Mine');
    expect(screen.queryByRole('region', { name: 'Global Templates' })).not.toBeInTheDocument();
  });
});
