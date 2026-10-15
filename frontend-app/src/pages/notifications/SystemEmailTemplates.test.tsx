import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import NotificationsPage from './NotificationsPage';
import notificationService from '../../services/notificationService';
import accountService from '../../services/accountService';
import { findInlineCssIssues, renderPreview, usedVariables } from './emailPreview';
import type { NotificationTemplate } from '../../types/notifications';

vi.mock('../../services/notificationService', () => ({
  default: {
    listTemplates: vi.fn(),
    listRoles: vi.fn(),
    sendBroadcast: vi.fn(),
    updateTemplate: vi.fn(),
    createTemplate: vi.fn(),
    resetTemplate: vi.fn(),
    sendTestEmail: vi.fn(),
    deleteTemplate: vi.fn(),
  },
}));
vi.mock('../../services/accountService', () => ({
  default: { list: vi.fn() },
}));

const auth = { superAdmin: true };
vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({ isSuperAdmin: () => auth.superAdmin }),
}));
vi.mock('../../core/context/TenantContext', () => ({
  useTenant: () => ({ selectedAccountId: null, selectedAccount: null }),
}));

const systemTemplate: NotificationTemplate = {
  id: 7,
  slug: 'onboarding.welcome',
  is_system: true,
  is_active: true,
  description: 'Sent to a customer right after they sign up on the website.',
  variables: { name: 'User name', brand: 'Platform name' },
  name: 'Welcome (new signup)',
  type: 'raw_html',
  category: 'Onboarding',
  subject: 'Welcome to {{brand}}, {{name}}!',
  body: '<table role="presentation"><tr><td style="color:#0f172a">Hi {{name}}</td></tr></table>',
  creator: null,
  created_at: '',
  updated_at: '',
};

const renderPage = () => render(<MemoryRouter><NotificationsPage /></MemoryRouter>);

const page = (data: NotificationTemplate[]) => ({ data, current_page: 1, last_page: 1, total: data.length, per_page: 100 });

describe('email preview helpers', () => {
  it('fills {{var}} and {{ var }} like the backend and keeps unknown variables', () => {
    expect(renderPreview('Hi {{name}} / {{ name }} / {{nope}}')).toBe('Hi Jane Doe / Jane Doe / {{nope}}');
    expect(usedVariables('{{a}} {{ b }} {{a}}')).toEqual(['a', 'b']);
  });

  it('flags what email clients drop and passes inline-only HTML', () => {
    const issues = findInlineCssIssues('<style>p{}</style><link rel="stylesheet" href="x.css"><p class="x" style="display:flex">a</p>');
    expect(issues.filter((i) => i.level === 'error')).toHaveLength(2);
    expect(issues.map((i) => i.message).join(' ')).toMatch(/class="…"/);
    expect(issues.map((i) => i.message).join(' ')).toMatch(/flex/);
    expect(findInlineCssIssues('<table><tr><td style="color:red">ok</td></tr></table>')).toEqual([]);
  });
});

describe('Mail Templates — system emails', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    auth.superAdmin = true;
    (accountService.list as Mock).mockResolvedValue({ data: [] });
    (notificationService.listTemplates as Mock).mockResolvedValue(page([systemTemplate]));
    (notificationService.listRoles as Mock).mockResolvedValue([{ id: 1, name: 'admin' }, { id: 2, name: 'agent' }]);
  });

  it('shows a system email as System, without a delete button, and filters by kind', async () => {
    const user = userEvent.setup();
    renderPage();
    await user.click(screen.getByRole('button', { name: 'Mail Templates' }));

    const row = (await screen.findByText('Welcome (new signup)')).closest('tr')!;
    expect(within(row).getByText('System')).toBeTruthy();
    expect(within(row).getByText('onboarding.welcome')).toBeTruthy();
    expect(within(row).queryByLabelText('Delete template')).toBeNull();

    await user.selectOptions(screen.getByLabelText('Template kind'), 'system');
    expect(notificationService.listTemplates).toHaveBeenLastCalledWith(1, 100, '', 'system');
  });

  it('opens the full preview with sample values and desktop / mobile widths', async () => {
    const user = userEvent.setup();
    renderPage();
    await user.click(screen.getByRole('button', { name: 'Mail Templates' }));
    await user.click(await screen.findByLabelText('Preview Welcome (new signup)'));

    const dialog = screen.getByRole('dialog', { name: 'Email preview' });
    expect(within(dialog).getByText('Welcome to WapHub, Jane Doe!')).toBeTruthy();
    const frame = within(dialog).getByTitle('Email preview') as HTMLIFrameElement;
    expect(frame.getAttribute('srcdoc')).toContain('Hi Jane Doe');
    expect(frame.getAttribute('sandbox')).toBe('');
    await user.click(within(dialog).getByRole('button', { name: /Mobile/ }));
    expect(within(dialog).getByRole('button', { name: /Mobile/ }).getAttribute('aria-pressed')).toBe('true');
    expect(within(dialog).getByText(/Inline CSS only/)).toBeTruthy();
  });

  it('edits a system email: variables, inline-CSS warning, test send, on/off and reset', async () => {
    const user = userEvent.setup();
    (notificationService.sendTestEmail as Mock).mockResolvedValue({ message: 'Test email sent to boss@x.test.' });
    (notificationService.resetTemplate as Mock).mockResolvedValue({ ...systemTemplate, subject: 'Default subject' });
    (notificationService.updateTemplate as Mock).mockResolvedValue(systemTemplate);
    renderPage();
    await user.click(screen.getByRole('button', { name: 'Mail Templates' }));
    await user.click(await screen.findByLabelText('Edit template'));

    expect(screen.getByText('Edit System Email')).toBeTruthy();
    expect(screen.getByText('{{name}}')).toBeTruthy();
    // A full email document stays raw HTML: no Rich Text switch.
    expect(screen.queryByRole('button', { name: 'Rich Text' })).toBeNull();

    const textarea = screen.getByDisplayValue(systemTemplate.body);
    await user.clear(textarea);
    await user.click(textarea);
    await user.paste('<style>p{}</style><p>{{code}}</p>');
    expect(screen.getAllByText(/<style> blocks are removed/).length).toBeGreaterThan(0);
    expect(screen.getByText(/Not available in this email/)).toBeTruthy();

    await user.click(screen.getByRole('button', { name: /Send test email/ }));
    expect(notificationService.sendTestEmail).toHaveBeenCalledWith({ subject: systemTemplate.subject, body: '<style>p{}</style><p>{{code}}</p>' });
    expect(await screen.findByText('Test email sent to boss@x.test.')).toBeTruthy();

    await user.click(screen.getByRole('button', { name: /Reset to default/ }));
    await user.click(screen.getByRole('button', { name: 'Reset' }));
    expect(notificationService.resetTemplate).toHaveBeenCalledWith(7);
    expect((screen.getByDisplayValue('Default subject') as HTMLInputElement).value).toBe('Default subject');

    await user.click(screen.getByRole('checkbox', { name: /Use this template/ }));
    await user.click(screen.getByRole('button', { name: 'Save Template' }));
    expect(notificationService.updateTemplate).toHaveBeenCalledWith(7, expect.objectContaining({ is_active: false, type: 'raw_html' }));
  });
});

describe('Compose Broadcast — new targets', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    auth.superAdmin = true;
    (accountService.list as Mock).mockResolvedValue({ data: [] });
    (notificationService.listTemplates as Mock).mockResolvedValue(page([]));
    (notificationService.listRoles as Mock).mockResolvedValue([{ id: 1, name: 'admin' }, { id: 2, name: 'agent' }]);
    (notificationService.sendBroadcast as Mock).mockResolvedValue({ recipient_count: 3, email_sent_count: 0, email_failed_count: 0 });
  });

  it('loads only broadcast templates and sends to a particular role', async () => {
    const user = userEvent.setup();
    renderPage();
    expect(notificationService.listTemplates).toHaveBeenCalledWith(1, 100, '', 'custom');

    const target = screen.getAllByRole('combobox')[1];
    expect(within(target).getByRole('option', { name: /All Clients/ })).toBeTruthy();
    expect(within(target).getByRole('option', { name: /All Agents/ })).toBeTruthy();
    await user.selectOptions(target, 'role');
    await user.selectOptions(await screen.findByLabelText('Role'), 'agent');

    // Text boxes in order: category, subject; the raw HTML body textarea comes last.
    await user.type(screen.getAllByRole('textbox')[1], 'Hello');
    await user.click(screen.getByRole('button', { name: 'Raw HTML' }));
    const boxes = screen.getAllByRole('textbox');
    await user.click(boxes[boxes.length - 1]);
    await user.paste('<p>Hi</p>');
    await user.click(screen.getByRole('button', { name: 'Send Broadcast' }));

    expect(notificationService.sendBroadcast).toHaveBeenCalledWith(
      expect.objectContaining({ target_type: 'role', role_name: 'agent', subject: 'Hello', body: '<p>Hi</p>' }),
    );
  });
});
