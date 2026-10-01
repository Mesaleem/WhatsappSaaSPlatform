import { useState } from 'react';
import { Plus } from 'lucide-react';
import educationService from '../../services/educationService';
import { TableCard } from '../common/Card';
import { ClearFiltersButton, Pagination, SearchInput } from '../common/DataTableControls';
import { TableSkeletonRows } from '../common/Skeleton';
import { EmptyState, ErrorBanner, READ_ONLY_TITLE } from '../crm/CrmUi';
import { useCrmQuery } from '../crm/crmHooks';
import { EDUCATION_DEFAULT_PER_PAGE } from './educationHooks';
import StudentFormModal from './StudentFormModal';
import { STUDENT_STATUSES, type EducationGroup, type EducationStudent, type StudentStatus } from '../../types/education';

const PER_PAGE_OPTIONS = [10, 20, 50];

/** Students list — GET /students (search, status and class filters, pagination: all server-side). */
export default function StudentsPanel({
  accountKey,
  groups,
  canManage,
  readOnly,
  onToast,
}: {
  accountKey: number | null;
  groups: EducationGroup[];
  canManage: boolean;
  readOnly: boolean;
  onToast: (message: string) => void;
}) {
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<StudentStatus | ''>('');
  const [groupId, setGroupId] = useState<number | null>(null);
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(EDUCATION_DEFAULT_PER_PAGE);
  const [editing, setEditing] = useState<EducationStudent | 'new' | null>(null);

  const query = useCrmQuery(
    JSON.stringify({ account: accountKey, search, status, groupId, page, perPage }),
    () => educationService.listStudents({ search, status, group_id: groupId }, page, perPage),
    'Failed to load students.',
  );
  const students = query.data?.data ?? [];
  const filtersActive = search !== '' || status !== '' || groupId !== null;

  return (
    <div className="space-y-4" data-testid="students-panel">
      <div className="flex flex-wrap items-center gap-3">
        <SearchInput value={search} onChange={(q) => { setSearch(q); setPage(1); }} placeholder="Search name, phone or admission no…" />
        <select
          aria-label="Filter by status"
          value={status}
          onChange={(e) => { setStatus(e.target.value as StudentStatus | ''); setPage(1); }}
          className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700"
        >
          <option value="">All statuses</option>
          {STUDENT_STATUSES.map((s) => (
            <option key={s} value={s}>{s.charAt(0).toUpperCase() + s.slice(1)}</option>
          ))}
        </select>
        <select
          aria-label="Filter by class or batch"
          value={groupId ?? ''}
          onChange={(e) => { setGroupId(e.target.value === '' ? null : Number(e.target.value)); setPage(1); }}
          className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700"
        >
          <option value="">All classes / batches</option>
          {groups.map((g) => (
            <option key={g.id} value={g.id}>{g.name}</option>
          ))}
        </select>
        <ClearFiltersButton active={filtersActive} onClear={() => { setSearch(''); setStatus(''); setGroupId(null); setPage(1); }} />
        {canManage && (
          <button
            type="button"
            onClick={() => setEditing('new')}
            disabled={readOnly}
            title={readOnly ? READ_ONLY_TITLE : undefined}
            className="ml-auto flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
          >
            <Plus className="h-4 w-4" />
            New student
          </button>
        )}
      </div>

      {query.error && <ErrorBanner message={query.error} />}

      <TableCard>
        <table className="min-w-full divide-y divide-slate-200 text-sm" data-testid="students-table">
          <thead className="bg-slate-50">
            <tr>
              {['Student', 'Phone', 'Admission no.', 'Status', 'Classes / batches', 'Parents / guardians', ''].map((h) => (
                <th key={h || 'actions'} className="px-4 py-3 text-left font-medium text-slate-600">{h}</th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {query.isLoading ? (
              <TableSkeletonRows columns={7} />
            ) : students.length === 0 ? (
              <tr>
                <td colSpan={7}>
                  {filtersActive ? (
                    <EmptyState title="No students match these filters." />
                  ) : (
                    <EmptyState title="No students yet." hint="Add a student — they are linked to a CRM contact, so their history stays in your CRM." />
                  )}
                </td>
              </tr>
            ) : (
              students.map((s) => (
                <tr key={s.id} data-testid={`student-row-${s.id}`}>
                  <td className="px-4 py-3 font-medium text-slate-900">{s.contact?.name || 'Unnamed contact'}</td>
                  <td className="whitespace-nowrap px-4 py-3 text-slate-700">{s.contact?.phone_number}</td>
                  <td className="px-4 py-3 text-slate-700">{s.admission_number ?? '—'}</td>
                  <td className="px-4 py-3 capitalize text-slate-700">{s.status}</td>
                  <td className="px-4 py-3 text-slate-700">{s.groups.length ? s.groups.map((g) => g.name).join(', ') : '—'}</td>
                  <td className="px-4 py-3 text-slate-700">
                    {s.guardians.length ? s.guardians.map((g) => `${g.contact?.name || g.contact?.phone_number} (${g.relationship})`).join(', ') : '—'}
                  </td>
                  <td className="px-4 py-3 text-right">
                    {canManage && (
                      <button
                        type="button"
                        onClick={() => setEditing(s)}
                        disabled={readOnly}
                        title={readOnly ? READ_ONLY_TITLE : undefined}
                        className="text-xs font-semibold text-indigo-600 hover:text-indigo-800 disabled:cursor-not-allowed disabled:opacity-50"
                      >
                        Edit
                      </button>
                    )}
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
        <Pagination
          page={page}
          lastPage={query.data?.last_page ?? 1}
          total={query.data?.total ?? 0}
          perPage={perPage}
          onPageChange={setPage}
          onPerPageChange={(n) => { setPerPage(n); setPage(1); }}
          perPageOptions={PER_PAGE_OPTIONS}
        />
      </TableCard>

      {editing !== null && (
        <StudentFormModal
          student={editing === 'new' ? undefined : editing}
          groups={groups.filter((g) => g.status === 'active' || (editing !== 'new' && editing.groups.some((x) => x.id === g.id)))}
          onCancel={() => setEditing(null)}
          onSaved={(_, message) => {
            setEditing(null);
            onToast(message);
            query.reload();
          }}
        />
      )}
    </div>
  );
}
