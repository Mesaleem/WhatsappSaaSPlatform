import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import OrganicPostModal from './OrganicPostModal';
import OrganicPostsPanel from './OrganicPostsPanel';
import organicPostService from '../../services/organicPostService';
import type { OrganicPost } from '../../types/organic';

/**
 * Phase 9 Task 3 — scheduling in the organic post modal (publish now vs.
 * schedule, idempotency key reuse, per-platform results) and the Organic
 * Posts panel (statuses, Cancel / Retry driven by the server's flags,
 * reconnect notice, the extra confirmation for an outcome-unknown retry).
 */

vi.mock('../../services/organicPostService', () => ({
  default: { list: vi.fn(), publish: vi.fn(), cancel: vi.fn(), retry: vi.fn() },
}));
vi.mock('../../services/mediaService', () => ({ default: { upload: vi.fn() } }));

const service = organicPostService as unknown as Record<'list' | 'publish' | 'cancel' | 'retry', Mock>;

const post = (overrides: Partial<OrganicPost> = {}): OrganicPost => ({
  id: 1, social_account_id: 1, provider: 'meta', platform: 'facebook', caption: 'Hi', media_url: null, media_type: null,
  status: 'published', external_post_id: 'FB_1', error_message: null, published_at: null, created_at: null,
  origin: 'manual', scheduled_at: null, failure_code: null, attempts: 1, next_attempt_at: null, cancelled_at: null,
  can_cancel: false, can_retry: false,
  ...overrides,
});

const localInput = (date: Date) => {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
};

beforeEach(() => vi.clearAllMocks());

describe('OrganicPostModal — schedule', () => {
  it('schedules with an ISO time and an idempotency key, and shows the scheduled result', async () => {
    const at = new Date(Date.now() + 2 * 60 * 60 * 1000);
    service.publish.mockResolvedValue(post({ status: 'scheduled', origin: 'scheduled', scheduled_at: at.toISOString(), external_post_id: null }));
    const onPublished = vi.fn();
    const user = userEvent.setup();

    render(<OrganicPostModal onClose={() => {}} onPublished={onPublished} />);
    await user.type(screen.getByPlaceholderText('Write your post…'), 'Launch day');
    await user.click(screen.getByRole('radio', { name: 'Schedule' }));
    fireEvent.change(screen.getByTestId('organic-scheduled-at'), { target: { value: localInput(at) } });
    await user.click(screen.getByRole('button', { name: 'Schedule Post' }));

    expect(await screen.findByText(/^Scheduled for /)).toBeInTheDocument();
    const payload = service.publish.mock.calls[0][0];
    expect(payload).toMatchObject({ platform: 'facebook', caption: 'Launch day' });
    expect(new Date(payload.scheduled_at).getTime()).toBe(new Date(localInput(at)).getTime());
    expect(payload.idempotency_key).toMatch(/^org-[a-z0-9]+-[a-z0-9]+-facebook$/);
    expect(onPublished).toHaveBeenCalledWith([expect.objectContaining({ status: 'scheduled' })]);
  });

  it('refuses a time in the past without calling the API', async () => {
    const user = userEvent.setup();
    render(<OrganicPostModal onClose={() => {}} onPublished={() => {}} />);
    await user.type(screen.getByPlaceholderText('Write your post…'), 'Late');
    await user.click(screen.getByRole('radio', { name: 'Schedule' }));
    fireEvent.change(screen.getByTestId('organic-scheduled-at'), { target: { value: localInput(new Date(Date.now() - 60 * 60 * 1000)) } });
    await user.click(screen.getByRole('button', { name: 'Schedule Post' }));

    expect(await screen.findByText('The scheduled time must be in the future.')).toBeInTheDocument();
    expect(service.publish).not.toHaveBeenCalled();
  });

  it('a resubmission after an error reuses the key; an edited post gets a new one', async () => {
    service.publish.mockRejectedValueOnce({ response: { status: 500, data: { message: 'Server hiccup.' } } }).mockRejectedValueOnce({ response: { status: 500, data: { message: 'Server hiccup.' } } });
    const user = userEvent.setup();
    render(<OrganicPostModal onClose={() => {}} onPublished={() => {}} />);
    await user.type(screen.getByPlaceholderText('Write your post…'), 'Hello');

    await user.click(screen.getByRole('button', { name: 'Publish Now' }));
    expect(await screen.findByText('Server hiccup.')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Publish Now' }));
    await screen.findByText('Server hiccup.');
    const [first, second] = service.publish.mock.calls.map((c) => c[0].idempotency_key);
    expect(second).toBe(first);

    service.publish.mockResolvedValueOnce(post());
    await user.type(screen.getByPlaceholderText('Write your post…'), ' again');
    await user.click(screen.getByRole('button', { name: 'Publish Now' }));
    await screen.findByText('Published (id: FB_1)');
    expect(service.publish.mock.calls[2][0].idempotency_key).not.toBe(first);
  });

  it('a failure on one platform does not hide a post created on another', async () => {
    service.publish.mockImplementation((payload: { platform: string }) =>
      payload.platform === 'facebook'
        ? Promise.resolve(post({ external_post_id: 'FB_OK' }))
        : Promise.reject({ response: { status: 422, data: { message: 'No connected Linkedin asset for this tenant.' } } }),
    );
    const user = userEvent.setup();
    render(<OrganicPostModal onClose={() => {}} onPublished={() => {}} />);
    await user.click(screen.getByRole('button', { name: 'LinkedIn' }));
    await user.type(screen.getByPlaceholderText('Write your post…'), 'Both');
    await user.click(screen.getByRole('button', { name: 'Publish Now' }));

    expect(await screen.findByText('Published (id: FB_OK)')).toBeInTheDocument();
    expect(screen.getByText('No connected Linkedin asset for this tenant.')).toBeInTheDocument();
  });

  it('a reconnect-required post is shown with its safe message', async () => {
    service.publish.mockResolvedValue(post({ status: 'reconnect_required', external_post_id: null, error_message: 'Reconnect it in Social Accounts, then try again.' }));
    const user = userEvent.setup();
    render(<OrganicPostModal onClose={() => {}} onPublished={() => {}} />);
    await user.type(screen.getByPlaceholderText('Write your post…'), 'x');
    await user.click(screen.getByRole('button', { name: 'Publish Now' }));

    expect(await screen.findByText('Reconnect it in Social Accounts, then try again.')).toBeInTheDocument();
  });
});

function renderPanel() {
  return render(
    <MemoryRouter>
      <OrganicPostsPanel refreshKey={0} canManageSocialAccounts />
    </MemoryRouter>,
  );
}

describe('OrganicPostsPanel', () => {
  it('lists statuses and only offers the actions the server allows', async () => {
    service.list.mockResolvedValue([
      post({ id: 1, status: 'scheduled', scheduled_at: new Date().toISOString(), can_cancel: true, external_post_id: null }),
      post({ id: 2, status: 'failed', error_message: 'Meta rejected the post (error 100).', can_retry: true }),
      post({ id: 3, status: 'published' }),
    ]);
    renderPanel();

    const scheduled = await screen.findByTestId('organic-post-1');
    expect(within(scheduled).getByText('Scheduled')).toBeInTheDocument();
    expect(within(scheduled).getByRole('button', { name: /Cancel/ })).toBeInTheDocument();
    expect(within(scheduled).queryByRole('button', { name: /Retry/ })).toBeNull();

    const failed = screen.getByTestId('organic-post-2');
    expect(within(failed).getByText('Meta rejected the post (error 100).')).toBeInTheDocument();
    expect(within(failed).getByRole('button', { name: /Retry/ })).toBeInTheDocument();

    expect(within(screen.getByTestId('organic-post-3')).queryByRole('button')).toBeNull();
  });

  it('cancel updates the row from the server response', async () => {
    service.list.mockResolvedValue([post({ id: 5, status: 'scheduled', can_cancel: true, external_post_id: null })]);
    service.cancel.mockResolvedValue(post({ id: 5, status: 'cancelled', can_cancel: false, external_post_id: null }));
    const user = userEvent.setup();
    renderPanel();

    await user.click(within(await screen.findByTestId('organic-post-5')).getByRole('button', { name: /Cancel/ }));

    expect(service.cancel).toHaveBeenCalledWith(5);
    expect(await within(screen.getByTestId('organic-post-5')).findByText('Cancelled')).toBeInTheDocument();
  });

  it('a retry refused for an expired connection shows the reconnect notice', async () => {
    service.list.mockResolvedValue([post({ id: 6, status: 'reconnect_required', can_retry: true, can_cancel: true, external_post_id: null })]);
    service.retry.mockRejectedValue({
      response: { status: 409, data: { error_code: 'SOCIAL_CONNECTION_EXPIRED', message: 'The connected Facebook Page "Acme" has expired. Reconnect it in Social Accounts, then try again.', connection: { social_account_id: 1, asset_type: 'facebook_page', connection_status: 'expired', reason: null }, reconnect_path: '/social/accounts' } },
    });
    const user = userEvent.setup();
    renderPanel();

    await user.click(within(await screen.findByTestId('organic-post-6')).getByRole('button', { name: /Retry/ }));

    const notice = await screen.findByTestId('social-reconnect-notice');
    expect(notice).toHaveTextContent('has expired');
  });

  it('an outcome-unknown post needs a second click before it is retried', async () => {
    service.list.mockResolvedValue([post({ id: 7, status: 'failed', failure_code: 'outcome_unknown', can_retry: true, external_post_id: null })]);
    service.retry.mockResolvedValue(post({ id: 7, status: 'scheduled', can_cancel: true, external_post_id: null }));
    const user = userEvent.setup();
    renderPanel();

    const row = await screen.findByTestId('organic-post-7');
    await user.click(within(row).getByRole('button', { name: /Retry/ }));
    expect(service.retry).not.toHaveBeenCalled();
    expect(within(row).getByTestId('organic-retry-warning')).toBeInTheDocument();

    await user.click(within(row).getByRole('button', { name: /Retry anyway/ }));
    expect(service.retry).toHaveBeenCalledWith(7);
    expect(await within(screen.getByTestId('organic-post-7')).findByText('Scheduled')).toBeInTheDocument();
  });
});
