import { lazy, Suspense, useCallback, useEffect, useMemo, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { useAuth } from '../core/context/AuthContext';
import tourService from '../services/tourService';
import {
  PAGE_TOUR_ROUTES,
  pageTourKey,
  TOUR_REPLAY_EVENT,
  TOUR_START_EVENT,
  WELCOME_TOUR,
  type TourReplayDetail,
  type TourStartDetail,
} from './tourKeys';
import type { TourAudience } from './tourContent';

// The tour texts and overlay load only when a tour is actually shown.
const TourRunner = lazy(() => import('./TourRunner'));
// A screen's own tours (e.g. Journey nodes) — a separate chunk, so their data stays off the dashboard.
const NamedTourRunner = lazy(() => import('./NamedTourRunner'));

/** Wait for the page to render its elements before pointing at them. */
const START_DELAY_MS = 600;

/**
 * Finished / skipped tours are also kept in this browser (per user), so a tour never comes
 * back when the server save fails — e.g. a database where the `completed_tours` migration
 * has not run yet; the next /auth/me refresh used to bring the skipped tour straight back.
 */
const localKey = (userId: number) => `waphub.completedTours.${userId}`;

function readLocalTours(userId: number): string[] {
  try {
    const parsed: unknown = JSON.parse(window.localStorage.getItem(localKey(userId)) ?? '[]');
    return Array.isArray(parsed) ? parsed.filter((t): t is string => typeof t === 'string') : [];
  } catch {
    return [];
  }
}

function rememberLocalTour(userId: number, tour: string): void {
  try {
    const tours = readLocalTours(userId);
    if (!tours.includes(tour)) window.localStorage.setItem(localKey(userId), JSON.stringify([...tours, tour].slice(-200)));
  } catch {
    // Storage blocked (private window): the server copy still applies.
  }
}

interface ActiveTour {
  kind: 'welcome' | 'page' | 'named';
  key: string;
  route: string;
}

/**
 * Product tours (owner, 2026-10-15). Mounted once in the app shell.
 *   - first login: the welcome tour, built from the sidebar items this user
 *     can see (so it follows the plan, role and permissions);
 *   - afterwards, the first visit to a page with a page tour shows it once;
 *   - the header's ? button replays either (TOUR_REPLAY_EVENT).
 * Finished / skipped tours are kept on the user (POST /api/auth/tours), so a
 * tour never shows twice, on any device.
 */
export default function TourHost({ visibleRoutes }: { visibleRoutes: string[] }) {
  const { user, isSuperAdmin, hasRole, setCompletedTours } = useAuth();
  const { pathname } = useLocation();
  const [active, setActive] = useState<ActiveTour | null>(null);

  const [localDone, setLocalDone] = useState(0); // bumps after a local save so `completed` re-reads it
  const completed = useMemo(() => {
    const server = user?.completed_tours ?? [];
    if (!user) return server;
    return Array.from(new Set([...server, ...readLocalTours(user.id)]));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [user?.id, user?.completed_tours, localDone]);
  const welcomeDone = completed.includes(WELCOME_TOUR);
  const pageTourDue = (PAGE_TOUR_ROUTES as readonly string[]).includes(pathname) && !completed.includes(pageTourKey(pathname));

  // First login → welcome tour; later, the first visit of a page with a tour → that page's tour.
  useEffect(() => {
    if (!user || active) return;
    const due: ActiveTour | null = !welcomeDone
      ? { kind: 'welcome', key: WELCOME_TOUR, route: pathname }
      : pageTourDue
        ? { kind: 'page', key: pageTourKey(pathname), route: pathname }
        : null;
    if (!due) return;
    const timer = window.setTimeout(() => setActive(due), START_DELAY_MS);
    return () => window.clearTimeout(timer);
  }, [user, active, welcomeDone, pageTourDue, pathname]);

  // The header's ? button: replay a tour even when it was finished before.
  useEffect(() => {
    const onReplay = (e: Event) => {
      const kind = (e as CustomEvent<TourReplayDetail>).detail?.kind ?? 'welcome';
      const hasPageTour = (PAGE_TOUR_ROUTES as readonly string[]).includes(pathname);
      setActive(
        kind === 'page' && hasPageTour
          ? { kind: 'page', key: pageTourKey(pathname), route: pathname }
          : { kind: 'welcome', key: WELCOME_TOUR, route: pathname },
      );
    };
    window.addEventListener(TOUR_REPLAY_EVENT, onReplay);
    return () => window.removeEventListener(TOUR_REPLAY_EVENT, onReplay);
  }, [pathname]);

  // A screen asks for its own tour (TOUR_START_EVENT): once per user, or always when forced.
  useEffect(() => {
    const onStart = (e: Event) => {
      const detail = (e as CustomEvent<TourStartDetail>).detail;
      if (!detail?.key) return;
      if (!detail.force && (completed.includes(detail.key) || active)) return;
      setActive({ kind: 'named', key: detail.key, route: pathname });
    };
    window.addEventListener(TOUR_START_EVENT, onStart);
    return () => window.removeEventListener(TOUR_START_EVENT, onStart);
  }, [completed, active, pathname]);

  const finish = useCallback(() => {
    if (!active) return;
    const key = active.key;
    setActive(null);
    if (user) {
      rememberLocalTour(user.id, key);
      setLocalDone((n) => n + 1);
    }
    if (!(user?.completed_tours ?? []).includes(key)) {
      setCompletedTours([...completed, key]);
      // Best effort: if the server save fails, this browser's copy still keeps the tour closed.
      void tourService.complete(key).then(setCompletedTours).catch(() => undefined);
    }
  }, [active, completed, user, setCompletedTours]);

  if (!user || !active) return null;

  const audience: TourAudience = isSuperAdmin()
    ? 'super_admin'
    : user.account?.account_type === 'agent'
      ? 'agent'
      : hasRole('admin')
        ? 'admin'
        : 'member';

  if (active.kind === 'named') {
    return (
      <Suspense fallback={null}>
        <NamedTourRunner tourKey={active.key} onFinish={finish} />
      </Suspense>
    );
  }

  return (
    <Suspense fallback={null}>
      <TourRunner kind={active.kind} route={active.route} visibleRoutes={visibleRoutes} audience={audience} onFinish={finish} />
    </Suspense>
  );
}
