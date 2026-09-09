import { useEffect, useRef, useState } from 'react';
import { ChevronLeft, ChevronRight, Search } from 'lucide-react';

/**
 * UI Standardization — Data Table Standardization. Shared search bar,
 * status filter dropdown and pagination bar so every module's table
 * offers the same controls in the same place, styled identically.
 */

/** Real-time text search with a built-in 300ms debounce (fires onChange after typing settles, not on every keystroke). */
export function SearchInput({
  value,
  onChange,
  placeholder = 'Search…',
}: {
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
}) {
  const [draft, setDraft] = useState(value);
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  // Keep the draft in sync when the controlled value is reset externally
  // (e.g. a "Clear filters" action), without fighting the debounce timer.
  useEffect(() => {
    setDraft(value);
  }, [value]);

  const handleChange = (next: string) => {
    setDraft(next);
    if (timerRef.current) clearTimeout(timerRef.current);
    timerRef.current = setTimeout(() => onChange(next), 300);
  };

  useEffect(() => () => {
    if (timerRef.current) clearTimeout(timerRef.current);
  }, []);

  return (
    <div className="relative">
      <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
      <input
        type="text"
        value={draft}
        onChange={(e) => handleChange(e.target.value)}
        placeholder={placeholder}
        className="w-full rounded-lg border border-slate-300 py-2 pl-9 pr-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 sm:w-64"
      />
    </div>
  );
}

export interface StatusFilterOption {
  value: string;
  label: string;
}

export function StatusFilterSelect({
  value,
  onChange,
  options,
  allLabel = 'All statuses',
}: {
  value: string;
  onChange: (value: string) => void;
  options: StatusFilterOption[];
  allLabel?: string;
}) {
  return (
    <select
      value={value}
      onChange={(e) => onChange(e.target.value)}
      className="rounded-lg border border-slate-300 bg-white py-2 pl-3 pr-8 text-sm text-slate-700 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
    >
      <option value="">{allLabel}</option>
      {options.map((opt) => (
        <option key={opt.value} value={opt.value}>
          {opt.label}
        </option>
      ))}
    </select>
  );
}

const DEFAULT_PER_PAGE_OPTIONS = [10, 15, 25, 50];

/** Previous/Next + numbered pages (current ± 2, with first/last and ellipses) + a rows-per-page selector. */
export function Pagination({
  page,
  lastPage,
  total,
  perPage,
  onPageChange,
  onPerPageChange,
  perPageOptions = DEFAULT_PER_PAGE_OPTIONS,
}: {
  page: number;
  lastPage: number;
  total: number;
  perPage: number;
  onPageChange: (page: number) => void;
  /** Omit to hide the rows-per-page selector (e.g. a table with a fixed page size). */
  onPerPageChange?: (perPage: number) => void;
  perPageOptions?: number[];
}) {
  if (lastPage <= 1 && !onPerPageChange) return null;

  const pageNumbers = (): (number | 'ellipsis')[] => {
    const nums: (number | 'ellipsis')[] = [];
    const windowStart = Math.max(2, page - 2);
    const windowEnd = Math.min(lastPage - 1, page + 2);

    nums.push(1);
    if (windowStart > 2) nums.push('ellipsis');
    for (let p = windowStart; p <= windowEnd; p++) nums.push(p);
    if (windowEnd < lastPage - 1) nums.push('ellipsis');
    if (lastPage > 1) nums.push(lastPage);

    return nums;
  };

  const rangeStart = total === 0 ? 0 : (page - 1) * perPage + 1;
  const rangeEnd = Math.min(page * perPage, total);

  return (
    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-6 py-3 text-sm text-slate-600">
      <span>{total > 0 ? `Showing ${rangeStart}–${rangeEnd} of ${total}` : 'No results'}</span>

      <div className="flex items-center gap-4">
        {onPerPageChange && (
          <label className="flex items-center gap-1.5 text-xs text-slate-500">
            Rows per page
            <select
              value={perPage}
              onChange={(e) => onPerPageChange(Number(e.target.value))}
              className="rounded-md border border-slate-300 bg-white py-1 pl-2 pr-6 text-xs text-slate-700 outline-none focus:border-indigo-500"
            >
              {perPageOptions.map((n) => (
                <option key={n} value={n}>
                  {n}
                </option>
              ))}
            </select>
          </label>
        )}

        {lastPage > 1 && (
          <div className="flex items-center gap-1">
            <button
              onClick={() => onPageChange(page - 1)}
              disabled={page <= 1}
              aria-label="Previous page"
              className="rounded-md border border-slate-300 p-1.5 text-slate-600 hover:bg-slate-50 disabled:opacity-40"
            >
              <ChevronLeft className="h-3.5 w-3.5" />
            </button>
            {pageNumbers().map((p, idx) =>
              p === 'ellipsis' ? (
                <span key={`e-${idx}`} className="px-1.5 text-slate-400">
                  …
                </span>
              ) : (
                <button
                  key={p}
                  onClick={() => onPageChange(p)}
                  className={`min-w-[28px] rounded-md px-2 py-1 text-xs font-medium ${
                    p === page ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100'
                  }`}
                >
                  {p}
                </button>
              ),
            )}
            <button
              onClick={() => onPageChange(page + 1)}
              disabled={page >= lastPage}
              aria-label="Next page"
              className="rounded-md border border-slate-300 p-1.5 text-slate-600 hover:bg-slate-50 disabled:opacity-40"
            >
              <ChevronRight className="h-3.5 w-3.5" />
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
