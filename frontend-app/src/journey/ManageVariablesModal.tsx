import { useRef, useState } from 'react';
import { Download, Pencil, Trash2, Upload, X } from 'lucide-react';

import { inputClass } from '../components/common/Card';
import { indigo, activeGradient } from '../theme/signalIndigo';
import type { JourneyVariableDataType, JourneyVariableDeclaration } from '../types/journey';

/**
 * "Manage Variables" modal, matching the reference builder's table of
 * declared Local Variables (Name / Data Type / Default Value / Actions).
 *
 * SCOPE — confirmed with the user before building this:
 *   - Local Variable only. "Global Variable" (shared across every
 *     journey in the account) would need a real new backend resource —
 *     graph_data is one flow's own JSON blob, so there's nowhere to put
 *     an account-wide value without a new table. Not built; the type
 *     dropdown below only offers Local, rather than showing a Global
 *     option that does nothing.
 *   - UI-only / documentation, not a new runtime capability. See
 *     JourneyVariableDeclaration's docblock (types/journey.ts): a
 *     declared variable's "Default Value" is never seeded into a running
 *     session. What this DOES do: every declared name is added to the
 *     variable autocomplete used elsewhere (Manage Conditions, the
 *     legacy condition editor), same as a Question node's own variable.
 */

const DATA_TYPES: { value: JourneyVariableDataType; label: string }[] = [
  { value: 'string', label: 'String' },
  { value: 'number', label: 'Number' },
  { value: 'boolean', label: 'Boolean' },
  { value: 'json', label: 'JSON' },
];

function genId(): string {
  return `var_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;
}

function isValidName(name: string): boolean {
  return /^[a-zA-Z_][a-zA-Z0-9_]*$/.test(name);
}

export default function ManageVariablesModal({
  initialVariables,
  onSave,
  onClose,
}: {
  initialVariables: JourneyVariableDeclaration[];
  onSave: (variables: JourneyVariableDeclaration[]) => void;
  onClose: () => void;
}) {
  const [variables, setVariables] = useState<JourneyVariableDeclaration[]>(initialVariables);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [draftName, setDraftName] = useState('');
  const [draftType, setDraftType] = useState<JourneyVariableDataType>('string');
  const [draftDefault, setDraftDefault] = useState('');
  const [formError, setFormError] = useState<string | null>(null);
  const [search, setSearch] = useState({ name: '', type: '', value: '' });
  const fileInputRef = useRef<HTMLInputElement>(null);

  const resetForm = () => {
    setEditingId(null);
    setDraftName('');
    setDraftType('string');
    setDraftDefault('');
    setFormError(null);
  };

  const startEdit = (v: JourneyVariableDeclaration) => {
    setEditingId(v.id);
    setDraftName(v.name);
    setDraftType(v.dataType);
    setDraftDefault(v.defaultValue ?? '');
    setFormError(null);
  };

  const removeVariable = (id: string) => {
    setVariables((prev) => prev.filter((v) => v.id !== id));
    if (editingId === id) resetForm();
  };

  const submitForm = () => {
    const name = draftName.trim();

    if (!name) {
      setFormError('A variable name is required.');
      return;
    }

    if (!isValidName(name)) {
      setFormError('Use letters, numbers and underscores only, starting with a letter or underscore.');
      return;
    }

    const duplicate = variables.some((v) => v.name === name && v.id !== editingId);

    if (duplicate) {
      setFormError(`A variable named "${name}" already exists.`);
      return;
    }

    if (editingId) {
      setVariables((prev) => prev.map((v) => (v.id === editingId ? { ...v, name, dataType: draftType, defaultValue: draftDefault || undefined } : v)));
    } else {
      setVariables((prev) => [...prev, { id: genId(), name, dataType: draftType, defaultValue: draftDefault || undefined }]);
    }

    resetForm();
  };

  const exportVariables = () => {
    const blob = new Blob([JSON.stringify(variables, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'journey-variables.json';
    a.click();
    URL.revokeObjectURL(url);
  };

  const importVariables = (file: File) => {
    file
      .text()
      .then((text) => {
        const parsed = JSON.parse(text);

        if (!Array.isArray(parsed)) throw new Error('Expected a list of variables.');

        const imported: JourneyVariableDeclaration[] = parsed
          .filter((row): row is Record<string, unknown> => typeof row === 'object' && row !== null)
          .map((row) => ({
            id: typeof row.id === 'string' ? row.id : genId(),
            name: String(row.name ?? '').trim(),
            dataType: (DATA_TYPES.some((t) => t.value === row.dataType) ? row.dataType : 'string') as JourneyVariableDataType,
            defaultValue: typeof row.defaultValue === 'string' ? row.defaultValue : undefined,
          }))
          .filter((row) => isValidName(row.name));

        // Merge by name — an imported row replaces an existing one with
        // the same name rather than duplicating it.
        setVariables((prev) => {
          const byName = new Map(prev.map((v) => [v.name, v]));
          for (const row of imported) byName.set(row.name, { ...byName.get(row.name), ...row, id: byName.get(row.name)?.id ?? row.id });
          return Array.from(byName.values());
        });
        setFormError(null);
      })
      .catch(() => setFormError('That file is not a valid variables export.'));
  };

  const filtered = variables.filter(
    (v) =>
      v.name.toLowerCase().includes(search.name.toLowerCase()) &&
      v.dataType.toLowerCase().includes(search.type.toLowerCase()) &&
      (v.defaultValue ?? '').toLowerCase().includes(search.value.toLowerCase()),
  );

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" onMouseDown={(e) => e.stopPropagation()}>
      <div className="flex max-h-[85vh] w-full max-w-3xl flex-col rounded-2xl bg-white shadow-xl">
        <div className="flex items-center justify-between gap-2 border-b px-6 py-4" style={{ borderColor: indigo.border }}>
          <div className="flex items-center gap-2">
            <h2 className="font-display text-base font-bold" style={{ color: indigo.ink }}>
              Manage Variables
            </h2>
            <button type="button" onClick={exportVariables} title="Export as JSON" className="rounded-md border p-1.5 text-slate-500 hover:bg-slate-50" style={{ borderColor: indigo.border }}>
              <Download className="h-3.5 w-3.5" />
            </button>
            <button
              type="button"
              onClick={() => fileInputRef.current?.click()}
              title="Import from JSON"
              className="rounded-md border p-1.5 text-slate-500 hover:bg-slate-50"
              style={{ borderColor: indigo.border }}
            >
              <Upload className="h-3.5 w-3.5" />
            </button>
            <input
              ref={fileInputRef}
              type="file"
              accept="application/json"
              className="hidden"
              onChange={(e) => {
                const file = e.target.files?.[0];
                if (file) importVariables(file);
                e.target.value = '';
              }}
            />
          </div>
          <button onClick={onClose} className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Close">
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className="flex-1 space-y-4 overflow-y-auto px-6 py-5">
          <div>
            <label className="block text-xs font-medium text-slate-700">
              Variable Type
              <select className={`${inputClass} !mt-1 max-w-xs`} value="local" disabled>
                <option value="local">Local Variable</option>
              </select>
            </label>
            <p className="mt-1 text-[11px]" style={{ color: indigo.muted }}>
              Global Variable (shared across every journey) isn't built yet — it would need its own account-wide storage.
            </p>
          </div>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <label className="block text-xs font-medium text-slate-700">
              Variable Name
              <input
                type="text"
                className={`${inputClass} !mt-1`}
                value={draftName}
                placeholder="Enter a Variable Name"
                onChange={(e) => setDraftName(e.target.value)}
              />
            </label>
            <label className="block text-xs font-medium text-slate-700">
              Data Type
              <select className={`${inputClass} !mt-1`} value={draftType} onChange={(e) => setDraftType(e.target.value as JourneyVariableDataType)}>
                {DATA_TYPES.map((t) => (
                  <option key={t.value} value={t.value}>
                    {t.label}
                  </option>
                ))}
              </select>
            </label>
            <label className="block text-xs font-medium text-slate-700">
              Default Value
              <input
                type="text"
                className={`${inputClass} !mt-1`}
                value={draftDefault}
                placeholder="Enter a default value"
                onChange={(e) => setDraftDefault(e.target.value)}
              />
            </label>
          </div>

          {formError && <p className="text-xs font-medium text-red-600">{formError}</p>}

          <div className="flex justify-end gap-2">
            {editingId && (
              <button type="button" onClick={resetForm} className="rounded-lg border px-4 py-2 text-xs font-semibold" style={{ borderColor: indigo.border, color: indigo.ink }}>
                Cancel edit
              </button>
            )}
            <button type="button" onClick={submitForm} className="rounded-lg px-4 py-2 text-xs font-semibold text-white" style={{ background: activeGradient }}>
              {editingId ? 'Update' : 'Add'}
            </button>
          </div>

          <div className="overflow-hidden rounded-xl border" style={{ borderColor: indigo.border }}>
            <div className="border-b px-3 py-2 text-xs font-bold" style={{ borderColor: indigo.border, color: indigo.ink }}>
              Local Variables
            </div>
            <table className="w-full text-left text-xs">
              <thead>
                <tr className="border-b" style={{ borderColor: indigo.border }}>
                  <th className="px-3 py-2 font-semibold" style={{ color: indigo.ink }}>
                    Name
                  </th>
                  <th className="px-3 py-2 font-semibold" style={{ color: indigo.ink }}>
                    Data Type
                  </th>
                  <th className="px-3 py-2 font-semibold" style={{ color: indigo.ink }}>
                    Default Value
                  </th>
                  <th className="px-3 py-2 text-right font-semibold" style={{ color: indigo.ink }}>
                    Actions
                  </th>
                </tr>
                <tr className="border-b" style={{ borderColor: indigo.border }}>
                  <td className="px-3 py-1.5">
                    <input
                      type="text"
                      className={`${inputClass} !mt-0 !py-1`}
                      placeholder="Search name…"
                      value={search.name}
                      onChange={(e) => setSearch((s) => ({ ...s, name: e.target.value }))}
                    />
                  </td>
                  <td className="px-3 py-1.5">
                    <input
                      type="text"
                      className={`${inputClass} !mt-0 !py-1`}
                      placeholder="Search type…"
                      value={search.type}
                      onChange={(e) => setSearch((s) => ({ ...s, type: e.target.value }))}
                    />
                  </td>
                  <td className="px-3 py-1.5">
                    <input
                      type="text"
                      className={`${inputClass} !mt-0 !py-1`}
                      placeholder="Search value…"
                      value={search.value}
                      onChange={(e) => setSearch((s) => ({ ...s, value: e.target.value }))}
                    />
                  </td>
                  <td />
                </tr>
              </thead>
              <tbody>
                {filtered.length === 0 && (
                  <tr>
                    <td colSpan={4} className="px-3 py-4 text-center" style={{ color: indigo.muted }}>
                      {variables.length === 0 ? 'No variables declared yet.' : 'No variables match this search.'}
                    </td>
                  </tr>
                )}
                {filtered.map((v) => (
                  <tr key={v.id} className="border-b last:border-0" style={{ borderColor: indigo.border }}>
                    <td className="px-3 py-2 font-semibold" style={{ color: indigo.ink }}>
                      {v.name}
                    </td>
                    <td className="px-3 py-2">{DATA_TYPES.find((t) => t.value === v.dataType)?.label ?? v.dataType}</td>
                    <td className="px-3 py-2" style={{ color: indigo.muted }}>
                      {v.defaultValue || '—'}
                    </td>
                    <td className="px-3 py-2">
                      <div className="flex items-center justify-end gap-2">
                        <button type="button" onClick={() => startEdit(v)} className="text-slate-400 hover:text-indigo-600" aria-label={`Edit ${v.name}`}>
                          <Pencil className="h-3.5 w-3.5" />
                        </button>
                        <button type="button" onClick={() => removeVariable(v.id)} className="text-slate-400 hover:text-red-600" aria-label={`Delete ${v.name}`}>
                          <Trash2 className="h-3.5 w-3.5" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>

        <div className="flex items-center justify-end gap-2 border-t px-6 py-4" style={{ borderColor: indigo.border }}>
          <button type="button" onClick={onClose} className="rounded-lg border px-4 py-2 text-xs font-semibold" style={{ borderColor: indigo.border, color: indigo.ink }}>
            Cancel
          </button>
          <button type="button" onClick={() => onSave(variables)} className="rounded-lg px-4 py-2 text-xs font-semibold text-white" style={{ background: activeGradient }}>
            Save
          </button>
        </div>
      </div>
    </div>
  );
}
