import { useState } from 'react';
import { Plus } from 'lucide-react';
import { TableCard } from '../common/Card';
import { TableSkeletonRows } from '../common/Skeleton';
import { EmptyState, ErrorBanner, READ_ONLY_TITLE } from '../crm/CrmUi';
import GroupFormModal from './GroupFormModal';
import type { EducationGroup } from '../../types/education';

/** Classes / batches — the list is loaded by the page (GET /groups) because the students tab needs it too. */
export default function GroupsPanel({
  groups,
  isLoading,
  error,
  canManage,
  readOnly,
  onChanged,
  onToast,
}: {
  groups: EducationGroup[];
  isLoading: boolean;
  error: string | null;
  canManage: boolean;
  readOnly: boolean;
  onChanged: () => void;
  onToast: (message: string) => void;
}) {
  const [editing, setEditing] = useState<EducationGroup | 'new' | null>(null);

  return (
    <div className="space-y-4" data-testid="groups-panel">
      {canManage && (
        <div className="flex justify-end">
          <button
            type="button"
            onClick={() => setEditing('new')}
            disabled={readOnly}
            title={readOnly ? READ_ONLY_TITLE : undefined}
            className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
          >
            <Plus className="h-4 w-4" />
            New class / batch
          </button>
        </div>
      )}

      {error && <ErrorBanner message={error} />}

      <TableCard>
        <table className="min-w-full divide-y divide-slate-200 text-sm" data-testid="groups-table">
          <thead className="bg-slate-50">
            <tr>
              {['Name', 'Type', 'Academic year', 'Status', 'Students', ''].map((h) => (
                <th key={h || 'actions'} className="px-4 py-3 text-left font-medium text-slate-600">{h}</th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading ? (
              <TableSkeletonRows columns={6} />
            ) : groups.length === 0 ? (
              <tr>
                <td colSpan={6}>
                  <EmptyState title="No classes or batches yet." hint="Create one, then assign students to it." />
                </td>
              </tr>
            ) : (
              groups.map((g) => (
                <tr key={g.id} data-testid={`group-row-${g.id}`}>
                  <td className="px-4 py-3 font-medium text-slate-900">{g.name}</td>
                  <td className="px-4 py-3 capitalize text-slate-700">{g.kind}</td>
                  <td className="px-4 py-3 text-slate-700">{g.academic_year ?? '—'}</td>
                  <td className="px-4 py-3 capitalize text-slate-700">{g.status}</td>
                  <td className="px-4 py-3 text-slate-700">{g.students_count}</td>
                  <td className="px-4 py-3 text-right">
                    {canManage && (
                      <button
                        type="button"
                        onClick={() => setEditing(g)}
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
      </TableCard>

      {editing !== null && (
        <GroupFormModal
          group={editing === 'new' ? undefined : editing}
          onCancel={() => setEditing(null)}
          onSaved={(_, message) => {
            setEditing(null);
            onToast(message);
            onChanged();
          }}
        />
      )}
    </div>
  );
}
