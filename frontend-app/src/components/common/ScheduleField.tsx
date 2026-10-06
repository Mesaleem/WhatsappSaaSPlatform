import { CalendarClock, X } from 'lucide-react';

export interface ScheduleValue {
  /** YYYY-MM-DD, or empty. */
  date: string;
  /** HH:MM (24-hour), or empty. */
  time: string;
}

export const EMPTY_SCHEDULE: ScheduleValue = { date: '', time: '' };

/** Every scheduled time is Indian Standard Time (UTC+05:30), whatever zone the browser is in. */
const IST_OFFSET_MS = (5 * 60 + 30) * 60 * 1000;

/** The time limits the server also enforces: at least one minute ahead, at most 90 days ahead. */
const MIN_LEAD_MS = 60 * 1000;
const MAX_LEAD_MS = 90 * 24 * 60 * 60 * 1000;

/** The chosen wall-clock time read as IST, as a UTC millisecond timestamp; null when not valid. */
function istInstantMs(value: ScheduleValue): number | null {
  const [year, month, day] = value.date.split('-').map(Number);
  const [hour, minute] = value.time.split(':').map(Number);
  if ([year, month, day, hour, minute].some((n) => Number.isNaN(n))) return null;
  const ms = Date.UTC(year, month - 1, day, hour, minute) - IST_OFFSET_MS;
  return Number.isNaN(ms) ? null : ms;
}

/** Today's date in IST as YYYY-MM-DD, used for the date picker's minimum. */
function todayInIst(): string {
  return new Date(Date.now() + IST_OFFSET_MS).toISOString().slice(0, 10);
}

/**
 * The chosen time in the format the API takes: "YYYY-MM-DD HH:MM:SS", read as IST.
 * Null when the send is immediate.
 */
export function scheduleToIso(value: ScheduleValue): string | null {
  if (!value.date || !value.time || istInstantMs(value) === null) return null;
  return `${value.date} ${value.time}:00`;
}

/** A message the user can read about the chosen time, or null when the time is fine or not set. */
export function scheduleError(value: ScheduleValue): string | null {
  if (!value.date && !value.time) return null;
  if (!value.date || !value.time) return 'Choose both the date and the time, or clear them to send now.';

  const at = istInstantMs(value);
  if (at === null) return 'The date or time is not valid.';

  const lead = at - Date.now();
  if (lead < MIN_LEAD_MS) return 'Choose a time at least one minute from now (IST).';
  if (lead > MAX_LEAD_MS) return 'You can schedule a message up to 90 days ahead.';

  return null;
}

/** Human text for the chosen time, always in IST. */
export function formatSchedule(value: ScheduleValue): string {
  if (!scheduleToIso(value)) return '';
  return `${value.date} ${value.time} IST`;
}

const inputClass =
  'rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 disabled:bg-slate-50';

/**
 * Optional "send later" field: a calendar date and a clock time. Empty means the message is sent
 * now. It sits beside the Media URL field and is never required.
 */
export default function ScheduleField({
  value,
  onChange,
  disabled = false,
}: {
  value: ScheduleValue;
  onChange: (next: ScheduleValue) => void;
  disabled?: boolean;
}) {
  const problem = scheduleError(value);
  const today = todayInIst();

  return (
    <div>
      <label className="flex items-center gap-1.5 text-sm font-medium text-slate-700">
        <CalendarClock className="h-4 w-4 text-slate-500" />
        Send later
        <span className="ml-1 text-xs font-normal text-slate-400">(optional — leave empty to send now; time is Indian Standard Time, IST)</span>
      </label>
      <div className="mt-1.5 flex flex-wrap items-center gap-2">
        <input
          type="date"
          aria-label="Send date"
          min={today}
          value={value.date}
          disabled={disabled}
          onChange={(e) => onChange({ ...value, date: e.target.value })}
          className={inputClass}
        />
        <input
          type="time"
          aria-label="Send time"
          value={value.time}
          disabled={disabled}
          onChange={(e) => onChange({ ...value, time: e.target.value })}
          className={inputClass}
        />
        {(value.date || value.time) && (
          <button
            type="button"
            onClick={() => onChange(EMPTY_SCHEDULE)}
            disabled={disabled}
            className="flex items-center gap-1 rounded-lg border border-slate-300 px-2.5 py-2 text-xs font-medium text-slate-600 hover:bg-slate-50 disabled:opacity-50"
          >
            <X className="h-3.5 w-3.5" /> Clear
          </button>
        )}
      </div>
      {problem ? (
        <p className="mt-1.5 text-xs text-red-600">{problem}</p>
      ) : value.date && value.time ? (
        <p className="mt-1.5 text-xs text-slate-600">This message will be sent on {formatSchedule(value)}.</p>
      ) : null}
    </div>
  );
}
