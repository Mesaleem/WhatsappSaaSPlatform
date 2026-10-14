import { act, render } from '@testing-library/react';
import { MemoryRouter, useNavigate } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { setSiteEventSenderForTests, trackSignup, useSiteTracking } from './siteTracking';

/** The website tracker: one "view" per page, "ping" with the visible seconds, nothing for the Super Admin. */

const auth = { superAdmin: false };
vi.mock('../core/context/AuthContext', () => ({ useAuth: () => ({ isSuperAdmin: () => auth.superAdmin }) }));

let go: (path: string) => void = () => undefined;
function Page() {
  useSiteTracking();
  const navigate = useNavigate();
  go = navigate;
  return null;
}

const events: Record<string, unknown>[] = [];

beforeEach(() => {
  vi.useFakeTimers();
  events.length = 0;
  auth.superAdmin = false;
  localStorage.clear();
  sessionStorage.clear();
  setSiteEventSenderForTests((e) => events.push(e));
});

afterEach(() => {
  setSiteEventSenderForTests(null);
  vi.useRealTimers();
});

describe('website tracker', () => {
  it('sends a view per page, pings visible seconds, and keeps one session and visitor', () => {
    render(
      <MemoryRouter initialEntries={['/home?utm_source=newsletter&utm_campaign=diwali']}>
        <Page />
      </MemoryRouter>,
    );

    const first = events[0];
    expect(first).toMatchObject({ type: 'view', path: '/home', utm: { source: 'newsletter', medium: null, campaign: 'diwali' } });

    act(() => {
      vi.advanceTimersByTime(15_000);
    });
    expect(events[1]).toMatchObject({ type: 'ping', view_id: first.view_id, seconds: 15 });

    act(() => go('/pricing'));
    // Leaving a page sends its final time, then the next page's view — same session, no new source.
    const second = events.find((e, i) => i > 1 && e.type === 'view')!;
    expect(second).toMatchObject({ path: '/pricing', session_id: first.session_id, visitor_id: first.visitor_id });
    expect(second).not.toHaveProperty('utm');

    trackSignup();
    expect(events.at(-1)).toEqual({ type: 'signup', session_id: first.session_id });
  });

  it('does not record the Super Admin', () => {
    auth.superAdmin = true;
    render(
      <MemoryRouter initialEntries={['/home']}>
        <Page />
      </MemoryRouter>,
    );
    act(() => {
      vi.advanceTimersByTime(30_000);
    });
    expect(events).toHaveLength(0);
  });
});
