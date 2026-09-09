import type { ReactNode } from 'react';

/**
 * UI Standardization — shared card surface (`rounded-xl border shadow-sm`)
 * used for both content cards and table containers, so every module's
 * cards/tables share one shadow depth, border color and radius instead of
 * each page re-declaring the same class string.
 */
export function Card({
  children,
  className = '',
  padded = true,
}: {
  children: ReactNode;
  className?: string;
  /** false for a table container, where the <table>/<thead> own their own padding. */
  padded?: boolean;
}) {
  return (
    <div className={`rounded-xl border border-slate-200 bg-white shadow-sm ${padded ? 'p-6' : ''} ${className}`}>
      {children}
    </div>
  );
}

/** Standardized table container: card surface + horizontal scroll, no inner padding. */
export function TableCard({ children }: { children: ReactNode }) {
  return (
    <Card padded={false}>
      <div className="overflow-x-auto">{children}</div>
    </Card>
  );
}

export const inputClass =
  'mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100';
