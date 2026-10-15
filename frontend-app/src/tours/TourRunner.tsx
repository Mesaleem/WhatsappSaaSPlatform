import TourOverlay from './TourOverlay';
import { buildWelcomeTour, PAGE_TOURS, type TourAudience } from './tourContent';

/**
 * The lazily-loaded half of the product tours: builds the steps (welcome
 * tour from the visible sidebar routes, or the current page's tour) and shows
 * them. TourHost imports this only when a tour is due.
 */
export default function TourRunner({
  kind,
  route,
  visibleRoutes,
  audience,
  onFinish,
}: {
  kind: 'welcome' | 'page';
  route: string;
  visibleRoutes: string[];
  audience: TourAudience;
  onFinish: () => void;
}) {
  const steps = kind === 'welcome' ? buildWelcomeTour(visibleRoutes, audience) : (PAGE_TOURS[route] ?? []);

  if (steps.length === 0) {
    queueMicrotask(onFinish);
    return null;
  }

  return <TourOverlay steps={steps} onFinish={onFinish} />;
}
