import { useState } from 'react';
import educationService from '../../services/educationService';
import { TableCard, inputClass } from '../common/Card';
import { Pagination } from '../common/DataTableControls';
import { TableSkeletonRows } from '../common/Skeleton';
import { EmptyState, ErrorBanner } from '../crm/CrmUi';
import { useCrmQuery } from '../crm/crmHooks';
import { formatPercentage, STATUS_LABELS, STATUS_STYLES } from './attendanceUtils';
import type { EducationGroup } from '../../types/education';

/**
 * Phase 11 Task 3 — one student's attendance history with group and date filters and Present / Absent /
 * Late totals (computed server-side over the same filters, not just the visible page). No analytics.
 */
export default function AttendanceHistory({ accountKey, groups }: { accountKey: number | null; groups: EducationGroup[] }) {
  // Selections belong to the client they were made for.
  const [sel, setSel] = useState<{ account: number | null; studentId: number | null; groupId: number | null }>({ account: accountKey, studentId: null, groupId: null });
  const current = sel.account === accountKey ? sel : { account: accountKey, studentId: null, groupId: null };
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [page, setPage] = useState(1);

  const students = useCrmQuery(
    JSON.stringify({ students: accountKey, group: current.groupId }),
    () => educationService.listStudents({ group_id: current.groupId }, 1, 100),
    'Failed to load students.',
  );

  const historyKey = current.studentId !== null ? JSON.stringify({ account: accountKey, student: current.studentId, group: current.groupId, from, to, page }) : null;
  const history = useCrmQuery(
    historyKey,
    () => educationService.studentAttendance(current.studentId as number, { group_id: current.groupId, from, to }, page),
    'Failed to load attendance history.',
  );
  const data = history.data;

  const update = (patch: Partial<typeof current>) => {
    setSel({ ...current, ...patch });
    setPage(1);
  };

  return (
    <div className="space-y-4" data-testid="attendance-history">
      <div className="flex flex-wrap items-end gap-3">
        <label className="text-xs font-medium text-slate-600">
          Class / batch
          <select className={inputClass} value={current.groupId ?? ''} onChange={(e) => update({ groupId: e.target.value === '' ? null : Number(e.target.value) })}>
            <option value="">All classes / batches</option>
            {groups.map((g) => (
              <option key={g.id} value={g.id}>{g.name}</option>
            ))}
          </select>
        </label>
        <label className="text-xs font-medium text-slate-600">
          Student
          <select className={`${inputClass} min-w-[14rem]`} value={current.studentId ?? ''} onChange={(e) => update({ studentId: e.target.value === '' ? null : Number(e.target.value) })}>
            <option value="">Select a student…</option>
            {(students.data?.data ?? []).map((s) => (
              <option key={s.id} value={s.id}>{s.contact?.name || s.contact?.phone_number || `Student ${s.id}`}</option>
            ))}
          </select>
        </label>
        <label className="text-xs font-medium text-slate-600">
          From
          <input className={inputClass} type="date" value={from} onChange={(e) => { setFrom(e.target.value); setPage(1); }} />
        </label>
        <label className="text-xs font-medium text-slate-600">
          To
          <input className={inputClass} type="date" value={to} onChange={(e) => { setTo(e.target.value); setPage(1); }} />
        </label>
      </div>

      {students.error && <ErrorBanner message={students.error} />}

      {current.studentId === null ? (
        <TableCard>
          <EmptyState title="Select a student to see their attendance." />
        </TableCard>
      ) : (
        <>
          {history.error && <ErrorBanner message={history.error} />}
          {data && (
            <div className="flex flex-wrap gap-3 text-sm" data-testid="history-totals">
              <Total label="Present" value={data.summary.present} />
              <Total label="Absent" value={data.summary.absent} />
              <Total label="Late" value={data.summary.late} />
              <Total label="Total days" value={data.summary.total} />
              <Total label="Attendance" value={formatPercentage(data.summary.attendance_percentage)} />
            </div>
          )}
          <TableCard>
            <table className="min-w-full divide-y divide-slate-200 text-sm" data-testid="history-table">
              <thead className="bg-slate-50">
                <tr>
                  <th className="px-4 py-3 text-left font-medium text-slate-600">Date</th>
                  <th className="px-4 py-3 text-left font-medium text-slate-600">Class / batch</th>
                  <th className="px-4 py-3 text-left font-medium text-slate-600">Status</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {history.isLoading ? (
                  <TableSkeletonRows columns={3} />
                ) : !data ? null : data.data.length === 0 ? (
                  <tr>
                    <td colSpan={3}>
                      <EmptyState title="No attendance recorded for these filters." />
                    </td>
                  </tr>
                ) : (
                  data.data.map((row) => (
                    <tr key={row.id} data-testid={`history-row-${row.id}`}>
                      <td className="whitespace-nowrap px-4 py-3 text-slate-700">{row.attendance_date}</td>
                      <td className="px-4 py-3 text-slate-700">{row.group?.name ?? '—'}</td>
                      <td className="px-4 py-3">
                        <span className={`rounded-full border px-2.5 py-0.5 text-xs font-medium ${STATUS_STYLES[row.status] ?? ''}`}>{STATUS_LABELS[row.status] ?? row.status}</span>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
            <Pagination page={page} lastPage={data?.last_page ?? 1} total={data?.total ?? 0} perPage={data?.per_page ?? 31} onPageChange={setPage} />
          </TableCard>
        </>
      )}
    </div>
  );
}

function Total({ label, value }: { label: string; value: number | string }) {
  return (
    <div className="rounded-lg border border-slate-200 bg-white px-3 py-2">
      <div className="text-xs text-slate-500">{label}</div>
      <div className="font-semibold text-slate-900">{value}</div>
    </div>
  );
}
