import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import MessageLogsPage from './MessageLogsPage';
import messageLogsService from '../../services/messageLogsService';
import type { MessageDispatchLog, MessageDispatchLogDetail } from '../../types/messageLog';

vi.mock('../../services/messageLogsService', () => ({ default: { list: vi.fn(), show: vi.fn() } }));
const svc = vi.mocked(messageLogsService);

const row = (id: number, over: Partial<MessageDispatchLog> = {}): MessageDispatchLog =>
  ({
    id, account_id: 1, source: 'api', api_key_id: null, recipient_phone: `9199${id}`, recipient_type: 'individual', group_id: null, group_name: null,
    recipient_count: 1, status: 'sent', error_reason: null, has_media: false, media_url: null, reference_type: 'template', reference_id: 5,
    template_name: 'Low Quantity Product', message_preview: 'Hello Arvind, the following…', sent_at: null, created_at: '2026-10-03T07:07:21Z', updated_at: '2026-10-03T07:07:21Z', ...over,
  }) as MessageDispatchLog;

const detail = (id: number, over: Partial<MessageDispatchLogDetail> = {}): MessageDispatchLogDetail =>
  ({
    ...row(id), api_key_id: undefined, message_body: 'Hello Arvind,\nProduct A — 2 remaining\nProduct B — 1 remaining', message_body_is_complete: true,
    template_code: 'LOW_QUANTITY_PRODUCT', gateway_message_id: 'PROV-77', engine_type: 'qr', api_key: { name: 'ERP', key_prefix: 'wasaas_live_ab' },
    media: { has_media: false, name: null, type: null, url: null }, account: { id: 1, company_name: 'Vihan Medical' }, ...over,
  }) as unknown as MessageDispatchLogDetail;

function renderPage() {
  return render(<MemoryRouter><MessageLogsPage /></MemoryRouter>);
}

beforeEach(() => {
  vi.clearAllMocks();
  svc.list.mockResolvedValue({ data: [row(1), row(2, { status: 'failed', error_reason: 'WhatsApp account is disconnected.' })], scope: 'global', current_page: 1, last_page: 1, total: 2, per_page: 15 } as never);
});

describe('MessageLogsPage View action', () => {
  it('still lists the logs and offers a View button on every row', async () => {
    renderPage();
    expect(await screen.findByText('91991')).toBeInTheDocument();
    expect(screen.getAllByRole('button', { name: /^View message \d+$/ })).toHaveLength(2);
    expect(svc.list).toHaveBeenCalledWith(1, 15, expect.objectContaining({ search: '', status: '', source: '' }));
  });

  it('opens the clicked row, shows the complete resolved message, template name/code, provider id and engine', async () => {
    const user = userEvent.setup();
    svc.show.mockResolvedValue(detail(2));
    renderPage();
    await user.click(await screen.findByRole('button', { name: 'View message 2' }));

    const dialog = await screen.findByRole('dialog', { name: 'Message Details' });
    expect(svc.show).toHaveBeenCalledWith(2);
    expect(await within(dialog).findByTestId('message-body')).toHaveTextContent('Product B — 1 remaining');
    expect(within(dialog).getByText('Low Quantity Product')).toBeInTheDocument();
    expect(within(dialog).getByText('LOW_QUANTITY_PRODUCT')).toBeInTheDocument();
    expect(within(dialog).getByText('PROV-77')).toBeInTheDocument();
    expect(within(dialog).getByText('QR (Baileys)')).toBeInTheDocument();
    expect(within(dialog).getByText('Vihan Medical')).toBeInTheDocument();
    expect(within(dialog).queryByText(/shortened preview/i)).not.toBeInTheDocument();
  });

  it('shows media information and a safe open link when available', async () => {
    const user = userEvent.setup();
    svc.show.mockResolvedValue(detail(1, { media: { has_media: true, name: 'report.pdf', type: 'document', url: 'https://cdn.example.com/report.pdf' } }));
    renderPage();
    await user.click(await screen.findByRole('button', { name: 'View message 1' }));
    const dialog = await screen.findByRole('dialog');
    expect(await within(dialog).findByText(/report\.pdf/)).toBeInTheDocument();
    expect(within(dialog).getByRole('link', { name: /open attachment/i })).toHaveAttribute('href', 'https://cdn.example.com/report.pdf');
  });

  it('shows no open link when the server withheld the media URL', async () => {
    const user = userEvent.setup();
    svc.show.mockResolvedValue(detail(1, { media: { has_media: true, name: 'photo.png', type: 'image', url: null } }));
    renderPage();
    await user.click(await screen.findByRole('button', { name: 'View message 1' }));
    const dialog = await screen.findByRole('dialog');
    expect(await within(dialog).findByText(/photo\.png/)).toBeInTheDocument();
    expect(within(dialog).queryByRole('link')).not.toBeInTheDocument();
    expect(within(dialog).queryByRole('img')).not.toBeInTheDocument();
  });

  it('shows the failure reason for a failed message', async () => {
    const user = userEvent.setup();
    svc.show.mockResolvedValue(detail(2, { status: 'failed', error_reason: 'WhatsApp account is disconnected.' }));
    renderPage();
    await user.click(await screen.findByRole('button', { name: 'View message 2' }));
    const dialog = await screen.findByRole('dialog');
    expect(await within(dialog).findByText(/WhatsApp account is disconnected\./)).toBeInTheDocument();
  });

  it('flags a legacy row that only has a preview', async () => {
    const user = userEvent.setup();
    svc.show.mockResolvedValue(detail(1, { message_body: 'Hello Arvind, the following…', message_body_is_complete: false }));
    renderPage();
    await user.click(await screen.findByRole('button', { name: 'View message 1' }));
    expect(await screen.findByText(/shortened preview/i)).toBeInTheDocument();
  });

  it('reports a not-found message (another tenant or missing id) without any content', async () => {
    const user = userEvent.setup();
    svc.show.mockRejectedValue({ response: { status: 404, data: { message: 'No query results for model [App\\Models\\MessageDispatchLog] 9' } } });
    renderPage();
    await user.click(await screen.findByRole('button', { name: 'View message 1' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('This message could not be found.');
    expect(screen.queryByTestId('message-body')).not.toBeInTheDocument();
  });

  it('closes the dialog and leaves the list intact', async () => {
    const user = userEvent.setup();
    svc.show.mockResolvedValue(detail(1));
    renderPage();
    await user.click(await screen.findByRole('button', { name: 'View message 1' }));
    await screen.findByTestId('message-body');
    await user.click(screen.getAllByRole('button', { name: /^close/i })[0]);
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(screen.getByText('91991')).toBeInTheDocument();
  });
});
