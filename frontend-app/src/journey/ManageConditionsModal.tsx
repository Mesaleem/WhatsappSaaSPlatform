import { useState } from 'react';
import { ChevronDown, Plus, Trash2, X } from 'lucide-react';

import { inputClass } from '../components/common/Card';
import { indigo, activeGradient } from '../theme/signalIndigo';
import type { ConditionalOperator, ConditionalRule } from '../types/journeyNodes';

/**
 * Phase 5 follow-up — "Manage Conditions" modal for the `conditional`
 * node, matching the reference builder's condition editor (Variable /
 * Operator / Value / Type columns, "Add Conjunction", Reset / Save).
 *
 * DATA CONTRACT: unchanged. A rule is still exactly
 * `{ variable, operator, value }` (ConditionalRule, types/journeyNodes.ts)
 * — the evaluator backend-side (JourneyConditionEvaluator) already infers
 * numeric vs. text handling from the OPERATOR, not from a declared type.
 * `dataType` below is additive, UI-only bookkeeping (kept on the rule
 * object so it round-trips through graph_data like any other key) — it is
 * never read by validateJourneyNodeConfig or the backend. Omitting it
 * entirely changes nothing at runtime.
 *
 * AND / OR — real constraint, read from the actual backend code
 * (JourneyConditionEvaluator::evaluateAll()): a `conditional` node's
 * whole rule list is combined with exactly ONE mode — `match: 'all'`
 * (every rule, i.e. AND) or `match: 'any'` (at least one rule, i.e. OR).
 * There is no per-row combinator at runtime, so a list like
 * "A AND B OR C" cannot be evaluated by this engine today — that would
 * need a new nested-condition-group data model and a matching evaluator
 * change on the backend, not a UI change here.
 *
 * So this modal lets the user pick AND or OR (via "Add Conjunction"),
 * but that choice is the node's one shared `match` mode: picking it
 * relabels every conjunction row, existing ones included, rather than
 * attaching a different operator to just the new row. That's a real
 * capability this didn't have before (the operator used to be hardcoded
 * to "AND" with no way to reach "any"); it's just not the row-by-row mix
 * the reference screenshots show.
 */

type RuleDraft = {
  variable?: string;
  operator?: ConditionalOperator;
  value?: string;
  dataType?: 'string' | 'number' | 'boolean';
};

type MatchMode = 'all' | 'any';

const OPERATORS: { value: ConditionalOperator; label: string }[] = [
  { value: 'equals', label: 'Equal to' },
  { value: 'not_equals', label: 'Not equal to' },
  { value: 'contains', label: 'Contains' },
  { value: 'not_contains', label: 'Does not contain' },
  { value: 'starts_with', label: 'Starts with' },
  { value: 'ends_with', label: 'Ends with' },
  { value: 'greater_than', label: 'Greater than' },
  { value: 'less_than', label: 'Less than' },
  { value: 'greater_or_equal', label: 'Greater than or equal to' },
  { value: 'less_or_equal', label: 'Less than or equal to' },
  { value: 'exists', label: 'Is empty' },
  { value: 'not_exists', label: 'Is not empty' },
];

const UNARY: ConditionalOperator[] = ['exists', 'not_exists'];

const DATA_TYPES: { value: NonNullable<RuleDraft['dataType']>; label: string }[] = [
  { value: 'string', label: 'String' },
  { value: 'number', label: 'Number' },
  { value: 'boolean', label: 'Boolean' },
];

export default function ManageConditionsModal({
  initialConditions,
  initialMatch = 'all',
  knownVariables,
  onSave,
  onClose,
}: {
  initialConditions: RuleDraft[];
  /** The node's current `match` field — 'all' (AND, default) or 'any' (OR). */
  initialMatch?: MatchMode;
  knownVariables: string[];
  /** Only rows with a non-empty `variable` are ever passed here — see save() below. */
  onSave: (conditions: ConditionalRule[], match: MatchMode) => void;
  onClose: () => void;
}) {
  const [rules, setRules] = useState<RuleDraft[]>(
    initialConditions.length > 0 ? initialConditions : [{ variable: '', operator: 'equals', value: '', dataType: 'string' }],
  );
  const [matchMode, setMatchMode] = useState<MatchMode>(initialMatch);
  const [conjunctionPickerOpen, setConjunctionPickerOpen] = useState(false);

  const patchRule = (index: number, patch: Partial<RuleDraft>) =>
    setRules((prev) => prev.map((r, i) => (i === index ? { ...r, ...patch } : r)));

  const addConjunction = (mode: MatchMode) => {
    setMatchMode(mode);
    setRules((prev) => [...prev, { variable: '', operator: 'equals', value: '', dataType: 'string' }]);
    setConjunctionPickerOpen(false);
  };

  const removeRule = (index: number) => setRules((prev) => prev.filter((_, i) => i !== index));

  const reset = () => {
    setRules(initialConditions.length > 0 ? initialConditions : [{ variable: '', operator: 'equals', value: '', dataType: 'string' }]);
    setMatchMode(initialMatch);
  };

  const save = () => {
    // Drop fully-empty trailing rows rather than saving a blank condition, and narrow each
    // remaining row's optional `variable`/`operator` to ConditionalRule's required ones — the
    // filter already guarantees `variable` is non-empty; `operator` defaults the same way a new
    // row already does (see rules' own initial state above).
    const cleaned: ConditionalRule[] = rules
      .filter((r) => (r.variable ?? '').trim() !== '')
      .map((r) => ({ ...r, variable: (r.variable ?? '').trim(), operator: r.operator ?? 'equals' }));
    onSave(cleaned, matchMode);
  };

  const listId = 'manage-conditions-vars';
  const conjunctionLabel = matchMode === 'any' ? 'OR' : 'AND';

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" onMouseDown={(e) => e.stopPropagation()}>
      <div className="flex max-h-[85vh] w-full max-w-3xl flex-col rounded-2xl bg-white shadow-xl">
        <div className="flex items-center justify-between border-b px-6 py-4" style={{ borderColor: indigo.border }}>
          <h2 className="font-display text-base font-bold" style={{ color: indigo.ink }}>
            Manage Conditions
          </h2>
          <button onClick={onClose} className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Close">
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className="flex-1 space-y-4 overflow-y-auto px-6 py-5">
          {rules.map((rule, index) => (
            <div key={index} className="rounded-xl border p-3" style={{ borderColor: indigo.border }}>
              <div className="mb-2 flex items-center justify-between">
                <span className="text-[11px] font-bold uppercase tracking-wide" style={{ color: indigo.muted }}>
                  {index === 0 ? 'IF' : conjunctionLabel}
                </span>
                {rules.length > 1 && (
                  <button type="button" onClick={() => removeRule(index)} className="text-slate-400 hover:text-red-600" aria-label="Remove condition">
                    <Trash2 className="h-4 w-4" />
                  </button>
                )}
              </div>
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-4">
                <label className="block text-xs font-medium text-slate-700">
                  Variable
                  <input
                    type="text"
                    className={`${inputClass} !mt-1`}
                    list={listId}
                    value={rule.variable ?? ''}
                    placeholder="var_local.abc"
                    onChange={(e) => patchRule(index, { variable: e.target.value.trim().replace(/\s+/g, '_') })}
                  />
                </label>
                <label className="block text-xs font-medium text-slate-700">
                  Operator
                  <select
                    className={`${inputClass} !mt-1`}
                    value={rule.operator ?? 'equals'}
                    onChange={(e) => patchRule(index, { operator: e.target.value as ConditionalOperator })}
                  >
                    {OPERATORS.map((op) => (
                      <option key={op.value} value={op.value}>
                        {op.label}
                      </option>
                    ))}
                  </select>
                </label>
                <label className="block text-xs font-medium text-slate-700">
                  Value
                  <input
                    type="text"
                    className={`${inputClass} !mt-1`}
                    value={rule.value ?? ''}
                    disabled={UNARY.includes(rule.operator ?? 'equals')}
                    placeholder={UNARY.includes(rule.operator ?? 'equals') ? '— not needed —' : 'Enter value'}
                    onChange={(e) => patchRule(index, { value: e.target.value })}
                  />
                </label>
                <label className="block text-xs font-medium text-slate-700">
                  Type
                  <select
                    className={`${inputClass} !mt-1`}
                    value={rule.dataType ?? 'string'}
                    onChange={(e) => patchRule(index, { dataType: e.target.value as RuleDraft['dataType'] })}
                  >
                    {DATA_TYPES.map((t) => (
                      <option key={t.value} value={t.value}>
                        {t.label}
                      </option>
                    ))}
                  </select>
                </label>
              </div>
            </div>
          ))}

          <datalist id={listId}>
            {knownVariables.map((v) => (
              <option key={v} value={v} />
            ))}
          </datalist>

          <div className="relative inline-block">
            <button
              type="button"
              onClick={() => setConjunctionPickerOpen((o) => !o)}
              aria-expanded={conjunctionPickerOpen}
              className="flex items-center gap-1 text-xs font-semibold"
              style={{ color: indigo.accentSolid }}
            >
              <Plus className="h-3.5 w-3.5" />
              Add Conjunction
              {rules.length > 1 && <ChevronDown className="h-3 w-3" />}
            </button>

            {conjunctionPickerOpen && (
              <div className="absolute left-0 top-full z-10 mt-1 w-24 overflow-hidden rounded-lg border bg-white shadow-lg" style={{ borderColor: indigo.border }}>
                <button
                  type="button"
                  onClick={() => addConjunction('all')}
                  className="block w-full px-3 py-1.5 text-left text-xs font-semibold hover:bg-slate-50"
                  style={{ color: indigo.ink }}
                >
                  AND
                </button>
                <button
                  type="button"
                  onClick={() => addConjunction('any')}
                  className="block w-full px-3 py-1.5 text-left text-xs font-semibold hover:bg-slate-50"
                  style={{ color: indigo.ink }}
                >
                  OR
                </button>
              </div>
            )}
          </div>

          {rules.length > 1 && (
            <p className="text-[11px]" style={{ color: indigo.muted }}>
              All rows combine with the same operator ({conjunctionLabel}) — picking AND or OR applies it to every row, not just the new one.
            </p>
          )}
        </div>

        <div className="flex items-center justify-end gap-2 border-t px-6 py-4" style={{ borderColor: indigo.border }}>
          <button type="button" onClick={reset} className="rounded-lg border px-4 py-2 text-xs font-semibold" style={{ borderColor: indigo.border, color: indigo.ink }}>
            Reset
          </button>
          <button
            type="button"
            onClick={save}
            className="rounded-lg px-4 py-2 text-xs font-semibold text-white"
            style={{ background: activeGradient }}
          >
            Save
          </button>
        </div>
      </div>
    </div>
  );
}
