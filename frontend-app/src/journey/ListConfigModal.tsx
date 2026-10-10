import { useState } from 'react';
import { Plus, Trash2, X } from 'lucide-react';

import { inputClass } from '../components/common/Card';
import { indigo, activeGradient } from '../theme/signalIndigo';
import type { ListRow, ListSection } from '../types/journeyNodes';

/**
 * "List Configuration" modal for the `list` node, matching the reference
 * builder's dedicated editor (section/row counters, per-field character
 * counts) instead of the old bare repeatable-row form, which didn't even
 * expose a row's `description` even though ListRow has one.
 *
 * LIMITS: not decorative — these are WhatsApp's own interactive-list
 * message limits (Cloud API), so a list that fits here is one WhatsApp
 * will actually accept: max 10 sections, max 10 rows total across every
 * section, a row/section title up to 24 characters, a row description up
 * to 72. The registry's `validate()` for the `list` node enforces the
 * same numbers at save time — this modal only stops you going over
 * earlier, with no new rule either place lacks.
 *
 * NOT built: the reference's per-section "Static/Dynamic" mode toggle.
 * We have nowhere to put "Dynamic" — no API-driven / variable-bound list
 * content exists in this engine today — so showing that toggle would be
 * a control with no effect. Omitted rather than faked.
 */

export const LIST_MAX_SECTIONS = 10;
export const LIST_MAX_ROWS = 10;
export const LIST_TITLE_MAX = 24;
export const LIST_DESCRIPTION_MAX = 72;

function genRowId(): string {
  return `row_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;
}

function totalRows(sections: ListSection[]): number {
  return sections.reduce((sum, s) => sum + (Array.isArray(s.rows) ? s.rows.length : 0), 0);
}

export default function ListConfigModal({
  initialSections,
  onSave,
  onClose,
}: {
  initialSections: ListSection[];
  onSave: (sections: ListSection[]) => void;
  onClose: () => void;
}) {
  const [sections, setSections] = useState<ListSection[]>(
    initialSections.length > 0 ? initialSections : [{ title: '', rows: [{ id: genRowId(), title: '', description: '' }] }],
  );

  const rowCount = totalRows(sections);
  const atSectionLimit = sections.length >= LIST_MAX_SECTIONS;
  const atRowLimit = rowCount >= LIST_MAX_ROWS;

  const patchSection = (index: number, patch: Partial<ListSection>) =>
    setSections((prev) => prev.map((s, i) => (i === index ? { ...s, ...patch } : s)));

  const removeSection = (index: number) => setSections((prev) => prev.filter((_, i) => i !== index));

  const addSection = () => {
    if (atSectionLimit) return;
    setSections((prev) => [...prev, { title: '', rows: [{ id: genRowId(), title: '', description: '' }] }]);
  };

  const addRow = (sectionIndex: number) => {
    if (atRowLimit) return;
    patchSection(sectionIndex, { rows: [...sections[sectionIndex].rows, { id: genRowId(), title: '', description: '' }] });
  };

  const patchRow = (sectionIndex: number, rowIndex: number, patch: Partial<ListRow>) =>
    patchSection(sectionIndex, {
      rows: sections[sectionIndex].rows.map((r, i) => (i === rowIndex ? { ...r, ...patch } : r)),
    });

  const removeRow = (sectionIndex: number, rowIndex: number) =>
    patchSection(sectionIndex, { rows: sections[sectionIndex].rows.filter((_, i) => i !== rowIndex) });

  const save = () => {
    // Drop fully-empty trailing rows/sections rather than saving blanks —
    // same courtesy the conditions modal gives.
    const cleaned = sections
      .map((s) => ({ ...s, rows: s.rows.filter((r) => (r.title ?? '').trim() !== '') }))
      .filter((s) => (s.title ?? '').trim() !== '' || s.rows.length > 0);
    onSave(cleaned);
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" onMouseDown={(e) => e.stopPropagation()}>
      <div className="flex max-h-[85vh] w-full max-w-3xl flex-col rounded-2xl bg-white shadow-xl">
        <div className="flex items-center justify-between gap-3 border-b px-6 py-4" style={{ borderColor: indigo.border }}>
          <div className="flex items-center gap-2">
            <h2 className="font-display text-base font-bold" style={{ color: indigo.ink }}>
              List Configuration
            </h2>
            <span
              className="rounded-full px-2 py-0.5 text-[10px] font-bold"
              style={{ background: sections.length >= LIST_MAX_SECTIONS ? '#fef3c7' : '#f1f5f9', color: sections.length >= LIST_MAX_SECTIONS ? '#b45309' : indigo.muted }}
            >
              Sections: {sections.length}/{LIST_MAX_SECTIONS}
            </span>
            <span
              className="rounded-full px-2 py-0.5 text-[10px] font-bold"
              style={{ background: rowCount >= LIST_MAX_ROWS ? '#fef3c7' : '#f1f5f9', color: rowCount >= LIST_MAX_ROWS ? '#b45309' : indigo.muted }}
            >
              Total Rows: {rowCount}/{LIST_MAX_ROWS}
            </span>
          </div>
          <button onClick={onClose} className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Close">
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className="flex-1 space-y-4 overflow-y-auto px-6 py-5">
          {sections.map((section, sectionIndex) => (
            <div key={sectionIndex} className="rounded-xl border p-3" style={{ borderColor: indigo.border }}>
              <div className="mb-2 flex items-center justify-between">
                <span className="text-[11px] font-bold uppercase tracking-wide" style={{ color: indigo.muted }}>
                  Section {sectionIndex + 1}
                </span>
                {sections.length > 1 && (
                  <button type="button" onClick={() => removeSection(sectionIndex)} className="text-slate-400 hover:text-red-600" aria-label="Remove section">
                    <Trash2 className="h-4 w-4" />
                  </button>
                )}
              </div>

              <label className="block text-xs font-medium text-slate-700">
                Section Title
                <input
                  type="text"
                  className={`${inputClass} !mt-1`}
                  value={section.title ?? ''}
                  maxLength={LIST_TITLE_MAX}
                  placeholder="Enter section title"
                  onChange={(e) => patchSection(sectionIndex, { title: e.target.value })}
                />
                <span className="mt-0.5 block text-right text-[10px]" style={{ color: indigo.muted }}>
                  {(section.title ?? '').length}/{LIST_TITLE_MAX}
                </span>
              </label>

              <div className="mt-2 flex items-center justify-between">
                <span className="text-[11px] font-semibold" style={{ color: indigo.muted }}>
                  Row Items ({section.rows.length})
                </span>
                <button
                  type="button"
                  onClick={() => addRow(sectionIndex)}
                  disabled={atRowLimit}
                  className="flex items-center gap-1 text-xs font-semibold disabled:cursor-not-allowed disabled:text-slate-300"
                  style={{ color: atRowLimit ? undefined : indigo.accentSolid }}
                >
                  <Plus className="h-3.5 w-3.5" />
                  Add Row
                </button>
              </div>

              <div className="mt-1.5 space-y-2">
                {section.rows.map((row, rowIndex) => (
                  <div key={row.id ?? rowIndex} className="flex items-start gap-2 rounded-lg border p-2" style={{ borderColor: indigo.border }}>
                    <div className="grid flex-1 grid-cols-1 gap-2 sm:grid-cols-2">
                      <label className="block text-xs font-medium text-slate-700">
                        Title
                        <input
                          type="text"
                          className={`${inputClass} !mt-1`}
                          value={row.title ?? ''}
                          maxLength={LIST_TITLE_MAX}
                          placeholder="Enter row title"
                          onChange={(e) => patchRow(sectionIndex, rowIndex, { title: e.target.value })}
                        />
                        <span className="mt-0.5 block text-right text-[10px]" style={{ color: indigo.muted }}>
                          {(row.title ?? '').length}/{LIST_TITLE_MAX}
                        </span>
                      </label>
                      <label className="block text-xs font-medium text-slate-700">
                        Description
                        <input
                          type="text"
                          className={`${inputClass} !mt-1`}
                          value={row.description ?? ''}
                          maxLength={LIST_DESCRIPTION_MAX}
                          placeholder="Optional description"
                          onChange={(e) => patchRow(sectionIndex, rowIndex, { description: e.target.value })}
                        />
                        <span className="mt-0.5 block text-right text-[10px]" style={{ color: indigo.muted }}>
                          {(row.description ?? '').length}/{LIST_DESCRIPTION_MAX}
                        </span>
                      </label>
                    </div>
                    {section.rows.length > 1 && (
                      <button type="button" onClick={() => removeRow(sectionIndex, rowIndex)} className="mt-5 text-slate-400 hover:text-red-600" aria-label="Remove row">
                        <X className="h-4 w-4" />
                      </button>
                    )}
                  </div>
                ))}
              </div>
            </div>
          ))}

          <button
            type="button"
            onClick={addSection}
            disabled={atSectionLimit}
            className="flex items-center gap-1 text-xs font-semibold disabled:cursor-not-allowed disabled:text-slate-300"
            style={{ color: atSectionLimit ? undefined : indigo.accentSolid }}
          >
            <Plus className="h-3.5 w-3.5" />
            Add Section
          </button>
        </div>

        <div className="flex items-center justify-end gap-2 border-t px-6 py-4" style={{ borderColor: indigo.border }}>
          <button type="button" onClick={onClose} className="rounded-lg border px-4 py-2 text-xs font-semibold" style={{ borderColor: indigo.border, color: indigo.ink }}>
            Cancel
          </button>
          <button type="button" onClick={save} className="rounded-lg px-4 py-2 text-xs font-semibold text-white" style={{ background: activeGradient }}>
            Save Changes
          </button>
        </div>
      </div>
    </div>
  );
}
