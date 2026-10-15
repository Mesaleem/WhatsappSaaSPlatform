import TourOverlay from './TourOverlay';
import { buildJourneyNodesTour } from './journeyTour';
import { JOURNEY_NODES_TOUR } from './tourKeys';
import type { TourStep } from './tourContent';

/**
 * Tours a screen asks for itself (TOUR_START_EVENT), not tied to a route —
 * e.g. the Journey nodes tour when the journey editor opens. Lazy-loaded by
 * TourHost on demand, so the node registry it reads is never pulled into the
 * dashboard.
 */
const NAMED_TOURS: Record<string, () => TourStep[]> = {
  [JOURNEY_NODES_TOUR]: buildJourneyNodesTour,
};

export default function NamedTourRunner({ tourKey, onFinish }: { tourKey: string; onFinish: () => void }) {
  const steps = NAMED_TOURS[tourKey]?.() ?? [];

  if (steps.length === 0) {
    queueMicrotask(onFinish);
    return null;
  }

  return <TourOverlay steps={steps} onFinish={onFinish} />;
}
