import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import WhatsAppInboxPage from './WhatsAppInboxPage';
import whatsappInboxService from '../../services/whatsappInboxService';

/** WhatsApp inbox (QA fixes, 2026-10-14). */

vi.mock('../../services/whatsappInboxService', () => ({ default: { threads: vi.fn(), messages: vi.fn(), reply: vi.fn() } }));
vi.mock('../../core/context/TenantContext', () => ({ useTenant: () => ({ selectedAccountId: null }) }));
vi.mock('../../components/common/actionGateHooks', () => ({ useClientGate: () => ({ openPicker: vi.fn(), picker: null, guard: vi.fn() }) }));
const readOnly = { value: false };
vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({ isSuperAdmin: () => false, isReadOnly: () => readOnly.value }),
}));

const thread = (over: Record<string, unknown> = {}) => ({
  id: 7,
  contact_phone: '919876543210',
  contact_name: 'Asha',
  last_message_preview: 'Is the shop open?',
  last_direction: 'in',
  last_message_at: '2026-10-14T10:00:00Z',
  last_inbound_at: '2026-10-14T10:00:00Z',
  unread_count: 2,
  can_reply_freely: true,
  ...over,
});

const messages = [
  { id: 1, direction: 'out', message_type: 'text', body: 'Your order is ready', status: 'sent', source: 'alerts', created_at: '2026-10-14T09:00:00Z' },
  { id: 2, direction: 'in', message_type: 'text', body: 'Is the shop open?', status: null, source: null, created_at: '2026-10-14T10:00:00Z' },
];

function renderPage() {
  return render(
    <MemoryRouter>
      <WhatsAppInboxPage />
    </MemoryRouter>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  readOnly.value = false;
  (whatsappInboxService.threads as Mock).mockResolvedValue({ data: [thread()], current_page: 1, last_page: 1, total: 1, unread_total: 2 });
  (whatsappInboxService.messages as Mock).mockResolvedValue({ thread: thread({ unread_count: 0 }), data: messages });
  (whatsappInboxService.reply as Mock).mockResolvedValue({ message: 'Reply sent.', data: {} });
});

describe('WhatsAppInboxPage', () => {
  it('lists conversations and opens the newest one with both directions', async () => {
    renderPage();

    expect(await screen.findByText('Your order is ready')).toBeTruthy();
    expect(screen.getAllByText('Is the shop open?').length).toBeGreaterThan(0);
    expect(screen.getAllByText('Asha').length).toBeGreaterThan(0);
    expect(whatsappInboxService.messages).toHaveBeenCalledWith(7);
  });

  it('sends a reply and refreshes the conversation', async () => {
    renderPage();
    await screen.findByText('Your order is ready');

    await userEvent.type(screen.getByLabelText('Reply'), 'Yes, till 9 pm');
    await userEvent.click(screen.getByRole('button', { name: 'Send reply' }));

    await waitFor(() => expect(whatsappInboxService.reply).toHaveBeenCalledWith(7, 'Yes, till 9 pm'));
    await waitFor(() => expect((whatsappInboxService.messages as Mock).mock.calls.length).toBeGreaterThan(1));
  });

  it('explains the Meta 24-hour window instead of a reply box', async () => {
    (whatsappInboxService.messages as Mock).mockResolvedValue({ thread: thread({ can_reply_freely: false }), data: messages });
    renderPage();

    expect(await screen.findByTestId('wa-window-closed')).toBeTruthy();
    expect(screen.queryByLabelText('Reply')).toBeNull();
  });

  it('disables replying in read-only mode (plan ended)', async () => {
    readOnly.value = true;
    renderPage();
    await screen.findByText('Your order is ready');

    expect((screen.getByLabelText('Reply') as HTMLTextAreaElement).disabled).toBe(true);
  });
});
