import { useState } from 'react';
import { Plus, X } from 'lucide-react';

import { inputClass } from '../components/common/Card';
import { indigo } from '../theme/signalIndigo';
import { getJourneyNode } from './nodeRegistry';
import ManageConditionsModal from './ManageConditionsModal';
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
  /** Phase 8 Task 10 — the edited account's knowledge bases; null while loading / unavailable. */
  knowledgeBases?: ReadonlyArray<{ id: number; name: string }> | null;
  /** Phase 8 Task 11 — the edited account's registered AI agents; null while loading / unavailable. */
  aiAgents?: ReadonlyArray<{ id: number; name: string; is_enabled?: boolean }> | null;
  onChange: (patch: Record<string, unknown>) => void;
  /** Rendered inline inside a canvas node card, which already shows the node's icon + label — skip the repeated heading. */
  hideHeader?: boolean;
}

export default function JourneyNodeConfigForm({
  nodeType,
  config,
  errors = {},
  knownVariables = [],
  knowledgeBases = null,
  aiAgents = null,
  onChange,
  hideHeader = false,
}: JourneyNodeConfigFormProps) {
  const [conditionsModalField, setConditionsModalField] = useState<string | null>(null);
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

      case 'knowledgeBase': {
        // Phase 8 Task 10 — account-scoped choices; the backend re-checks ownership.
        const selected = value === undefined || value === null ? '' : String(value);
        const known = (knowledgeBases ?? []).some((kb) => String(kb.id) === selected);

        return (
          <FieldShell key={field.key} field={field} error={error}>
            <select
              className={inputClass}
              data-testid={`kb-select-${field.key}`}
              value={selected}
              onChange={(e) => onChange({ [field.key]: e.target.value })}
            >
              <option value="">{knowledgeBases === null ? 'Loading knowledge bases…' : '— select —'}</option>
              {(knowledgeBases ?? []).map((kb) => (
                <option key={kb.id} value={String(kb.id)}>
                  {kb.name}
                </option>
              ))}
              {selected !== '' && !known && knowledgeBases !== null && (
                <option value={selected}>Unavailable knowledge base (#{selected})</option>
              )}
            </select>
            {knowledgeBases !== null && knowledgeBases.length === 0 && (
              <p className="mt-1 text-[11px] text-slate-500" data-testid="kb-empty">
                This account has no knowledge bases yet. There is no screen to create one in this version — they are added through the Knowledge Base API
                (<code>/api/knowledge-bases</code>); ask your administrator.
              </p>
            )}
          </FieldShell>
        );
      }

      case 'aiAgent': {
        // Phase 8 Task 11 — account-scoped choices; the backend re-checks ownership on save and at run time.
        const selected = value === undefined || value === null ? '' : String(value);
        const known = (aiAgents ?? []).some((agent) => String(agent.id) === selected);

        return (
          <FieldShell key={field.key} field={field} error={error}>
            <select
              className={inputClass}
              data-testid={`agent-select-${field.key}`}
              value={selected}
              onChange={(e) => onChange({ [field.key]: e.target.value })}
            >
              <option value="">{aiAgents === null ? 'Loading AI agents…' : '— none (single bounded reply) —'}</option>
              {(aiAgents ?? []).map((agent) => (
                <option key={agent.id} value={String(agent.id)}>
                  {agent.name}
                  {agent.is_enabled === false ? ' (disabled)' : ''}
                </option>
              ))}
              {selected !== '' && !known && aiAgents !== null && <option value={selected}>Unavailable AI agent (#{selected})</option>}
            </select>
            {aiAgents !== null && aiAgents.length === 0 && (
              <p className="mt-1 text-[11px] text-slate-500" data-testid="agent-empty">
                This account has no registered AI agents yet — leave this empty for a single bounded reply. There is no screen to create agents in this version — they
                are added through the AI Agents API (<code>/api/ai-agents</code>); ask your administrator.
              </p>
            )}
          </FieldShell>
        );
      }

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

        return (
          <FieldShell key={field.key} field={field} error={error}>
            <div className="mt-1 flex items-center justify-between gap-2 rounded-lg border px-2.5 py-2" style={{ borderColor: indigo.border }}>
              <span className="text-xs text-slate-600">
                {rules.length === 0 ? 'No conditions yet' : `${rules.length} condition${rules.length === 1 ? '' : 's'} configured`}
              </span>
              <button
                type="button"
                onClick={() => setConditionsModalField(field.key)}
                className={`${ROW_BUTTON} flex-shrink-0`}
                style={{ color: indigo.accentSolid }}
              >
                Manage Conditions
              </button>
            </div>
            {conditionsModalField === field.key && (
              <ManageConditionsModal
                initialConditions={rules}
                knownVariables={knownVariables}
                onClose={() => setConditionsModalField(null)}
                onSave={(next) => {
                  update(next);
                  setConditionsModalField(null);
                }}
              />
            )}
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
                    {/*
                      P5-6 — a credential value comes back from the server
                      masked (`masked: true`, value = the mask). It is never
                      shown or re-typed: left untouched, the mask is sent back
                      and the server keeps the stored (encrypted) value; typing
                      replaces it.
                    */}
                    <input
                      type={pair.masked ? 'password' : 'text'}
                      className={`${inputClass} !mt-0`}
                      value={pair.masked ? '' : (pair.value ?? '')}
                      placeholder={pair.masked ? 'Saved — type to replace' : 'value'}
                      autoComplete="off"
                      onChange={(e) =>
                        update(pairs.map((p, i) => (i === index ? { key: p.key, value: e.target.value } : p)))
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
      {!hideHeader && (
        <div>
          <h3 className="text-sm font-semibold" style={{ color: indigo.ink }}>
            {definition.label}
          </h3>
          <p className="text-[11px]" style={{ color: indigo.muted }}>
            {definition.description}
          </p>
        </div>
      )}

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
