import { useState, type FormEvent } from 'react';
import { Loader2, XCircle } from 'lucide-react';
import educationService from '../../services/educationService';
import { inputClass } from '../common/Card';
import { describeApiError } from '../../utils/apiError';
import { GROUP_KINDS, GROUP_STATUSES, type EducationGroup, type GroupKind, type GroupStatus } from '../../types/education';
import DismissibleAlert from '../common/DismissibleAlert';

/**
 * Phase 11 Task 2 — create (POST /groups) or edit (PATCH /groups/{id}) a class or batch. The type is
 * chosen only when creating (blank = the default for the account's vertical: school → class, otherwise
 * batch) and is fixed afterwards. There is no delete: a group is archived.
 */
export default function GroupFormModal({
  group,
  onSaved,
  onCancel,
}: {
  group?: EducationGroup;
  onSaved: (group: EducationGroup, message: string) => void;
  onCancel: () => void;
}) {
  const [name, setName] = useState(group?.name ?? '');
  const [kind, setKind] = useState<GroupKind | ''>('');
  const [year, setYear] = useState(group?.academic_year ?? '');
  const [status, setStatus] = useState<GroupStatus>(group?.status ?? 'active');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const submit = (e: FormEvent) => {
    e.preventDefault();
    if (saving) return;
    setSaving(true);
    setError(null);
    setFieldErrors({});
    const common = { name: name.trim(), academic_year: year.trim() === '' ? null : year.trim(), status };
    const call = group
      ? educationService.updateGroup(group.id, common)
      : educationService.createGroup({ ...common, ...(kind !== '' ? { kind } : {}) });
    call
      .then((res) => onSaved(res.data, res.message))
      .catch((err: unknown) => {
        const described = describeApiError(err, 'Failed to save.');
        setError(described.message);
        setFieldErrors(described.fieldErrors);
      })
      .finally(() => setSaving(false));
  };

  const field = (key: string) => (fieldErrors[key] ? <p className="mt-1 text-xs text-red-600">{fieldErrors[key]}</p> : null);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <form onSubmit={submit} className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl" data-testid="group-form">
        <div className="flex items-center justify-between">
          <h3 className="text-base font-semibold text-slate-900">{group ? 'Edit class / batch' : 'New class / batch'}</h3>
          <button type="button" onClick={onCancel} disabled={saving} className="text-slate-400 hover:text-slate-600" aria-label="Close">
            <XCircle className="h-5 w-5" />
          </button>
        </div>
        <div className="mt-4 space-y-3">
          <label className="block text-xs font-medium text-slate-600">
            Name *
            <input className={inputClass} value={name} onChange={(e) => setName(e.target.value)} required maxLength={120} />
            {field('name')}
          </label>
          {!group && (
            <label className="block text-xs font-medium text-slate-600">
              Type
              <select className={inputClass} value={kind} onChange={(e) => setKind(e.target.value as GroupKind | '')}>
                <option value="">Default for this account</option>
                {GROUP_KINDS.map((k) => (
                  <option key={k} value={k}>
                    {k === 'class' ? 'Class' : 'Batch'}
                  </option>
                ))}
              </select>
              {field('kind')}
            </label>
          )}
          <label className="block text-xs font-medium text-slate-600">
            Academic year
            <input className={inputClass} value={year} onChange={(e) => setYear(e.target.value)} maxLength={20} placeholder="2026-27" />
            {field('academic_year')}
          </label>
          <label className="block text-xs font-medium text-slate-600">
            Status
            <select className={inputClass} value={status} onChange={(e) => setStatus(e.target.value as GroupStatus)}>
              {GROUP_STATUSES.map((s) => (
                <option key={s} value={s}>
                  {s.charAt(0).toUpperCase() + s.slice(1)}
                </option>
              ))}
            </select>
          </label>
        </div>
        {error && <DismissibleAlert as="p" className="mt-3 text-sm text-red-600 flex items-start justify-between gap-2" role="alert">{error}</DismissibleAlert>}
        <div className="mt-5 flex justify-end gap-2">
          <button type="button" onClick={onCancel} disabled={saving} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60">
            Cancel
          </button>
          <button type="submit" disabled={saving || name.trim() === ''} className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60">
            {saving && <Loader2 className="h-4 w-4 animate-spin" />}
            {group ? 'Save changes' : 'Create'}
          </button>
        </div>
      </form>
    </div>
  );
}
