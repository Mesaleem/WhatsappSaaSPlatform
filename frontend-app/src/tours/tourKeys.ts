/**
 * Product tours — the tiny part that is always loaded (owner, 2026-10-15):
 * tour keys and which routes have a first-visit page tour. Everything else
 * (texts, overlay) loads only when a tour is shown. Keep PAGE_TOUR_ROUTES in
 * sync with tourContent.ts' PAGE_TOURS (a test checks it).
 */

export const WELCOME_TOUR = 'welcome';

export const PAGE_TOUR_ROUTES = [
  '/settings/whatsapp',
  '/alerts/send',
  '/whatsapp/inbox',
  '/contact-groups',
  '/admin/templates',
  '/chatbot',
  '/chatbot/journeys',
  '/crm/leads',
  '/crm/pipeline',
  '/social/accounts',
  '/social/inbox',
  '/social/ads',
  '/billing',
  '/users',
  '/admin/accounts',
  '/admin/plans',
  '/developer',
] as const;

export const pageTourKey = (route: string) => 'page:' + route;

/** Event the header's ? button sends: replay the welcome tour, or the current page's tour. */
export const TOUR_REPLAY_EVENT = 'app:tour-replay';

export type TourReplayDetail = { kind: 'welcome' | 'page' };

/** The Journey nodes tour — shown the first time the journey editor opens. */
export const JOURNEY_NODES_TOUR = 'journey:nodes';

/** Event a screen sends to show one of its own tours (once, unless `force`). */
export const TOUR_START_EVENT = 'app:tour-start';

export type TourStartDetail = { key: string; force?: boolean };

/** Ask for a screen's own tour: shown only if the user has not finished it (or always, with force). */
export function startTour(key: string, force = false): void {
  window.dispatchEvent(new CustomEvent<TourStartDetail>(TOUR_START_EVENT, { detail: { key, force } }));
}
