import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import AdsDashboardPage from './AdsDashboardPage';
import adsDashboardService from '../../services/adsDashboardService';
import adsConversionValueService, { type ConvertedAttribution } from '../../services/adsConversionValueService';
import type { AdsCampaignReportRow, AdsDashboard } from '../../types/adsDashboard';

/**
 * Phase 10 Task 5 — Ads Dashboard: conversion value, cost per conversion, ROAS, campaign
 * comparison columns, daily conversions and the manual conversion-value entry (CRM + Ads
 * gated). NULL ("not recorded / not available") and 0 are always displayed differently.
 */

vi.mock('../../services/adsDashboardService', () => ({ default: { dashboard: vi.fn() } }));
vi.mock('../../services/adsConversionValueService', () => ({ default: { listConverted: vi.fn(), setValue: vi.fn() } }));

const auth = { superAdmin: false, permissions: ['social_ads.view', 'manage-crm'] as string[], capabilities: { ads: true, crm: true } as Record<string, boolean> };
vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({
    isLoading: false,
    isAuthenticated: true,
    user: { id: 1, account_id: 7, account: { id: 7, account_type: 'client' }, permissions: auth.permissions, capabilities: auth.capabilities, roles: [] },
    hasPermission: (p: string) => auth.permissions.includes(p),
    hasRole: () => false,
    isSuperAdmin: () => auth.superAdmin,
    hasModule: () => true,
    isReadOnly: () => false,
    refreshUser: () => Promise.resolve(),
  }),
}));

const tenant = { selectedAccountId: null as number | null };
vi.mock('../../core/context/TenantContext', () => ({
  useTenant: () => ({ selectedAccountId: tenant.selectedAccountId, selectedAccount: null, accounts: [], isLoadingAccounts: false, canSwitchClients: auth.superAdmin, selectAccount: vi.fn() }),
}));

const dash = adsDashboardService as unknown as Record<'dashboard', Mock>;
const values = adsConversionValueService as unknown as Record<'listConverted' | 'setValue', Mock>;

const row = (id: number, name: string, extra: Partial<AdsCampaignReportRow> = {}): AdsCampaignReportRow => ({
  id, name, objective: 'CLICK_TO_WHATSAPP', status: 'ACTIVE', spend: 100, spend_status: 'available', impressions: 2500,
  referrals: 4, conversations: 3, leads: 3, journeys: 1, conversions: 2, conversion_rate: 0.5, cost_per_lead: 33.33,
  conversion_value: 500, roas: 5, last_checked_at: null, currency: 'INR', cost_per_conversion: 50, valued_conversions: 2,
  conversion_value_currency: 'INR', conversion_value_issue: null, ...extra,
});

function dashboard(overrides: Partial<AdsDashboard> = {}): AdsDashboard {
  return {
    range: { from: '2026-09-01', to: '2026-09-30', days: 30 },
    currency: 'INR',
    campaigns_summary: { total: 1, active: 1, paused: 0, launching: 0, failed: 0, unconfirmed: 0, unavailable: 0 },
    spend: { total: { value: 100, status: 'available' }, impressions: { value: 2500, status: 'available' }, campaigns_with_spend: 1, daily: [{ date: '2026-09-30', spend: 100 }] },
    attribution: {
      ads: 1, referrals: 4, conversations: 3, leads: 3, journeys: 1, conversions: 2,
      conversion_rate: { value: 0.5, status: 'available' },
      conversion_value: { value: 500, status: 'available' },
      unlinked_referrals: 0,
      cost_per_lead: { value: 33.33, status: 'available' },
      roas: { value: 5, status: 'available' },
      valued_conversions: 2, conversion_value_currency: 'INR', conversion_value_issue: null,
      cost_per_conversion: { value: 50, status: 'available' }, roas_issue: null,
    },
    conversion_daily: [
      { date: '2026-09-28', conversions: 1, valued_conversions: 1, conversion_value: 0 },
      { date: '2026-09-29', conversions: 0, valued_conversions: 0, conversion_value: null },
      { date: '2026-09-30', conversions: 1, valued_conversions: 0, conversion_value: null },
    ],
    funnel: [{ stage: 'referrals', label: 'Referrals', count: 4 }],
    campaigns: [row(1, 'Alpha')],
    campaigns_truncated: false,
    notes: { source: 'Figures come from stored data only.', conversion_daily: 'Daily conversions are counted on the day recorded.' },
    ...overrides,
  };
}

const converted = (id: number, leadId: number, value: number | null, extra: Partial<ConvertedAttribution> = {}): ConvertedAttribution => ({
  id, crm_lead_id: leadId, contact_phone: `9199000${id}`, source_id: 'AD-1', headline: null, campaign: { id: 1, name: 'Alpha' },
  converted_at: '2026-09-28T10:00:00+00:00', conversion_value: value, conversion_currency: value === null ? null : 'INR', ...extra,
});

function renderDashboard() {
  return render(
    <MemoryRouter initialEntries={['/social/ads/dashboard']}>
      <Routes>
        <Route path="/social/ads/dashboard" element={<AdsDashboardPage />} />
      </Routes>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  auth.superAdmin = false;
  auth.permissions = ['social_ads.view', 'manage-crm'];
  auth.capabilities = { ads: true, crm: true };
  tenant.selectedAccountId = null;
  dash.dashboard.mockResolvedValue(dashboard());
  values.listConverted.mockResolvedValue([converted(1, 11, null), converted(2, 12, 250), converted(3, 13, 0)]);
  values.setValue.mockResolvedValue({ changed: true, message: 'Conversion value saved.' });
});

describe('Ads Dashboard — conversion value reporting', () => {
  it('shows conversion value, cost per conversion, ROAS and the campaign comparison columns', async () => {
    renderDashboard();
    await screen.findByTestId('ads-summary');

    expect(within(screen.getByTestId('card-value')).getByText('₹500.00')).toBeTruthy();
    expect(within(screen.getByTestId('card-value')).getByText('2 of 2 conversion(s) have a value')).toBeTruthy();
    expect(within(screen.getByTestId('card-cpc')).getByText('₹50.00')).toBeTruthy();
    expect(within(screen.getByTestId('card-roas')).getByText('5.00×')).toBeTruthy();

    const alpha = within(screen.getByTestId('ads-campaign-table')).getByText('Alpha').closest('tr') as HTMLElement;
    for (const text of ['2,500', '₹100.00', '₹33.33', '₹50.00', '₹500.00', '5.00×']) expect(within(alpha).getByText(text)).toBeTruthy();
  });

  it('shows 0 as a value and unknown as unknown — never the other way round', async () => {
    dash.dashboard.mockResolvedValue(
      dashboard({
        attribution: { ...dashboard().attribution, conversion_value: { value: 0, status: 'available' }, roas: { value: 0, status: 'available' }, valued_conversions: 1 },
        campaigns: [
          row(1, 'Zero value', { conversion_value: 0, roas: 0 }),
          row(2, 'No value', { conversion_value: null, roas: null, valued_conversions: 0 }),
        ],
      }),
    );
    renderDashboard();
    await screen.findByTestId('ads-summary');

    expect(within(screen.getByTestId('card-value')).getByText('₹0.00')).toBeTruthy();
    expect(within(screen.getByTestId('card-roas')).getByText('0.00×')).toBeTruthy();
    const table = screen.getByTestId('ads-campaign-table');
    const zero = within(table).getByText('Zero value').closest('tr') as HTMLElement;
    expect(within(zero).getByText('₹0.00')).toBeTruthy();
    expect(within(zero).getByText('0.00×')).toBeTruthy();
    const none = within(table).getByText('No value').closest('tr') as HTMLElement;
    expect(within(none).queryByText('₹0.00')).toBeNull();
    expect(within(none).queryByText('0.00×')).toBeNull();
    expect(within(none).getAllByText('—').length).toBeGreaterThanOrEqual(2);
  });

  it('labels unavailable / not applicable states and explains withheld values', async () => {
    dash.dashboard.mockResolvedValue(
      dashboard({
        attribution: {
          ...dashboard().attribution,
          conversions: 0,
          conversion_value: { value: null, status: 'unavailable' },
          roas: { value: null, status: 'unavailable' },
          cost_per_conversion: { value: null, status: 'not_applicable' },
          valued_conversions: 0,
        },
      }),
    );
    const { unmount } = renderDashboard();
    await screen.findByTestId('ads-summary');
    expect(within(screen.getByTestId('card-cpc')).getByText('Not applicable')).toBeTruthy();
    expect(within(screen.getByTestId('card-value')).getByText('Not available')).toBeTruthy();
    expect(within(screen.getByTestId('card-roas')).getByText('Not available')).toBeTruthy();
    unmount();

    dash.dashboard.mockResolvedValue(
      dashboard({
        attribution: { ...dashboard().attribution, conversion_value: { value: null, status: 'unavailable' }, conversion_value_issue: 'mixed_currency', roas: { value: null, status: 'unavailable' }, roas_issue: 'currency_mismatch' },
        campaigns: [row(1, 'Mixed', { conversion_value: null, roas: null, conversion_value_issue: 'mixed_currency' })],
      }),
    );
    renderDashboard();
    await screen.findByTestId('ads-summary');
    expect(within(screen.getByTestId('card-value')).getByText('Recorded in different currencies — not added together')).toBeTruthy();
    expect(within(screen.getByTestId('card-roas')).getByText('Value and spend are in different currencies')).toBeTruthy();
  });

  it('lists only the days with conversions; a day with no valued conversion shows a dash, a 0 value shows 0', async () => {
    renderDashboard();
    await screen.findByTestId('ads-conversion-daily');

    const daily = screen.getByTestId('ads-conversion-daily');
    expect(within(daily).queryByTestId('conversion-day-2026-09-29')).toBeNull();
    expect(within(within(daily).getByTestId('conversion-day-2026-09-28')).getByText('₹0.00')).toBeTruthy();
    expect(within(within(daily).getByTestId('conversion-day-2026-09-30')).getByText('—')).toBeTruthy();
  });

  it('says so when there are no conversions in the period', async () => {
    dash.dashboard.mockResolvedValue(dashboard({ conversion_daily: [{ date: '2026-09-30', conversions: 0, valued_conversions: 0, conversion_value: null }] }));
    renderDashboard();

    expect(await screen.findByTestId('ads-conversion-daily-empty')).toBeTruthy();
  });

  it('copes with an older backend that sends none of the new fields', async () => {
    const base = dashboard();
    const legacyAttribution = { ...base.attribution } as Partial<AdsDashboard['attribution']>;
    delete legacyAttribution.cost_per_conversion;
    delete legacyAttribution.valued_conversions;
    dash.dashboard.mockResolvedValue({ ...base, attribution: legacyAttribution as AdsDashboard['attribution'], conversion_daily: undefined, campaigns: [{ ...row(1, 'Legacy'), cost_per_conversion: undefined }] });
    renderDashboard();

    expect(await screen.findByTestId('ads-summary')).toBeTruthy();
    expect(within(screen.getByTestId('card-cpc')).getByText('Not available')).toBeTruthy();
    expect(screen.getByTestId('ads-conversion-daily-empty')).toBeTruthy();
  });
});

describe('Manual conversion value entry', () => {
  it('is offered to a user with CRM and Ads access, and shows Not recorded vs 0', async () => {
    renderDashboard();
    await screen.findByTestId('conversion-row-1');

    expect(within(screen.getByTestId('conversion-value-1')).getByText('Not recorded')).toBeTruthy();
    expect(within(screen.getByTestId('conversion-value-2')).getByText('₹250.00')).toBeTruthy();
    expect(within(screen.getByTestId('conversion-value-3')).getByText('₹0.00')).toBeTruthy();
    expect(within(screen.getByTestId('conversion-row-1')).getByRole('button', { name: 'Add value' })).toBeTruthy();
    expect(within(screen.getByTestId('conversion-row-2')).getByRole('button', { name: 'Edit value' })).toBeTruthy();
  });

  it('is hidden — and nothing is requested — without manage-crm, without the crm capability, or without a client for a Super Admin', async () => {
    auth.permissions = ['social_ads.view'];
    const first = renderDashboard();
    await screen.findByTestId('ads-summary');
    expect(screen.queryByTestId('ads-conversion-values')).toBeNull();
    first.unmount();

    auth.permissions = ['social_ads.view', 'manage-crm'];
    auth.capabilities = { ads: true, crm: false };
    const second = renderDashboard();
    await screen.findByTestId('ads-summary');
    expect(screen.queryByTestId('ads-conversion-values')).toBeNull();
    second.unmount();

    auth.superAdmin = true;
    auth.capabilities = { ads: true, crm: true };
    renderDashboard();
    expect(screen.getByText(/Select one to view their Ads dashboard/)).toBeTruthy();
    expect(screen.queryByTestId('ads-conversion-values')).toBeNull();
    expect(values.listConverted).not.toHaveBeenCalled();
    expect(values.setValue).not.toHaveBeenCalled();
  });

  it('a Super Admin with a selected client can record a value', async () => {
    auth.superAdmin = true;
    auth.permissions = [];
    tenant.selectedAccountId = 5;
    renderDashboard();

    expect(await screen.findByRole('button', { name: 'Add value' })).toBeTruthy();
  });

  it('saves a value with its currency, accepts 0, and reloads the dashboard and the list', async () => {
    const user = userEvent.setup();
    renderDashboard();
    const rowEl = await screen.findByTestId('conversion-row-1');

    await user.click(within(rowEl).getByRole('button', { name: 'Add value' }));
    await user.type(within(rowEl).getByLabelText('Conversion value'), '1500.5');
    expect((within(rowEl).getByLabelText('Currency') as HTMLInputElement).value).toBe('INR'); // prefilled from the ad account currency
    await user.click(within(rowEl).getByRole('button', { name: 'Save' }));

    await waitFor(() => expect(values.setValue).toHaveBeenCalledWith(11, 1500.5, 'INR'));
    await waitFor(() => expect(dash.dashboard).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(values.listConverted).toHaveBeenCalledTimes(2));

    const again = within(await screen.findByTestId('conversion-row-1'));
    await user.click(again.getByRole('button', { name: 'Add value' }));
    await user.type(again.getByLabelText('Conversion value'), '0');
    await user.click(again.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(values.setValue).toHaveBeenLastCalledWith(11, 0, 'INR'));
  });

  it('refuses a blank, negative or badly-formed value and a bad currency before calling the API', async () => {
    const user = userEvent.setup();
    renderDashboard();
    const rowEl = await screen.findByTestId('conversion-row-1');
    await user.click(within(rowEl).getByRole('button', { name: 'Add value' }));

    await user.click(within(rowEl).getByRole('button', { name: 'Save' }));
    expect(within(rowEl).getByRole('alert').textContent).toContain('Enter a value of 0 or more');

    await user.type(within(rowEl).getByLabelText('Conversion value'), '10.123');
    await user.click(within(rowEl).getByRole('button', { name: 'Save' }));
    expect(within(rowEl).getByRole('alert').textContent).toContain('at most 2 decimal');

    await user.clear(within(rowEl).getByLabelText('Conversion value'));
    await user.type(within(rowEl).getByLabelText('Conversion value'), '10');
    await user.clear(within(rowEl).getByLabelText('Currency'));
    await user.type(within(rowEl).getByLabelText('Currency'), 'RS');
    await user.click(within(rowEl).getByRole('button', { name: 'Save' }));
    expect(within(rowEl).getByRole('alert').textContent).toContain('3-letter currency');

    expect(values.setValue).not.toHaveBeenCalled();
  });

  it('clears a recorded value (null, not 0) and shows server errors', async () => {
    const user = userEvent.setup();
    renderDashboard();
    const rowEl = await screen.findByTestId('conversion-row-2');
    await user.click(within(rowEl).getByRole('button', { name: 'Edit value' }));
    await user.click(within(rowEl).getByRole('button', { name: 'Clear value' }));
    await waitFor(() => expect(values.setValue).toHaveBeenCalledWith(12, null, null));

    values.setValue.mockRejectedValueOnce({ response: { status: 422, data: { success: false, error_code: 'LEAD_NOT_CONVERTED', message: 'Only a converted lead can have a conversion value.' } } });
    const other = await screen.findByTestId('conversion-row-1');
    await user.click(within(other).getByRole('button', { name: 'Add value' }));
    await user.type(within(other).getByLabelText('Conversion value'), '5');
    await user.click(within(other).getByRole('button', { name: 'Save' }));
    expect((await within(other).findByRole('alert')).textContent).toContain('Only a converted lead can have a conversion value.');
  });

  it('switching client clears the old list and ignores a late answer for the previous client', async () => {
    auth.superAdmin = true;
    auth.permissions = [];
    tenant.selectedAccountId = 5;
    let resolveFive: (d: ConvertedAttribution[]) => void = () => {};
    values.listConverted.mockReturnValueOnce(new Promise<ConvertedAttribution[]>((r) => { resolveFive = r; }));
    const view = renderDashboard();
    await screen.findByTestId('ads-summary');

    tenant.selectedAccountId = 6;
    values.listConverted.mockResolvedValueOnce([converted(9, 99, 70, { campaign: { id: 2, name: 'Client six campaign' } })]);
    view.rerender(
      <MemoryRouter initialEntries={['/social/ads/dashboard']}>
        <Routes>
          <Route path="/social/ads/dashboard" element={<AdsDashboardPage />} />
        </Routes>
      </MemoryRouter>,
    );
    expect(await screen.findByText('Client six campaign')).toBeTruthy();

    resolveFive([converted(8, 88, 10, { campaign: { id: 1, name: 'Client five campaign' } })]);
    await new Promise((r) => setTimeout(r, 0));
    expect(screen.queryByText('Client five campaign')).toBeNull();
    expect(screen.getByText('Client six campaign')).toBeTruthy();
  });
});
