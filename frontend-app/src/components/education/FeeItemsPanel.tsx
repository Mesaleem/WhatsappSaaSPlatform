import { useState } from 'react';
import { Plus } from 'lucide-react';
import { TableCard } from '../common/Card';
import { TableSkeletonRows } from '../common/Skeleton';
import { EmptyState, ErrorBanner, READ_ONLY_TITLE } from '../crm/CrmUi';
import FeeItemFormModal from './FeeItemFormModal';
import { FREQUENCY_LABELS } from './feeUtils';
import type { FeeItem } from '../../types/education';

/** Phase 11 Task 4 — the fee catalogue (loaded by the Fees tab, which the assignment form also needs). */
export default function FeeItemsPanel({
  items,
  isLoading,
  error,
  canManage,
  readOnly,
  onChanged,
  onToast,
}: {
  items: FeeItem[];
  isLoading: boolean;
  error: string | null;
  canManage: boolean;
  readOnly: boolean;
  onChanged: () => void;
  onToast: (message: string) => void;
}) {
  const [editing, setEditing] = useState<FeeItem | 'new' | null>(null);

  return (
    <section className="space-y-3" data-testid="fee-items-panel">
      <div className="flex items-center justify-between">
        <h2 className="text-sm font-semibold text-slate-900">Fees</h2>
        {canManage && (
          <button
            type="button"
            onClick={() => setEditing('new')}
            disabled={readOnly}
            title={readOnly ? READ_ONLY_TITLE : undefined}
            className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
          >
            <Plus className="h-4 w-4" />
            New fee
          </button>
        )}
      </div>

      {error && <ErrorBanner message={error} />}

      <TableCard>
        <table className="min-w-full divide-y divide-slate-200 text-sm" data-testid="fee-items-table">
          <thead className="bg-slate-50">
            <tr>
              {['Name', 'Amount', 'Frequency', 'Status', ''].map((h) => (
                <th key={h || 'actions'} className="px-4 py-3 text-left font-medium text-slate-600">{h}</th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading ? (
              <TableSkeletonRows columns={5} />
            ) : items.length === 0 ? (
              <tr>
                <td colSpan={5}>
                  <EmptyState title="No fees yet." hint="Create a fee, then assign it to students." />
                </td>
              </tr>
            ) : (
              items.map((i) => (
                <tr key={i.id} data-testid={`fee-item-row-${i.id}`}>
                  <td className="px-4 py-3 font-medium text-slate-900">
                    {i.name}
                    {i.description && <div className="text-xs font-normal text-slate-500">{i.description}</div>}
                  </td>
                  <td className="px-4 py-3 text-slate-700">{i.amount}</td>
                  <td className="px-4 py-3 text-slate-700">{FREQUENCY_LABELS[i.frequency] ?? i.frequency}</td>
                  <td className="px-4 py-3 capitalize text-slate-700">{i.status}</td>
                  <td className="px-4 py-3 text-right">
                    {canManage && (
                      <button
                        type="button"
                        onClick={() => setEditing(i)}
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
        <FeeItemFormModal
          item={editing === 'new' ? undefined : editing}
          onCancel={() => setEditing(null)}
          onSaved={(_, message) => {
            setEditing(null);
            onToast(message);
            onChanged();
          }}
        />
      )}
    </section>
  );
}
