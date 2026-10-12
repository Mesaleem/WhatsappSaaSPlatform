import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import AdminSocialSettingsPage from './AdminSocialSettingsPage';
import socialGatewayService from '../../services/socialGatewayService';
import type { SocialProviderConfigRow } from '../../types/social';

vi.mock('../../services/socialGatewayService', () => ({ default: { list: vi.fn(), update: vi.fn() } }));

const meta: SocialProviderConfigRow = {
  provider: 'meta',
  is_active: true,
  is_fully_configured: true,
  client_id_set: true,
  client_secret_set: true,
  redirect_uri: 'https://api.example.test/api/social/callback/meta',
  webhook_verify_token_set: true,
  webhook_url: 'https://api.example.test/api/social/webhook/meta',
  scope_groups: [],
  available_scope_groups: [
    { key: 'publishing_scopes', label: 'Publish posts & reels (Facebook Page + Instagram)', permissions: ['pages_manage_posts', 'instagram_basic', 'instagram_content_publish'] },
    { key: 'leads_scopes', label: 'Lead Ads (lead forms)', permissions: ['leads_retrieval', 'pages_manage_metadata'] },
  ],
};

describe('Social Gateway Settings — extra permissions', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    (socialGatewayService.list as Mock).mockResolvedValue([meta]);
    (socialGatewayService.update as Mock).mockResolvedValue({ message: 'ok', data: { ...meta, scope_groups: ['publishing_scopes'] } });
  });

  it('lets the Super Admin switch the publishing permissions on and saves them', async () => {
    const user = userEvent.setup();
    render(<MemoryRouter><AdminSocialSettingsPage /></MemoryRouter>);

    const groups = await screen.findByTestId('scope-groups-meta');
    expect(within(groups).getByText('pages_manage_posts, instagram_basic, instagram_content_publish')).toBeTruthy();

    await user.click(within(groups).getByLabelText(/Publish posts & reels/));
    await user.click(screen.getByRole('button', { name: /Save .* Settings/ }));

    expect(socialGatewayService.update).toHaveBeenCalledWith('meta', expect.objectContaining({ scope_groups: ['publishing_scopes'] }));
  });
});
