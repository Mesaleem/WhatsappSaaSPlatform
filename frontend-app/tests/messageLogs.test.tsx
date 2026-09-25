import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import MessageLogsPage from '../src/pages/analytics/MessageLogsPage';
import TemplateMessageDetailModal from '../src/components/templates/TemplateMessageDetailModal';
import RouteErrorBoundary from '../src/components/common/RouteErrorBoundary';
import messageLogsService from '../src/services/messageLogsService';
import type { MessageDispatchLog, TemplateMessageDetail } from '../src/types/messageLog';

vi.mock('../src/services/messageLogsService', () => ({
  default: { list: vi.fn(), getTemplateDetail: vi.fn() },
}));

const list = vi.mocked(messageLogsService.list);
const getDetail = vi.mocked(messageLogsService.getTemplateDetail);

const LONG_TAIL =
  ' Please keep this message for your records. Our team will contact you if anything changes with your delivery window, and you can reply here any time for help.';

function logRow(overrides: Partial<MessageDispatchLog> = {}): MessageDispatchLog {
  return {
    id: 1,
    account_id: 10,
    source: 'web_template',
    api_key_id: null,
    recipient_phone: '919876543210',
    recipient_type: 'individual',
    group_id: null,
    group_name: null,
    recipient_count: 1,
    status: 'sent',
    error_reason: null,
    has_media: false,
    media_url: null,
    reference_type: 'template',
    reference_id: 5,
    template_name: 'order_update',
    message_preview: 'Hello John, your order #12345 is ready.',
    sent_at: '2026-09-25T10:00:00Z',
    created_at: '2026-09-25T10:00:00Z',
    updated_at: '2026-09-25T10:00:00Z',
    ...overrides,
  };
}

function listResponse(rows: MessageDispatchLog[]) {
  return { data: rows, current_page: 1, last_page: 1, total: rows.length, per_page: 15, scope: 'account' } as never;
}

function detail(overrides: Partial<TemplateMessageDetail> = {}): TemplateMessageDetail {
  return {
    id: 1,
    account: null,
    source: 'web_template',
    status: 'sent',
    error_reason: null,
    recipient_type: 'individual',
    recipient: '919876543210',
    group_name: null,
    recipient_count: 1,
    sent_at: '2026-09-25T10:00:00Z',
    created_at: '2026-09-25T10:00:00Z',
    provider: 'qr',
    provider_message_id: 'QR-ABC',
    snapshot_available: true,
    snapshot_scope: 'individual',
    captured_at: '2026-09-25T10:00:00Z',
    template: { id: 5, name: 'order_update', code: 'ORD-1', header_type: 'text', body: `Hello {{1}}, your order {{2}} is ready.${LONG_TAIL}` },
    parameters: [
      { key: '1', label: '1', value: 'John' },
      { key: '2', label: '2', value: '#12345' },
    ],
    rendered_content: `Hello John, your order #12345 is ready.${LONG_TAIL}`,
    group_preview: null,
    message_preview: 'Hello John, your order #12345 is ready.',
    message_preview_possibly_truncated: false,
    media: { has_media: false, type: null, url: null, filename: null },
    not_applicable: ['template_language', 'footer', 'buttons'],
    ...overrides,
  };
}

function renderPage() {
  return render(
    <MemoryRouter>
      <MessageLogsPage />
    </MemoryRouter>,
  );
}

beforeEach(() => {
  list.mockReset();
  getDetail.mockReset();
  vi.spyOn(console, 'error').mockImplementation(() => {});
});

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});

describe('Message Logs list', () => {
  it('shows View message only for template rows', async () => {
    list.mockResolvedValue(
      listResponse([
        logRow({ id: 1 }),
        logRow({ id: 2, source: 'web_ui', template_name: null, reference_type: 'payment_alert', message_preview: 'Payment received' }),
      ]),
    );
    renderPage();

    expect(await screen.findByRole('button', { name: 'View message 1' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'View message 2' })).toBeNull();
    expect(screen.getByText('Payment received')).toBeTruthy();
  });

  it('does not crash on the whatsapp-bulk source or an unknown future source (original blank-page bug)', async () => {
    list.mockResolvedValue(
      listResponse([
        logRow({ id: 1, source: 'web_template_bulk' }),
        logRow({ id: 2, source: 'something_new' as never, template_name: null, reference_type: null }),
      ]),
    );
    renderPage();

    expect(await screen.findByText('Bulk Template')).toBeTruthy();
    expect(screen.getByText('Other')).toBeTruthy();
  });

  it('opens the detail with loading state, then the exact rendered message, template and parameters', async () => {
    list.mockResolvedValue(listResponse([logRow()]));
    let resolve!: (d: TemplateMessageDetail) => void;
    getDetail.mockReturnValue(new Promise((r) => (resolve = r)));
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'View message 1' }));
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByTestId('detail-loading')).toBeTruthy();
    expect(getDetail).toHaveBeenCalledWith(1);

    resolve(detail());

    const rendered = await within(dialog).findByTestId('rendered-message');
    // Full text, not the 160-char list preview.
    expect(rendered.textContent).toBe(`Hello John, your order #12345 is ready.${LONG_TAIL}`);
    expect(rendered.textContent!.length).toBeGreaterThan(160);
    expect(within(dialog).getByTestId('template-body').textContent).toContain('Hello {{1}}, your order {{2}} is ready.');

    const params = within(within(dialog).getByTestId('parameters')).getAllByRole('listitem');
    expect(params.map((li) => li.textContent)).toEqual(['{{1}}John', '{{2}}#12345']);
    expect(within(dialog).getByText('QR-ABC')).toBeTruthy();
  });

  it('shows an error state when the detail API fails, and the page stays usable after closing', async () => {
    list.mockResolvedValue(listResponse([logRow()]));
    getDetail.mockRejectedValue({ response: { data: { message: 'Server exploded' } } });
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'View message 1' }));
    const alert = await within(screen.getByRole('dialog')).findByRole('alert');
    expect(alert.textContent).toContain('Server exploded');

    // The list is still rendered behind the modal.
    expect(screen.getByText('order_update')).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: 'Close' }));
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(screen.getByRole('button', { name: 'View message 1' })).toBeTruthy();
  });

  it('retry after a failure loads the detail', async () => {
    list.mockResolvedValue(listResponse([logRow()]));
    getDetail.mockRejectedValueOnce(new Error('network')).mockResolvedValueOnce(detail());
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'View message 1' }));
    const dialog = screen.getByRole('dialog');
    fireEvent.click(await within(dialog).findByRole('button', { name: /Try again/ }));
    expect(await within(dialog).findByTestId('rendered-message')).toBeTruthy();
  });
});

describe('TemplateMessageDetailModal', () => {
  it('keeps a malformed payload inside the modal instead of blanking the page', async () => {
    getDetail.mockResolvedValue({ ...detail(), parameters: null } as unknown as TemplateMessageDetail);
    const onClose = vi.fn();
    render(
      <div>
        <p>Message Logs table</p>
        <TemplateMessageDetailModal logId={1} onClose={onClose} />
      </div>,
    );

    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('could not be displayed');
    expect(screen.getByText('Message Logs table')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Close' }));
    expect(onClose).toHaveBeenCalled();
  });

  it('pre-snapshot rows show the stored preview and never a current template body', async () => {
    getDetail.mockResolvedValue(
      detail({
        snapshot_available: false,
        snapshot_scope: null,
        template: { id: 5, name: 'order_update', code: null, header_type: null, body: null },
        parameters: [],
        rendered_content: null,
        message_preview: 'Hello Old, legacy preview',
        message_preview_possibly_truncated: true,
      }),
    );
    render(<TemplateMessageDetailModal logId={1} onClose={() => {}} />);

    expect(await screen.findByTestId('no-snapshot-notice')).toBeTruthy();
    expect(screen.getByTestId('stored-preview').textContent).toBe('Hello Old, legacy preview');
    expect(screen.getByText(/may be cut short/)).toBeTruthy();
    expect(screen.queryByTestId('template-body')).toBeNull();
    expect(screen.queryByTestId('rendered-message')).toBeNull();
  });

  it('group rows show the group preview and media details', async () => {
    getDetail.mockResolvedValue(
      detail({
        recipient_type: 'group',
        group_name: 'VIP Customers',
        recipient_count: 25,
        snapshot_scope: 'group',
        rendered_content: null,
        group_preview: 'Hello {{name}}, sale on Friday.',
        media: { has_media: true, type: 'document', url: 'https://files.example.test/b.pdf', filename: 'b.pdf' },
      }),
    );
    render(<TemplateMessageDetailModal logId={1} onClose={() => {}} />);

    expect((await screen.findByTestId('group-preview')).textContent).toBe('Hello {{name}}, sale on Friday.');
    expect(screen.getByText('VIP Customers (25)')).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Open file' }).getAttribute('href')).toBe('https://files.example.test/b.pdf');
  });

  it('Escape closes the modal', async () => {
    getDetail.mockResolvedValue(detail());
    const onClose = vi.fn();
    render(<TemplateMessageDetailModal logId={1} onClose={onClose} />);
    await screen.findByTestId('rendered-message');
    fireEvent.keyDown(window, { key: 'Escape' });
    expect(onClose).toHaveBeenCalled();
  });
});

describe('RouteErrorBoundary', () => {
  function Boom(): never {
    throw new Error('render failed');
  }

  it('renders an error panel instead of a blank app when a page throws', () => {
    render(
      <RouteErrorBoundary>
        <Boom />
      </RouteErrorBoundary>,
    );
    expect(screen.getByText('This page failed to render.')).toBeTruthy();
    expect(screen.getByText('render failed')).toBeTruthy();
  });

  it('recovers when Try again is clicked and the child no longer throws', async () => {
    let shouldThrow = true;
    function Flaky() {
      if (shouldThrow) throw new Error('flaky');
      return <p>recovered</p>;
    }
    render(
      <RouteErrorBoundary>
        <Flaky />
      </RouteErrorBoundary>,
    );
    shouldThrow = false;
    fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
    await waitFor(() => expect(screen.getByText('recovered')).toBeTruthy());
  });
});
