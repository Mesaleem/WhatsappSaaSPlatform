import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import PlanManagementPage from './PlanManagementPage';
import planService from '../../services/planService';
import type { ManagedPlansResponse } from '../../types/plan';

/**
 * Phase 5 Task 12 — Super Admin Plan Management UI.
 *
 * What is worth testing here is that the page is a faithful CLIENT of
 * the Task 10/11 API and nothing more:
 *
 *   - every value rendered comes from the response, never a constant;
 *   - a create sends exactly the contract's fields;
 *   - an edit sends ONLY what changed — and specifically does NOT send
 *     `capabilities` when the administrator only touched the price,
 *     because sending it is a bundle replacement that would trigger
 *     fleet-wide reconciliation;
 *   - an intentionally emptied bundle IS sent as [];
 *   - backend 422s surface as field errors rather than being masked.
 *
 * Authorization is NOT tested here: this component never decides it.
 * The route guard (strictRole="super_admin") hides the page and
 * PlanManagementController::assertSuperAdmin() refuses the call — the
 * latter is the boundary, and it has backend tests.
 */

vi.mock('../../services/planService', () => ({
  default: { list: vi.fn(), create: vi.fn(), update: vi.fn() },
}));

const plans = planService as unknown as { list: Mock; create: Mock; update: Mock };

const response: ManagedPlansResponse = {
  data: [
    {
      slug: 'starter',
      label: 'Starter',
      price: 499,
      duration_days: 30,
      description: '500 messages/month over the QR engine.',
      engine_type: 'qr',
      billing_model: 'flat_quota',
      rate_per_message: null,
      total_allocated_messages: 500,
      is_active: true,
      capabilities: ['external_api', 'social', 'whatsapp_groups', 'whatsapp_send'],
      accounts: 12,
    },
    {
      slug: 'business',
      label: 'Business',
      price: 7999,
      duration_days: 30,
      description: null,
      engine_type: 'meta',
      billing_model: 'flat_quota',
      rate_per_message: null,
      total_allocated_messages: 10000,
      is_active: false,
      capabilities: ['crm', 'whatsapp_send'],
      accounts: 3,
    },
  ],
  available_capabilities: [
    { slug: 'whatsapp_send', label: 'WhatsApp Messaging', category: 'whatsapp', unsupported_providers: [] },
    { slug: 'whatsapp_groups', label: 'WhatsApp Groups', category: 'whatsapp', unsupported_providers: ['meta'] },
    { slug: 'crm', label: 'CRM', category: 'growth', unsupported_providers: [] },
    { slug: 'journey_automation', label: 'Journey Automation', category: 'growth', unsupported_providers: ['qr'] },
    { slug: 'external_api', label: 'External API Calls', category: 'platform', unsupported_providers: [] },
    { slug: 'social', label: 'Social Media', category: 'growth', unsupported_providers: [] },
  ],
};

beforeEach(() => {
  vi.clearAllMocks();
  plans.list.mockResolvedValue(structuredClone(response));
  plans.create.mockResolvedValue({ message: 'Plan created.' });
  plans.update.mockResolvedValue({
    message: 'Plan updated.',
    data: { slug: 'starter', capabilities: [], bundle_changed: false, added: [], removed: [] },
  });
});

async function open() {
  render(<PlanManagementPage />);
  await screen.findByTestId('plan-row-starter');
}

/** Opens the edit modal for a plan. */
async function openEdit(user: ReturnType<typeof userEvent.setup>, slug: string) {
  await open();
  await user.click(screen.getByTestId(`edit-plan-${slug}`));
  await screen.findByTestId('plan-form-modal');
}

// ====================================================================
// 1. Listing
// ====================================================================
describe('listing', () => {
  it('renders every plan from the API response', async () => {
    await open();

    expect(screen.getByTestId('plan-row-starter')).toBeTruthy();
    expect(screen.getByTestId('plan-row-business')).toBeTruthy();
  });

  it('renders plan data from the response, not from constants', async () => {
    await open();

    const row = screen.getByTestId('plan-row-starter');

    expect(within(row).getByText('Starter')).toBeTruthy();
    expect(screen.getByTestId('plan-price-starter').textContent).toBe('499');
    expect(within(row).getByText('qr')).toBeTruthy();
    expect(within(row).getByText('500')).toBeTruthy();
    expect(within(row).getByText('30d')).toBeTruthy();
    // Capability COUNT and affected-account count both come from the server.
    expect(screen.getByTestId('plan-capabilities-starter').textContent).toBe('4');
    expect(screen.getByTestId('plan-accounts-starter').textContent).toBe('12');
  });

  it('shows active and inactive status from the response', async () => {
    await open();

    expect(screen.getByTestId('plan-status-starter').textContent).toContain('Active');
    expect(screen.getByTestId('plan-status-business').textContent).toContain('Inactive');
  });

  it('surfaces a load failure instead of rendering an empty table silently', async () => {
    plans.list.mockRejectedValue({ response: { status: 500, data: { message: 'Server exploded.' } } });

    render(<PlanManagementPage />);

    expect(await screen.findByTestId('plan-page-error')).toBeTruthy();
  });

  it('shows the backend 403 when a non-Super-Admin reaches the API directly', async () => {
    // Route hiding is UX; this is what the user sees if they get past it.
    plans.list.mockRejectedValue({
      response: { status: 403, data: { message: 'Only Super Admin can manage plans.' } },
    });

    render(<PlanManagementPage />);

    expect((await screen.findByTestId('plan-page-error')).textContent).toContain('Only Super Admin');
  });
});

// ====================================================================
// 2. Create
// ====================================================================
describe('create', () => {
  it('sends exactly the contract fields', async () => {
    const user = userEvent.setup();
    await open();

    await user.click(screen.getByTestId('open-create-plan'));
    await screen.findByTestId('plan-form-modal');

    await user.type(screen.getByTestId('plan-slug'), 'scale');
    await user.type(screen.getByTestId('plan-label'), 'Scale');
    await user.type(screen.getByTestId('plan-price'), '4999');
    await user.clear(screen.getByTestId('plan-duration'));
    await user.type(screen.getByTestId('plan-duration'), '60');
    await user.selectOptions(screen.getByTestId('plan-engine'), 'meta');
    await user.type(screen.getByTestId('plan-quota'), '25000');
    await user.click(screen.getByTestId('capability-checkbox-crm'));

    await user.click(screen.getByTestId('plan-save'));

    await waitFor(() => expect(plans.create).toHaveBeenCalled());

    const payload = plans.create.mock.calls[0][0];

    expect(payload).toMatchObject({
      slug: 'scale',
      label: 'Scale',
      price: 4999,
      duration_days: 60,
      engine_type: 'meta',
      billing_model: 'flat_quota',
      total_allocated_messages: 25000,
      capabilities: ['crm'],
    });
    // No invented fields reach the API.
    expect(Object.keys(payload).sort()).toEqual([
      'billing_model', 'capabilities', 'description', 'duration_days', 'engine_type',
      'is_active', 'label', 'price', 'rate_per_message', 'slug', 'total_allocated_messages',
    ]);
  });

  it('renders a duplicate-slug 422 as a field error', async () => {
    plans.create.mockRejectedValue({
      response: { status: 422, data: { errors: { slug: ['The slug has already been taken.'] } } },
    });

    const user = userEvent.setup();
    await open();
    await user.click(screen.getByTestId('open-create-plan'));

    await user.type(screen.getByTestId('plan-slug'), 'starter');
    await user.type(screen.getByTestId('plan-label'), 'Dupe');
    await user.type(screen.getByTestId('plan-price'), '1');
    await user.click(screen.getByTestId('plan-save'));

    const error = await screen.findByTestId('field-error-slug');

    expect(error.textContent).toContain('already been taken');
  });

  it('renders an unknown-capability 422 as a field error', async () => {
    plans.create.mockRejectedValue({
      response: { status: 422, data: { errors: { 'capabilities.0': ['The selected capability is invalid.'] } } },
    });

    const user = userEvent.setup();
    await open();
    await user.click(screen.getByTestId('open-create-plan'));
    await user.type(screen.getByTestId('plan-slug'), 'x');
    await user.click(screen.getByTestId('plan-save'));

    expect(await screen.findByTestId('plan-form-error')).toBeTruthy();
  });

  it('offers only capabilities the API returned', async () => {
    const user = userEvent.setup();
    await open();
    await user.click(screen.getByTestId('open-create-plan'));

    const picker = await screen.findByTestId('capability-picker');

    for (const option of response.available_capabilities) {
      expect(within(picker).getByTestId(`capability-option-${option.slug}`), option.slug).toBeTruthy();
    }
    // And nothing the API did not return.
    expect(within(picker).queryByTestId('capability-option-teleportation')).toBeNull();
  });
});

// ====================================================================
// 3. Capability bundle
// ====================================================================
describe('capability bundle', () => {
  it('pre-selects the plan’s current bundle when editing', async () => {
    const user = userEvent.setup();
    await openEdit(user, 'starter');

    expect((screen.getByTestId('capability-checkbox-whatsapp_send') as HTMLInputElement).checked).toBe(true);
    expect((screen.getByTestId('capability-checkbox-crm') as HTMLInputElement).checked).toBe(false);
  });

  it('warns when a selected capability is unsupported on the chosen engine', async () => {
    const user = userEvent.setup();
    await openEdit(user, 'starter');

    // starter is QR; journey_automation is unsupported on qr per the API.
    await user.click(screen.getByTestId('capability-checkbox-journey_automation'));

    expect(await screen.findByTestId('capability-warning-journey_automation')).toBeTruthy();
  });

  it('does not warn for a compatible pairing', async () => {
    const user = userEvent.setup();
    await openEdit(user, 'starter');

    await user.click(screen.getByTestId('capability-checkbox-crm'));

    expect(screen.queryByTestId('capability-warning-crm')).toBeNull();
  });

  it('re-evaluates the warning when the engine changes', async () => {
    const user = userEvent.setup();
    await openEdit(user, 'starter');

    // whatsapp_groups is already selected and fine on qr…
    expect(screen.queryByTestId('capability-warning-whatsapp_groups')).toBeNull();

    // …but not on meta.
    await user.selectOptions(screen.getByTestId('plan-engine'), 'meta');

    expect(await screen.findByTestId('capability-warning-whatsapp_groups')).toBeTruthy();
  });
});

// ====================================================================
// 4. Modify — the partial-patch semantics
// ====================================================================
describe('modify', () => {
  it('sends only the changed dimension and omits capabilities entirely', async () => {
    const user = userEvent.setup();
    await openEdit(user, 'starter');

    await user.clear(screen.getByTestId('plan-price'));
    await user.type(screen.getByTestId('plan-price'), '549');
    await user.click(screen.getByTestId('plan-save'));

    await waitFor(() => expect(plans.update).toHaveBeenCalled());

    const [slug, patch] = plans.update.mock.calls[0];

    expect(slug).toBe('starter');
    expect(patch).toEqual({ price: 549 });
    // The critical assertion: an untouched bundle must NOT be sent,
    // because sending it is a replacement that reconciles every account.
    expect(patch).not.toHaveProperty('capabilities');
  });

  it('sends an intentionally emptied bundle as an explicit empty array', async () => {
    const user = userEvent.setup();
    await openEdit(user, 'starter');

    for (const slug of response.data[0].capabilities) {
      await user.click(screen.getByTestId(`capability-checkbox-${slug}`));
    }

    await user.click(screen.getByTestId('plan-save'));
    // Replacing the bundle is high-impact, so it confirms first.
    await user.click(await screen.findByRole('button', { name: 'Apply' }));

    await waitFor(() => expect(plans.update).toHaveBeenCalled());

    expect(plans.update.mock.calls[0][1]).toEqual({ capabilities: [] });
  });

  it('sends a changed bundle after confirmation', async () => {
    const user = userEvent.setup();
    await openEdit(user, 'starter');

    await user.click(screen.getByTestId('capability-checkbox-crm'));
    await user.click(screen.getByTestId('plan-save'));
    await user.click(await screen.findByRole('button', { name: 'Apply' }));

    await waitFor(() => expect(plans.update).toHaveBeenCalled());

    expect(plans.update.mock.calls[0][1].capabilities).toContain('crm');
  });

  it('confirms a bundle replacement and names the plan and its reach', async () => {
    const user = userEvent.setup();
    await openEdit(user, 'starter');

    await user.click(screen.getByTestId('capability-checkbox-crm'));
    await user.click(screen.getByTestId('plan-save'));

    const dialog = await screen.findByText(/Replacing the capability bundle/i);

    expect(dialog.textContent).toContain('Starter');
    // Affected-account count comes from the API, not a client guess.
    expect(dialog.textContent).toContain('12 accounts');
    expect(plans.update).not.toHaveBeenCalled();
  });

  it('confirms a large price change without touching the bundle', async () => {
    const user = userEvent.setup();
    await openEdit(user, 'starter');

    await user.clear(screen.getByTestId('plan-price'));
    await user.type(screen.getByTestId('plan-price'), '9999');
    await user.click(screen.getByTestId('plan-save'));

    const dialog = await screen.findByText(/new purchases only/i);

    expect(dialog.textContent).toContain('Starter');

    await user.click(screen.getByRole('button', { name: 'Apply' }));
    await waitFor(() => expect(plans.update).toHaveBeenCalled());

    expect(plans.update.mock.calls[0][1]).toEqual({ price: 9999 });
  });

  it('cancelling a confirmation sends nothing', async () => {
    const user = userEvent.setup();
    await openEdit(user, 'starter');

    await user.click(screen.getByTestId('capability-checkbox-crm'));
    await user.click(screen.getByTestId('plan-save'));
    const message = await screen.findByText(/Replacing the capability bundle/i);

    // The form has its own Cancel too — scope to the dialog's.
    const dialog = message.closest('div')!.parentElement!;
    await user.click(within(dialog).getByRole('button', { name: 'Cancel' }));

    expect(plans.update).not.toHaveBeenCalled();
  });

  it('does not allow the slug to be edited', async () => {
    const user = userEvent.setup();
    await openEdit(user, 'starter');

    expect((screen.getByTestId('plan-slug') as HTMLInputElement).disabled).toBe(true);
  });

  it('surfaces a backend refusal on save', async () => {
    plans.update.mockRejectedValue({
      response: { status: 422, data: { errors: { price: ['The price must be at least 0.'] } } },
    });

    const user = userEvent.setup();
    await openEdit(user, 'starter');

    await user.clear(screen.getByTestId('plan-price'));
    await user.type(screen.getByTestId('plan-price'), '1');
    await user.click(screen.getByTestId('plan-save'));
    // 499 -> 1 is a large change, so it confirms before sending.
    await user.click(await screen.findByRole('button', { name: 'Apply' }));

    expect(await screen.findByTestId('field-error-price')).toBeTruthy();
  });
});

// ====================================================================
// 5. Activate / deactivate
// ====================================================================
describe('activation', () => {
  it('deactivates through the API after confirming, and explains the consequences', async () => {
    const user = userEvent.setup();
    await open();

    await user.click(screen.getByTestId('toggle-plan-starter'));

    const dialog = await screen.findByText(/no longer be purchasable/i);

    expect(dialog.textContent).toContain('Starter');
    expect(dialog.textContent).toContain('12 accounts');
    expect(dialog.textContent).toMatch(/nothing is cancelled or revoked/i);

    await user.click(screen.getByRole('button', { name: 'Deactivate' }));

    await waitFor(() => expect(plans.update).toHaveBeenCalledWith('starter', { is_active: false }));
  });

  it('reactivates an inactive plan through the API', async () => {
    const user = userEvent.setup();
    await open();

    await user.click(screen.getByTestId('toggle-plan-business'));
    await user.click(await screen.findByRole('button', { name: 'Activate' }));

    await waitFor(() => expect(plans.update).toHaveBeenCalledWith('business', { is_active: true }));
  });

  it('re-reads the listing after a successful change', async () => {
    const user = userEvent.setup();
    await open();

    expect(plans.list).toHaveBeenCalledTimes(1);

    await user.click(screen.getByTestId('toggle-plan-starter'));
    await user.click(await screen.findByRole('button', { name: 'Deactivate' }));

    // The server decides the resulting state; the page re-reads it
    // rather than patching local state optimistically.
    await waitFor(() => expect(plans.list).toHaveBeenCalledTimes(2));
  });

  it('surfaces a failed toggle', async () => {
    plans.update.mockRejectedValue({ response: { status: 403, data: { message: 'Only Super Admin can manage plans.' } } });

    const user = userEvent.setup();
    await open();

    await user.click(screen.getByTestId('toggle-plan-starter'));
    await user.click(await screen.findByRole('button', { name: 'Deactivate' }));

    expect((await screen.findByTestId('plan-page-error')).textContent).toContain('Only Super Admin');
  });
});

// ====================================================================
// 6. After create
// ====================================================================
describe('after a successful write', () => {
  it('shows a newly created plan by re-reading the listing', async () => {
    const user = userEvent.setup();
    await open();

    const withScale = structuredClone(response);
    withScale.data.push({
      slug: 'scale', label: 'Scale', price: 4999, duration_days: 60, description: null,
      engine_type: 'meta', billing_model: 'flat_quota', rate_per_message: null,
      total_allocated_messages: 25000, is_active: true, capabilities: ['whatsapp_send'], accounts: 0,
    });
    // open() already consumed the first call; every later read includes it.
    plans.list.mockResolvedValue(withScale);

    await user.click(screen.getByTestId('open-create-plan'));
    await user.type(screen.getByTestId('plan-slug'), 'scale');
    await user.type(screen.getByTestId('plan-label'), 'Scale');
    await user.type(screen.getByTestId('plan-price'), '4999');
    await user.click(screen.getByTestId('plan-save'));

    expect(await screen.findByTestId('plan-row-scale')).toBeTruthy();
    expect(screen.getByTestId('plan-notice').textContent).toContain('Plan created.');
  });
});

// ====================================================================
// 7. No hardcoded plan data
// ====================================================================
describe('no hardcoded plan data', () => {
  it('renders whatever the API returns, including plans it has never heard of', async () => {
    plans.list.mockResolvedValue({
      data: [{
        slug: 'mystery', label: 'Mystery Tier', price: 1, duration_days: 1, description: null,
        engine_type: 'qr', billing_model: 'unlimited', rate_per_message: null,
        total_allocated_messages: null, is_active: true, capabilities: [], accounts: 0,
      }],
      available_capabilities: [],
    });

    render(<PlanManagementPage />);

    expect(await screen.findByTestId('plan-row-mystery')).toBeTruthy();
    // And none of the seeded ones, because the API did not return them.
    expect(screen.queryByTestId('plan-row-starter')).toBeNull();
    expect(screen.queryByTestId('plan-row-business')).toBeNull();
  });
});
