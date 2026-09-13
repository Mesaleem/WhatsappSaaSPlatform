import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { MouseEvent as ReactMouseEvent } from 'react';
import {
  AlertTriangle,
  ArrowLeft,
  Loader2,
  MessageCircle,
  MessageSquare,
  Plus,
  Send,
  Target,
  Trash2,
  UserPlus,
  X,
  XCircle,
  Zap,
} from 'lucide-react';
import journeyService from '../../services/journeyService';
import { ClearFiltersButton, SearchInput } from '../../components/common/DataTableControls';
import { TableCard, inputClass } from '../../components/common/Card';
import { useTenant } from '../../core/context/TenantContext';
import { extractErrorMessage } from '../../utils/apiError';
import { indigo, activeGradient } from '../../theme/signalIndigo';
import {
  NODE_TYPE_LABELS,
  TRIGGER_TYPE_LABELS,
} from '../../types/journey';
import type {
  ConditionOperator,
  FlowTriggerType,
  JourneyEdge,
  JourneyGraph,
  JourneyNode,
  JourneyNodeOption,
  JourneyNodeType,
  QuestionInputType,
  QuestionValidationType,
  SaveFlowPayload,
  WhatsAppFlow,
} from '../../types/journey';

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

const NODE_WIDTH = 208;
const NODE_HEIGHT = 92;
const CANVAS_WIDTH = 1800;
const CANVAS_HEIGHT = 1100;

const NODE_TYPE_META: Record<JourneyNodeType, { icon: typeof Zap; color: string; bg: string }> = {
  trigger: { icon: Zap, color: '#7c3aed', bg: '#f5f3ff' },
  message: { icon: MessageSquare, color: '#2563eb', bg: '#eff6ff' },
  question: { icon: MessageCircle, color: '#0891b2', bg: '#ecfeff' },
  condition: { icon: Target, color: '#d97706', bg: '#fffbeb' },
  save_lead: { icon: UserPlus, color: '#16a34a', bg: '#f0fdf4' },
};

const ADDABLE_NODE_TYPES: JourneyNodeType[] = ['message', 'question', 'condition', 'save_lead'];

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

function newTriggerGraph(): JourneyGraph {
  return {
    nodes: [{ id: genId('trigger'), type: 'trigger', position: { x: 40, y: 200 }, data: {} }],
    edges: [],
  };
}

function nodePreview(node: JourneyNode): string {
  switch (node.type) {
    case 'trigger':
      return 'Flow entry point';
    case 'message':
      return node.data.text?.trim() || 'No message text yet';
    case 'question':
      return node.data.prompt_text?.trim() || 'No prompt text yet';
    case 'condition':
      return node.data.variable ? `IF {{${node.data.variable}}} …` : 'No variable selected';
    case 'save_lead':
      return 'Saves collected answers to Leads';
    default:
      return '';
  }
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
  const [graph, setGraph] = useState<JourneyGraph>(initialFlow?.graph_data ?? newTriggerGraph());
  const [selection, setSelection] = useState<Selection>(null);
  const [isSaving, setIsSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [testingFlow, setTestingFlow] = useState<WhatsAppFlow | null>(null);

  const canvasRef = useRef<HTMLDivElement>(null);
  const nodeDragRef = useRef<NodeDragState | null>(null);
  const [connectDrag, setConnectDrag] = useState<ConnectDragState | null>(null);

  const knownVariables = useMemo(
    () => graph.nodes.filter((n) => n.type === 'question' && n.data.variable_name).map((n) => n.data.variable_name as string),
    [graph.nodes],
  );

  const nodeById = useCallback((id: string) => graph.nodes.find((n) => n.id === id), [graph.nodes]);

  const updateNodeData = (nodeId: string, patch: Record<string, unknown>) => {
    setGraph((g) => ({
      ...g,
      nodes: g.nodes.map((n) => (n.id === nodeId ? { ...n, data: { ...n.data, ...patch } } : n)),
    }));
  };

  const addNode = (type: JourneyNodeType) => {
    const index = graph.nodes.length;
    const position = { x: 320 + (index % 3) * 260, y: 40 + Math.floor(index / 3) * 170 };
    const node: JourneyNode = { id: genId(type), type, position, data: {} };
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
              p.y <= n.position.y + NODE_HEIGHT,
          );

          if (!target) return g;

          const sourceNode = g.nodes.find((n) => n.id === prev.sourceId);
          const sourceIsCondition = sourceNode?.type === 'condition';

          // Non-condition nodes have exactly one outgoing edge — replace it.
          // Condition nodes may have several (one per branch) — append.
          const edges = sourceIsCondition ? g.edges : g.edges.filter((e2) => e2.source !== prev.sourceId);

          const newEdge: JourneyEdge = { id: genId('edge'), source: prev.sourceId, target: target.id };
          setSelection({ kind: 'edge', id: newEdge.id });

          return { ...g, edges: [...edges, newEdge] };
        });

        return null;
      });
    },
    [canvasRelativePoint],
  );

  const onHandleMouseDown = (e: ReactMouseEvent, sourceId: string) => {
    e.stopPropagation();
    const p = canvasRelativePoint(e.clientX, e.clientY);
    setConnectDrag({ sourceId, mouseX: p.x, mouseY: p.y });
    activeConnectListenersRef.current = { move: onWindowMouseMoveForConnect, up: onWindowMouseUpForConnect };
    window.addEventListener('mousemove', onWindowMouseMoveForConnect);
    window.addEventListener('mouseup', onWindowMouseUpForConnect);
  };

  const handleSave = () => {
    if (!name.trim()) {
      setSaveError('Give this journey a name.');
      return;
    }
    const hasTrigger = graph.nodes.some((n) => n.type === 'trigger');
    if (!hasTrigger) {
      setSaveError('This journey has no Trigger node.');
      return;
    }

    const payload: SaveFlowPayload = {
      name: name.trim(),
      trigger_type: triggerType,
      trigger_value: triggerValue.trim() || null,
      graph_data: graph,
      is_active: isActive,
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
          <button
            onClick={handleSave}
            disabled={isSaving}
            className="flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
            style={{ background: activeGradient }}
          >
            {isSaving ? <Loader2 className="h-4 w-4 animate-spin" /> : null}
            {isSaving ? 'Saving…' : 'Save Journey'}
          </button>
        </div>
      </div>

      <div className="mb-4 grid grid-cols-1 gap-3 rounded-xl border p-3 sm:grid-cols-3" style={{ borderColor: indigo.border }}>
        <label className="block text-xs font-medium text-slate-700">
          Trigger Type
          <select className={inputClass} value={triggerType} onChange={(e) => setTriggerType(e.target.value as FlowTriggerType)}>
            {(Object.keys(TRIGGER_TYPE_LABELS) as FlowTriggerType[]).map((t) => (
              <option key={t} value={t}>
                {TRIGGER_TYPE_LABELS[t]}
              </option>
            ))}
          </select>
        </label>
        <label className="block text-xs font-medium text-slate-700 sm:col-span-2">
          {triggerType === 'keyword' ? 'Keywords (comma-separated)' : triggerType === 'ctwa_referral' ? 'Meta Ad ID (optional — blank matches any ad click)' : 'Trigger Value'}
          <input
            type="text"
            className={inputClass}
            value={triggerValue}
            onChange={(e) => setTriggerValue(e.target.value)}
            disabled={triggerType === 'default'}
            placeholder={triggerType === 'keyword' ? 'hi, start, menu' : triggerType === 'ctwa_referral' ? 'e.g. 120210000000000' : ''}
          />
        </label>
      </div>

      {saveError && (
        <div className="mb-3 flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          <XCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
          {saveError}
        </div>
      )}

      <div className="mb-3 flex flex-wrap items-center gap-2">
        <span className="text-xs font-semibold uppercase tracking-wide" style={{ color: indigo.muted }}>
          Add node:
        </span>
        {ADDABLE_NODE_TYPES.map((type) => {
          const meta = NODE_TYPE_META[type];
          const Icon = meta.icon;
          return (
            <button
              key={type}
              onClick={() => addNode(type)}
              className="flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-semibold hover:bg-slate-50"
              style={{ borderColor: indigo.border, color: meta.color }}
            >
              <Plus className="h-3 w-3" />
              <Icon className="h-3.5 w-3.5" />
              {NODE_TYPE_LABELS[type]}
            </button>
          );
        })}
        <span className="ml-auto text-[11px]" style={{ color: indigo.muted }}>
          Drag a node's right-edge dot onto another node to connect them. Click a node or connection to edit it.
        </span>
      </div>

      <div className="flex min-h-0 flex-1 gap-4">
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
                const y1 = source.position.y + NODE_HEIGHT / 2;
                const x2 = target.position.x;
                const y2 = target.position.y + NODE_HEIGHT / 2;
                const midX = (x1 + x2) / 2;
                const d = `M ${x1} ${y1} C ${midX} ${y1}, ${midX} ${y2}, ${x2} ${y2}`;
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
                  const y1 = source.position.y + NODE_HEIGHT / 2;
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
              const meta = NODE_TYPE_META[node.type];
              const Icon = meta.icon;
              const isSelected = selection?.kind === 'node' && selection.id === node.id;

              return (
                <div
                  key={node.id}
                  onMouseDown={(e) => onNodeMouseDown(e, node)}
                  className="absolute cursor-move select-none rounded-xl border bg-white shadow-sm"
                  style={{
                    left: node.position.x,
                    top: node.position.y,
                    width: NODE_WIDTH,
                    minHeight: NODE_HEIGHT,
                    borderColor: isSelected ? indigo.accentSolid : indigo.border,
                    borderWidth: isSelected ? 2 : 1,
                  }}
                >
                  <div className="flex items-center gap-1.5 rounded-t-xl px-2.5 py-1.5" style={{ background: meta.bg }}>
                    <Icon className="h-3.5 w-3.5 flex-shrink-0" style={{ color: meta.color }} />
                    <span className="text-xs font-semibold" style={{ color: meta.color }}>
                      {NODE_TYPE_LABELS[node.type]}
                    </span>
                    {node.type !== 'trigger' && (
                      <button
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
                  <p className="line-clamp-2 px-2.5 py-2 text-[11px] text-slate-600">{nodePreview(node)}</p>

                  {node.type !== 'save_lead' && (
                    <div
                      data-handle="true"
                      onMouseDown={(e) => onHandleMouseDown(e, node.id)}
                      className="absolute -right-1.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 cursor-crosshair rounded-full border-2 border-white"
                      style={{ background: meta.color }}
                      title="Drag to connect"
                    />
                  )}
                </div>
              );
            })}
          </div>
        </div>

        <div className="w-80 flex-shrink-0 overflow-y-auto rounded-xl border p-4" style={{ borderColor: indigo.border }}>
          {selectedNode && (
            <NodeEditorPanel
              node={selectedNode}
              knownVariables={knownVariables}
              onChange={(patch) => updateNodeData(selectedNode.id, patch)}
            />
          )}
          {selectedEdge && (
            <EdgeEditorPanel
              edge={selectedEdge}
              isFromCondition={selectedEdgeSourceNode?.type === 'condition'}
              onChange={(patch) => updateEdge(selectedEdge.id, patch)}
              onDelete={() => deleteEdge(selectedEdge.id)}
            />
          )}
          {!selectedNode && !selectedEdge && (
            <p className="text-xs" style={{ color: indigo.muted }}>
              Select a node or a connection line to edit its settings here.
            </p>
          )}
        </div>
      </div>
    </div>
  );
}

/** ---------- Node data editor (right-side panel) ---------- */

function NodeEditorPanel({
  node,
  knownVariables,
  onChange,
}: {
  node: JourneyNode;
  knownVariables: string[];
  onChange: (patch: Record<string, unknown>) => void;
}) {
  if (node.type === 'trigger') {
    return (
      <div>
        <h3 className="mb-2 text-sm font-semibold" style={{ color: indigo.ink }}>
          Trigger
        </h3>
        <p className="text-xs" style={{ color: indigo.muted }}>
          Every journey starts here. Configure how it's entered (keyword / ad click / default) in the bar above the canvas, then
          drag this node's dot to the first real step.
        </p>
      </div>
    );
  }

  if (node.type === 'message') {
    return (
      <div>
        <h3 className="mb-2 text-sm font-semibold" style={{ color: indigo.ink }}>
          Send Message
        </h3>
        <label className="block text-xs font-medium text-slate-700">
          Message Text
          <textarea className={inputClass} rows={5} value={node.data.text ?? ''} onChange={(e) => onChange({ text: e.target.value })} />
        </label>
      </div>
    );
  }

  if (node.type === 'question') {
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
      <div className="space-y-3">
        <h3 className="text-sm font-semibold" style={{ color: indigo.ink }}>
          Ask Question
        </h3>
        <label className="block text-xs font-medium text-slate-700">
          Prompt Text
          <textarea
            className={inputClass}
            rows={3}
            value={node.data.prompt_text ?? ''}
            onChange={(e) => onChange({ prompt_text: e.target.value })}
          />
        </label>
        <label className="block text-xs font-medium text-slate-700">
          Save Answer As Variable
          <input
            type="text"
            className={inputClass}
            value={node.data.variable_name ?? ''}
            onChange={(e) => onChange({ variable_name: e.target.value.trim().replace(/\s+/g, '_') })}
            placeholder="e.g. user_name"
          />
        </label>
        <label className="block text-xs font-medium text-slate-700">
          Answer Type
          <select className={inputClass} value={inputType} onChange={(e) => onChange({ input_type: e.target.value as QuestionInputType })}>
            <option value="text">Free text</option>
            <option value="buttons">Buttons (max 3)</option>
            <option value="list">List</option>
          </select>
        </label>

        {inputType !== 'text' && (
          <div>
            {inputType === 'list' && (
              <label className="mb-2 block text-xs font-medium text-slate-700">
                Menu Button Label
                <input type="text" className={inputClass} value={node.data.button_text ?? ''} onChange={(e) => onChange({ button_text: e.target.value })} placeholder="Menu" />
              </label>
            )}
            <p className="mb-1 text-xs font-medium text-slate-700">Options</p>
            <div className="space-y-2">
              {options.map((opt, i) => (
                <div key={opt.id} className="flex items-center gap-1.5">
                  <input
                    type="text"
                    className={`${inputClass} !mt-0`}
                    value={opt.title}
                    onChange={(e) => updateOption(i, { title: e.target.value })}
                    placeholder={`Option ${i + 1}`}
                  />
                  <button onClick={() => removeOption(i)} className="flex-shrink-0 text-slate-400 hover:text-red-600" aria-label="Remove option">
                    <X className="h-4 w-4" />
                  </button>
                </div>
              ))}
              {(inputType !== 'buttons' || options.length < 3) && (
                <button onClick={addOption} className="text-xs font-semibold" style={{ color: indigo.accentSolid }}>
                  + Add option
                </button>
              )}
            </div>
          </div>
        )}

        {inputType === 'text' && (
          <>
            <label className="block text-xs font-medium text-slate-700">
              Validate Answer As
              <select
                className={inputClass}
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
                  className={inputClass}
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

  if (node.type === 'condition') {
    return (
      <div className="space-y-3">
        <h3 className="text-sm font-semibold" style={{ color: indigo.ink }}>
          Condition
        </h3>
        <label className="block text-xs font-medium text-slate-700">
          Check Variable
          <select className={inputClass} value={node.data.variable ?? ''} onChange={(e) => onChange({ variable: e.target.value })}>
            <option value="">— select —</option>
            {knownVariables.map((v) => (
              <option key={v} value={v}>
                {v}
              </option>
            ))}
          </select>
        </label>
        <p className="text-[11px]" style={{ color: indigo.muted }}>
          Drag this node's dot to each possible next step, then click that connection line to set its branch condition (or mark
          it as the "else" default).
        </p>
      </div>
    );
  }

  // save_lead
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
    <div className="space-y-3">
      <h3 className="text-sm font-semibold" style={{ color: indigo.ink }}>
        Save Lead
      </h3>
      <p className="text-xs" style={{ color: indigo.muted }}>
        All collected answers are always saved with the lead — mapping fields below just fills in the Name/Email/Phone columns.
      </p>
      <label className="block text-xs font-medium text-slate-700">
        Name Variable
        <select className={inputClass} value={node.data.name_variable ?? ''} onChange={(e) => onChange({ name_variable: e.target.value || undefined })}>
          {selectOptions}
        </select>
      </label>
      <label className="block text-xs font-medium text-slate-700">
        Email Variable
        <select className={inputClass} value={node.data.email_variable ?? ''} onChange={(e) => onChange({ email_variable: e.target.value || undefined })}>
          {selectOptions}
        </select>
      </label>
      <label className="block text-xs font-medium text-slate-700">
        Phone Variable (defaults to the sender's WhatsApp number)
        <select className={inputClass} value={node.data.phone_variable ?? ''} onChange={(e) => onChange({ phone_variable: e.target.value || undefined })}>
          {selectOptions}
        </select>
      </label>
      <label className="block text-xs font-medium text-slate-700">
        Completion Message (optional)
        <textarea
          className={inputClass}
          rows={3}
          value={node.data.completion_message ?? ''}
          onChange={(e) => onChange({ completion_message: e.target.value })}
        />
      </label>
    </div>
  );
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

  const handleDelete = (flow: WhatsAppFlow) => {
    if (!window.confirm(`Delete "${flow.name}"? This cannot be undone.`)) return;
    setBusyId(flow.id);
    journeyService
      .remove(flow.id)
      .then(() => loadFlows())
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
        <div className="mx-6 mt-6 flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          <XCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
          {pageError}
        </div>
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
    </>
  );
}
