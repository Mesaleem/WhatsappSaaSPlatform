import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import OrganicPostsPanel from './OrganicPostsPanel';
import organicPostService from '../../services/organicPostService';
import type { OrganicPost } from '../../types/organic';
import type { InsightMetric, InsightMetricKey, InsightsSummary, PostInsights } from '../../types/organicInsights';

/**
 * Phase 9 Task 4 — insights inside the Organic Posts panel: account totals,
 * per-post metrics (value / real zero / not available + why / not fetched),
 * the controlled refresh, and the reconnect / provider-error states. The
 * summary is only requested when the caller may view insights.
 */

vi.mock('../../services/organicPostService', () => ({
  default: { list: vi.fn(), cancel: vi.fn(), retry: vi.fn(), insightsSummary: vi.fn(), refreshInsights: vi.fn() },
}));

const service = organicPostService as unknown as Record<'list' | 'insightsSummary' | 'refreshInsights', Mock>;

const post = (overrides: Partial<OrganicPost> = {}): OrganicPost => ({
  id: 1, social_account_id: 1, provider: 'meta', platform: 'facebook', caption: 'Hello', media_url: null, media_type: null,
  status: 'published', external_post_id: 'PG_1', error_message: null, published_at: new Date().toISOString(), created_at: null,
  origin: 'manual', scheduled_at: null, failure_code: null, attempts: 1, next_attempt_at: null, cancelled_at: null,
  can_cancel: false, can_retry: false,
  ...overrides,
});

const KEYS: InsightMetricKey[] = ['impressions', 'reach', 'reactions', 'comments', 'shares', 'saves', 'clicks', 'video_views', 'video_avg_watch_time_ms'];

const metrics = (overrides: Partial<Record<InsightMetricKey, InsightMetric>> = {}, base: InsightMetric = { value: null, status: 'not_fetched', reason: null }) =>
  Object.fromEntries(KEYS.map((k) => [k, overrides[k] ?? base])) as Record<InsightMetricKey, InsightMetric>;

const insights = (overrides: Partial<PostInsights> = {}): PostInsights => ({
  post_id: 1, platform: 'facebook', post_status: 'published', state: 'ok', not_applicable_reason: null,
  fetched_at: new Date().toISOString(), last_attempted_at: null, next_refresh_at: null, error_code: null, error_message: null,
  refresh_in_progress: false, can_refresh: true, refresh_available_at: null,
  metrics: metrics({
    reach: { value: 300, status: 'available', reason: null },
    clicks: { value: 0, status: 'available', reason: null },
    saves: { value: null, status: 'unavailable', reason: 'not_supported' },
    impressions: { value: null, status: 'unavailable', reason: 'permission' },
  }, { value: null, status: 'unavailable', reason: 'not_reported' }),
  ...overrides,
});

const totals = (value: number | null) => Object.fromEntries(KEYS.map((k) => [k, { value, posts: value === null ? 0 : 1 }])) as InsightsSummary['totals'];

const summary = (posts: PostInsights[], overrides: Partial<InsightsSummary> = {}): InsightsSummary => ({
  posts_published: 1, posts_with_insights: 1, last_fetched_at: null, totals: { ...totals(null), reach: { value: 300, posts: 1 } }, posts, ...overrides,
});

function renderPanel(canViewInsights = true) {
  return render(
    <MemoryRouter>
      <OrganicPostsPanel refreshKey={0} canManageSocialAccounts canViewInsights={canViewInsights} />
    </MemoryRouter>,
  );
}

async function openInsights(user: ReturnType<typeof userEvent.setup>, postId = 1) {
  await user.click(within(await screen.findByTestId(`organic-post-${postId}`)).getByRole('button', { name: /Insights/ }));
  return screen.findByTestId(`organic-insights-${postId}`);
}

beforeEach(() => {
  vi.clearAllMocks();
  service.list.mockResolvedValue([post()]);
});

describe('Organic Posts panel — insights', () => {
  it('shows account totals and never shows an unavailable total as zero', async () => {
    service.insightsSummary.mockResolvedValue(summary([insights()]));
    renderPanel();

    const strip = await screen.findByTestId('organic-insights-summary');
    expect(within(strip).getByTestId('summary-reach')).toHaveTextContent('300');
    expect(within(strip).getByTestId('summary-shares')).toHaveTextContent('Not available');
    expect(strip).toHaveTextContent('1 / 1');
  });

  it('shows a value, a real zero, and unavailable metrics with the reason', async () => {
    service.insightsSummary.mockResolvedValue(summary([insights()]));
    const user = userEvent.setup();
    renderPanel();

    const panel = await openInsights(user);
    expect(within(panel).getByTestId('insight-reach')).toHaveTextContent('300');
    expect(within(panel).getByTestId('insight-clicks')).toHaveTextContent('0');
    expect(within(panel).getByTestId('insight-saves')).toHaveTextContent('Not offered for this type of post');
    expect(within(panel).getByTestId('insight-impressions')).toHaveTextContent('Needs the insights permission');
    expect(within(panel).getByText(/^Updated /)).toBeInTheDocument();
  });

  it('before the first fetch every metric is a dash and the action is Fetch insights', async () => {
    service.insightsSummary.mockResolvedValue(summary([insights({ state: 'not_fetched', fetched_at: null, metrics: metrics() })], { posts_with_insights: 0 }));
    service.refreshInsights.mockResolvedValue({ insights: insights(), outcome: 'refreshed' });
    const user = userEvent.setup();
    renderPanel();

    const panel = await openInsights(user);
    expect(within(panel).getByText('Insights have not been fetched yet.')).toBeInTheDocument();
    expect(within(panel).getByTestId('insight-reach')).toHaveTextContent('—');

    await user.click(within(panel).getByRole('button', { name: 'Fetch insights' }));
    expect(service.refreshInsights).toHaveBeenCalledWith(1);
    expect(await within(panel).findByText('300')).toBeInTheDocument();
  });

  it('a throttled refresh explains that the stored figures are shown', async () => {
    service.insightsSummary.mockResolvedValue(summary([insights()]));
    service.refreshInsights.mockResolvedValue({ insights: insights(), outcome: 'recently_refreshed' });
    const user = userEvent.setup();
    renderPanel();

    const panel = await openInsights(user);
    await user.click(within(panel).getByRole('button', { name: 'Refresh insights' }));
    expect(await within(panel).findByText(/updated a few minutes ago/)).toBeInTheDocument();
  });

  it('a reconnect-required snapshot shows the reconnect notice with an in-app link only', async () => {
    service.insightsSummary.mockResolvedValue(summary([insights({
      state: 'reconnect_required', error_code: 'connection_revoked', reconnect_path: 'https://evil.example',
      error_message: 'Access to the connected Facebook Page "Acme" was revoked. Reconnect it in Social Accounts, then try again.',
    })]));
    const user = userEvent.setup();
    renderPanel();

    const panel = await openInsights(user);
    const notice = within(panel).getByTestId('social-reconnect-notice');
    expect(notice).toHaveTextContent('Access revoked');
    expect(within(notice).getByTestId('social-reconnect-link').getAttribute('href')).toBe('/social/accounts');
  });

  it('a provider error or rate limit is explained and the last figures stay visible', async () => {
    service.insightsSummary.mockResolvedValue(summary([insights({ state: 'rate_limited', error_message: 'The platform is rate-limiting requests. Insights will be retried later.' })]));
    const user = userEvent.setup();
    renderPanel();

    const panel = await openInsights(user);
    expect(within(panel).getByTestId('insights-provider-error')).toHaveTextContent('rate-limiting');
    expect(within(panel).getByTestId('insight-reach')).toHaveTextContent('300');
  });

  it('posts that cannot have insights get no Insights button', async () => {
    service.list.mockResolvedValue([post({ id: 2, status: 'failed', external_post_id: null, can_retry: true })]);
    service.insightsSummary.mockResolvedValue(summary([insights({ post_id: 2, state: 'not_applicable', not_applicable_reason: 'failed' })]));
    renderPanel();

    const row = await screen.findByTestId('organic-post-2');
    await screen.findByTestId('organic-insights-summary');
    expect(within(row).queryByRole('button', { name: /Insights/ })).toBeNull();
  });

  it('without insights access the summary is never requested', async () => {
    renderPanel(false);

    await screen.findByTestId('organic-post-1');
    expect(service.insightsSummary).not.toHaveBeenCalled();
    expect(screen.queryByTestId('organic-insights-summary')).toBeNull();
    expect(within(screen.getByTestId('organic-post-1')).queryByRole('button', { name: /Insights/ })).toBeNull();
  });

  it('a refused summary does not break the posts list', async () => {
    service.insightsSummary.mockRejectedValue({ response: { status: 403, data: { message: 'Your current plan does not include Social Media.' } } });
    renderPanel();

    expect(await screen.findByText('Your current plan does not include Social Media.')).toBeInTheDocument();
    expect(screen.getByTestId('organic-post-1')).toBeInTheDocument();
  });
});
