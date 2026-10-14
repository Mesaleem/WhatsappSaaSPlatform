import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import NotificationBell from './NotificationBell';
import notificationService from '../../services/notificationService';

/**
 * A "template rejected" notification must not drop a client on /unauthorized: older rows link
 * to Template Manager, which only Super Admin and Agents can open, so a client is sent to its
 * own templates (Send Notification → My Templates) instead.
 */

vi.mock('../../services/notificationService', () => ({
  default: { unreadCount: vi.fn(), listInbox: vi.fn(), markRead: vi.fn() },
}));

const auth = { superAdmin: false, permissions: [] as string[], modules: ['send_alert'] as string[] };
vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({ isSuperAdmin: () => auth.superAdmin, hasPermission: (p: string) => auth.permissions.includes(p), hasModule: (m: string) => auth.modules.includes(m) }),
}));

const svc = notificationService as unknown as Record<string, Mock>;

function Where() {
  const loc = useLocation();
  return <p data-testid="where">{loc.pathname + loc.search}</p>;
}

async function clickTemplateNotification() {
  const user = userEvent.setup();
  render(
    <MemoryRouter initialEntries={['/']}>
      <NotificationBell />
      <Routes>
        <Route path="*" element={<Where />} />
      </Routes>
    </MemoryRouter>,
  );
  await user.click(screen.getByRole('button', { name: 'Notifications' }));
  await user.click(await screen.findByText('Template request rejected'));
  return screen.getByTestId('where').textContent;
}

beforeEach(() => {
  vi.clearAllMocks();
  auth.superAdmin = false;
  auth.permissions = ['send-messages'];
  auth.modules = ['send_alert'];
  svc.unreadCount.mockResolvedValue(1);
  svc.markRead.mockResolvedValue({});
  svc.listInbox.mockResolvedValue({
    data: [{ id: 5, title: 'Template request rejected', body: '"Offer" was rejected.', category: 'template_review', link: '/admin/templates', is_read: false, created_at: new Date().toISOString() }],
  });
});

describe('notification bell links', () => {
  it('sends a client to its own templates, not to /unauthorized', async () => {
    expect(await clickTemplateNotification()).toBe('/alerts/send?templates=mine');
  });

  it('sends a client without Send Notification to the dashboard, never /unauthorized', async () => {
    auth.modules = [];
    expect(await clickTemplateNotification()).toBe('/');
  });

  it('keeps Template Manager for those who can open it', async () => {
    auth.permissions = ['manage-templates'];
    expect(await clickTemplateNotification()).toBe('/admin/templates');
  });

  it('keeps Template Manager for the Super Admin', async () => {
    auth.superAdmin = true;
    expect(await clickTemplateNotification()).toBe('/admin/templates');
  });
});
