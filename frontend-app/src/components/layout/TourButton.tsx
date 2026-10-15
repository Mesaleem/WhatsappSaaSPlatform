import { useEffect, useRef, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { HelpCircle } from 'lucide-react';
import { PAGE_TOUR_ROUTES, TOUR_REPLAY_EVENT, type TourReplayDetail } from '../../tours/tourKeys';

/** Header ? button: replay the welcome tour, or this page's tour (product tours, 2026-10-15). */
export default function TourButton() {
  const { pathname } = useLocation();
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  const hasPageTour = (PAGE_TOUR_ROUTES as readonly string[]).includes(pathname);

  useEffect(() => {
    if (!open) return;
    const close = (e: MouseEvent) => {
      if (!ref.current?.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', close);
    return () => document.removeEventListener('mousedown', close);
  }, [open]);

  const replay = (kind: TourReplayDetail['kind']) => {
    setOpen(false);
    window.dispatchEvent(new CustomEvent<TourReplayDetail>(TOUR_REPLAY_EVENT, { detail: { kind } }));
  };

  return (
    <div className="relative" ref={ref}>
      <button
        type="button"
        data-tour="tour-button"
        onClick={() => setOpen((o) => !o)}
        aria-label="Help and tours"
        aria-expanded={open}
        title="Help and tours"
        className="flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-slate-800"
      >
        <HelpCircle className="h-5 w-5" />
      </button>
      {open && (
        <div role="menu" className="absolute right-0 z-50 mt-2 w-52 rounded-xl border border-slate-200 bg-white p-1.5 shadow-lg">
          {hasPageTour && (
            <button type="button" role="menuitem" onClick={() => replay('page')} className="w-full rounded-lg px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50">
              Tour this page
            </button>
          )}
          <button type="button" role="menuitem" onClick={() => replay('welcome')} className="w-full rounded-lg px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50">
            Replay the welcome tour
          </button>
        </div>
      )}
    </div>
  );
}
