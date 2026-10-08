import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { MouseEvent as ReactMouseEvent } from 'react';
import {
  AlertTriangle,
  ArrowLeft,
  Blocks,
  LayoutGrid,
  List,
  Loader2,
  Lock,
  Plus,
  Send,
  Trash2,
  X,
  XCircle,
  Zap,
} from 'lucide-react';
import { Link } from 'react-router-dom';
import journeyService from '../../services/journeyService';
import knowledgeBaseService, { type KnowledgeBaseSummary } from '../../services/knowledgeBaseService';
import aiAgentService, { type AiAgentSummary } from '../../services/aiAgentService';
import {
  JOURNEY_NODE_CATEGORIES,
  JOURNEY_NODE_CATEGORY_LABELS,
} from '../../types/journeyNodes';
import {
  getJourneyNode,
  isRuntimeExecutableNodeType,
  journeyNodesByCategory,
  nonExecutableNodeTypes,
  validateJourneyGraph,
} from '../../journey/nodeRegistry';
import JourneyNodeConfigForm from '../../journey/JourneyNodeConfigForm';
import { journeyNodeAvailability, type JourneyEntitlementContext } from '../../journey/nodeEntitlement';
import { ClearFiltersButton, SearchInput } from '../../components/common/DataTableControls';
import { TableCard, inputClass } from '../../components/common/Card';
import ConfirmModal from '../../components/common/ConfirmModal';
import { useUpgradePath } from '../../components/common/actionGateHooks';
import { useAuth } from '../../core/context/AuthContext';
import { useTenant } from '../../core/context/TenantContext';
import { extractErrorMessage } from '../../utils/apiError';
import { indigo, activeGradient } from '../../theme/signalIndigo';
import { TRIGGER_TYPE_LABELS } from '../../types/journey';
import type {
  ConditionOperator,
  FlowTriggerType,
  JourneyEdge,
  JourneyGraph,
  JourneyLegacyEngineNodeType,
  JourneyNode,
  JourneyNodeOption,
  JourneyNodeType,
  QuestionInputType,
  QuestionValidationType,
  SaveFlowPayload,
  WhatsAppFlow,
} from '../../types/journey';
import DismissibleAlert from '../../components/common/DismissibleAlert';

/**
 * Module 5 — No-Code WhatsApp Journey Builder.
 *
 * DISCLOSED ENVIRONMENT CONSTRAINT: this canvas is built with ZERO new
 * npm dependencies — `npm ping`/a direct registry fetch both returned
 * 403 from this environment's proxy (confirmed before writing a line of
 * this file), consistent with this session's standing "restricted
 * environment, do not assume package installation permissions"
 * discipline. A library like React Flow would normally be the obvious
 * choice for a node-graph editor; instead, nodes are plain absolutely-
 * positioned <div>s (drag via pointer events, position kept in React
 * state) and edges are hand-drawn SVG <path> curves recomputed from
 * node positions on every render. This means: no pan/zoom, no auto-
 * layout, no minimap — a deliberately minimal-but-complete trade-off,
 * not an oversight. If react-flow (or an equivalent) becomes
 * installable in a future session, swapping it in is a contained,
 * isolated rewrite of this one file — the backend graph_data JSON
 * contract (WhatsAppJourneyEngine's docblock) does not change either way.
 */

// Widened from the original 208px chip so a node's own configuration
// fields (text, buttons, sections, …) fit directly in the card — see the
// canvas node render below. NODE_HEIGHT is only the pre-measurement
// fallback (a node's real height is measured once it has painted; see
// `nodeHeights` state) since cards are no longer a fixed size.
const NODE_WIDTH = 300;
const NODE_HEIGHT = 92;
const CANVAS_WIDTH = 2400;
const CANVAS_HEIGHT = 2200;

/**
 * Phase 5 — Journey / Automation: node metadata now comes from the ONE
 * canonical registry (src/journey/nodeRegistry.tsx) instead of the
 * hand-maintained NODE_TYPE_META map that used to sit here. That map
 * covered five types; the registry covers all 32 (27 palette + the 5
 * legacy ones saved flows already contain), and adding a node is a
 * registry entry rather than an edit in three files.
 *
 * `fallbackMeta` only ever fires for a type this build has never heard
 * of — a flow saved by a newer deploy. It renders visibly rather than
 * crashing the canvas.
 */
const FALLBACK_META = { icon: Zap, color: '#64748b', bg: '#f1f5f9' };

/**
 * Rounded elbow (step) connector — out of the source's right edge, one
 * vertical run, into the target's left edge, replacing the earlier
 * diagonal S-curve. Matches the reference builder's routing and, unlike
 * a diagonal bezier, never visually crosses through an unrelated node
 * sitting between source and target on a plain two-column layout.
 */
function orthogonalEdgePath(x1: number, y1: number, x2: number, y2: number): string {
  const STUB = 28;
  const midX = x1 + Math.max(STUB, (x2 - x1) / 2);

  if (Math.abs(y1 - y2) < 1) {
    return `M ${x1} ${y1} L ${x2} ${y2}`;
  }

  const dy = y2 >= y1 ? 1 : -1;
  const dx2 = x2 >= midX ? 1 : -1;
  const r = Math.max(0, Math.min(10, Math.abs(y2 - y1) / 2, Math.abs(midX - x1), Math.abs(x2 - midX)));

  if (r === 0) {
    return `M ${x1} ${y1} L ${midX} ${y1} L ${midX} ${y2} L ${x2} ${y2}`;
  }

  return [
    `M ${x1} ${y1}`,
    `L ${midX - r} ${y1}`,
    `Q ${midX} ${y1} ${midX} ${y1 + r * dy}`,
    `L ${midX} ${y2 - r * dy}`,
    `Q ${midX} ${y2} ${midX + r * dx2} ${y2}`,
    `L ${x2} ${y2}`,
  ].join(' ');
}

function nodeMeta(type: string): { icon: typeof Zap; color: string; bg: string } {
  const definition = getJourneyNode(type);

  return definition
    ? { icon: definition.icon as typeof Zap, color: definition.color, bg: definition.background }
    : FALLBACK_META;
}

function nodeLabel(type: string): string {
  return getJourneyNode(type)?.label ?? type;
}



const VALIDATION_TYPE_OPTIONS: { value: QuestionValidationType; label: string }[] = [
  { value: 'none', label: 'None' },
  { value: 'number', label: 'Number' },
  { value: 'email', label: 'Email' },
  { value: 'phone', label: 'Phone number' },
];

const CONDITION_OPERATOR_OPTIONS: { value: ConditionOperator; label: string }[] = [
  { value: 'equals', label: 'equals' },
  { value: 'not_equals', label: 'does not equal' },
  { value: 'contains', label: 'contains' },
  { value: 'exists', label: 'was answered' },
];

function genId(prefix: string): string {
  return `${prefix}_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;
}

/**
 * A new journey starts with its Start (type: 'trigger') and End nodes
 * already on the canvas, unconnected — the user wires the rest between
 * them. Matches the reference builder's "Start+End auto-added" behaviour.
 * See `stripEndNodes()` for why the End node never reaches the backend.
 */
function newJourneyGraph(): JourneyGraph {
  return {
    nodes: [
      { id: genId('trigger'), type: 'trigger', position: { x: 40, y: 160 }, data: {} },
      { id: genId('end'), type: 'end', position: { x: 40, y: 480 }, data: { ...(getJourneyNode('end')?.defaultConfig ?? {}) } },
    ],
    edges: [],
  };
}

/**
 * Every 'end' node (and any edge into one) is UI sugar, stripped before
 * validation and before the save payload is built — see EndNodeConfig's
 * docblock (types/journeyNodes.ts) for why this is safe: a node with no
 * outgoing edge already completes the session today.
 */
function stripEndNodes(graph: JourneyGraph): JourneyGraph {
  const endIds = new Set(graph.nodes.filter((n) => n.type === 'end').map((n) => n.id));

  if (endIds.size === 0) return graph;

  return {
    nodes: graph.nodes.filter((n) => !endIds.has(n.id)),
    edges: graph.edges.filter((e) => !endIds.has(e.target) && !endIds.has(e.source)),
  };
}

/**
 * The inverse of stripEndNodes(), run once when a graph is first loaded
 * onto the canvas (new or existing journey).
 *
 * BUG FIXED: stripEndNodes() removes every 'end' node, and every edge
 * that pointed at one, before a save — correct, since the backend has no
 * 'end' type and the engine already completes a session once a node has
 * no outgoing edge (WhatsAppJourneyEngine::advance()). But that also
 * means a SAVED journey's graph_data never has an 'end' node, so
 * re-opening it for editing showed either no End node at all, or (once
 * the user re-added one by hand) only whatever single connection they'd
 * just drawn — every earlier node that used to converge on End looked
 * like it had silently lost its connection, because that information
 * genuinely isn't in the saved data: a "leaf" node (no outgoing edge on
 * a handle that CAN have one) is indistinguishable from "used to point
 * at End" — they mean the same thing to the engine.
 *
 * So: if the graph has no 'end' node yet (a reload), reconstruct it by
 * adding one and drawing an edge to it from every such leaf handle,
 * across every node — exactly recreating the "N nodes converge on End"
 * picture the user had before the last save, including when several
 * different nodes all finish there. A brand-new graph (newJourneyGraph())
 * already has its own 'end' node and is returned unchanged.
 */
function ensureEndConnections(graph: JourneyGraph): JourneyGraph {
  if (graph.nodes.some((n) => n.type === 'end')) return graph;

  const leafHandles: { nodeId: string; handleId: string }[] = [];

  for (const node of graph.nodes) {
    // save_lead renders no outgoing handle at all (it's a natural
    // terminus); legacy 'condition' manages its own branch edges on the
    // edge object rather than one-edge-per-handle, so guessing which of
    // its branches is "unfilled" would be guessing, not reading data.
    if (node.type === 'end' || node.type === 'save_lead' || node.type === 'condition') continue;

    const handles = getJourneyNode(node.type)?.sourceHandles ?? [{ id: 'next', label: 'Next' }];

    for (const handle of handles) {
      const hasEdge = graph.edges.some((e) => e.source === node.id && (e.sourceHandle ?? 'next') === handle.id);
      if (!hasEdge) leafHandles.push({ nodeId: node.id, handleId: handle.id });
    }
  }

  const maxY = graph.nodes.length > 0 ? Math.max(...graph.nodes.map((n) => n.position.y)) : 160;
  const endNode: JourneyNode = {
    id: genId('end'),
    type: 'end',
    position: { x: 40, y: maxY + 260 },
    data: { ...(getJourneyNode('end')?.defaultConfig ?? {}) },
  };

  const newEdges: JourneyEdge[] = leafHandles.map(({ nodeId, handleId }) => ({
    id: genId('edge'),
    source: nodeId,
    target: endNode.id,
    ...(handleId !== 'next' ? { sourceHandle: handleId } : {}),
  }));

  return {
    nodes: [...graph.nodes, endNode],
    edges: [...graph.edges, ...newEdges],
  };
}

/**
 * Node-add palette — a collapsed icon rail that opens a searchable
 * "Nodes" panel with a grid/list view toggle, matching the reference
 * builder (which shows node tiles on a side rail instead of an
 * always-expanded horizontal bar). Still entirely registry-driven:
 * every node, icon, label, category, entitlement check and
 * draft-only badge below reads straight from the same
 * journeyNodesByCategory()/journeyNodeAvailability() the old bar used
 * — this only changes how it's laid out, not what's in it.
 */
function NodePalette({
  entitlement,
  onAdd,
  onBlocked,
}: {
  entitlement: JourneyEntitlementContext;
  onAdd: (type: JourneyNodeType) => void;
  onBlocked: (reason: string, upgradable: boolean) => void;
}) {
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState('');
  const [view, setView] = useState<'grid' | 'list'>('grid');

  const totalCount = JOURNEY_NODE_CATEGORIES.reduce((sum, c) => sum + journeyNodesByCategory(c).length, 0);
  const query = search.trim().toLowerCase();

  return (
    <div className="relative flex-shrink-0">
      <div className="flex w-12 flex-col items-center gap-1.5 rounded-xl border bg-white py-3" style={{ borderColor: indigo.border }}>
        <button
          type="button"
          onClick={() => setOpen((o) => !o)}
          data-testid="palette-toggle"
          aria-expanded={open}
          aria-label="Add node"
          title="Add node"
          className="flex h-9 w-9 items-center justify-center rounded-lg"
          style={open ? { background: activeGradient, color: '#fff' } : { color: indigo.ink }}
        >
          <Blocks className="h-4 w-4" />
        </button>
        <span className="text-[9px] font-bold uppercase tracking-wide" style={{ color: indigo.muted }}>
          Nodes
        </span>
      </div>

      {open && (
        <div
          data-testid="node-palette-panel"
          onMouseDown={(e) => e.stopPropagation()}
          className="absolute left-14 top-0 z-20 flex max-h-[80vh] w-80 flex-col rounded-xl border bg-white shadow-xl"
          style={{ borderColor: indigo.border }}
        >
          <div className="flex items-center justify-between gap-2 border-b px-3 py-2.5" style={{ borderColor: indigo.border }}>
            <span className="font-display text-sm font-bold" style={{ color: indigo.ink }}>
              Nodes <span className="font-normal" style={{ color: indigo.muted }}>{totalCount}</span>
            </span>
            <div className="flex items-center gap-1">
              <button
                type="button"
                onClick={() => setView('grid')}
                aria-label="Grid view"
                aria-pressed={view === 'grid'}
                className="rounded-md p-1.5"
                style={{ background: view === 'grid' ? '#f1f5f9' : 'transparent', color: indigo.ink }}
              >
                <LayoutGrid className="h-3.5 w-3.5" />
              </button>
              <button
                type="button"
                onClick={() => setView('list')}
                aria-label="List view"
                aria-pressed={view === 'list'}
                className="rounded-md p-1.5"
                style={{ background: view === 'list' ? '#f1f5f9' : 'transparent', color: indigo.ink }}
              >
                <List className="h-3.5 w-3.5" />
              </button>
              <button type="button" onClick={() => setOpen(false)} className="rounded-md p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Close">
                <X className="h-3.5 w-3.5" />
              </button>
            </div>
          </div>

          <div className="border-b px-3 py-2" style={{ borderColor: indigo.border }}>
            <SearchInput value={search} onChange={setSearch} placeholder="Search nodes…" />
          </div>

          <div className="flex-1 overflow-y-auto px-3 py-3">
            {JOURNEY_NODE_CATEGORIES.map((category) => {
              const nodes = journeyNodesByCategory(category).filter(
                (d) => !query || d.label.toLowerCase().includes(query) || d.description.toLowerCase().includes(query),
              );

              if (nodes.length === 0) return null;

              return (
                <div key={category} data-testid={`palette-category-${category}`} className="mb-4">
                  <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                    {JOURNEY_NODE_CATEGORY_LABELS[category]}
                  </span>
                  <div className={view === 'grid' ? 'mt-1.5 grid grid-cols-3 gap-1.5' : 'mt-1.5 flex flex-col gap-1'}>
                    {nodes.map((definition) => {
                      const Icon = definition.icon;
                      /*
                        Phase 5 Task 7 — entitlement UX, NOT authorization.
                        The server re-derives this from the account's own
                        entitlements and subscription and answers 403
                        JOURNEY_NODE_NOT_ENTITLED regardless of what this
                        button does. Disabling it only spares the operator
                        from configuring a node they were never going to be
                        allowed to save. See src/journey/nodeEntitlement.ts.
                      */
                      const availability = journeyNodeAvailability(definition, entitlement);
                      // P5-7 — runtime truth (UX): placeable in a draft, not publishable yet.
                      const runnable = isRuntimeExecutableNodeType(definition.type);
                      const tooltip =
                        availability.reason ??
                        (runnable ? definition.description : `${definition.description} Not executable yet — a journey containing it can only be saved as a draft.`);

                      return (
                        <button
                          key={definition.type}
                          type="button"
                          data-testid={`palette-node-${definition.type}`}
                          data-node-category={definition.category}
                          data-entitled={availability.available ? 'true' : 'false'}
                          data-runtime={runnable ? 'executable' : 'draft-only'}
                          aria-disabled={!availability.available}
                          onClick={() => {
                            if (!availability.available) {
                              onBlocked(availability.reason ?? `${definition.label} is not available for this account.`, (availability.reason ?? '').includes('capability'));
                              return;
                            }
                            onAdd(definition.type);
                          }}
                          title={tooltip}
                          className={
                            view === 'grid'
                              ? `relative flex flex-col items-center gap-1 rounded-lg border px-1.5 py-2 text-center hover:bg-slate-50 ${availability.available ? '' : 'opacity-40 hover:bg-transparent'}`
                              : `flex items-center gap-2 rounded-lg border px-2.5 py-1.5 text-left hover:bg-slate-50 ${availability.available ? '' : 'opacity-40 hover:bg-transparent'}`
                          }
                          style={{ borderColor: indigo.border, color: definition.color }}
                        >
                          <Icon className={view === 'grid' ? 'h-4 w-4' : 'h-3.5 w-3.5 flex-shrink-0'} />
                          <span
                            className={view === 'grid' ? 'line-clamp-1 w-full text-[10px] font-semibold' : 'flex-1 text-xs font-semibold'}
                            style={{ color: indigo.ink }}
                          >
                            {definition.label}
                          </span>
                          {!availability.available && (
                            <Lock className={view === 'grid' ? 'absolute right-1 top-1 h-2.5 w-2.5 text-slate-400' : 'h-3 w-3 flex-shrink-0 text-slate-400'} />
                          )}
                          {availability.available && !runnable && (
                            <span
                              className={
                                view === 'grid'
                                  ? 'absolute -right-1 -top-1 rounded bg-slate-100 px-1 text-[7px] font-bold uppercase text-slate-500'
                                  : 'flex-shrink-0 rounded bg-slate-100 px-1 text-[9px] font-bold uppercase tracking-wide text-slate-500'
                              }
                            >
                              {view === 'grid' ? 'D' : 'Draft only'}
                            </span>
                          )}
                        </button>
                      );
                    })}
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      )}
    </div>
  );
}

/** ---------- Flows list view ---------- */

function FlowsListView({
  flows,
  isLoading,
  onEdit,
  onCreate,
  onDelete,
  onToggle,
  onTest,
  busyId,
}: {
  flows: WhatsAppFlow[];
  isLoading: boolean;
  onEdit: (flow: WhatsAppFlow) => void;
  onCreate: () => void;
  onDelete: (flow: WhatsAppFlow) => void;
  onToggle: (flow: WhatsAppFlow) => void;
  onTest: (flow: WhatsAppFlow) => void;
  busyId: number | null;
}) {
  const [search, setSearch] = useState('');

  const filtered = useMemo(() => {
    const term = search.trim().toLowerCase();
    if (!term) return flows;
    return flows.filter((f) => f.name.toLowerCase().includes(term));
  }, [flows, search]);

  return (
    <div className="p-6">
      <div className="w-full">
        <div className="mb-6 flex items-center justify-between">
          <div>
            <h1 className="font-display text-lg font-bold" style={{ color: indigo.ink }}>
              WhatsApp Journey Builder
            </h1>
            <p className="mt-1 text-sm" style={{ color: indigo.muted }}>
              No-code multi-turn conversation flows — collect answers, branch on them, and save leads automatically.
            </p>
          </div>
          <button
            onClick={onCreate}
            className="flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold text-white"
            style={{ background: activeGradient }}
          >
            <Plus className="h-4 w-4" />
            New Journey
          </button>
        </div>

        <div className="mb-4 flex flex-wrap items-center gap-3">
          <SearchInput value={search} onChange={setSearch} placeholder="Search journeys…" />
          <ClearFiltersButton active={search !== ''} onClear={() => setSearch('')} />
        </div>

        <TableCard>
          <table className="min-w-full divide-y divide-slate-200 text-sm">
            <thead className="bg-slate-50">
              <tr>
                <th className="px-4 py-3 text-left font-medium text-slate-600">Name</th>
                <th className="px-4 py-3 text-left font-medium text-slate-600">Trigger</th>
                <th className="px-4 py-3 text-left font-medium text-slate-600">Nodes</th>
                <th className="px-4 py-3 text-left font-medium text-slate-600">Status</th>
                <th className="px-4 py-3 text-right font-medium text-slate-600">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {isLoading ? (
                <tr>
                  <td colSpan={5} className="px-4 py-10 text-center">
                    <Loader2 className="mx-auto h-5 w-5 animate-spin" style={{ color: indigo.muted }} />
                  </td>
                </tr>
              ) : filtered.length === 0 ? (
                <tr>
                  <td colSpan={5} className="px-4 py-10 text-center text-sm text-slate-500">
                    {flows.length === 0 ? 'No journeys yet — create your first one.' : 'No journeys match your search.'}
                  </td>
                </tr>
              ) : (
                filtered.map((flow) => (
                  <tr key={flow.id}>
                    <td className="px-4 py-3 font-medium text-slate-900">{flow.name}</td>
                    <td className="px-4 py-3">
                      <span className="inline-flex items-center rounded-full border border-slate-200 px-2.5 py-0.5 text-xs font-medium text-slate-600">
                        {TRIGGER_TYPE_LABELS[flow.trigger_type]}
                        {flow.trigger_value ? `: ${flow.trigger_value}` : ''}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-slate-600">{flow.graph_data?.nodes?.length ?? 0}</td>
                    <td className="px-4 py-3">
                      <button
                        onClick={() => onToggle(flow)}
                        disabled={busyId === flow.id}
                        className={`relative inline-flex h-5 w-9 items-center rounded-full transition disabled:opacity-60 ${
                          flow.is_active ? 'bg-emerald-500' : 'bg-slate-300'
                        }`}
                      >
                        <span
                          className={`inline-block h-3.5 w-3.5 transform rounded-full bg-white transition ${
                            flow.is_active ? 'translate-x-[18px]' : 'translate-x-1'
                          }`}
                        />
                      </button>
                    </td>
                    <td className="px-4 py-3 text-right">
                      <div className="flex justify-end gap-3">
                        <button onClick={() => onEdit(flow)} className="text-xs font-medium text-indigo-600 hover:text-indigo-700">
                          Edit
                        </button>
                        <button onClick={() => onTest(flow)} className="text-xs font-medium text-indigo-600 hover:text-indigo-700">
                          Test
                        </button>
                        <button
                          onClick={() => onDelete(flow)}
                          disabled={busyId === flow.id}
                          className="inline-flex items-center gap-1 text-xs font-medium text-red-600 hover:text-red-700 disabled:opacity-60"
                        >
                          <Trash2 className="h-3.5 w-3.5" />
                          Delete
                        </button>
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </TableCard>
      </div>
    </div>
  );
}

/** ---------- Test Trigger modal ---------- */

function TestTriggerModal({ flow, onClose }: { flow: WhatsAppFlow; onClose: () => void }) {
  const [phone, setPhone] = useState('');
  const [isSending, setIsSending] = useState(false);
  const [result, setResult] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const send = () => {
    if (!phone.trim()) {
      setError('Enter a phone number to test with.');
      return;
    }
    setError(null);
    setIsSending(true);
    journeyService
      .test(flow.id, phone.trim())
      .then((res) => setResult(res.message))
      .catch((err: unknown) => setError(extractErrorMessage(err, 'Failed to start the test.')))
      .finally(() => setIsSending(false));
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div className="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl">
        <div className="flex items-center justify-between">
          <h2 className="font-display text-base font-bold" style={{ color: indigo.ink }}>
            Test "{flow.name}"
          </h2>
          <button onClick={onClose} className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Close">
            <X className="h-5 w-5" />
          </button>
        </div>
        <div className="mt-3 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
          <AlertTriangle className="mt-0.5 h-3.5 w-3.5 flex-shrink-0" />
          This sends REAL WhatsApp messages to the number below and consumes your message quota — it is not a simulation.
        </div>
        <label className="mt-4 block text-sm font-medium text-slate-700">
          Phone Number
          <input
            type="text"
            className={inputClass}
            value={phone}
            onChange={(e) => setPhone(e.target.value)}
            placeholder="e.g. 919876543210"
            disabled={result !== null}
          />
        </label>
        {error && <p className="mt-2 text-xs font-medium text-red-600">{error}</p>}
        {result && <p className="mt-2 text-xs font-medium text-emerald-600">{result}</p>}
        {!result && (
          <button
            type="button"
            onClick={send}
            disabled={isSending}
            className="mt-4 flex w-full items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
            style={{ background: activeGradient }}
          >
            {isSending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
            {isSending ? 'Starting…' : 'Send Test'}
          </button>
        )}
      </div>
    </div>
  );
}

/** ---------- Canvas editor ---------- */

type Selection = { kind: 'node'; id: string } | { kind: 'edge'; id: string } | null;

interface NodeDragState {
  nodeId: string;
  startMouseX: number;
  startMouseY: number;
  startNodeX: number;
  startNodeY: number;
}

interface ConnectDragState {
  sourceId: string;
  /** Which declared branch this connection leaves from — see JourneyEdge.sourceHandle. */
  sourceHandle: string;
  mouseX: number;
  mouseY: number;
}

function JourneyCanvasEditor({
  initialFlow,
  onBack,
  onSaved,
}: {
  initialFlow: WhatsAppFlow | null;
  onBack: () => void;
  onSaved: () => void;
}) {
  const isNew = initialFlow === null;
  const [name, setName] = useState(initialFlow?.name ?? 'Untitled Journey');
  const [triggerType, setTriggerType] = useState<FlowTriggerType>(initialFlow?.trigger_type ?? 'keyword');
  const [triggerValue, setTriggerValue] = useState(initialFlow?.trigger_value ?? '');
  const [isActive, setIsActive] = useState(initialFlow?.is_active ?? true);
  const [graph, setGraph] = useState<JourneyGraph>(() => ensureEndConnections(initialFlow?.graph_data ?? newJourneyGraph()));
  /** Measured on-canvas height of each node, keyed by id — see the ref callback on the node render below. */
  const [nodeHeights, setNodeHeights] = useState<Record<string, number>>({});
  const nodeHeight = useCallback((id: string) => nodeHeights[id] ?? NODE_HEIGHT, [nodeHeights]);
  const [selection, setSelection] = useState<Selection>(null);
  const [isSaving, setIsSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  // Final hardening §23 — a palette node this account cannot use is still
  // clickable: the click explains why (and where to get it) instead of the
  // reason living only in a hover tooltip.
  const [paletteNotice, setPaletteNotice] = useState<{ reason: string; upgradable: boolean } | null>(null);
  const upgrade = useUpgradePath();
  const [testingFlow, setTestingFlow] = useState<WhatsAppFlow | null>(null);

  /*
    Phase 5 Task 7 — the two dimensions the palette greys out on, both
    read from data the app ALREADY has: the capability map /auth/me has
    returned since Phase 1, and the tenant's own current subscription.
    A Super Admin acting on a client sees that client's account; their
    own bypass matches the server's.
  */
  const { user, isSuperAdmin } = useAuth();
  const { selectedAccount } = useTenant();
  const entitlement = useMemo(
    () => ({
      capabilities: user?.capabilities,
      provider: (selectedAccount ?? user?.account)?.current_subscription?.engine_type ?? null,
      isSuperAdmin: isSuperAdmin(),
    }),
    [user, selectedAccount, isSuperAdmin],
  );

  const canvasRef = useRef<HTMLDivElement>(null);
  const nodeDragRef = useRef<NodeDragState | null>(null);
  const [connectDrag, setConnectDrag] = useState<ConnectDragState | null>(null);
  /** Field-level configuration errors per node id, from the last save attempt. */
  const [nodeErrors, setNodeErrors] = useState<Record<string, Record<string, string>>>({});

  const knownVariables = useMemo(
    () =>
      graph.nodes.flatMap((n) => {
        if (n.type === 'question' && n.data.variable_name) return [n.data.variable_name as string];
        // Phase 8 Task 7 — an AI node's reply is a variable too.
        if ((n.type === 'prompt' || n.type === 'agent' || n.type === 'rag') && typeof n.data.outputVariable === 'string' && n.data.outputVariable) {
          return [n.data.outputVariable];
        }
        return [];
      }),
    [graph.nodes],
  );

  const nodeById = useCallback((id: string) => graph.nodes.find((n) => n.id === id), [graph.nodes]);

  // Phase 8 Task 10 — the edited account's knowledge bases for the `rag` node's
  // selector, fetched once when a rag node is first opened (null = not loaded;
  // an account without AI access simply gets an empty list).
  const [knowledgeBases, setKnowledgeBases] = useState<KnowledgeBaseSummary[] | null>(null);
  const needsKnowledgeBases = graph.nodes.some((n) => n.type === 'rag');
  useEffect(() => {
    if (!needsKnowledgeBases || knowledgeBases !== null) return;
    let cancelled = false;
    knowledgeBaseService
      .list()
      .then((list) => !cancelled && setKnowledgeBases(list))
      .catch(() => !cancelled && setKnowledgeBases([]));
    return () => {
      cancelled = true;
    };
  }, [needsKnowledgeBases, knowledgeBases]);

  // Phase 8 Task 11 — the edited account's registered AI agents for the `agent`
  // node's selector, fetched once when an agent node exists (null = not loaded;
  // an account without AI access simply gets an empty list).
  const [aiAgents, setAiAgents] = useState<AiAgentSummary[] | null>(null);
  const needsAiAgents = graph.nodes.some((n) => n.type === 'agent');
  useEffect(() => {
    if (!needsAiAgents || aiAgents !== null) return;
    let cancelled = false;
    aiAgentService
      .list()
      .then((list) => !cancelled && setAiAgents(list))
      .catch(() => !cancelled && setAiAgents([]));
    return () => {
      cancelled = true;
    };
  }, [needsAiAgents, aiAgents]);

  const updateNodeData = (nodeId: string, patch: Record<string, unknown>) => {
    setGraph((g) => ({
      ...g,
      nodes: g.nodes.map((n) => (n.id === nodeId ? { ...n, data: { ...n.data, ...patch } } : n)),
    }));
  };

  const addNode = (type: JourneyNodeType) => {
    const index = graph.nodes.length;
    const position = { x: 400 + (index % 3) * 360, y: 40 + Math.floor(index / 3) * 260 };
    // Seeded from the registry so a freshly dropped node already holds a
    // valid-shaped config (delay: 1 minute, api: GET, code: javascript)
    // rather than an empty object the config form has to special-case.
    const node: JourneyNode = {
      id: genId(type),
      type,
      position,
      data: { ...(getJourneyNode(type)?.defaultConfig ?? {}) },
    };
    setGraph((g) => ({ ...g, nodes: [...g.nodes, node] }));
    setSelection({ kind: 'node', id: node.id });
  };

  const deleteNode = (nodeId: string) => {
    setGraph((g) => ({
      nodes: g.nodes.filter((n) => n.id !== nodeId),
      edges: g.edges.filter((e) => e.source !== nodeId && e.target !== nodeId),
    }));
    setSelection(null);
  };

  const deleteEdge = (edgeId: string) => {
    setGraph((g) => ({ ...g, edges: g.edges.filter((e) => e.id !== edgeId) }));
    setSelection(null);
  };

  const updateEdge = (edgeId: string, patch: Partial<JourneyEdge>) => {
    setGraph((g) => ({ ...g, edges: g.edges.map((e) => (e.id === edgeId ? { ...e, ...patch } : e)) }));
  };

  /**
   * Node dragging (reposition) and connection dragging (draw a new edge
   * from a node's handle) both attach window-level mousemove/mouseup
   * listeners for the duration of the drag. Each pair's own "up" handler
   * needs to remove ITSELF from 'mouseup' once the drag ends — rather
   * than a mouseup handler naming its own useCallback const directly in
   * its body (a self-referencing closure that is safe at runtime — the
   * reference isn't read until a real mouseup event fires, long after
   * the const is assigned — but trips oxlint's react(immutability)
   * "read during its own initialization" check, since the linter's
   * static analysis can't see that the read is deferred), each pair's
   * live function references are looked up from a ref
   * (activeListenersRef) that is populated in the mousedown handler,
   * AFTER both useCallback consts already exist. This sidesteps the
   * lint warning entirely while keeping the exact same runtime
   * behavior: add/remove always pair up correctly because every
   * useCallback below has an EMPTY dependency array (each reads only
   * refs and uses the functional `setState(prev => ...)` form, both
   * stable across renders), so their identities never change.
   */
  const canvasRelativePoint = useCallback((clientX: number, clientY: number) => {
    const rect = canvasRef.current?.getBoundingClientRect();
    if (!rect) return { x: 0, y: 0 };
    return { x: clientX - rect.left, y: clientY - rect.top };
  }, []);

  const activeDragListenersRef = useRef<{ move: (e: MouseEvent) => void; up: (e: MouseEvent) => void } | null>(null);
  const activeConnectListenersRef = useRef<{ move: (e: MouseEvent) => void; up: (e: MouseEvent) => void } | null>(null);

  const onWindowMouseMoveForDrag = useCallback((e: MouseEvent) => {
    const drag = nodeDragRef.current;
    if (!drag) return;
    const dx = e.clientX - drag.startMouseX;
    const dy = e.clientY - drag.startMouseY;
    setGraph((g) => ({
      ...g,
      nodes: g.nodes.map((n) =>
        n.id === drag.nodeId
          ? { ...n, position: { x: Math.max(0, drag.startNodeX + dx), y: Math.max(0, drag.startNodeY + dy) } }
          : n,
      ),
    }));
  }, []);

  const onWindowMouseUpForDrag = useCallback(() => {
    nodeDragRef.current = null;
    const listeners = activeDragListenersRef.current;
    if (listeners) {
      window.removeEventListener('mousemove', listeners.move);
      window.removeEventListener('mouseup', listeners.up);
    }
    activeDragListenersRef.current = null;
  }, []);

  const onNodeMouseDown = (e: ReactMouseEvent, node: JourneyNode) => {
    if ((e.target as HTMLElement).dataset.handle) return; // handled by handle's own mousedown
    e.stopPropagation();
    setSelection({ kind: 'node', id: node.id });
    nodeDragRef.current = {
      nodeId: node.id,
      startMouseX: e.clientX,
      startMouseY: e.clientY,
      startNodeX: node.position.x,
      startNodeY: node.position.y,
    };
    activeDragListenersRef.current = { move: onWindowMouseMoveForDrag, up: onWindowMouseUpForDrag };
    window.addEventListener('mousemove', onWindowMouseMoveForDrag);
    window.addEventListener('mouseup', onWindowMouseUpForDrag);
  };

  const onWindowMouseMoveForConnect = useCallback(
    (e: MouseEvent) => {
      setConnectDrag((prev) => {
        if (!prev) return prev;
        const p = canvasRelativePoint(e.clientX, e.clientY);
        return { ...prev, mouseX: p.x, mouseY: p.y };
      });
    },
    [canvasRelativePoint],
  );

  const onWindowMouseUpForConnect = useCallback(
    (e: MouseEvent) => {
      const listeners = activeConnectListenersRef.current;
      if (listeners) {
        window.removeEventListener('mousemove', listeners.move);
        window.removeEventListener('mouseup', listeners.up);
      }
      activeConnectListenersRef.current = null;

      setConnectDrag((prev) => {
        if (!prev) return null;
        const p = canvasRelativePoint(e.clientX, e.clientY);

        setGraph((g) => {
          const target = g.nodes.find(
            (n) =>
              n.id !== prev.sourceId &&
              p.x >= n.position.x &&
              p.x <= n.position.x + NODE_WIDTH &&
              p.y >= n.position.y &&
              p.y <= n.position.y + nodeHeight(n.id),
          );

          if (!target) return g;

          const sourceNode = g.nodes.find((n) => n.id === prev.sourceId);
          const sourceIsLegacyCondition = sourceNode?.type === 'condition';
          const branches = getJourneyNode(sourceNode?.type ?? '')?.sourceHandles.length ?? 1;

          // A node with ONE outgoing branch has exactly one edge -- replace
          // it. A branching node keeps one edge per branch, so replace only
          // the edge already leaving THIS handle, never the sibling branch.
          // 'condition' is the legacy shape: its branches live on the edge
          // (edge.condition / edge.is_default) rather than on a handle, so
          // it keeps appending exactly as before.
          const edges = sourceIsLegacyCondition
            ? g.edges
            : branches > 1
              ? g.edges.filter((e2) => !(e2.source === prev.sourceId && (e2.sourceHandle ?? 'next') === prev.sourceHandle))
              : g.edges.filter((e2) => e2.source !== prev.sourceId);

          const newEdge: JourneyEdge = {
            id: genId('edge'),
            source: prev.sourceId,
            target: target.id,
            // Branch identity is explicit and persisted. Nothing downstream
            // has to infer it from where a box sits on the canvas.
            ...(sourceIsLegacyCondition ? {} : { sourceHandle: prev.sourceHandle }),
          };
          setSelection({ kind: 'edge', id: newEdge.id });

          return { ...g, edges: [...edges, newEdge] };
        });

        return null;
      });
    },
    [canvasRelativePoint, nodeHeight],
  );

  const onHandleMouseDown = (e: ReactMouseEvent, sourceId: string, sourceHandle = 'next') => {
    e.stopPropagation();
    const p = canvasRelativePoint(e.clientX, e.clientY);
    setConnectDrag({ sourceId, sourceHandle, mouseX: p.x, mouseY: p.y });
    activeConnectListenersRef.current = { move: onWindowMouseMoveForConnect, up: onWindowMouseUpForConnect };
    window.addEventListener('mousemove', onWindowMouseMoveForConnect);
    window.addEventListener('mouseup', onWindowMouseUpForConnect);
  };

  const handleSave = (publish = true) => {
    if (!name.trim()) {
      setSaveError('Give this journey a name.');
      return;
    }

    // 'end' nodes are canvas-only (see stripEndNodes's docblock) — every
    // check below, and the payload itself, works off the graph with them
    // (and any edge into one) already removed.
    const backendGraph = stripEndNodes(graph);

    const hasTrigger = backendGraph.nodes.some((n) => n.type === 'trigger');
    if (!hasTrigger) {
      setSaveError('This journey has no Start node.');
      return;
    }

    // Phase 5 -- field-level configuration validation, from the registry.
    // Deliberately NOT the HTML `required` attribute: this form is
    // submitted through application code, and a native constraint bubble
    // would block it before these messages could render -- the exact trap
    // the Template Manager hit in Phase 4. The backend revalidates
    // independently; this is UX, not authorization.
    const graphErrors = validateJourneyGraph(
      backendGraph.nodes.map((n) => ({ id: n.id, type: n.type, data: n.data as Record<string, unknown> })),
      backendGraph.edges,
    );
    setNodeErrors(graphErrors);

    const firstBadNodeId = Object.keys(graphErrors)[0];

    if (firstBadNodeId) {
      const node = backendGraph.nodes.find((n) => n.id === firstBadNodeId);
      const label = node ? (getJourneyNode(node.type)?.label ?? node.type) : firstBadNodeId;
      const message = Object.values(graphErrors[firstBadNodeId])[0];

      setSaveError(`${label}: ${message}`);
      setSelection({ kind: 'node', id: firstBadNodeId });

      return;
    }

    // P5-7 — a journey is published only when the runtime can run every
    // node. UX mirror of the server's 422 JOURNEY_NOT_PUBLISHABLE (the
    // server decides): say which nodes block it and offer a draft save.
    const blocking = nonExecutableNodeTypes(backendGraph.nodes);

    if (publish && blocking.length > 0) {
      const labels = blocking.map((type) => getJourneyNode(type)?.label ?? type).join(', ');

      setSaveError(`${labels} cannot run yet, so this journey cannot be published. Remove ${blocking.length === 1 ? 'it' : 'them'}, or use Save draft.`);

      return;
    }

    const payload: SaveFlowPayload = {
      name: name.trim(),
      trigger_type: triggerType,
      trigger_value: triggerValue.trim() || null,
      graph_data: backendGraph,
      is_active: isActive,
      ...(publish ? {} : { publish: false }),
    };

    setSaveError(null);
    setIsSaving(true);

    const request = initialFlow ? journeyService.update(initialFlow.id, payload) : journeyService.create(payload);

    request
      .then(() => onSaved())
      .catch((err: unknown) => setSaveError(extractErrorMessage(err, 'Failed to save this journey.')))
      .finally(() => setIsSaving(false));
  };

  const selectedNode = selection?.kind === 'node' ? nodeById(selection.id) : null;
  const selectedEdge = selection?.kind === 'edge' ? graph.edges.find((e) => e.id === selection.id) : null;
  const selectedEdgeSourceNode = selectedEdge ? nodeById(selectedEdge.source) : null;

  return (
    <div className="flex h-[calc(100vh-0px)] flex-col p-6">
      {testingFlow && <TestTriggerModal flow={testingFlow} onClose={() => setTestingFlow(null)} />}

      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <button onClick={onBack} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100" aria-label="Back">
            <ArrowLeft className="h-4 w-4" />
          </button>
          <input
            type="text"
            value={name}
            onChange={(e) => setName(e.target.value)}
            className="rounded-lg border border-transparent bg-transparent px-2 py-1 font-display text-base font-bold hover:border-slate-200 focus:border-indigo-300 focus:outline-none"
            style={{ color: indigo.ink }}
          />
        </div>
        <div className="flex items-center gap-2">
          {!isNew && (
            <button
              onClick={() => setTestingFlow(initialFlow)}
              className="flex items-center gap-1.5 rounded-lg border px-3 py-2 text-xs font-semibold"
              style={{ borderColor: indigo.border, color: indigo.ink }}
            >
              <Send className="h-3.5 w-3.5" />
              Test Trigger
            </button>
          )}
          <label className="flex items-center gap-1.5 text-xs font-medium" style={{ color: indigo.muted }}>
            <input type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} className="h-4 w-4 rounded border-slate-300 text-indigo-600" />
            Active
          </label>
          {/* P5-7 — a draft may hold nodes the runtime cannot execute yet; it is never what new sessions run. */}
          <button
            onClick={() => handleSave(false)}
            disabled={isSaving}
            data-testid="journey-save-draft"
            className="rounded-lg border px-3 py-2 text-xs font-semibold disabled:opacity-60"
            style={{ borderColor: indigo.border, color: indigo.ink }}
          >
            Save draft
          </button>
          <button
            onClick={() => handleSave()}
            disabled={isSaving}
            className="flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
            style={{ background: activeGradient }}
          >
            {isSaving ? <Loader2 className="h-4 w-4 animate-spin" /> : null}
            {isSaving ? 'Saving…' : 'Save Journey'}
          </button>
        </div>
      </div>

      {saveError && (
        <DismissibleAlert className="mb-3 flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          <XCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
          {saveError}
        </DismissibleAlert>
      )}

      <p className="mb-2 text-[11px]" style={{ color: indigo.muted }}>
        Drag a node's right-edge dot onto another node to connect them. Click a node or connection to edit it.
      </p>

      {paletteNotice && (
        <div className="flex flex-wrap items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800" role="status" data-testid="palette-notice">
          <Lock className="h-3.5 w-3.5 flex-shrink-0" />
          <span>{paletteNotice.reason}</span>
          {paletteNotice.upgradable && <span>{upgrade.hint}</span>}
          {paletteNotice.upgradable && upgrade.action && (
            <Link to={upgrade.action.to} className="font-semibold underline">
              {upgrade.action.label}
            </Link>
          )}
          <button type="button" onClick={() => setPaletteNotice(null)} className="ml-auto text-amber-700 hover:text-amber-900" aria-label="Dismiss">
            ×
          </button>
        </div>
      )}

      <div className="flex min-h-0 flex-1 gap-4">
        {/*
          Collapsed icon rail + "Nodes" panel (search, grid/list view),
          replacing the old always-expanded horizontal category bar — the
          registry is still the only source of truth for what's listed.
        */}
        <NodePalette
          entitlement={entitlement}
          onAdd={(type) => {
            setPaletteNotice(null);
            addNode(type);
          }}
          onBlocked={(reason, upgradable) => setPaletteNotice({ reason, upgradable })}
        />

        <div
          ref={canvasRef}
          onMouseDown={() => setSelection(null)}
          className="relative flex-1 overflow-auto rounded-xl border bg-slate-50"
          style={{ borderColor: indigo.border }}
        >
          <div className="relative" style={{ width: CANVAS_WIDTH, height: CANVAS_HEIGHT }}>
            <svg width={CANVAS_WIDTH} height={CANVAS_HEIGHT} className="absolute left-0 top-0" style={{ pointerEvents: 'none' }}>
              <defs>
                <marker id="journey-arrow" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto">
                  <path d="M0,0 L8,4 L0,8 Z" fill={indigo.accentSolid} />
                </marker>
              </defs>
              {graph.edges.map((edge) => {
                const source = nodeById(edge.source);
                const target = nodeById(edge.target);
                if (!source || !target) return null;
                const x1 = source.position.x + NODE_WIDTH;
                const y1 = source.position.y + nodeHeight(source.id) / 2;
                const x2 = target.position.x;
                const y2 = target.position.y + nodeHeight(target.id) / 2;
                const d = orthogonalEdgePath(x1, y1, x2, y2);
                const midX = (x1 + x2) / 2;
                const isSelected = selection?.kind === 'edge' && selection.id === edge.id;
                const label = edge.is_default ? 'else' : edge.condition ? `${edge.condition.operator} "${edge.condition.value ?? ''}"` : null;

                return (
                  <g key={edge.id}>
                    <path
                      d={d}
                      fill="none"
                      stroke="transparent"
                      strokeWidth={14}
                      style={{ pointerEvents: 'stroke', cursor: 'pointer' }}
                      onClick={(e) => {
                        e.stopPropagation();
                        setSelection({ kind: 'edge', id: edge.id });
                      }}
                    />
                    <path
                      d={d}
                      fill="none"
                      stroke={isSelected ? indigo.accentSolid : '#94a3b8'}
                      strokeWidth={isSelected ? 2.5 : 1.5}
                      markerEnd="url(#journey-arrow)"
                      style={{ pointerEvents: 'none' }}
                    />
                    {label && (
                      <text x={midX} y={(y1 + y2) / 2 - 6} fontSize="10" textAnchor="middle" fill={indigo.muted} style={{ pointerEvents: 'none' }}>
                        {label}
                      </text>
                    )}
                  </g>
                );
              })}
              {connectDrag &&
                (() => {
                  const source = nodeById(connectDrag.sourceId);
                  if (!source) return null;
                  const x1 = source.position.x + NODE_WIDTH;
                  const y1 = source.position.y + nodeHeight(source.id) / 2;
                  return (
                    <path
                      d={`M ${x1} ${y1} L ${connectDrag.mouseX} ${connectDrag.mouseY}`}
                      fill="none"
                      stroke={indigo.accentSolid}
                      strokeWidth={2}
                      strokeDasharray="4 3"
                    />
                  );
                })()}
            </svg>

            {graph.nodes.map((node) => {
              const meta = nodeMeta(node.type);
              const Icon = meta.icon;
              const definition = getJourneyNode(node.type);
              const isSelected = selection?.kind === 'node' && selection.id === node.id;

              return (
                <div
                  key={node.id}
                  ref={(el) => {
                    if (!el) return;
                    const h = el.offsetHeight;
                    setNodeHeights((prev) => (prev[node.id] === h ? prev : { ...prev, [node.id]: h }));
                  }}
                  data-testid={`canvas-node-${node.id}`}
                  data-node-type={node.type}
                  // Dragging/selecting the card itself (anywhere outside the
                  // inline fields below, which stop this from bubbling up)
                  // works exactly as it did when the card was a fixed-size
                  // chip — selectCanvasNode() in the test suite still
                  // targets this element directly.
                  onMouseDown={(e) => onNodeMouseDown(e, node)}
                  className="absolute cursor-move select-none rounded-xl border bg-white shadow-sm"
                  style={{
                    left: node.position.x,
                    top: node.position.y,
                    width: NODE_WIDTH,
                    borderColor: isSelected ? indigo.accentSolid : indigo.border,
                    borderWidth: isSelected ? 2 : 1,
                  }}
                >
                  <div
                    className="flex items-center gap-1.5 rounded-t-xl px-2.5 py-1.5"
                    style={{ background: meta.bg }}
                  >
                    <Icon className="h-3.5 w-3.5 flex-shrink-0" style={{ color: meta.color }} />
                    <span className="text-xs font-semibold" style={{ color: meta.color }}>
                      {nodeLabel(node.type)}
                    </span>
                    {/* Start and End anchor the journey — never deletable from the canvas. */}
                    {node.type !== 'trigger' && node.type !== 'end' && (
                      <button
                        onMouseDown={(e) => e.stopPropagation()}
                        onClick={(e) => {
                          e.stopPropagation();
                          deleteNode(node.id);
                        }}
                        className="ml-auto text-slate-400 hover:text-red-600"
                        aria-label="Delete node"
                      >
                        <X className="h-3.5 w-3.5" />
                      </button>
                    )}
                  </div>

                  {/*
                    Inline, on-card configuration — selecting/clicking here
                    never starts a drag (no onNodeMouseDown attached), it
                    just keeps this node selected while its fields are
                    being edited.
                  */}
                  <div
                    onMouseDown={(e) => {
                      e.stopPropagation();
                      setSelection({ kind: 'node', id: node.id });
                    }}
                    className="px-2.5 py-2"
                  >
                    <NodeInlineFields
                      node={node}
                      errors={nodeErrors[node.id]}
                      knownVariables={knownVariables}
                      knowledgeBases={knowledgeBases}
                      aiAgents={aiAgents}
                      triggerType={triggerType}
                      triggerValue={triggerValue}
                      onTriggerTypeChange={setTriggerType}
                      onTriggerValueChange={setTriggerValue}
                      onChange={(patch) => updateNodeData(node.id, patch)}
                    />
                  </div>
                  {nodeErrors[node.id] && (
                    <p
                      data-testid={`node-error-${node.id}`}
                      className="px-2.5 pb-2 text-[11px] font-medium text-red-600"
                    >
                      {Object.values(nodeErrors[node.id])[0]}
                    </p>
                  )}

                  {/*
                    One connection point per declared source handle. A
                    branching node (conditional: TRUE / FALSE) therefore
                    shows a labelled dot per branch, and the branch a
                    connection belongs to is the handle id recorded on the
                    edge — never which dot happens to sit higher.
                  */}
                  {node.type !== 'save_lead' &&
                    (definition?.sourceHandles ?? [{ id: 'next', label: 'Next' }]).map((handle, handleIndex, handles) => (
                      <div
                        key={handle.id}
                        data-handle="true"
                        data-source-handle={handle.id}
                        onMouseDown={(e) => onHandleMouseDown(e, node.id, handle.id)}
                        className="absolute -right-1.5 flex items-center gap-1 whitespace-nowrap"
                        style={{ top: `${((handleIndex + 1) / (handles.length + 1)) * 100}%`, transform: 'translateY(-50%)' }}
                        title={`Drag to connect (${handle.label})`}
                      >
                        {handles.length > 1 && (
                          <span className="text-[9px] font-bold uppercase tracking-wide" style={{ color: meta.color }}>
                            {handle.label}
                          </span>
                        )}
                        <span
                          className="h-3.5 w-3.5 cursor-crosshair rounded-full border-2 border-white"
                          style={{ background: meta.color }}
                        />
                      </div>
                    ))}
                </div>
              );
            })}
          </div>
        </div>

        <div className="w-72 flex-shrink-0 overflow-y-auto rounded-xl border p-4" style={{ borderColor: indigo.border }}>
          {selectedEdge && (
            <EdgeEditorPanel
              edge={selectedEdge}
              isFromCondition={selectedEdgeSourceNode?.type === 'condition'}
              onChange={(patch) => updateEdge(selectedEdge.id, patch)}
              onDelete={() => deleteEdge(selectedEdge.id)}
            />
          )}
          {!selectedEdge && (
            <p className="text-xs" style={{ color: indigo.muted }}>
              {selectedNode
                ? 'Edit this node directly on its card. Click a connection line here to set its branch condition.'
                : 'Click a node to edit its settings on the card, or a connection line to edit it here.'}
            </p>
          )}
        </div>
      </div>
    </div>
  );
}

/** ---------- Node data editor (now rendered inline, on the card) ---------- */

/** The only five types with a bespoke inline editor; everything else is schema-driven. */
const LEGACY_PANEL_TYPES: JourneyLegacyEngineNodeType[] = [
  'trigger',
  'message',
  'question',
  'condition',
  'save_lead',
];

/** Compact variant of `inputClass` for use inside a canvas card, where every pixel of width is shared with the next field. */
const inlineInputClass = `${inputClass} !mt-1`;

/**
 * Start (type: 'trigger') node's inline "Select Event" + "User Input
 * Config" — mirrors the reference builder. Maps directly onto the
 * existing page-level trigger_type / trigger_value fields (no new data
 * shape): "No Event" is trigger_type 'default' (catch-all), "User Input"
 * is 'keyword' (the journey starts when the first message matches one of
 * the given keywords). trigger_type 'ctwa_referral' (ad-click entry) is
 * not reachable from this simplified selector but is preserved untouched
 * on a flow that already has it set — nothing here rewrites it unless the
 * operator explicitly changes the dropdown.
 */
function StartNodeFields({
  triggerType,
  triggerValue,
  onTriggerTypeChange,
  onTriggerValueChange,
}: {
  triggerType: FlowTriggerType;
  triggerValue: string;
  onTriggerTypeChange: (t: FlowTriggerType) => void;
  onTriggerValueChange: (v: string) => void;
}) {
  const [configOpen, setConfigOpen] = useState(triggerType !== 'default');
  const hasEvent = triggerType !== 'default';

  return (
    <div className="space-y-2">
      <label className="block text-xs font-medium text-slate-700">
        Select Event
        <select
          className={inlineInputClass}
          value={hasEvent ? 'user_input' : 'no_event'}
          onChange={(e) => {
            if (e.target.value === 'no_event') {
              onTriggerTypeChange('default');
              setConfigOpen(false);
            } else {
              onTriggerTypeChange(triggerType === 'ctwa_referral' ? 'ctwa_referral' : 'keyword');
              setConfigOpen(true);
            }
          }}
        >
          <option value="no_event">No Event</option>
          <option value="user_input">User Input</option>
        </select>
      </label>

      {!hasEvent ? (
        <span className="inline-block rounded bg-slate-100 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide text-slate-500">
          Default
        </span>
      ) : (
        <div className="rounded-lg border p-2" style={{ borderColor: indigo.border }}>
          <button
            type="button"
            onMouseDown={(e) => e.stopPropagation()}
            onClick={() => setConfigOpen((v) => !v)}
            className="flex w-full items-center justify-between text-xs font-semibold"
            style={{ color: indigo.ink }}
          >
            User Input Config
            <span>{configOpen ? '−' : '+'}</span>
          </button>
          {configOpen && (
            <label className="mt-2 block text-xs font-medium text-slate-700">
              {triggerType === 'ctwa_referral' ? 'Meta Ad ID (optional — blank matches any ad click)' : 'Starting message (comma-separated)'}
              <input
                type="text"
                className={inlineInputClass}
                value={triggerValue}
                onChange={(e) => onTriggerValueChange(e.target.value)}
                placeholder={triggerType === 'ctwa_referral' ? 'e.g. 120210000000000' : 'hi, start, menu'}
              />
              <span className="mt-1 block text-[11px]" style={{ color: indigo.muted }}>
                The journey starts when the user's first message matches one of these. Leave blank to match any message.
              </span>
            </label>
          )}
        </div>
      )}
    </div>
  );
}

function MessageFields({ node, onChange }: { node: JourneyNode; onChange: (patch: Record<string, unknown>) => void }) {
  return (
    <label className="block text-xs font-medium text-slate-700">
      Message
      <textarea className={inlineInputClass} rows={4} value={node.data.text ?? ''} onChange={(e) => onChange({ text: e.target.value })} />
    </label>
  );
}

function QuestionFields({
  node,
  knownVariables,
  onChange,
}: {
  node: JourneyNode;
  knownVariables: string[];
  onChange: (patch: Record<string, unknown>) => void;
}) {
  const inputType: QuestionInputType = node.data.input_type ?? 'text';
  const options: JourneyNodeOption[] = node.data.options ?? [];
  const validationType: QuestionValidationType = node.data.validation?.type ?? 'none';

  const updateOption = (i: number, patch: Partial<JourneyNodeOption>) => {
    const next = options.map((o, idx) => (idx === i ? { ...o, ...patch } : o));
    onChange({ options: next });
  };
  const addOption = () => onChange({ options: [...options, { id: genId('opt'), title: '' }] });
  const removeOption = (i: number) => onChange({ options: options.filter((_, idx) => idx !== i) });

  return (
    <div className="space-y-2">
      <label className="block text-xs font-medium text-slate-700">
        Prompt
        <textarea className={inlineInputClass} rows={3} value={node.data.prompt_text ?? ''} onChange={(e) => onChange({ prompt_text: e.target.value })} />
      </label>
      <label className="block text-xs font-medium text-slate-700">
        Save Answer As
        <input
          type="text"
          className={inlineInputClass}
          value={node.data.variable_name ?? ''}
          onChange={(e) => onChange({ variable_name: e.target.value.trim().replace(/\s+/g, '_') })}
          placeholder="e.g. user_name"
        />
      </label>
      <label className="block text-xs font-medium text-slate-700">
        Answer Type
        <select className={inlineInputClass} value={inputType} onChange={(e) => onChange({ input_type: e.target.value as QuestionInputType })}>
          <option value="text">Free text</option>
          <option value="buttons">Buttons (max 3)</option>
          <option value="list">List</option>
        </select>
      </label>

      {inputType !== 'text' && (
        <div>
          {inputType === 'list' && (
            <label className="mb-1.5 block text-xs font-medium text-slate-700">
              Menu Button Label
              <input type="text" className={inlineInputClass} value={node.data.button_text ?? ''} onChange={(e) => onChange({ button_text: e.target.value })} placeholder="Menu" />
            </label>
          )}
          <p className="mb-1 text-xs font-medium text-slate-700">Options</p>
          <div className="space-y-1.5">
            {options.map((opt, i) => (
              <div key={opt.id} className="flex items-center gap-1.5">
                <input
                  type="text"
                  className={`${inputClass} !mt-0`}
                  value={opt.title}
                  onChange={(e) => updateOption(i, { title: e.target.value })}
                  placeholder={`Option ${i + 1}`}
                />
                <button onMouseDown={(e) => e.stopPropagation()} onClick={() => removeOption(i)} className="flex-shrink-0 text-slate-400 hover:text-red-600" aria-label="Remove option">
                  <X className="h-4 w-4" />
                </button>
              </div>
            ))}
            {(inputType !== 'buttons' || options.length < 3) && (
              <button onMouseDown={(e) => e.stopPropagation()} onClick={addOption} className="text-xs font-semibold" style={{ color: indigo.accentSolid }}>
                + Add option
              </button>
            )}
          </div>
        </div>
      )}

      {inputType === 'text' && (
        <>
          <label className="block text-xs font-medium text-slate-700">
            Validate As
            <select
              className={inlineInputClass}
              value={validationType}
              onChange={(e) => onChange({ validation: { ...node.data.validation, type: e.target.value as QuestionValidationType } })}
            >
              {VALIDATION_TYPE_OPTIONS.map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </select>
          </label>
          {validationType !== 'none' && (
            <label className="block text-xs font-medium text-slate-700">
              Error Message
              <input
                type="text"
                className={inlineInputClass}
                value={node.data.validation?.error_message ?? ''}
                onChange={(e) => onChange({ validation: { ...node.data.validation, type: validationType, error_message: e.target.value } })}
                placeholder="Please enter a valid value."
              />
            </label>
          )}
        </>
      )}

      {knownVariables.length > 0 && (
        <p className="text-[11px]" style={{ color: indigo.muted }}>
          Variables so far: {knownVariables.map((v) => `{{${v}}}`).join(', ')}
        </p>
      )}
    </div>
  );
}

function ConditionFields({
  node,
  knownVariables,
  onChange,
}: {
  node: JourneyNode;
  knownVariables: string[];
  onChange: (patch: Record<string, unknown>) => void;
}) {
  return (
    <div className="space-y-2">
      <label className="block text-xs font-medium text-slate-700">
        Check Variable
        <select className={inlineInputClass} value={node.data.variable ?? ''} onChange={(e) => onChange({ variable: e.target.value })}>
          <option value="">— select —</option>
          {knownVariables.map((v) => (
            <option key={v} value={v}>
              {v}
            </option>
          ))}
        </select>
      </label>
      <p className="text-[11px]" style={{ color: indigo.muted }}>
        Drag this node's dot to each next step, then click that connection to set its branch (or mark it "else"). For an
        IF/ELSE with a Manage Conditions screen, use the Conditional node instead.
      </p>
    </div>
  );
}

function SaveLeadFields({
  node,
  knownVariables,
  onChange,
}: {
  node: JourneyNode;
  knownVariables: string[];
  onChange: (patch: Record<string, unknown>) => void;
}) {
  const selectOptions = (
    <>
      <option value="">— none —</option>
      {knownVariables.map((v) => (
        <option key={v} value={v}>
          {v}
        </option>
      ))}
    </>
  );

  return (
    <div className="space-y-2">
      <label className="block text-xs font-medium text-slate-700">
        Name Variable
        <select className={inlineInputClass} value={node.data.name_variable ?? ''} onChange={(e) => onChange({ name_variable: e.target.value || undefined })}>
          {selectOptions}
        </select>
      </label>
      <label className="block text-xs font-medium text-slate-700">
        Email Variable
        <select className={inlineInputClass} value={node.data.email_variable ?? ''} onChange={(e) => onChange({ email_variable: e.target.value || undefined })}>
          {selectOptions}
        </select>
      </label>
      <label className="block text-xs font-medium text-slate-700">
        Phone Variable (defaults to the sender's number)
        <select className={inlineInputClass} value={node.data.phone_variable ?? ''} onChange={(e) => onChange({ phone_variable: e.target.value || undefined })}>
          {selectOptions}
        </select>
      </label>
      <label className="block text-xs font-medium text-slate-700">
        Completion Message (optional)
        <textarea className={inlineInputClass} rows={2} value={node.data.completion_message ?? ''} onChange={(e) => onChange({ completion_message: e.target.value })} />
      </label>
    </div>
  );
}

/**
 * Dispatches a node to its inline, on-card editor. Every registered type
 * — all 27 palette nodes plus 'end' — is rendered from its registry
 * `configSchema` by JourneyNodeConfigForm (`hideHeader`, since the card's
 * own header strip already shows the icon and label). The five legacy
 * engine-executed types (byte-for-byte behaviour WhatsAppJourneyEngine
 * depends on) and the Start node's event selector keep their own
 * hand-written bodies, unchanged from the former side-panel version.
 */
function NodeInlineFields({
  node,
  errors,
  knownVariables,
  knowledgeBases,
  aiAgents,
  triggerType,
  triggerValue,
  onTriggerTypeChange,
  onTriggerValueChange,
  onChange,
}: {
  node: JourneyNode;
  errors?: Record<string, string>;
  knownVariables: string[];
  knowledgeBases: KnowledgeBaseSummary[] | null;
  aiAgents: AiAgentSummary[] | null;
  triggerType: FlowTriggerType;
  triggerValue: string;
  onTriggerTypeChange: (t: FlowTriggerType) => void;
  onTriggerValueChange: (v: string) => void;
  onChange: (patch: Record<string, unknown>) => void;
}) {
  if (!LEGACY_PANEL_TYPES.includes(node.type as JourneyLegacyEngineNodeType)) {
    return (
      <JourneyNodeConfigForm
        nodeType={node.type}
        config={node.data as Record<string, unknown>}
        errors={errors}
        knownVariables={knownVariables}
        knowledgeBases={knowledgeBases}
        aiAgents={aiAgents}
        onChange={onChange}
        hideHeader
      />
    );
  }

  if (node.type === 'trigger') {
    return (
      <StartNodeFields
        triggerType={triggerType}
        triggerValue={triggerValue}
        onTriggerTypeChange={onTriggerTypeChange}
        onTriggerValueChange={onTriggerValueChange}
      />
    );
  }

  if (node.type === 'message') return <MessageFields node={node} onChange={onChange} />;
  if (node.type === 'question') return <QuestionFields node={node} knownVariables={knownVariables} onChange={onChange} />;
  if (node.type === 'condition') return <ConditionFields node={node} knownVariables={knownVariables} onChange={onChange} />;

  // save_lead
  return <SaveLeadFields node={node} knownVariables={knownVariables} onChange={onChange} />;
}

function EdgeEditorPanel({
  edge,
  isFromCondition,
  onChange,
  onDelete,
}: {
  edge: JourneyEdge;
  isFromCondition: boolean;
  onChange: (patch: Partial<JourneyEdge>) => void;
  onDelete: () => void;
}) {
  return (
    <div className="space-y-3">
      <h3 className="text-sm font-semibold" style={{ color: indigo.ink }}>
        Connection
      </h3>
      {isFromCondition && (
        <>
          <label className="flex items-center gap-1.5 text-xs font-medium text-slate-700">
            <input
              type="checkbox"
              checked={!!edge.is_default}
              onChange={(e) => onChange({ is_default: e.target.checked, condition: e.target.checked ? undefined : edge.condition })}
              className="h-4 w-4 rounded border-slate-300 text-indigo-600"
            />
            This is the "else" (default) branch
          </label>
          {!edge.is_default && (
            <>
              <label className="block text-xs font-medium text-slate-700">
                Operator
                <select
                  className={inputClass}
                  value={edge.condition?.operator ?? 'equals'}
                  onChange={(e) => onChange({ condition: { operator: e.target.value as ConditionOperator, value: edge.condition?.value } })}
                >
                  {CONDITION_OPERATOR_OPTIONS.map((opt) => (
                    <option key={opt.value} value={opt.value}>
                      {opt.label}
                    </option>
                  ))}
                </select>
              </label>
              {edge.condition?.operator !== 'exists' && (
                <label className="block text-xs font-medium text-slate-700">
                  Value
                  <input
                    type="text"
                    className={inputClass}
                    value={edge.condition?.value ?? ''}
                    onChange={(e) => onChange({ condition: { operator: edge.condition?.operator ?? 'equals', value: e.target.value } })}
                  />
                </label>
              )}
            </>
          )}
        </>
      )}
      <button onClick={onDelete} className="flex items-center gap-1.5 text-xs font-medium text-red-600 hover:text-red-700">
        <Trash2 className="h-3.5 w-3.5" />
        Delete connection
      </button>
    </div>
  );
}

/** ---------- Top-level page: list <-> editor ---------- */

export default function JourneyBuilderPage() {
  const { selectedAccountId } = useTenant();
  const [flows, setFlows] = useState<WhatsAppFlow[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [pageError, setPageError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [editingFlow, setEditingFlow] = useState<WhatsAppFlow | 'new' | null>(null);
  const [testingFlow, setTestingFlow] = useState<WhatsAppFlow | null>(null);

  const loadFlows = useCallback(() => {
    setIsLoading(true);
    setPageError(null);
    journeyService
      .list()
      .then(setFlows)
      .catch((err: unknown) => setPageError(extractErrorMessage(err, 'Failed to load journeys.')))
      .finally(() => setIsLoading(false));
  }, []);

  useEffect(() => {
    loadFlows();
  }, [loadFlows, selectedAccountId]);

  const [pendingDelete, setPendingDelete] = useState<WhatsAppFlow | null>(null);

  const handleDelete = (flow: WhatsAppFlow) => {
    setPendingDelete(flow);
  };

  const confirmDelete = () => {
    if (!pendingDelete) return;
    const flow = pendingDelete;
    setBusyId(flow.id);
    journeyService
      .remove(flow.id)
      .then(() => {
        setPendingDelete(null);
        loadFlows();
      })
      .catch((err: unknown) => setPageError(extractErrorMessage(err, 'Failed to delete this journey.')))
      .finally(() => setBusyId(null));
  };

  const handleToggle = (flow: WhatsAppFlow) => {
    setBusyId(flow.id);
    journeyService
      .toggle(flow.id)
      .then((updated) => setFlows((prev) => prev.map((f) => (f.id === updated.id ? updated : f))))
      .catch((err: unknown) => setPageError(extractErrorMessage(err, 'Failed to update status.')))
      .finally(() => setBusyId(null));
  };

  if (editingFlow !== null) {
    return (
      <JourneyCanvasEditor
        initialFlow={editingFlow === 'new' ? null : editingFlow}
        onBack={() => setEditingFlow(null)}
        onSaved={() => {
          setEditingFlow(null);
          loadFlows();
        }}
      />
    );
  }

  return (
    <>
      {pageError && (
        <DismissibleAlert className="mx-6 mt-6 flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          <XCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
          {pageError}
        </DismissibleAlert>
      )}
      {testingFlow && <TestTriggerModal flow={testingFlow} onClose={() => setTestingFlow(null)} />}
      <FlowsListView
        flows={flows}
        isLoading={isLoading}
        onEdit={setEditingFlow}
        onCreate={() => setEditingFlow('new')}
        onDelete={handleDelete}
        onToggle={handleToggle}
        onTest={setTestingFlow}
        busyId={busyId}
      />
      {pendingDelete && (
        <ConfirmModal
          title="Delete journey"
          message={`Delete "${pendingDelete.name}"? This cannot be undone.`}
          confirmLabel="Delete"
          variant="danger"
          isLoading={busyId === pendingDelete.id}
          onConfirm={confirmDelete}
          onCancel={() => setPendingDelete(null)}
        />
      )}
    </>
  );
}
