import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import SocialAnalyticsPage from './SocialAnalyticsPage';
import socialAnalyticsService from '../../services/socialAnalyticsService';
import organicPostService from '../../services/organicPostService';
import type { AggregateMetric, AnalyticsMetricKey, MetricBlock, SocialAnalyticsDashboard } from '../../types/socialAnalytics';

/**
 * Phase 9 Task 5 — Social Analytics dashboard: cards never show a
 * misleading 0 (available / real zero / not available / not fetched / no
 * data), platform breakdown, empty states, ranges, top posts, per-post
 * refresh, view-only analytics access, and Super Admin client selection
 * (no request without a client; switching client reloads).
 */

vi.mock('recharts', async (importOriginal) => {
  const actual = await importOriginal<typeof import('recharts')>();
  return { ...actual, ResponsiveContainer: ({ children }: { children: ReactNode }) => <div data-testid="chart">{children}</div> };
});
vi.mock('../../services/socialAnalyticsService', () => ({ default: { dashboard: vi.fn(), topPosts: vi.fn() } }));
vi.mock('../../services/organicPostService', () => ({ default: { refreshInsights: vi.fn() } }));

const auth = { superAdmin: false, permissions: ['view-social-analytics'] as string[] };
vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({ isSuperAdmin: () => auth.superAdmin, hasPermission: (p: string) => auth.permissions.includes(p), hasModule: () => true }),
}));
const tenant = { selectedAccountId: null as number | null };
vi.mock('../../core/context/TenantContext', () => ({ useTenant: () => ({ selectedAccountId: tenant.selectedAccountId }) }));
const openPicker = vi.fn();
vi.mock('../../components/common/actionGateHooks', () => ({ useClientGate: () => ({ openPicker, picker: null, guard: vi.fn() }) }));

const analytics = socialAnalyticsService as unknown as Record<'dashboard' | 'topPosts', Mock>;
const organic = organicPostService as unknown as Record<'refreshInsights', Mock>;

const KEYS: AnalyticsMetricKey[] = ['impressions', 'reach', 'reactions', 'comments', 'shares', 'saves', 'clicks', 'video_views', 'video_avg_watch_time_ms'];
const m = (value: number | null, status: AggregateMetric['status'] = value === null ? 'unavailable' : 'available', posts = 1): AggregateMetric => ({ value, status, posts_reporting: posts });

function block(published: number, overrides: Partial<Record<AnalyticsMetricKey, AggregateMetric>> = {}, base: AggregateMetric = m(null)): MetricBlock {
  return {
    published,
    metrics: Object.fromEntries(KEYS.map((k) => [k, overrides[k] ?? base])) as MetricBlock['metrics'],
    engagement: { value: 40, status: 'available', components_included: ['reactions', 'comments'], components_unavailable: ['shares', 'saves'] },
  };
}

const coverage = { published: 3, with_provider_id: 3, without_provider_id: 0, fetched: 3, never_fetched: 0, partial: 1, reconnect_required: 0, post_not_found: 0, provider_error: 0 };

function dashboard(overrides: Partial<SocialAnalyticsDashboard> = {}): SocialAnalyticsDashboard {
  return {
    range: { from: '2026-09-01', to: '2026-09-30', days: 30, interval: 'day', timezone: 'UTC' },
    summary: block(3, { reach: m(220, 'available', 3), clicks: m(0), impressions: m(150, 'available', 1) }),
    coverage,
    platforms: [
      { platform: 'facebook', ...block(2, { reach: m(150) }), coverage: { ...coverage, published: 2, fetched: 2 } },
      { platform: 'instagram', ...block(1, { reach: m(70) }), coverage: { ...coverage, published: 1, fetched: 1 } },
    ],
    freshness: { last_fetched_at: '2026-09-30T07:30:00+00:00', never_fetched: 0 },
    trend: { interval: 'day', points: [{ period: '2026-09-29', published: 1, reach: 10, impressions: null, video_views: null, engagement: 3 }] },
    top_posts: [
      { post_id: 11, platform: 'instagram', media_type: 'image', caption: 'Monsoon launch', published_at: '2026-09-21T10:00:00+00:00', insights_state: 'ok', fetched_at: '2026-09-30T07:30:00+00:00', value: 32, engagement: 32, engagement_components: { reactions: 30, comments: null, shares: null, saves: 2 }, reach: null, impressions: null, video_views: null },
    ],
    status_summary: { scheduled: 0, publishing: 0, pending: 0, failed: 2, reconnect_required: 0, cancelled: 1 },
    connections: { facebook_pages: 1, instagram_accounts: 1, needing_reconnect: 0 },
    ...overrides,
  };
}

function renderPage() {
  return render(
    <MemoryRouter>
      <SocialAnalyticsPage />
    </MemoryRouter>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  auth.superAdmin = false;
  auth.permissions = ['view-social-analytics'];
  tenant.selectedAccountId = null;
  analytics.dashboard.mockResolvedValue(dashboard());
});

describe('SocialAnalyticsPage', () => {
  it('a view-only analytics user sees the cards, with zero and unavailable kept apart', async () => {
    renderPage();

    expect(await screen.findByTestId('card-published')).toHaveTextContent('3');
    expect(screen.getByTestId('card-reach')).toHaveTextContent('220');
    expect(screen.getByTestId('card-impressions')).toHaveTextContent('Reported by 1 of 3 posts');
    expect(screen.getByTestId('card-clicks')).toHaveTextContent('0');
    expect(screen.getByTestId('card-saves')).toHaveTextContent('Not available');
    expect(screen.getByTestId('card-video-views')).toHaveTextContent('Not available');
    expect(screen.getByTestId('card-engagement')).toHaveTextContent('40');
    expect(screen.getByTestId('card-engagement')).toHaveTextContent('not available: shares, saves');
    expect(screen.getByTestId('analytics-freshness')).toHaveTextContent('Insights last refreshed');
    expect(screen.getByTestId('status-summary')).toHaveTextContent('2 failed, 1 cancelled');
    expect(analytics.dashboard).toHaveBeenCalledWith({ range: '30d' });
  });

  it('shows the platform breakdown per platform', async () => {
    renderPage();

    const table = await screen.findByTestId('platform-breakdown');
    expect(within(table).getByTestId('platform-facebook')).toHaveTextContent('150');
    expect(within(table).getByTestId('platform-instagram')).toHaveTextContent('70');
  });

  it('never-fetched and no-data states are named, not shown as 0', async () => {
    analytics.dashboard.mockResolvedValue(dashboard({
      summary: block(2, {}, m(null, 'not_fetched', 0)),
      coverage: { ...coverage, published: 2, fetched: 0, never_fetched: 2 },
      freshness: { last_fetched_at: null, never_fetched: 2 },
      top_posts: [],
    }));
    renderPage();

    expect(await screen.findByTestId('analytics-not-fetched')).toBeInTheDocument();
    expect(screen.getByTestId('card-reach')).toHaveTextContent('Not fetched yet');
    expect(screen.getByTestId('analytics-freshness')).toHaveTextContent('never');
    expect(screen.getByTestId('analytics-freshness')).toHaveTextContent('2 published post(s) not fetched yet');
    expect(screen.getByTestId('top-posts')).toHaveTextContent('No post in this period reported this metric.');
  });

  it('empty account: no posts and no connections, with the Social Accounts link only for managers', async () => {
    analytics.dashboard.mockResolvedValue(dashboard({
      summary: block(0, {}, m(null, 'no_data', 0)), platforms: [], top_posts: [],
      connections: { facebook_pages: 0, instagram_accounts: 0, needing_reconnect: 0 },
    }));
    const { unmount } = renderPage();

    expect(await screen.findByTestId('analytics-empty')).toBeInTheDocument();
    expect(screen.getByTestId('card-reach')).toHaveTextContent('No data');
    expect(screen.getByTestId('analytics-no-connections')).toHaveTextContent('Ask a team member');
    expect(screen.queryByTestId('platform-breakdown')).toBeNull();
    unmount();

    auth.permissions = ['view-social-analytics', 'manage-social-accounts'];
    renderPage();
    expect(within(await screen.findByTestId('analytics-no-connections')).getByRole('link').getAttribute('href')).toBe('/social/accounts');
  });

  it('reconnect-needed connections are flagged', async () => {
    analytics.dashboard.mockResolvedValue(dashboard({ connections: { facebook_pages: 1, instagram_accounts: 0, needing_reconnect: 1 } }));
    renderPage();

    expect(await screen.findByTestId('analytics-reconnect')).toHaveTextContent('1 connected account(s) need to be reconnected');
  });

  it('changing the period reloads; a custom range waits for both dates', async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByTestId('card-published');

    await user.selectOptions(screen.getByLabelText('Period'), '7d');
    await waitFor(() => expect(analytics.dashboard).toHaveBeenLastCalledWith({ range: '7d' }));

    await user.selectOptions(screen.getByLabelText('Period'), 'custom');
    expect(screen.getByText('Choose a start and an end date.')).toBeInTheDocument();
    const calls = analytics.dashboard.mock.calls.length;
    await user.type(screen.getByLabelText('From date'), '2026-08-01');
    expect(analytics.dashboard.mock.calls.length).toBe(calls);
    await user.type(screen.getByLabelText('To date'), '2026-08-31');
    await waitFor(() => expect(analytics.dashboard).toHaveBeenLastCalledWith({ range: 'custom', from: '2026-08-01', to: '2026-08-31' }));
  });

  it('top posts can be ranked by another metric', async () => {
    analytics.topPosts.mockResolvedValue([]);
    const user = userEvent.setup();
    renderPage();

    expect(await screen.findByTestId('top-post-11')).toHaveTextContent('Monsoon launch');
    await user.selectOptions(screen.getByLabelText('Rank by'), 'reach');
    expect(analytics.topPosts).toHaveBeenCalledWith({ range: '30d' }, 'reach');
    expect(await screen.findByText('No post in this period reported this metric.')).toBeInTheDocument();
  });

  it('refreshing a top post reloads the dashboard only when it actually refreshed', async () => {
    organic.refreshInsights.mockResolvedValueOnce({ outcome: 'recently_refreshed', insights: {} }).mockResolvedValueOnce({ outcome: 'refreshed', insights: {} });
    const user = userEvent.setup();
    renderPage();

    const row = await screen.findByTestId('top-post-11');
    await user.click(within(row).getByRole('button', { name: /Refresh/ }));
    expect(await screen.findByText(/refreshed a few minutes ago/)).toBeInTheDocument();
    expect(analytics.dashboard).toHaveBeenCalledTimes(1);

    await user.click(within(screen.getByTestId('top-post-11')).getByRole('button', { name: /Refresh/ }));
    await waitFor(() => expect(analytics.dashboard).toHaveBeenCalledTimes(2));
    expect(organic.refreshInsights).toHaveBeenCalledWith(11);
  });

  it('a Super Admin without a client sees the client picker and no request is made', async () => {
    auth.superAdmin = true;
    const user = userEvent.setup();
    renderPage();

    await user.click(within(screen.getByTestId('select-client-notice')).getByRole('button'));
    expect(openPicker).toHaveBeenCalled();
    expect(analytics.dashboard).not.toHaveBeenCalled();
  });

  it('switching the selected client reloads every figure', async () => {
    auth.superAdmin = true;
    tenant.selectedAccountId = 5;
    const { rerender } = renderPage();
    expect(await screen.findByTestId('card-reach')).toHaveTextContent('220');

    analytics.dashboard.mockResolvedValue(dashboard({ summary: block(1, { reach: m(9) }) }));
    tenant.selectedAccountId = 6;
    rerender(
      <MemoryRouter>
        <SocialAnalyticsPage />
      </MemoryRouter>,
    );

    await waitFor(() => expect(screen.getByTestId('card-reach')).toHaveTextContent('9'));
    expect(analytics.dashboard).toHaveBeenCalledTimes(2);
  });

  it('a refused request shows the server message', async () => {
    analytics.dashboard.mockRejectedValue({ response: { status: 403, data: { message: 'Your current plan does not include Social Media.' } } });
    renderPage();

    expect(await screen.findByRole('alert')).toHaveTextContent('Your current plan does not include Social Media.');
  });
});

describe('SocialAnalyticsPage — client switching (Phase 9 Task 6)', () => {
  it('a refresh note of the previous client is not shown after switching', async () => {
    auth.superAdmin = true;
    tenant.selectedAccountId = 5;
    organic.refreshInsights.mockResolvedValue({ outcome: 'recently_refreshed', insights: {} });
    const user = userEvent.setup();
    const { rerender } = renderPage();

    await user.click(within(await screen.findByTestId('top-post-11')).getByRole('button', { name: /Refresh/ }));
    expect(await screen.findByText(/refreshed a few minutes ago/)).toBeInTheDocument();

    tenant.selectedAccountId = 6;
    rerender(
      <MemoryRouter>
        <SocialAnalyticsPage />
      </MemoryRouter>,
    );
    await waitFor(() => expect(analytics.dashboard).toHaveBeenCalledTimes(2));
    await screen.findByTestId('top-post-11');
    expect(screen.queryByText(/refreshed a few minutes ago/)).toBeNull();
  });

  it('while the next client loads, the previous client\'s figures are not shown', async () => {
    auth.superAdmin = true;
    tenant.selectedAccountId = 5;
    const { rerender } = renderPage();
    expect(await screen.findByTestId('card-reach')).toHaveTextContent('220');

    analytics.dashboard.mockReturnValue(new Promise(() => {}));
    tenant.selectedAccountId = 6;
    rerender(
      <MemoryRouter>
        <SocialAnalyticsPage />
      </MemoryRouter>,
    );

    await waitFor(() => expect(screen.queryByTestId('card-reach')).toBeNull());
    expect(screen.getByText(/Loading analytics/)).toBeInTheDocument();
  });

  it('a view-only user sees no Social Accounts link (management stays hidden)', async () => {
    analytics.dashboard.mockResolvedValue(dashboard({ connections: { facebook_pages: 0, instagram_accounts: 0, needing_reconnect: 1 } }));
    renderPage();

    expect(await screen.findByTestId('analytics-no-connections')).toHaveTextContent('Ask a team member');
    expect(within(screen.getByTestId('analytics-reconnect')).queryByRole('link')).toBeNull();
  });
});
