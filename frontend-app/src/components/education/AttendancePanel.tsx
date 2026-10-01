import { useEffect, useRef, useState } from 'react';
import { Loader2 } from 'lucide-react';
import educationService from '../../services/educationService';
import { TableCard, inputClass } from '../common/Card';
import { TableSkeletonRows } from '../common/Skeleton';
import { EmptyState, ErrorBanner, READ_ONLY_TITLE } from '../crm/CrmUi';
import { useCrmQuery } from '../crm/crmHooks';
import { describeApiError } from '../../utils/apiError';
import { formatPercentage, STATUS_LABELS, todayLocal } from './attendanceUtils';
import { ATTENDANCE_STATUSES, type AttendanceSheet, type AttendanceStatus, type EducationGroup } from '../../types/education';

/**
 * Phase 11 Task 3 — mark attendance: class/batch → date → students → Present / Absent / Late → Save.
 * The whole sheet goes in ONE request (PUT /groups/{id}/attendance). Nothing is authorized here: the
 * server decides, the UI only disables what would be refused (expired subscription, archived group,
 * no manage-education).
 */
export default function AttendancePanel({
  accountKey,
  groups,
  groupsLoading,
  canManage,
  readOnly,
  onToast,
}: {
  accountKey: number | null;
  groups: EducationGroup[];
  groupsLoading: boolean;
  canManage: boolean;
  readOnly: boolean;
  onToast: (message: string) => void;
}) {
  // The selection belongs to the client it was made for: after a client switch it no longer applies.
  const [selection, setSelection] = useState<{ account: number | null; groupId: number | null }>({ account: accountKey, groupId: null });
  const groupId = selection.account === accountKey ? selection.groupId : null;
  const [date, setDate] = useState(todayLocal());
  const group = groups.find((g) => g.id === groupId) ?? null;
  const archived = group?.status === 'archived';
  const dateValid = /^\d{4}-\d{2}-\d{2}$/.test(date);

  const sheetKey = groupId !== null && dateValid ? JSON.stringify({ account: accountKey, groupId, date }) : null;
  const query = useCrmQuery<AttendanceSheet>(sheetKey, () => educationService.attendanceSheet(groupId as number, date), 'Failed to load the attendance sheet.');
  const sheet = query.data;

  // Unsaved marks live next to the key of the sheet they were made on, so a different class/date/client
  // starts clean without an effect.
  const [edits, setEdits] = useState<{ key: string | null; values: Record<number, AttendanceStatus> }>({ key: null, values: {} });
  const marks = edits.key === sheetKey ? edits.values : {};
  const statusOf = (studentId: number, saved: AttendanceStatus | null) => marks[studentId] ?? saved;

  const [saving, setSaving] = useState(false);
  const inFlight = useRef(false);
  const currentKey = useRef(sheetKey);
  useEffect(() => {
    currentKey.current = sheetKey;
  }, [sheetKey]);
  const [error, setError] = useState<{ key: string | null; message: string; rows: Record<number, string> } | null>(null);
  const shownError = error && error.key === sheetKey ? error : null;

  const mark = (studentId: number, status: AttendanceStatus) => setEdits({ key: sheetKey, values: { ...marks, [studentId]: status } });
  const markAllPresent = () => {
    if (!sheet) return;
    setEdits({ key: sheetKey, values: Object.fromEntries(sheet.students.map((s) => [s.student_id, 'present' as AttendanceStatus])) });
  };

  const records = (sheet?.students ?? [])
    .map((s) => ({ student_id: s.student_id, status: statusOf(s.student_id, s.status) }))
    .filter((r): r is { student_id: number; status: AttendanceStatus } => r.status !== null);
  const dirty = Object.keys(marks).length > 0;
  const canSave = canManage && !readOnly && !archived && !saving && records.length > 0 && dirty;

  const save = () => {
    if (!canSave || inFlight.current || groupId === null) return;
    inFlight.current = true;
    setSaving(true);
    setError(null);
    const savedKey = sheetKey;
    educationService
      .saveAttendance(groupId, date, records)
      .then((res) => {
        onToast(res.message);
        // A late answer for a class/date/client the user has since left must not overwrite what they now see.
        if (currentKey.current === savedKey) {
          query.setData(() => res.data);
          setEdits({ key: null, values: {} });
        }
      })
      .catch((err: unknown) => {
        const described = describeApiError(err, 'Failed to save attendance.');
        const rows: Record<number, string> = {};
        Object.entries(described.fieldErrors).forEach(([field, message]) => {
          const m = /^records\.(\d+)\./.exec(field);
          if (m) rows[records[Number(m[1])]?.student_id] = message;
        });
        const fieldMessages = Object.entries(described.fieldErrors).filter(([f]) => !f.startsWith('records.')).map(([, m]) => m);
        setError({ key: savedKey, message: [described.message, ...fieldMessages.filter((m) => !described.message.includes(m))].join(' '), rows });
      })
      .finally(() => {
        inFlight.current = false;
        setSaving(false);
      });
  };

  const summary = sheet?.summary;

  return (
    <div className="space-y-4" data-testid="attendance-panel">
      <div className="flex flex-wrap items-end gap-3">
        <label className="text-xs font-medium text-slate-600">
          Class / batch
          <select
            className={`${inputClass} min-w-[14rem]`}
            value={groupId ?? ''}
            disabled={groupsLoading}
            onChange={(e) => setSelection({ account: accountKey, groupId: e.target.value === '' ? null : Number(e.target.value) })}
          >
            <option value="">Select a class or batch…</option>
            {groups.map((g) => (
              <option key={g.id} value={g.id}>
                {g.name}
                {g.academic_year ? ` (${g.academic_year})` : ''}
                {g.status === 'archived' ? ' — archived' : ''}
              </option>
            ))}
          </select>
        </label>
        <label className="text-xs font-medium text-slate-600">
          Date
          <input className={inputClass} type="date" value={date} onChange={(e) => setDate(e.target.value)} />
        </label>
        {canManage && sheet && !archived && (
          <button type="button" onClick={markAllPresent} disabled={readOnly || saving} className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50">
            Mark all present
          </button>
        )}
      </div>

      {groupId === null ? (
        <TableCard>
          <EmptyState title="Select a class or batch and a date." hint="The enrolled students load here so you can mark them." />
        </TableCard>
      ) : !dateValid ? (
        <ErrorBanner message="Choose a valid date." />
      ) : (
        <>
          {archived && (
            <div role="status" className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800" data-testid="attendance-archived">
              This class/batch is archived. Past attendance is shown below, but new attendance can no longer be recorded.
            </div>
          )}
          {query.error && <ErrorBanner message={query.error} />}
          {shownError && <ErrorBanner message={shownError.message} />}

          {summary && (
            <div className="flex flex-wrap gap-3 text-sm" data-testid="attendance-summary">
              <Stat label="Students" value={summary.total_students} />
              <Stat label="Present" value={summary.present} />
              <Stat label="Absent" value={summary.absent} />
              <Stat label="Late" value={summary.late} />
              <Stat label="Unmarked" value={summary.unmarked} />
              <Stat label="Attendance" value={formatPercentage(summary.attendance_percentage)} />
            </div>
          )}

          <TableCard>
            <table className="min-w-full divide-y divide-slate-200 text-sm" data-testid="attendance-table">
              <thead className="bg-slate-50">
                <tr>
                  <th className="px-4 py-3 text-left font-medium text-slate-600">Student</th>
                  <th className="px-4 py-3 text-left font-medium text-slate-600">Admission no.</th>
                  <th className="px-4 py-3 text-left font-medium text-slate-600">Attendance</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {query.isLoading ? (
                  <TableSkeletonRows columns={3} />
                ) : !sheet ? null : sheet.students.length === 0 ? (
                  <tr>
                    <td colSpan={3}>
                      <EmptyState title="No students are enrolled in this class/batch yet." hint="Assign students to it from the Students tab." />
                    </td>
                  </tr>
                ) : (
                  sheet.students.map((s) => {
                    const current = statusOf(s.student_id, s.status);
                    return (
                      <tr key={s.student_id} data-testid={`attendance-row-${s.student_id}`}>
                        <td className="px-4 py-3">
                          <span className="font-medium text-slate-900">{s.name || 'Unnamed contact'}</span>
                          <span className="ml-2 text-xs text-slate-500">{s.phone_number}</span>
                          {s.student_status !== 'active' && <span className="ml-2 text-xs capitalize text-amber-700">({s.student_status})</span>}
                          {shownError?.rows[s.student_id] && <p className="mt-1 text-xs text-red-600">{shownError.rows[s.student_id]}</p>}
                        </td>
                        <td className="px-4 py-3 text-slate-700">{s.admission_number ?? '—'}</td>
                        <td className="px-4 py-3">
                          <div className="flex gap-1.5" role="radiogroup" aria-label={`Attendance for ${s.name ?? 'student'}`}>
                            {ATTENDANCE_STATUSES.map((status) => (
                              <button
                                key={status}
                                type="button"
                                role="radio"
                                aria-checked={current === status}
                                disabled={!canManage || readOnly || archived || saving}
                                title={readOnly ? READ_ONLY_TITLE : undefined}
                                onClick={() => mark(s.student_id, status)}
                                className={`rounded-lg border px-3 py-1 text-xs font-medium disabled:cursor-not-allowed ${
                                  current === status ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50 disabled:opacity-60'
                                }`}
                              >
                                {STATUS_LABELS[status]}
                              </button>
                            ))}
                          </div>
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </TableCard>

          {canManage && sheet && sheet.students.length > 0 && !archived && (
            <div className="flex justify-end">
              <button
                type="button"
                onClick={save}
                disabled={!canSave}
                title={readOnly ? READ_ONLY_TITLE : undefined}
                className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
              >
                {saving && <Loader2 className="h-4 w-4 animate-spin" />}
                Save attendance
              </button>
            </div>
          )}
        </>
      )}
    </div>
  );
}

function Stat({ label, value }: { label: string; value: number | string }) {
  return (
    <div className="rounded-lg border border-slate-200 bg-white px-3 py-2">
      <div className="text-xs text-slate-500">{label}</div>
      <div className="font-semibold text-slate-900">{value}</div>
    </div>
  );
}
