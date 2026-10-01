import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import OrganicPostModal from './OrganicPostModal';
import organicPostService from '../../services/organicPostService';
import type { OrganicPost } from '../../types/organic';

/**
 * Phase 5 fix P5-9 — an Instagram video is no longer published inside the
 * request: the backend returns the post as 'pending' and a queued job
 * publishes it once Meta finishes processing. The result list must show
 * that as in progress, not as a failure.
 */

vi.mock('../../services/organicPostService', () => ({
  default: { publish: vi.fn() },
}));
vi.mock('../../services/mediaService', () => ({ default: { upload: vi.fn() } }));

const service = organicPostService as unknown as { publish: Mock };

const post = (overrides: Partial<OrganicPost>): OrganicPost => ({
  id: 1, provider: 'meta', platform: 'facebook', caption: 'Hi', media_url: null, media_type: null,
  status: 'published', external_post_id: 'FB_1', error_message: null, published_at: null, created_at: null,
  social_account_id: 1, origin: 'manual', scheduled_at: null, failure_code: null, attempts: 1, next_attempt_at: null,
  cancelled_at: null, can_cancel: false, can_retry: false,
  ...overrides,
});

async function publishWith(result: OrganicPost) {
  service.publish.mockResolvedValue(result);
  render(<OrganicPostModal onClose={() => {}} onPublished={() => {}} />);
  await userEvent.type(screen.getByPlaceholderText('Write your post…'), 'Hi');
  await userEvent.click(screen.getByRole('button', { name: 'Publish Now' }));
}

beforeEach(() => vi.clearAllMocks());

describe('OrganicPostModal results', () => {
  it('shows a pending post as processing, not as failed', async () => {
    await publishWith(post({ status: 'pending', external_post_id: null }));

    expect(await screen.findByText(/Processing — Instagram is still preparing the video/)).toBeInTheDocument();
    expect(screen.queryByText('Publish failed.')).not.toBeInTheDocument();
  });

  it('still shows published and failed posts as before', async () => {
    await publishWith(post({ status: 'failed', external_post_id: null, error_message: 'Meta rejected it.' }));
    expect(await screen.findByText('Meta rejected it.')).toBeInTheDocument();
  });

  it('still shows a published post with its id', async () => {
    await publishWith(post({ status: 'published', external_post_id: 'FB_9' }));
    expect(await screen.findByText('Published (id: FB_9)')).toBeInTheDocument();
  });
});
