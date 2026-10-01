import type { AttendanceStatus } from '../../types/education';

/** Today as YYYY-MM-DD in the BROWSER's timezone (the school's day, not UTC's). */
export function todayLocal(now: Date = new Date()): string {
  const m = String(now.getMonth() + 1).padStart(2, '0');
  const d = String(now.getDate()).padStart(2, '0');
  return `${now.getFullYear()}-${m}-${d}`;
}

/** Percentage for display: null (no records) is N/A, never 0%. */
export function formatPercentage(value: number | null | undefined): string {
  return value === null || value === undefined ? 'N/A' : `${value}%`;
}

export const STATUS_LABELS: Record<AttendanceStatus, string> = { present: 'Present', absent: 'Absent', late: 'Late' };

export const STATUS_STYLES: Record<AttendanceStatus, string> = {
  present: 'bg-emerald-50 text-emerald-700 border-emerald-200',
  absent: 'bg-red-50 text-red-700 border-red-200',
  late: 'bg-amber-50 text-amber-700 border-amber-200',
};
