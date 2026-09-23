import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import AccountEntitlementsModal from './AccountEntitlementsModal';
import accountService from '../../services/accountService';
import type { Account, AccountEntitlementsResponse } from '../../types/account';

/**
 * Phase 5 Task 8 — Super Admin capability entitlement panel.
 *
 * The panel's whole job is to make the already-stored states legible,
 * so these tests assert exactly that: the three grant sources, the
 * explicit revoked state Phase 5 Task 9 added, and the one derived
 * state worth calling out (a plan-bundled capability the provider
 * cannot support).
 * Grant/revoke go through the pre-existing endpoints and are
 * re-authorized server-side; what is tested here is that the panel
 * re-reads rather than guessing the resulting state, and that it
 * surfaces a server refusal instead of swallowing it.
 */

vi.mock('../../services/accountService', () => ({
  default: {
    entitlements: vi.fn(),
    grantEntitlement: vi.fn(),
    revokeEntitlement: vi.fn(),
  },
}));

const accounts = accountService as unknown as {
  entitlements: Mock;
  grantEntitlement: Mock;
  revokeEntitlement: Mock;
};

const account = { id: 7, company_name: 'Acme Ltd' } as Account;

const response: AccountEntitlementsResponse = {
  data: [
    { slug: 'whatsapp_send', label: 'WhatsApp Messaging', category: 'whatsapp', granted: true, source: 'plan', granted_by_account_id: null, plan_granted: true, revoked: false, revoked_at: null, revoked_reason: null },
    { slug: 'external_api', label: 'External API Calls', category: 'platform', granted: true, source: 'manual_grant', granted_by_account_id: null, plan_granted: false, revoked: false, revoked_at: null, revoked_reason: null },
    { slug: 'crm', label: 'CRM', category: 'growth', granted: true, source: 'agent_delegated', granted_by_account_id: 3, plan_granted: false, revoked: false, revoked_at: null, revoked_reason: null },
    { slug: 'custom_code', label: 'Custom Code', category: 'platform', granted: false, source: null, granted_by_account_id: null, plan_granted: false, revoked: false, revoked_at: null, revoked_reason: null },
    { slug: 'commerce', label: 'Commerce Catalog', category: 'growth', granted: false, source: null, granted_by_account_id: null, plan_granted: true, revoked: false, revoked_at: null, revoked_reason: null },
    // Phase 5 Task 9 — deliberately revoked. The plan bundles it, an
    // administrator took it away, and the backfill will not restore it.
    { slug: 'payments', label: 'Payments', category: 'growth', granted: false, source: 'plan', granted_by_account_id: null, plan_granted: true, revoked: true, revoked_at: '2026-09-20T10:00:00.000Z', revoked_reason: 'manual' },
    // Phase 5 Task 10 — removed because the plan stopped including it.
    // Unlike the row above, this one returns by itself if the plan does.
    { slug: 'ads', label: 'Ads', category: 'growth', granted: false, source: 'plan', granted_by_account_id: null, plan_granted: false, revoked: true, revoked_at: '2026-09-21T10:00:00.000Z', revoked_reason: 'plan_downgrade' },
  ],
  meta: { plan: 'business', provider: 'meta' },
};

beforeEach(() => {
  vi.clearAllMocks();
  accounts.entitlements.mockResolvedValue(structuredClone(response));
  accounts.grantEntitlement.mockResolvedValue({ message: 'ok' });
  accounts.revokeEntitlement.mockResolvedValue({ message: 'ok' });
});

function renderPanel(canManage = true) {
  return render(<AccountEntitlementsModal account={account} canManage={canManage} onClose={() => {}} />);
}

describe('entitlement states', () => {
  it('distinguishes plan, manual, delegated and not-entitled', async () => {
    renderPanel();
    await screen.findByTestId('entitlement-row-whatsapp_send');

    expect(within(screen.getByTestId('entitlement-row-whatsapp_send')).getByText('Granted by plan')).toBeTruthy();
    expect(within(screen.getByTestId('entitlement-row-external_api')).getByText('Granted manually')).toBeTruthy();
    expect(within(screen.getByTestId('entitlement-row-crm')).getByText('Delegated by agent')).toBeTruthy();
    expect(within(screen.getByTestId('entitlement-row-custom_code')).getByText('Not entitled')).toBeTruthy();
  });

  it('calls out a plan-bundled capability the provider cannot support', async () => {
    renderPanel();
    await screen.findByTestId('entitlement-row-commerce');

    // Not collapsed into a plain "Not entitled": the administrator needs
    // to know the plan offers it and the provider refused it.
    expect(within(screen.getByTestId('entitlement-row-commerce')).getByText(/provider unsupported/i)).toBeTruthy();
  });

  it('shows a deliberately revoked capability as revoked, not as "not entitled"', async () => {
    renderPanel();
    const row = await screen.findByTestId('entitlement-row-payments');

    expect(within(row).getByText('Revoked by admin')).toBeTruthy();
    // And not collapsed into the provider badge either: the plan DOES
    // bundle it — a person removed it.
    expect(within(row).queryByText(/provider unsupported/i)).toBeNull();
    expect(within(row).queryByText('Not entitled')).toBeNull();
  });

  it('offers to restore an administrator revocation rather than grant it afresh', async () => {
    renderPanel();
    await screen.findByTestId('entitlement-toggle-payments');

    expect(screen.getByTestId('entitlement-toggle-payments').textContent).toContain('Restore');
    // An ordinary un-held capability still reads as a plain grant.
    expect(screen.getByTestId('entitlement-toggle-custom_code').textContent).toContain('Grant');
  });

  it('distinguishes a plan-driven removal from an administrator revocation', async () => {
    renderPanel();
    const planRow = await screen.findByTestId('entitlement-row-ads');

    // Phase 5 Task 10: these mean different things — one comes back by
    // itself when the plan does, the other never does.
    expect(within(planRow).getByText('Removed by plan change')).toBeTruthy();
    expect(within(planRow).queryByText('Revoked by admin')).toBeNull();

    expect(
      within(screen.getByTestId('entitlement-row-payments')).getByText('Revoked by admin'),
    ).toBeTruthy();
  });

  it('explains that a plan removal returns on its own', async () => {
    renderPanel();
    const badge = within(await screen.findByTestId('entitlement-row-ads')).getByText('Removed by plan change');

    expect(badge.getAttribute('title')).toMatch(/returns automatically/i);
  });

  it('lists every capability, entitled or not', async () => {
    renderPanel();
    await screen.findByTestId('entitlement-row-whatsapp_send');

    for (const row of response.data) {
      expect(screen.getByTestId(`entitlement-row-${row.slug}`), row.slug).toBeTruthy();
    }
  });

  it('shows the account plan and provider', async () => {
    renderPanel();

    expect(await screen.findByText(/Plan: business/)).toBeTruthy();
    expect(screen.getByText(/Provider: meta/)).toBeTruthy();
  });
});

describe('grant and revoke', () => {
  it('grants an unentitled capability and re-reads the resulting state', async () => {
    const user = userEvent.setup();
    renderPanel();
    await screen.findByTestId('entitlement-toggle-custom_code');

    await user.click(screen.getByTestId('entitlement-toggle-custom_code'));

    await waitFor(() => expect(accounts.grantEntitlement).toHaveBeenCalledWith(7, 'custom_code'));
    // Re-read, not patched locally: the server decides the source, and
    // may refuse a provider-incompatible grant outright.
    expect(accounts.entitlements).toHaveBeenCalledTimes(2);
  });

  it('revokes a granted capability', async () => {
    const user = userEvent.setup();
    renderPanel();
    await screen.findByTestId('entitlement-toggle-external_api');

    await user.click(screen.getByTestId('entitlement-toggle-external_api'));

    await waitFor(() => expect(accounts.revokeEntitlement).toHaveBeenCalledWith(7, 'external_api'));
  });

  it('surfaces a server refusal instead of showing a state that was never saved', async () => {
    accounts.grantEntitlement.mockRejectedValue({
      response: { status: 403, data: { message: 'You are not authorized to sell/grant the ‘commerce’ capability.' } },
    });

    const user = userEvent.setup();
    renderPanel();
    await screen.findByTestId('entitlement-toggle-commerce');

    await user.click(screen.getByTestId('entitlement-toggle-commerce'));

    expect(await screen.findByTestId('entitlement-action-error')).toBeTruthy();
    expect(within(screen.getByTestId('entitlement-row-commerce')).getByText(/provider unsupported/i)).toBeTruthy();
  });

  it('is read-only when the viewer cannot manage entitlements', async () => {
    renderPanel(false);
    await screen.findByTestId('entitlement-row-whatsapp_send');

    expect(screen.queryByTestId('entitlement-toggle-whatsapp_send')).toBeNull();
    // States are still visible — an Agent may look without changing.
    expect(screen.getByText('Granted by plan')).toBeTruthy();
  });
});

describe('security', () => {
  it('renders no credential-shaped value', async () => {
    renderPanel();
    const modal = await screen.findByTestId('entitlements-modal');
    const text = modal.textContent!.toLowerCase();

    for (const forbidden of ['token', 'secret', 'password', 'api key']) {
      expect(text, forbidden).not.toContain(forbidden);
    }
  });
});
