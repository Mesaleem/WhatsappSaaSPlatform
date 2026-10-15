import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import TourHost from './TourHost';
import { buildWelcomeTour, PAGE_TOURS } from './tourContent';
import { JOURNEY_NODES_TOUR, PAGE_TOUR_ROUTES, startTour, TOUR_REPLAY_EVENT } from './tourKeys';
import { buildJourneyNodesTour } from './journeyTour';
import { journeyNodesByCategory } from '../journey/nodeRegistry';
import { JOURNEY_NODE_CATEGORIES } from '../types/journeyNodes';
import tourService from '../services/tourService';

/** Product tours (2026-10-15): welcome tour on first login, page tours on first visit. */

vi.mock('../services/tourService', () => ({ default: { complete: vi.fn(), reset: vi.fn() } }));

const auth = {
  user: { id: 1, completed_tours: [] as string[], account: { account_type: 'client' } } as unknown as Record<string, unknown>,
  superAdmin: false,
  admin: true,
  setCompletedTours: vi.fn(),
};
vi.mock('../core/context/AuthContext', () => ({
  useAuth: () => ({
    user: auth.user,
    isSuperAdmin: () => auth.superAdmin,
    hasRole: (r: string) => r === 'admin' && auth.admin,
    setCompletedTours: auth.setCompletedTours,
  }),
}));

function renderAt(path: string, visibleRoutes: string[]) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <nav>
        {visibleRoutes.map((r) => (
          <a key={r} href={r} data-tour={'nav:' + r}>
            {r}
          </a>
        ))}
      </nav>
      <TourHost visibleRoutes={visibleRoutes} />
    </MemoryRouter>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  window.localStorage.clear();
  auth.user = { id: 1, completed_tours: [], account: { account_type: 'client' } };
  auth.superAdmin = false;
  auth.admin = true;
  (tourService.complete as Mock).mockImplementation((key: string) => Promise.resolve(['welcome', key]));
});

describe('tour content', () => {
  it('has a page tour for every route the host knows, and nothing else', () => {
    expect(Object.keys(PAGE_TOURS).sort()).toEqual([...PAGE_TOUR_ROUTES].sort());
  });

  it('builds the welcome tour from what the user can see: one step per feature, groups merged', () => {
    const steps = buildWelcomeTour(['/', '/settings/whatsapp', '/crm/leads', '/crm/pipeline', '/crm/deals', '/billing', '/unknown'], 'admin');

    expect(steps[0].title).toMatch(/Welcome aboard/);
    expect(steps.filter((s) => s.title === 'CRM')).toHaveLength(1); // three CRM items, one step
    expect(steps.find((s) => s.title === 'CRM')?.target).toBe('nav:/crm/leads');
    expect(steps.some((s) => s.title === 'Start here')).toBe(true); // a client: connect WhatsApp first
    expect(steps[steps.length - 1].target).toBe('tour-button');
    expect(steps.some((s) => s.target === 'nav:/unknown')).toBe(false);
  });

  it('shows no plan feature the user cannot see, and no WhatsApp start step without it', () => {
    const steps = buildWelcomeTour(['/', '/crm/leads', '/billing'], 'member');

    expect(steps.some((s) => s.title === 'WhatsApp Setup' || s.title === 'Start here')).toBe(false);
    expect(steps[0].title).toBe('Welcome 👋');
  });
});

describe('TourHost', () => {
  it('shows the welcome tour on first login and remembers when it is skipped', async () => {
    renderAt('/', ['/', '/settings/whatsapp', '/crm/leads']);

    expect(await screen.findByRole('dialog', { name: 'Welcome aboard 👋' }, { timeout: 2000 })).toBeTruthy();
    await userEvent.click(screen.getByRole('button', { name: 'Skip tour' }));

    expect(screen.queryByTestId('tour-overlay')).toBeNull();
    expect(auth.setCompletedTours).toHaveBeenCalledWith(['welcome']);
    expect(tourService.complete).toHaveBeenCalledWith('welcome');
  });

  it('keeps a skipped tour closed when the server cannot save it (e.g. migration not run)', async () => {
    (tourService.complete as Mock).mockRejectedValue(new Error('500'));
    const first = renderAt('/', ['/', '/settings/whatsapp']);
    await screen.findByRole('dialog', { name: 'Welcome aboard 👋' }, { timeout: 2000 });
    await userEvent.click(screen.getByRole('button', { name: 'Skip tour' }));
    first.unmount();

    // The next /auth/me still says nothing was finished — the tour must not come back.
    auth.user = { id: 1, completed_tours: [], account: { account_type: 'client' } };
    renderAt('/', ['/', '/settings/whatsapp']);
    await act(() => new Promise((r) => setTimeout(r, 900)));
    expect(screen.queryByTestId('tour-overlay')).toBeNull();
  });

  it('walks through the steps with Next and Back', async () => {
    renderAt('/', ['/', '/settings/whatsapp']);
    await screen.findByRole('dialog', { name: 'Welcome aboard 👋' }, { timeout: 2000 });

    await userEvent.click(screen.getByRole('button', { name: 'Next' }));
    expect(screen.getByRole('dialog', { name: 'Dashboard' })).toBeTruthy();
    await userEvent.click(screen.getByRole('button', { name: 'Back' }));
    expect(screen.getByRole('dialog', { name: 'Welcome aboard 👋' })).toBeTruthy();
  });

  it('offers Skip on every step but the last, and skipping from the middle ends the tour', async () => {
    renderAt('/', ['/', '/settings/whatsapp']);
    await screen.findByRole('dialog', { name: 'Welcome aboard 👋' }, { timeout: 2000 });

    await userEvent.click(screen.getByRole('button', { name: 'Next' }));
    expect(screen.getByRole('button', { name: 'Skip tour' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Back' })).toBeTruthy();

    // Last step: Done only, no Skip.
    while (screen.queryByRole('button', { name: 'Next' })) {
      await userEvent.click(screen.getByRole('button', { name: 'Next' }));
    }
    expect(screen.queryByRole('button', { name: 'Skip tour' })).toBeNull();
    await userEvent.click(screen.getByRole('button', { name: 'Back' }));

    await userEvent.click(screen.getByRole('button', { name: 'Skip tour' }));
    expect(screen.queryByTestId('tour-overlay')).toBeNull();
    expect(tourService.complete).toHaveBeenCalledWith('welcome');
  });

  it('after the welcome tour, a page tour shows on the first visit only', async () => {
    auth.user = { id: 1, completed_tours: ['welcome'], account: { account_type: 'client' } };
    const { unmount } = renderAt('/billing', ['/', '/billing']);
    expect(await screen.findByRole('dialog', { name: 'Your plan' }, { timeout: 2000 })).toBeTruthy();
    await userEvent.click(screen.getByRole('button', { name: 'Done' }));
    expect(tourService.complete).toHaveBeenCalledWith('page:/billing');
    unmount();

    auth.user = { id: 1, completed_tours: ['welcome', 'page:/billing'], account: { account_type: 'client' } };
    renderAt('/billing', ['/', '/billing']);
    await new Promise((r) => setTimeout(r, 800));
    expect(screen.queryByTestId('tour-overlay')).toBeNull();
  });

  it('the ? button replays a finished tour', async () => {
    auth.user = { id: 1, completed_tours: ['welcome', 'page:/billing'], account: { account_type: 'client' } };
    renderAt('/billing', ['/', '/billing']);

    act(() => {
      window.dispatchEvent(new CustomEvent(TOUR_REPLAY_EVENT, { detail: { kind: 'page' } }));
    });
    expect(await screen.findByRole('dialog', { name: 'Your plan' })).toBeTruthy();
    await userEvent.keyboard('{Escape}');
    expect(screen.queryByTestId('tour-overlay')).toBeNull();
    expect(tourService.complete).not.toHaveBeenCalled(); // already finished: nothing to save
  });

  it('greets a Super Admin with the platform tour', async () => {
    auth.superAdmin = true;
    auth.user = { id: 1, completed_tours: [], account: null };
    renderAt('/', ['/', '/admin/accounts', '/admin/plans']);

    expect(await screen.findByRole('dialog', { name: 'Welcome, Super Admin 👋' }, { timeout: 2000 })).toBeTruthy();
  });
});

describe('Journey nodes tour', () => {
  it('explains every node the palette offers, from the node registry', () => {
    const steps = buildJourneyNodesTour();
    const listed = steps.flatMap((s) => s.points ?? []).join(' | ');

    for (const category of JOURNEY_NODE_CATEGORIES) {
      for (const node of journeyNodesByCategory(category)) {
        expect(listed).toContain(`${node.label} — ${node.description}`);
      }
    }
    expect(steps.map((s) => s.target)).toEqual(expect.arrayContaining(['journey-canvas', 'journey-start-node', 'journey-palette', 'journey-save']));
  });

  it('opens once when the editor asks for it, and the How nodes work button replays it', async () => {
    auth.user = { id: 1, completed_tours: ['welcome'], account: { account_type: 'client' } };
    renderAt('/chatbot/journeys/new', ['/']);

    act(() => startTour(JOURNEY_NODES_TOUR));
    expect(await screen.findByRole('dialog', { name: 'Your journey canvas' })).toBeTruthy();
    await userEvent.click(screen.getByRole('button', { name: 'Skip tour' }));
    expect(tourService.complete).toHaveBeenCalledWith('journey:nodes');
  });

  it('is not shown again once finished, unless replayed', async () => {
    auth.user = { id: 1, completed_tours: ['welcome', 'journey:nodes'], account: { account_type: 'client' } };
    renderAt('/chatbot/journeys/new', ['/']);

    act(() => startTour(JOURNEY_NODES_TOUR));
    await new Promise((r) => setTimeout(r, 300));
    expect(screen.queryByTestId('tour-overlay')).toBeNull();

    act(() => startTour(JOURNEY_NODES_TOUR, true));
    expect(await screen.findByRole('dialog', { name: 'Your journey canvas' })).toBeTruthy();
  });
});
