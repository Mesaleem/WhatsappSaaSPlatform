import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import WebsiteContentPage from './WebsiteContentPage';
import publicSiteService from '../../services/publicSiteService';

vi.mock('../../services/publicSiteService', () => ({
  default: { adminContent: vi.fn(), saveSection: vi.fn(), resetSection: vi.fn(), messages: vi.fn(), setHandled: vi.fn() },
}));

const content = {
  brand: { name: 'Old name', signup_enabled: true, trial_days: 7 },
  home: {}, features: {}, about: { title: 'About us', values: [{ title: 'Simple', description: 'Easy' }] }, pricing: {}, contact: {}, faq: {}, legal: {},
};

describe('Website Content (Super Admin)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    (publicSiteService.adminContent as Mock).mockResolvedValue({ data: content, defaults: content, customized: [] });
    (publicSiteService.messages as Mock).mockResolvedValue({ data: [], current_page: 1, last_page: 1, total: 0, open_count: 2 });
    (publicSiteService.saveSection as Mock).mockImplementation((_key: string, value: unknown) => Promise.resolve({ message: 'Saved.', data: value }));
  });

  it('edits a brand field and saves the whole section', async () => {
    const user = userEvent.setup();
    render(<MemoryRouter><WebsiteContentPage /></MemoryRouter>);

    const name = await screen.findByLabelText('Name');
    await user.clear(name);
    await user.type(name, 'New name');
    await user.click(screen.getByLabelText('Signup enabled'));
    await user.click(screen.getByRole('button', { name: /Save section/ }));

    expect(publicSiteService.saveSection).toHaveBeenCalledWith('brand', { name: 'New name', signup_enabled: false, trial_days: 7 });
    expect(await screen.findByText(/Saved\. The website shows the new content now\./)).toBeTruthy();
    expect(screen.getByText('Customised')).toBeTruthy();
  });

  it('adds an item to a list using the shape of the existing items', async () => {
    const user = userEvent.setup();
    render(<MemoryRouter><WebsiteContentPage /></MemoryRouter>);

    await user.click(await screen.findByRole('tab', { name: /About page/ }));
    await user.click(screen.getByRole('button', { name: /Add item/ }));
    await user.click(screen.getByRole('button', { name: /Save section/ }));

    expect(publicSiteService.saveSection).toHaveBeenCalledWith('about', {
      title: 'About us',
      values: [{ title: 'Simple', description: 'Easy' }, { title: '', description: '' }],
    });
  });

  it('shows the open contact-message count on the Messages tab', async () => {
    render(<MemoryRouter><WebsiteContentPage /></MemoryRouter>);
    const tab = await screen.findByRole('tab', { name: /Messages/ });
    expect(await screen.findByText('2')).toBeTruthy();
    expect(tab).toBeTruthy();
  });
});
