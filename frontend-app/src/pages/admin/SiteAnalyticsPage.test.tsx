import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import SiteAnalyticsPage, { formatDuration, improvementTips } from './SiteAnalyticsPage';
import siteAnalyticsService, { type SiteAnalyticsReport } from '../../services/siteAnalyticsService';

/** Super Admin — Website Analytics: totals, pages with time, sources, visits with their page path, and tips. */

vi.mock('../../services/siteAnalyticsService', () => ({ default: { report: vi.fn(), visits: vi.fn() } }));
// recharts needs a real layout box; the chart itself is not under test here.
vi.mock('recharts', async (orig) => {
  const actual = await orig<typeof import('recharts')>();
  return { ...actual, ResponsiveContainer: ({ children }: { children: React.ReactNode }) => <div style={{ width: 600, height: 240 }}>{children}</div> };
});

const svc = siteAnalyticsService as unknown as Record<string, Mock>;

const report: SiteAnalyticsReport = {
  from: '2026-09-15',
  to: '2026-10-14',
  summary: { visits: 40, visitors: 31, page_views: 70, avg_duration_seconds: 95, bounce_rate: 65, signups: 2, returning_visits: 9 },
  daily: [
    { date: '2026-10-13', visits: 18, visitors: 15 },
    { date: '2026-10-14', visits: 22, visitors: 16 },
  ],
  pages: [
    { path: '/home', views: 40, visits: 40, avg_seconds: 12, exits: 26 },
    { path: '/pricing', views: 20, visits: 18, avg_seconds: 70, exits: 10 },
  ],
  landing_pages: [{ name: '/home', visits: 40 }],
  sources: [
    { name: 'Google', visits: 25, avg_seconds: 110, avg_pages: 2.1, signups: 2 },
    { name: 'Direct', visits: 15, avg_seconds: 60, avg_pages: 1.4, signups: 0 },
  ],
  devices: [{ name: 'mobile', visits: 28 }, { name: 'desktop', visits: 12 }],
  browsers: [{ name: 'Chrome', visits: 40 }],
  countries: [{ name: 'IN', visits: 40 }],
};

beforeEach(() => {
  vi.clearAllMocks();
  svc.report.mockResolvedValue(report);
  svc.visits.mockResolvedValue({
    data: [
      {
        id: 1, visitor_id: 'v', is_returning: false, ip: '49.36.1.2', country: 'IN', device: 'mobile', browser: 'Chrome', os: 'Android',
        referrer: 'https://www.google.com/', source: 'Google', utm_source: null, utm_medium: null, utm_campaign: null,
        landing_path: '/home', exit_path: '/pricing', page_count: 2, duration_seconds: 95, signed_up: true,
        started_at: '2026-10-14T10:00:00Z', last_seen_at: '2026-10-14T10:02:00Z',
        page_views: [
          { id: 1, path: '/home', title: null, duration_seconds: 25, entered_at: '2026-10-14T10:00:00Z' },
          { id: 2, path: '/pricing', title: null, duration_seconds: 70, entered_at: '2026-10-14T10:00:30Z' },
        ],
      },
    ],
    current_page: 1, last_page: 1, per_page: 25, total: 1,
  });
});

describe('Website Analytics page', () => {
  it('shows totals, pages with time, sources and each visit with the pages it opened', async () => {
    const user = userEvent.setup();
    render(<SiteAnalyticsPage />);

    const summary = await screen.findByTestId('site-analytics-summary');
    expect(within(summary).getByText('31')).toBeInTheDocument();
    expect(within(summary).getByText('1m 35s')).toBeInTheDocument();
    expect(within(summary).getByText('65%')).toBeInTheDocument();

    expect(within(screen.getByTestId('site-analytics-pages')).getByText('1m 10s')).toBeInTheDocument();
    expect(within(screen.getByTestId('site-analytics-sources')).getAllByText('Google').length).toBeGreaterThan(0);
    expect(screen.getByTestId('site-analytics-tips')).toHaveTextContent(/saw only one page/);

    const visits = await screen.findByTestId('site-analytics-visits');
    await user.click(await within(visits).findByText(/49\.36\.1\.2/));
    expect(within(visits).getByText('25s')).toBeInTheDocument();
    expect(within(visits).getByText('Signed up')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Last 7 days' }));
    await waitFor(() => expect(svc.report).toHaveBeenCalledTimes(2));
  });

  it('formats durations and turns the numbers into tips', () => {
    expect(formatDuration(40)).toBe('40s');
    expect(formatDuration(3720)).toBe('1h 2m');
    const tips = improvementTips(report);
    expect(tips.join(' ')).toMatch(/Visitors leave \/home quickly/);
    expect(tips.join(' ')).toMatch(/Google brings your best visitors/);
    expect(tips.join(' ')).toMatch(/70% of visits are on a phone/);
  });
});
