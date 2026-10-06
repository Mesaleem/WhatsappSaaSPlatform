import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import AppLayout from './AppLayout';

/**
 * Sidebar workflow order. Visibility rules are untouched by the reorder; this only pins the
 * relative order of the items a user can already see, and that no entry is duplicated.
 */

vi.mock('./Header', () => ({ default: () => null }));

const auth = { superAdmin: false, agent: false };
vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({
    isLoading: false,
    isAuthenticated: true,
    user: {
      id: 1, account_id: auth.superAdmin ? null : 7, account: auth.superAdmin ? null : { id: 7, account_type: auth.agent ? 'agent' : 'client' },
      permissions: [], capabilities: { social: true, ads: true, crm: true }, roles: [],
    },
    hasPermission: () => true,
    hasRole: () => false,
    isSuperAdmin: () => auth.superAdmin,
    hasModule: () => true,
    isReadOnly: () => false,
    refreshUser: () => Promise.resolve(),
  }),
}));

/** The intended workflow order of every item that exists (each user sees a subset, in this order). */
const WORKFLOW = [
  'Dashboard', 'Analytics', 'Message Logs', 'Notifications',
  'Manage Clients', 'My Clients', 'Team Users',
  'WhatsApp Setup', 'Send Notification', 'Contact Groups', 'Template Manager', 'Chatbot Rules', 'Journey Builder',
  'Developer API',
  'Social Accounts', 'Social Inbox', 'Comment Rules', 'Social Analytics', 'Social Reports',
  'Meta Ads Launcher', 'Ads Dashboard',
  'Instant Lead CRM', 'CRM Leads', 'CRM Pipeline', 'CRM Contacts', 'CRM Tags', 'CRM Analytics',
  'Education',
  'Billing & Plans', 'Add-ons', 'Plans', 'Admin Gateway Settings', 'Social Gateway Settings', 'Device Settings', 'Route Master', 'Activity Logs', 'Audit Logs', 'Quota Top-Up Requests',
];

function sidebarLabels(): string[] {
  render(
    <MemoryRouter initialEntries={['/']}>
      <Routes>
        <Route element={<AppLayout />}>
          <Route path="/" element={<div>home</div>} />
        </Route>
      </Routes>
    </MemoryRouter>,
  );
  return screen.getAllByRole('link').map((a) => (a.textContent ?? '').trim()).filter((t) => WORKFLOW.includes(t));
}

beforeEach(() => {
  auth.superAdmin = false;
  auth.agent = false;
});

describe('Sidebar workflow order', () => {
  it.each([
    ['a client admin', {}],
    ['a Super Admin', { superAdmin: true }],
    ['an Agent', { agent: true }],
  ])('lists %s items once each, in workflow order', (_name, flags) => {
    Object.assign(auth, flags);
    const labels = sidebarLabels();

    expect(labels.length).toBeGreaterThan(20);
    expect(new Set(labels).size).toBe(labels.length);
    expect(labels).toEqual(WORKFLOW.filter((l) => labels.includes(l)));
  });

  it('keeps related steps adjacent: account → team, social, ads, lead capture → CRM, admin last', () => {
    auth.superAdmin = true;
    const labels = sidebarLabels();
    const at = (l: string) => labels.indexOf(l);

    expect(at('Team Users')).toBe(at('Manage Clients') + 1);
    expect(at('Ads Dashboard')).toBe(at('Meta Ads Launcher') + 1);
    expect(at('CRM Leads')).toBe(at('Instant Lead CRM') + 1);
    expect(['CRM Pipeline', 'CRM Contacts', 'CRM Tags', 'CRM Analytics'].map(at)).toEqual([at('CRM Leads') + 1, at('CRM Leads') + 2, at('CRM Leads') + 3, at('CRM Leads') + 4]);
    expect(labels.slice(0, 4)).toEqual(['Dashboard', 'Analytics', 'Message Logs', 'Notifications']);
    expect(at('Billing & Plans')).toBeGreaterThan(at('CRM Analytics'));
    expect(labels[labels.length - 1]).toBe('Quota Top-Up Requests');
  });
});
