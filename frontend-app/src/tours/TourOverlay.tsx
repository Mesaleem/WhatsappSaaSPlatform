import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { ChevronLeft, ChevronRight, X } from 'lucide-react';
import type { TourStep } from './tourContent';

/**
 * Product tour overlay (owner, 2026-10-15) — no third-party tour library.
 * Highlights the step's `[data-tour]` element (when it is on screen) with a
 * spotlight and places the card next to it; otherwise the card is centered
 * (e.g. on a phone, where the sidebar is hidden). ← / → / Enter move, Esc skips.
 */

const CARD_WIDTH = 340;
const GAP = 14;

interface Rect {
  top: number;
  left: number;
  width: number;
  height: number;
}

function findTarget(target?: string): Rect | null {
  if (!target) return null;
  const el = document.querySelector<HTMLElement>(`[data-tour="${CSS.escape(target)}"]`);
  if (!el) return null;
  el.scrollIntoView?.({ block: 'nearest' });
  const r = el.getBoundingClientRect();
  const visible = r.width > 0 && r.height > 0 && r.bottom > 0 && r.right > 0 && r.top < window.innerHeight && r.left < window.innerWidth;
  return visible ? { top: r.top, left: r.left, width: r.width, height: r.height } : null;
}

/** Card position beside the target: right, else below, else above; clamped to the viewport. */
function cardPosition(rect: Rect | null, cardHeight: number): React.CSSProperties {
  if (!rect) {
    return { top: '50%', left: '50%', transform: 'translate(-50%, -50%)' };
  }
  const vw = window.innerWidth;
  const vh = window.innerHeight;
  const width = Math.min(CARD_WIDTH, vw - 24);
  let top: number;
  let left: number;
  if (rect.left + rect.width + GAP + width <= vw - 12) {
    left = rect.left + rect.width + GAP;
    top = rect.top + rect.height / 2 - cardHeight / 2;
  } else if (rect.top + rect.height + GAP + cardHeight <= vh - 12) {
    top = rect.top + rect.height + GAP;
    left = rect.left + rect.width / 2 - width / 2;
  } else {
    top = rect.top - GAP - cardHeight;
    left = rect.left + rect.width / 2 - width / 2;
  }
  return {
    top: Math.max(12, Math.min(top, vh - cardHeight - 12)),
    left: Math.max(12, Math.min(left, vw - width - 12)),
    width,
  };
}

export default function TourOverlay({
  steps,
  onFinish,
}: {
  steps: TourStep[];
  /** Called once when the tour is finished or skipped. */
  onFinish: () => void;
}) {
  const [index, setIndex] = useState(0);
  const [rect, setRect] = useState<Rect | null>(null);
  const [cardHeight, setCardHeight] = useState(180);
  const cardRef = useRef<HTMLDivElement>(null);
  const step = steps[index];
  const last = index === steps.length - 1;

  const measure = useCallback(() => setRect(findTarget(step?.target)), [step]);

  useLayoutEffect(() => {
    measure();
    if (cardRef.current) setCardHeight(cardRef.current.offsetHeight);
  }, [measure, index]);

  useEffect(() => {
    window.addEventListener('resize', measure);
    window.addEventListener('scroll', measure, true);
    return () => {
      window.removeEventListener('resize', measure);
      window.removeEventListener('scroll', measure, true);
    };
  }, [measure]);

  useEffect(() => {
    cardRef.current?.focus();
  }, [index]);

  const next = useCallback(() => (last ? onFinish() : setIndex((i) => i + 1)), [last, onFinish]);
  const back = useCallback(() => setIndex((i) => Math.max(0, i - 1)), []);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onFinish();
      else if (e.key === 'ArrowRight' || e.key === 'Enter') next();
      else if (e.key === 'ArrowLeft') back();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [next, back, onFinish]);

  if (!step) return null;

  return (
    <div className="fixed inset-0 z-[80]" data-testid="tour-overlay">
      {rect ? (
        <div
          aria-hidden="true"
          className="pointer-events-none fixed rounded-xl ring-2 ring-emerald-400 transition-all duration-200"
          style={{
            top: rect.top - 4,
            left: rect.left - 4,
            width: rect.width + 8,
            height: rect.height + 8,
            boxShadow: '0 0 0 9999px rgba(15, 23, 42, 0.55)',
          }}
        />
      ) : (
        <div aria-hidden="true" className="fixed inset-0 bg-slate-900/55" />
      )}

      <div
        ref={cardRef}
        role="dialog"
        aria-modal="true"
        aria-label={step.title}
        tabIndex={-1}
        className="fixed rounded-2xl bg-white p-5 shadow-2xl outline-none ring-1 ring-slate-200"
        style={{ width: CARD_WIDTH, maxWidth: 'calc(100vw - 24px)', ...cardPosition(rect, cardHeight) }}
      >
        <div className="flex items-start justify-between gap-3">
          <h2 className="font-display text-base font-bold text-slate-900">{step.title}</h2>
          <button type="button" onClick={onFinish} className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Close tour">
            <X className="h-4 w-4" />
          </button>
        </div>
        <p className="mt-2 text-sm leading-relaxed text-slate-600">{step.body}</p>
        {step.points && step.points.length > 0 && (
          <ul className="mt-2 max-h-[40vh] space-y-1 overflow-y-auto pr-1 text-sm text-slate-600">
            {step.points.map((p) => (
              <li key={p} className="flex gap-2">
                <span className="mt-2 h-1.5 w-1.5 flex-shrink-0 rounded-full bg-emerald-500" />
                {p}
              </li>
            ))}
          </ul>
        )}

        <div className="mt-4 flex items-center justify-between gap-2">
          <div className="flex items-center gap-1" aria-label={`Step ${index + 1} of ${steps.length}`}>
            {steps.map((_, i) => (
              <span key={i} className={`h-1.5 rounded-full transition-all ${i === index ? 'w-4 bg-emerald-600' : 'w-1.5 bg-slate-200'}`} />
            ))}
          </div>
          <div className="flex items-center gap-1.5">
            {/* Skip on every step but the last (which has Done); Back from the second step on. */}
            {!last && (
              <button type="button" onClick={onFinish} className="rounded-lg px-2.5 py-1.5 text-xs font-medium text-slate-500 hover:bg-slate-100">
                Skip tour
              </button>
            )}
            {index > 0 && (
              <button type="button" onClick={back} className="flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-100">
                <ChevronLeft className="h-3.5 w-3.5" />
                Back
              </button>
            )}
            <button
              type="button"
              onClick={next}
              className="flex items-center gap-1 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700"
            >
              {last ? 'Done' : 'Next'}
              {!last && <ChevronRight className="h-3.5 w-3.5" />}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
