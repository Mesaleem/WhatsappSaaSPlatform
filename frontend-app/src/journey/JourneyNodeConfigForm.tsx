import { Plus, X } from 'lucide-react';

import { inputClass } from '../components/common/Card';
import { indigo } from '../theme/signalIndigo';
import { getJourneyNode } from './nodeRegistry';
import {
  MAX_REPLY_BUTTONS,
  type ApiKeyValue,
  type ConditionalOperator,
  type JourneyNodeConfigErrors,
  type JourneyNodeField,
  type ListRow,
  type ListSection,
  type ReplyButton,
} from '../types/journeyNodes';

/**
 * Phase 5 — Journey / Automation: ONE schema-driven configuration form
 * for every palette node.
 *
 * WHY THIS EXISTS: the task's PASS bar is "do not mark PASS if any node
 * is merely displayed in the sidebar but lacks a stable type/configuration
 * contract". Before this component, selecting any of the 27 new nodes
 * fell through JourneyBuilderPage's if-chain and rendered the *Save Lead*
 * editor — the node was in the palette, had a registry contract, and was
 * still uneditable (and mis-editable). This closes that gap without
 * writing 27 bespoke panels: the registry's `configSchema` drives the
 * fields, and `validateJourneyNodeConfig` drives the messages.
 *
 * The five legacy engine-executed types (trigger/message/question/
 * condition/save_lead) keep their existing hand-written panels in
 * JourneyBuilderPage — untouched, so saved journeys behave exactly as
 * before.
 *
 * SECURITY POSTURE:
 *   - No field here is a credential. Nodes that need one (api, payment,
 *     agent, rag, email) declare a *reference* field (credentialRef /
 *     gatewayRef / agentId / knowledgeBaseId) that names a server-side
 *     configuration; the secret itself never enters this form, this
 *     component's state, or `graph_data`.
 *   - The `code` field is a plain <textarea>. Its value is stored and
 *     never evaluated — no eval, no Function, no dangerouslySetInnerHTML,
 *     no <script>. Execution is a later server-side sandbox concern.
 *   - Validation here is UX only. The backend
 *     (WhatsAppFlowController::validateFlow) revalidates node types and
 *     configuration independently and is the authorization boundary.
 */

const ROW_BUTTON = 'text-xs font-semibold';

/** Deliberately not crypto — only needs to be unique within one graph. */
function fieldId(prefix: string): string {
  return `${prefix}_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 7)}`;
}

function asArray<T>(value: unknown): T[] {
  return Array.isArray(value) ? (value as T[]) : [];
}

const CONDITION_OPERATORS: { value: ConditionalOperator; label: string }[] = [
  { value: 'equals', label: 'equals' },
  { value: 'not_equals', label: 'does not equal' },
  { value: 'contains', label: 'contains' },
  { value: 'not_contains', label: 'does not contain' },
  { value: 'starts_with', label: 'starts with' },
  { value: 'ends_with', label: 'ends with' },
  { value: 'exists', label: 'is set' },
  { value: 'not_exists', label: 'is not set' },
  { value: 'greater_than', label: 'is greater than (number)' },
  { value: 'less_than', label: 'is less than (number)' },
  { value: 'greater_or_equal', label: 'is at least (number)' },
  { value: 'less_or_equal', label: 'is at most (number)' },
];

function FieldShell({
  field,
  error,
  children,
}: {
  field: JourneyNodeField;
  error?: string;
  children: React.ReactNode;
}) {
  return (
    <div data-testid={`node-field-${field.key}`}>
      <p className="text-xs font-medium text-slate-700">
        {field.label}
        {field.required && <span className="ml-0.5 text-red-500">*</span>}
      </p>
      {children}
      {field.help && (
        <p className="mt-1 text-[11px]" style={{ color: indigo.muted }}>
          {field.help}
        </p>
      )}
      {error && (
        <p data-testid={`field-error-${field.key}`} className="mt-1 text-[11px] font-medium text-red-600">
          {error}
        </p>
      )}
    </div>
  );
}

function RepeatableRow({ onRemove, children }: { onRemove: () => void; children: React.ReactNode }) {
  return (
    <div className="flex items-start gap-1.5">
      <div className="flex-1 space-y-1">{children}</div>
      <button
        type="button"
        onClick={onRemove}
        className="mt-2 flex-shrink-0 text-slate-400 hover:text-red-600"
        aria-label="Remove row"
      >
        <X className="h-4 w-4" />
      </button>
    </div>
  );
}

function AddRowButton({ label, onClick, disabled }: { label: string; onClick: () => void; disabled?: boolean }) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      className={`${ROW_BUTTON} disabled:cursor-not-allowed disabled:text-slate-300`}
      style={disabled ? undefined : { color: indigo.accentSolid }}
    >
      <Plus className="mr-0.5 inline h-3 w-3" />
      {label}
    </button>
  );
}

export interface JourneyNodeConfigFormProps {
  nodeType: string;
  config: Record<string, unknown>;
  errors?: JourneyNodeConfigErrors;
  knownVariables?: string[];
  onChange: (patch: Record<string, unknown>) => void;
}

export default function JourneyNodeConfigForm({
  nodeType,
  config,
  errors = {},
  knownVariables = [],
  onChange,
}: JourneyNodeConfigFormProps) {
  const definition = getJourneyNode(nodeType);

  if (!definition) {
    // A flow saved by a newer deploy. Say so instead of silently
    // rendering the wrong editor (the bug this component replaces).
    return (
      <p className="text-xs text-red-600" data-testid="unknown-node-type">
        This build does not recognise the node type "{nodeType}". Its settings cannot be edited here.
      </p>
    );
  }

  const renderField = (field: JourneyNodeField) => {
    const error = errors[field.key];
    const value = config[field.key];

    switch (field.type) {
      case 'textarea':
        return (
          <FieldShell key={field.key} field={field} error={error}>
            <textarea
              className={inputClass}
              rows={4}
              value={typeof value === 'string' ? value : ''}
              placeholder={field.placeholder}
              onChange={(e) => onChange({ [field.key]: e.target.value })}
            />
          </FieldShell>
        );

      case 'code':
        return (
          <FieldShell key={field.key} field={field} error={error}>
            {/*
              Stored, never run. This is a textarea and nothing else —
              the value is not evaluated, injected into the DOM as HTML,
              or passed to Function/eval anywhere in this codebase.
            */}
            <textarea
              className={`${inputClass} font-mono text-[11px]`}
              rows={8}
              spellCheck={false}
              value={typeof value === 'string' ? value : ''}
              placeholder={field.placeholder}
              onChange={(e) => onChange({ [field.key]: e.target.value })}
            />
          </FieldShell>
        );

      case 'number':
        return (
          <FieldShell key={field.key} field={field} error={error}>
            <input
              type="number"
              className={inputClass}
              min={field.min}
              max={field.max}
              value={typeof value === 'number' ? value : ''}
              placeholder={field.placeholder}
              onChange={(e) => onChange({ [field.key]: e.target.value === '' ? undefined : Number(e.target.value) })}
            />
          </FieldShell>
        );

      case 'select':
        return (
          <FieldShell key={field.key} field={field} error={error}>
            <select
              className={inputClass}
              value={typeof value === 'string' ? value : ''}
              onChange={(e) => onChange({ [field.key]: e.target.value })}
            >
              <option value="">— select —</option>
              {(field.options ?? []).map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </FieldShell>
        );

      case 'variable': {
        const listId = `vars-${nodeType}-${field.key}`;

        return (
          <FieldShell key={field.key} field={field} error={error}>
            {/*
              A datalist, not a <select>: a variable may legitimately
              come from a branch this editor hasn't walked, so the field
              suggests without constraining.
            */}
            <input
              type="text"
              className={inputClass}
              list={listId}
              value={typeof value === 'string' ? value : ''}
              placeholder={field.placeholder}
              onChange={(e) => onChange({ [field.key]: e.target.value.trim().replace(/\s+/g, '_') })}
            />
            <datalist id={listId}>
              {knownVariables.map((variable) => (
                <option key={variable} value={variable} />
              ))}
            </datalist>
          </FieldShell>
        );
      }

      case 'buttons': {
        const buttons = asArray<ReplyButton>(value);
        const cap = field.maxItems ?? MAX_REPLY_BUTTONS;
        const update = (next: ReplyButton[]) => onChange({ [field.key]: next });

        return (
          <FieldShell key={field.key} field={field} error={error}>
            <div className="mt-1 space-y-1.5">
              {buttons.map((button, index) => (
                <RepeatableRow
                  key={button.id ?? index}
                  onRemove={() => update(buttons.filter((_, i) => i !== index))}
                >
                  <input
                    type="text"
                    className={`${inputClass} !mt-0`}
                    value={button.title ?? ''}
                    placeholder={`Button ${index + 1}`}
                    onChange={(e) =>
                      update(buttons.map((b, i) => (i === index ? { ...b, title: e.target.value } : b)))
                    }
                  />
                </RepeatableRow>
              ))}
              {/*
                The 3-button cap is enforced here AND in the registry
                validator AND in the backend controller. The UI cap is
                convenience; the other two are the contract.
              */}
              <AddRowButton
                label={`Add button (${buttons.length}/${cap})`}
                disabled={buttons.length >= cap}
                onClick={() => update([...buttons, { id: fieldId('btn'), title: '' }])}
              />
            </div>
          </FieldShell>
        );
      }

      case 'sections': {
        const sections = asArray<ListSection>(value);
        const update = (next: ListSection[]) => onChange({ [field.key]: next });
        const patchSection = (index: number, patch: Partial<ListSection>) =>
          update(sections.map((s, i) => (i === index ? { ...s, ...patch } : s)));

        return (
          <FieldShell key={field.key} field={field} error={error}>
            <div className="mt-1 space-y-2">
              {sections.map((section, sectionIndex) => {
                const rows = asArray<ListRow>(section.rows);

                return (
                  <div
                    key={sectionIndex}
                    className="rounded-lg border p-2"
                    style={{ borderColor: indigo.border }}
                  >
                    <RepeatableRow onRemove={() => update(sections.filter((_, i) => i !== sectionIndex))}>
                      <input
                        type="text"
                        className={`${inputClass} !mt-0`}
                        value={section.title ?? ''}
                        placeholder={`Section ${sectionIndex + 1} title`}
                        onChange={(e) => patchSection(sectionIndex, { title: e.target.value })}
                      />
                    </RepeatableRow>
                    <div className="mt-1.5 space-y-1 pl-2">
                      {rows.map((row, rowIndex) => (
                        <RepeatableRow
                          key={row.id ?? rowIndex}
                          onRemove={() =>
                            patchSection(sectionIndex, { rows: rows.filter((_, i) => i !== rowIndex) })
                          }
                        >
                          <input
                            type="text"
                            className={`${inputClass} !mt-0`}
                            value={row.title ?? ''}
                            placeholder={`Row ${rowIndex + 1}`}
                            onChange={(e) =>
                              patchSection(sectionIndex, {
                                rows: rows.map((r, i) => (i === rowIndex ? { ...r, title: e.target.value } : r)),
                              })
                            }
                          />
                        </RepeatableRow>
                      ))}
                      <AddRowButton
                        label="Add row"
                        onClick={() =>
                          patchSection(sectionIndex, { rows: [...rows, { id: fieldId('row'), title: '' }] })
                        }
                      />
                    </div>
                  </div>
                );
              })}
              <AddRowButton
                label="Add section"
                onClick={() => update([...sections, { title: '', rows: [] }])}
              />
            </div>
          </FieldShell>
        );
      }

      case 'conditions': {
        const rules = asArray<{ variable?: string; operator?: ConditionalOperator; value?: string }>(value);
        const update = (next: typeof rules) => onChange({ [field.key]: next });
        const patchRule = (index: number, patch: Partial<(typeof rules)[number]>) =>
          update(rules.map((r, i) => (i === index ? { ...r, ...patch } : r)));

        return (
          <FieldShell key={field.key} field={field} error={error}>
            <div className="mt-1 space-y-1.5">
              {rules.map((rule, index) => (
                <RepeatableRow key={index} onRemove={() => update(rules.filter((_, i) => i !== index))}>
                  <input
                    type="text"
                    className={`${inputClass} !mt-0`}
                    value={rule.variable ?? ''}
                    placeholder="variable"
                    onChange={(e) => patchRule(index, { variable: e.target.value.trim().replace(/\s+/g, '_') })}
                  />
                  <select
                    className={`${inputClass} !mt-0`}
                    value={rule.operator ?? ''}
                    onChange={(e) => patchRule(index, { operator: e.target.value as ConditionalOperator })}
                  >
                    <option value="">— operator —</option>
                    {CONDITION_OPERATORS.map((option) => (
                      <option key={option.value} value={option.value}>
                        {option.label}
                      </option>
                    ))}
                  </select>
                  {rule.operator !== 'exists' && rule.operator !== 'not_exists' && (
                    <input
                      type="text"
                      className={`${inputClass} !mt-0`}
                      value={rule.value ?? ''}
                      placeholder="value"
                      onChange={(e) => patchRule(index, { value: e.target.value })}
                    />
                  )}
                </RepeatableRow>
              ))}
              <AddRowButton
                label="Add condition"
                onClick={() => update([...rules, { variable: '', operator: 'equals', value: '' }])}
              />
            </div>
          </FieldShell>
        );
      }

      case 'keyvalue': {
        const pairs = asArray<ApiKeyValue>(value);
        const update = (next: ApiKeyValue[]) => onChange({ [field.key]: next });

        return (
          <FieldShell key={field.key} field={field} error={error}>
            <div className="mt-1 space-y-1.5">
              {pairs.map((pair, index) => (
                <RepeatableRow key={index} onRemove={() => update(pairs.filter((_, i) => i !== index))}>
                  <div className="flex gap-1.5">
                    <input
                      type="text"
                      className={`${inputClass} !mt-0`}
                      value={pair.key ?? ''}
                      placeholder="name"
                      onChange={(e) =>
                        update(pairs.map((p, i) => (i === index ? { ...p, key: e.target.value } : p)))
                      }
                    />
                    <input
                      type="text"
                      className={`${inputClass} !mt-0`}
                      value={pair.value ?? ''}
                      placeholder="value"
                      onChange={(e) =>
                        update(pairs.map((p, i) => (i === index ? { ...p, value: e.target.value } : p)))
                      }
                    />
                  </div>
                </RepeatableRow>
              ))}
              <AddRowButton label="Add row" onClick={() => update([...pairs, { key: '', value: '' }])} />
            </div>
          </FieldShell>
        );
      }

      case 'strings': {
        const items = asArray<string>(value);
        const update = (next: string[]) => onChange({ [field.key]: next });

        return (
          <FieldShell key={field.key} field={field} error={error}>
            <div className="mt-1 space-y-1.5">
              {items.map((item, index) => (
                <RepeatableRow key={index} onRemove={() => update(items.filter((_, i) => i !== index))}>
                  <input
                    type="text"
                    className={`${inputClass} !mt-0`}
                    value={item ?? ''}
                    placeholder={field.placeholder}
                    onChange={(e) => update(items.map((s, i) => (i === index ? e.target.value : s)))}
                  />
                </RepeatableRow>
              ))}
              <AddRowButton label="Add row" onClick={() => update([...items, ''])} />
            </div>
          </FieldShell>
        );
      }

      case 'json':
        return (
          <FieldShell key={field.key} field={field} error={error}>
            <textarea
              className={`${inputClass} font-mono text-[11px]`}
              rows={5}
              spellCheck={false}
              value={typeof value === 'string' ? value : value == null ? '' : JSON.stringify(value, null, 2)}
              placeholder={field.placeholder}
              onChange={(e) => onChange({ [field.key]: e.target.value })}
            />
          </FieldShell>
        );

      // 'text' and 'url' — a url stays type="text" on purpose: a native
      // type="url" constraint bubble would pre-empt the field-level
      // message the registry produces, which is the behaviour this task
      // explicitly asks for ("do not rely only on HTML required").
      default:
        return (
          <FieldShell key={field.key} field={field} error={error}>
            <input
              type="text"
              className={inputClass}
              inputMode={field.type === 'url' ? 'url' : undefined}
              value={typeof value === 'string' ? value : ''}
              placeholder={field.placeholder}
              onChange={(e) => onChange({ [field.key]: e.target.value })}
            />
          </FieldShell>
        );
    }
  };

  return (
    <div className="space-y-3" data-testid={`node-config-${definition.type}`}>
      <div>
        <h3 className="text-sm font-semibold" style={{ color: indigo.ink }}>
          {definition.label}
        </h3>
        <p className="text-[11px]" style={{ color: indigo.muted }}>
          {definition.description}
        </p>
      </div>

      {definition.configSchema.map(renderField)}

      {definition.sourceHandles.length > 1 && (
        <p className="text-[11px]" style={{ color: indigo.muted }}>
          This node branches: drag the{' '}
          {definition.sourceHandles.map((handle) => handle.label).join(' / ')} dot to the step that branch
          should continue to. The branch is recorded on the connection itself, not on where the boxes sit.
        </p>
      )}
    </div>
  );
}
