/**
 * UI/UX Standard Enforcement — shared table loading skeleton. Replaces the
 * old one-line "Loading…" text / bare centered spinner rows with row-shaped
 * animated placeholder bars, so a table's loading state visually previews
 * its final shape instead of collapsing to a single empty-feeling row.
 */
export function TableSkeletonRows({
  rows = 5,
  columns,
}: {
  /** Number of placeholder rows to render (default 5). */
  rows?: number;
  /** Must match the real table's column count so the skeleton lines up under the header. */
  columns: number;
}) {
  return (
    <>
      {Array.from({ length: rows }).map((_, rowIdx) => (
        <tr key={rowIdx} className="animate-pulse">
          {Array.from({ length: columns }).map((_, colIdx) => (
            <td key={colIdx} className="px-4 py-3">
              <div
                className="h-4 rounded bg-slate-200"
                style={{ width: colIdx === 0 ? '70%' : `${45 + ((rowIdx * 7 + colIdx * 13) % 40)}%` }}
              />
            </td>
          ))}
        </tr>
      ))}
    </>
  );
}
