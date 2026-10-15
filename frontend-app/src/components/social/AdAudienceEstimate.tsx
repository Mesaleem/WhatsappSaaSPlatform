import { useEffect, useRef, useState } from 'react';
import { Loader2, Users } from 'lucide-react';
import adsService from '../../services/adsService';
import type { AdPlacement, AdReachEstimate, TargetingSpecs } from '../../types/ads';

const people = new Intl.NumberFormat('en-IN', { notation: 'compact', maximumFractionDigits: 1 });

/**
 * Phase A (2026-10-15) — Meta's estimated audience size for the targeting in the
 * launch form (POST /social/ads/reach-estimate), refreshed 800 ms after the last
 * change. Informational only: no estimate never blocks a launch.
 */
export default function AdAudienceEstimate({ targeting, placements }: { targeting: TargetingSpecs | null; placements: AdPlacement[] }) {
  const [estimate, setEstimate] = useState<AdReachEstimate | null>(null);
  const [state, setState] = useState<'idle' | 'loading' | 'ready' | 'none'>('idle');
  const seq = useRef(0);
  const key = targeting ? JSON.stringify([targeting, placements]) : '';

  useEffect(() => {
    const mine = ++seq.current;
    if (!targeting) return undefined;
    const timer = setTimeout(() => {
      setState('loading');
      Promise.resolve()
        .then(() => adsService.reachEstimate(targeting, placements))
        .then((result) => {
          if (mine !== seq.current) return;
          setEstimate(result);
          setState(result ? 'ready' : 'none');
        })
        .catch(() => {
          if (mine === seq.current) setState('none');
        });
    }, 800);
    return () => clearTimeout(timer);
    // `key` captures every targeting change; the objects themselves are rebuilt each render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key]);

  if (!targeting) return null;

  return (
    <div className="flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm" data-testid="ad-audience-estimate" aria-live="polite">
      <Users className="h-4 w-4 flex-shrink-0 text-emerald-700" />
      {state === 'ready' && estimate ? (
        <span className="text-slate-700">
          Estimated audience:{' '}
          <strong className="text-slate-900">
            {people.format(estimate.users_lower_bound)} – {people.format(estimate.users_upper_bound)} people
          </strong>
        </span>
      ) : state === 'none' ? (
        <span className="text-slate-500">Meta has no audience estimate for this targeting right now.</span>
      ) : (
        <span className="inline-flex items-center gap-1 text-slate-500">
          <Loader2 className="h-3.5 w-3.5 animate-spin" /> Estimating audience…
        </span>
      )}
    </div>
  );
}
