import { useState } from 'react';
import { Plus, X } from 'lucide-react';

import { inputClass } from '../components/common/Card';
import { indigo } from '../theme/signalIndigo';
import { getJourneyNode, journeySourceHandles } from './nodeRegistry';
import ManageConditionsModal from './ManageConditionsModal';
import ListConfigModal from './ListConfigModal';
import {
  CONDITIONAL_MAX_GROUPS,
  MAX_REPLY_BUTTONS,
  type ApiKeyValue,
  type ConditionalGroup,
  type ConditionalOperator,
  type ConditionalRule,
  type JourneyNodeConfigErrors,
  type JourneyNodeField,
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
  /** Phase 8 Task 15 — the edited account's saved API connections; null while loading / unavailable. */
  apiConnections?: ReadonlyArray<{ id: number; name: string; base_url: string | null; headers: ApiKeyValue[]; query: ApiKeyValue[] }> | null;
  /** Task 25 — the edited account's OTHER journeys, for a sub-journey node's Static-mode picker; null while loading / unavailable. */
  subJourneys?: ReadonlyArray<{ id: number; name: string }> | null;
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
  apiConnections = null,
  subJourneys = null,
  onChange,
  hideHeader = false,
}: JourneyNodeConfigFormProps) {
  const [conditionsModalField, setConditionsModalField] = useState<string | null>(null);
  const [listModalField, setListModalField] = useState<string | null>(null);
  // Task 23 — which branch's Manage Conditions modal is open, in
  // 'conditionGroups' multi-branch mode. Index only — the group list
  // itself always comes fresh from `config.groups` on every render.
  const [conditionGroupModal, setConditionGroupModal] = useState<number | null>(null);
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

  const sourceHandles = journeySourceHandles(definition, config);

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

      case 'apiConnection': {
        // Phase 8 Task 15 — account-scoped choices; the backend re-checks
        // ownership on save (WhatsAppFlowController::
        // assertApiConnectionsOwned()). Selecting a connection fills this
        // node's own url/headers/query from it (a one-time copy, not a
        // live link — there is no runtime for the `api` node yet to
        // resolve a reference at execution time); non-empty existing
        // values are left alone so a choice here never silently clobbers
        // hand-entered configuration.
        const selected = value === undefined || value === null ? '' : String(value);
        const known = (apiConnections ?? []).some((c) => String(c.id) === selected);

        return (
          <FieldShell key={field.key} field={field} error={error}>
            <select
              className={inputClass}
              data-testid={`apiconn-select-${field.key}`}
              value={selected}
              onChange={(e) => {
                const id = e.target.value;
                const connection = (apiConnections ?? []).find((c) => String(c.id) === id);
                const patch: Record<string, unknown> = { [field.key]: id === '' ? undefined : Number(id) };

                if (connection) {
                  if (!config.url) patch.url = connection.base_url ?? '';
                  if (!asArray<ApiKeyValue>(config.headers).length) patch.headers = connection.headers;
                  if (!asArray<ApiKeyValue>(config.query).length) patch.query = connection.query;
                }

                onChange(patch);
              }}
            >
              <option value="">{apiConnections === null ? 'Loading API connections…' : '— none —'}</option>
              {(apiConnections ?? []).map((c) => (
                <option key={c.id} value={String(c.id)}>
                  {c.name}
                </option>
              ))}
              {selected !== '' && !known && apiConnections !== null && <option value={selected}>Unavailable API connection (#{selected})</option>}
            </select>
            {apiConnections !== null && apiConnections.length === 0 && (
              <p className="mt-1 text-[11px] text-slate-500" data-testid="apiconn-empty">
                This account has no saved API connections yet — use "API Connections" in the Journey Builder's toolbar to add one, or fill in the fields below by
                hand.
              </p>
            )}
          </FieldShell>
        );
      }

      case 'subJourney': {
        // Task 25 — account-scoped choices; the backend re-checks
        // ownership on save (WhatsAppFlowController::assertSubJourneysOwned()).
        const selected = value === undefined || value === null ? '' : String(value);
        const known = (subJourneys ?? []).some((j) => String(j.id) === selected);

        return (
          <FieldShell key={field.key} field={field} error={error}>
            <select
              className={inputClass}
              data-testid={`subjourney-select-${field.key}`}
              value={selected}
              onChange={(e) => onChange({ [field.key]: e.target.value })}
            >
              <option value="">{subJourneys === null ? 'Loading journeys…' : '— select —'}</option>
              {(subJourneys ?? []).map((j) => (
                <option key={j.id} value={String(j.id)}>
                  {j.name}
                </option>
              ))}
              {selected !== '' && !known && subJourneys !== null && <option value={selected}>Unavailable journey (#{selected})</option>}
            </select>
            {subJourneys !== null && subJourneys.length === 0 && (
              <p className="mt-1 text-[11px] text-slate-500" data-testid="subjourney-empty">
                This account has no other journeys yet to jump into.
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
        const totalRows = sections.reduce((sum, s) => sum + (Array.isArray(s.rows) ? s.rows.length : 0), 0);

        return (
          <FieldShell key={field.key} field={field} error={error}>
            <div className="mt-1 flex items-center justify-between gap-2 rounded-lg border px-2.5 py-2" style={{ borderColor: indigo.border }}>
              <span className="text-xs text-slate-600">
                {sections.length === 0 ? 'No sections yet' : `${sections.length} section${sections.length === 1 ? '' : 's'}, ${totalRows} row${totalRows === 1 ? '' : 's'}`}
              </span>
              <button
                type="button"
                onClick={() => setListModalField(field.key)}
                className={`${ROW_BUTTON} flex-shrink-0`}
                style={{ color: indigo.accentSolid }}
              >
                Manage List
              </button>
            </div>
            {listModalField === field.key && (
              <ListConfigModal
                initialSections={sections}
                onClose={() => setListModalField(null)}
                onSave={(next) => {
                  onChange({ [field.key]: next });
                  setListModalField(null);
                }}
              />
            )}
          </FieldShell>
        );
      }

      case 'conditions': {
        const rules = asArray<{ variable?: string; operator?: ConditionalOperator; value?: string }>(value);

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
                initialMatch={config.match === 'any' ? 'any' : 'all'}
                knownVariables={knownVariables}
                onClose={() => setConditionsModalField(null)}
                onSave={(next, match) => {
                  // Both fields are plain config keys (see the registry's
                  // separate 'match' select) — merged in one patch so a
                  // single Save can't leave them out of sync.
                  onChange({ [field.key]: next, match });
                  setConditionsModalField(null);
                }}
              />
            )}
          </FieldShell>
        );
      }

      case 'conditionGroups': {
        // Task 23 — Connexxa IF/ELSE-IF/ELSE parity. `groups` absent/empty
        // = the legacy single-branch shape every journey saved before
        // this feature already uses (field.key's own 'conditions' +
        // top-level 'match', exactly as the old 'conditions' case above
        // renders them) — this case ADDS the "switch to multi-branch"
        // entry point and, once switched, the per-branch editor. See
        // ConditionalNodeConfig's docblock (types/journeyNodes.ts).
        const groups = asArray<ConditionalGroup>(config.groups);
        const legacyRules = asArray<ConditionalRule>(value);
        const legacyMatch: 'all' | 'any' = config.match === 'any' ? 'any' : 'all';

        if (groups.length === 0) {
          return (
            <FieldShell key={field.key} field={field} error={error}>
              <div className="mt-1 flex items-center justify-between gap-2 rounded-lg border px-2.5 py-2" style={{ borderColor: indigo.border }}>
                <span className="text-xs text-slate-600">
                  {legacyRules.length === 0 ? 'No conditions yet' : `${legacyRules.length} condition${legacyRules.length === 1 ? '' : 's'} configured`}
                </span>
                <button type="button" onClick={() => setConditionsModalField(field.key)} className={`${ROW_BUTTON} flex-shrink-0`} style={{ color: indigo.accentSolid }}>
                  Manage Conditions
                </button>
              </div>
              {conditionsModalField === field.key && (
                <ManageConditionsModal
                  initialConditions={legacyRules}
                  initialMatch={legacyMatch}
                  knownVariables={knownVariables}
                  onClose={() => setConditionsModalField(null)}
                  onSave={(next, match) => {
                    onChange({ [field.key]: next, match });
                    setConditionsModalField(null);
                  }}
                />
              )}
              <div className="mt-1.5">
                <AddRowButton
                  label="Add an ELSE IF branch"
                  onClick={() =>
                    onChange({
                      groups: [
                        { conditions: legacyRules, match: legacyMatch },
                        { conditions: [], match: 'all' },
                      ],
                    })
                  }
                />
              </div>
            </FieldShell>
          );
        }

        const updateGroups = (next: ConditionalGroup[]) => onChange({ groups: next });

        return (
          <FieldShell key={field.key} field={field} error={error}>
            <div className="mt-1 space-y-1.5">
              {groups.map((group, index) => {
                const rules = asArray<ConditionalRule>(group.conditions);

                return (
                  <RepeatableRow
                    key={index}
                    onRemove={() => {
                      const next = groups.filter((_, i) => i !== index);
                      // Dropping back to one branch is the legacy shape
                      // again — revert cleanly rather than leaving a
                      // 1-item groups array around.
                      next.length <= 1
                        ? onChange({ groups: undefined, conditions: next[0]?.conditions ?? [], match: next[0]?.match ?? 'all' })
                        : updateGroups(next);
                    }}
                  >
                    <div className="flex items-center justify-between gap-2 rounded-lg border px-2.5 py-2" style={{ borderColor: indigo.border }}>
                      <span className="text-xs font-semibold" style={{ color: indigo.ink }}>
                        {index === 0 ? 'IF' : `ELSE IF ${index}`}
                        <span className="ml-1.5 font-normal text-slate-500">
                          {rules.length === 0 ? 'no conditions yet' : `${rules.length} condition${rules.length === 1 ? '' : 's'}`}
                        </span>
                      </span>
                      <button type="button" onClick={() => setConditionGroupModal(index)} className={`${ROW_BUTTON} flex-shrink-0`} style={{ color: indigo.accentSolid }}>
                        Manage Conditions
                      </button>
                    </div>
                    {conditionGroupModal === index && (
                      <ManageConditionsModal
                        initialConditions={rules}
                        initialMatch={group.match === 'any' ? 'any' : 'all'}
                        knownVariables={knownVariables}
                        onClose={() => setConditionGroupModal(null)}
                        onSave={(nextConditions, match) => {
                          updateGroups(groups.map((g, i) => (i === index ? { conditions: nextConditions, match } : g)));
                          setConditionGroupModal(null);
                        }}
                      />
                    )}
                  </RepeatableRow>
                );
              })}
              {groups.length < CONDITIONAL_MAX_GROUPS && (
                <AddRowButton label="Add another ELSE IF branch" onClick={() => updateGroups([...groups, { conditions: [], match: 'all' }])} />
              )}
              <p className="rounded-lg border border-dashed px-2.5 py-2 text-[11px]" style={{ borderColor: indigo.border, color: indigo.muted }}>
                <span className="font-semibold" style={{ color: indigo.ink }}>ELSE</span> — whatever matched none of the branches above. No conditions to configure; connect its own handle on the card.
              </p>
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

      case 'classifierBranches': {
        // Phase 8 Task 16 — positionally wired to the classifier node's
        // 5 fixed handles (branch_1..branch_5, see nodeRegistry.tsx's
        // CLASSIFIER_HANDLES): row index i IS branch i+1, nothing else
        // records that mapping. Capped at 5 rows for exactly that reason.
        const MAX_CLASSIFIER_BRANCHES = 5;
        const branches = asArray<{ label?: string; intent?: string }>(value);
        const update = (next: { label?: string; intent?: string }[]) => onChange({ [field.key]: next });

        return (
          <FieldShell key={field.key} field={field} error={error}>
            <div className="mt-1 space-y-1.5">
              {branches.map((branch, index) => (
                <RepeatableRow key={index} onRemove={() => update(branches.filter((_, i) => i !== index))}>
                  <div className="flex gap-1.5">
                    <span className="mt-1.5 flex-shrink-0 text-[11px] font-medium" style={{ color: indigo.muted }}>
                      {index + 1}.
                    </span>
                    <input
                      type="text"
                      className={`${inputClass} !mt-0`}
                      value={branch.label ?? ''}
                      placeholder="Branch label"
                      onChange={(e) => update(branches.map((b, i) => (i === index ? { ...b, label: e.target.value } : b)))}
                    />
                    <input
                      type="text"
                      className={`${inputClass} !mt-0`}
                      value={branch.intent ?? ''}
                      placeholder="Intent (optional context for the model)"
                      onChange={(e) => update(branches.map((b, i) => (i === index ? { ...b, intent: e.target.value } : b)))}
                    />
                  </div>
                </RepeatableRow>
              ))}
              {branches.length < MAX_CLASSIFIER_BRANCHES && (
                <AddRowButton label="Add branch" onClick={() => update([...branches, { label: '' }])} />
              )}
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

      {sourceHandles.length > 1 && (
        <p className="text-[11px]" style={{ color: indigo.muted }}>
          This node branches: drag the{' '}
          {sourceHandles.map((handle) => handle.label).join(' / ')} dot to the step that branch
          should continue to. The branch is recorded on the connection itself, not on where the boxes sit.
        </p>
      )}
    </div>
  );
}
