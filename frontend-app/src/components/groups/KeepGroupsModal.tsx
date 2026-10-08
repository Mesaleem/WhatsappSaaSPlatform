import { useMemo, useState } from 'react';
import { X } from 'lucide-react';
import contactGroupsService from '../../services/contactGroupsService';
import type { ContactGroup } from '../../types/contactGroup';
import { extractErrorMessage } from '../../utils/apiError';

interface KeepGroupsModalProps {
  /** The client's own Native WhatsApp Groups (not the default group, not an internal segment group). */
  groups: ContactGroup[];
  /** How many groups the running term includes. */
  limit: number;
  onClose: () => void;
  onSaved: () => void;
}

/**
 * After a downgrade: the client chooses which of its groups stay open for the term. The number is
 * exact (the term's groups, or all of them when there are fewer). The rest stay locked until the next term.
 */
export default function KeepGroupsModal({ groups, limit, onClose, onSaved }: KeepGroupsModalProps) {
  const required = Math.min(limit, groups.length);
  const [selected, setSelected] = useState<number[]>(() =>
    groups.filter((g) => !g.locked_at).map((g) => g.id).slice(0, required),
  );
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const remaining = required - selected.length;
  const valid = selected.length === required;
  const sorted = useMemo(() => [...groups].sort((a, b) => a.name.localeCompare(b.name)), [groups]);

  const toggle = (id: number) => {
    setError(null);
    setSelected((current) => {
      if (current.includes(id)) return current.filter((x) => x !== id);
      if (current.length >= required) return current;
      return [...current, id];
    });
  };

  const save = async () => {
    setBusy(true);
    setError(null);
    try {
      await contactGroupsService.keepGroups(selected);
      onSaved();
    } catch (err) {
      setError(extractErrorMessage(err, 'We could not save your choice. Please try again.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" role="dialog" aria-modal="true">
      <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
        <div className="flex items-start justify-between gap-4">
          <div>
            <h2 className="text-base font-semibold text-slate-900">Choose the groups to keep</h2>
            <p className="mt-1 text-xs text-slate-500">
              Your current plan includes {limit} {limit === 1 ? 'group' : 'groups'}. Choose which ones stay open until the term ends.
              The others stay locked, and you can choose again when your next term starts.
            </p>
          </div>
          <button type="button" onClick={onClose} className="text-slate-400 hover:text-slate-600" aria-label="Close">
            <X className="h-5 w-5" />
          </button>
        </div>

        <p className="mt-4 text-sm font-medium text-slate-800">
          Selected {selected.length} of {required}
          {remaining > 0 && <span className="ml-1 font-normal text-slate-500">: choose {remaining} more.</span>}
        </p>

        <ul className="mt-2 max-h-72 space-y-1 overflow-y-auto rounded-lg border border-slate-200 p-2">
          {sorted.map((group) => {
            const checked = selected.includes(group.id);
            const disabled = !checked && selected.length >= required;
            return (
              <li key={group.id}>
                <label className={`flex items-center gap-3 rounded-md px-2 py-2 text-sm ${disabled ? 'text-slate-400' : 'text-slate-800 hover:bg-slate-50'}`}>
                  <input type="checkbox" checked={checked} disabled={disabled} onChange={() => toggle(group.id)} />
                  <span className="flex-1">{group.name}</span>
                  <span className="text-xs text-slate-500">{group.members_count} contacts</span>
                </label>
              </li>
            );
          })}
        </ul>

        {error && <p className="mt-3 text-sm text-red-600">{error}</p>}

        <div className="mt-6 flex justify-end gap-2">
          <button type="button" onClick={onClose} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
            Cancel
          </button>
          <button
            type="button"
            onClick={() => void save()}
            disabled={!valid || busy}
            className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
          >
            {busy ? 'Saving…' : 'Save my choice'}
          </button>
        </div>
      </div>
    </div>
  );
}
