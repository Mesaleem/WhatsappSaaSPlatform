import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';
import AssetSelectionModal from './AssetSelectionModal';
import socialService from '../../services/socialService';
import type { OfferedAsset } from '../../types/social';

vi.mock('../../services/socialService', () => ({
  default: { listMetaBusinesses: vi.fn(), createMetaAdAccount: vi.fn() },
}));

const social = socialService as unknown as Record<string, Mock>;
const page: OfferedAsset = { asset_type: 'facebook_page', provider_id: 'page-1', name: 'Shana Trends', avatar_url: null };
const created: OfferedAsset = { asset_type: 'meta_ad_account', provider_id: 'act_555', name: 'Shana Trends Ads', avatar_url: null };

function Harness({ onConfirm }: { onConfirm: (selected: OfferedAsset[]) => void }) {
  const [assets, setAssets] = useState<OfferedAsset[]>([page]);
  return (
    <AssetSelectionModal
      assets={assets}
      isSubmitting={false}
      errorMessage={null}
      onCancel={() => undefined}
      onConfirm={onConfirm}
      adAccountCreation={{ nonce: 'n-1', onCreated: (asset) => setAssets((prev) => [...prev, asset]) }}
    />
  );
}

describe('Create a Meta ad account while connecting (Ads Phase C)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    social.listMetaBusinesses.mockResolvedValue([{ id: '9001', name: 'Shana Trends' }]);
    social.createMetaAdAccount.mockResolvedValue(created);
  });

  it('creates an ad account in the chosen Business portfolio and connects it with the Page', async () => {
    const onConfirm = vi.fn();
    const user = userEvent.setup();
    render(<Harness onConfirm={onConfirm} />);

    // No ad account was offered, so the form is open straight away; one portfolio is preselected.
    expect(await screen.findByTestId('create-ad-account')).toBeTruthy();
    await waitFor(() => expect(screen.getByLabelText('Business portfolio')).toHaveValue('9001'));
    expect(screen.getByLabelText('Ad account name')).toHaveValue('Shana Trends Ads');
    expect(social.listMetaBusinesses).toHaveBeenCalledWith('n-1');

    await user.click(screen.getByRole('button', { name: 'Create ad account' }));
    const done = await screen.findByTestId('ad-account-created');
    expect(done).toHaveTextContent('INR');
    // Payment steps open straight away, linking to this ad account's billing page.
    expect(screen.getByRole('link', { name: /Open Meta billing/ })).toHaveAttribute('href', 'https://business.facebook.com/billing_hub/payment_settings/?asset_id=555');
    expect(social.createMetaAdAccount).toHaveBeenCalledWith('n-1', '9001', 'Shana Trends Ads');

    await user.click(screen.getByRole('button', { name: /Shana Trends\s*Facebook Page/ }));
    await user.click(screen.getByRole('button', { name: 'Connect 2' }));
    expect(onConfirm).toHaveBeenCalledWith([page, created]);
  });

  it('shows Meta\'s refusal and creates nothing', async () => {
    social.createMetaAdAccount.mockRejectedValue({ response: { status: 422, data: { message: 'Meta does not allow more ad accounts for this Business portfolio right now (limit reached).' } } });
    const user = userEvent.setup();
    render(<Harness onConfirm={vi.fn()} />);

    await waitFor(() => expect(screen.getByLabelText('Business portfolio')).toHaveValue('9001'));
    await user.click(screen.getByRole('button', { name: 'Create ad account' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('limit reached');
    expect(screen.queryByTestId('ad-account-created')).toBeNull();
  });

  it('explains when the login has no Business portfolio', async () => {
    social.listMetaBusinesses.mockResolvedValue([]);
    render(<Harness onConfirm={vi.fn()} />);

    expect(await screen.findByText(/No Business portfolio was found/)).toBeTruthy();
  });
});
