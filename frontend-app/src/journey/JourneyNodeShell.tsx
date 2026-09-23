import type { ComponentType } from 'react';

import {
  JOURNEY_NODE_DEFINITIONS,
  getJourneyNode,
  type JourneyNodeDefinition,
} from './nodeRegistry';
import type { AnyJourneyNodeType } from '../types/journeyNodes';

/**
 * Phase 5 — Journey / Automation: ONE node component for all 32
 * registered types (27 new + 5 legacy).
 *
 * WHY ONE SHELL: 27 bespoke components would be 27 places to drift on
 * spacing, handle placement and selection styling, and the brief
 * explicitly asks to avoid that. Everything that differs between node
 * types — icon, colour, label, category, the configured summary — is
 * data on the registry entry, so a new node type is a registry entry and
 * nothing else.
 *
 * ===================================================================
 * REACT FLOW: WHAT THIS IS AND IS NOT
 * ===================================================================
 * The props below are deliberately the shape React Flow passes a custom
 * node (`{ id, data, selected }`), and `journeyNodeTypes` below is the
 * exact `Record<type, Component>` its `nodeTypes` prop takes. React Flow
 * is NOT currently a dependency of this project — the Journey canvas in
 * JourneyBuilderPage.tsx is hand-built with absolutely-positioned divs
 * and SVG edges, a documented decision from the module that shipped it.
 * This component renders identically in that canvas today and would drop
 * into `<ReactFlow nodeTypes={journeyNodeTypes} />` unchanged if the
 * dependency is ever added. Nothing here assumes one renderer or the
 * other.
 *
 * Handles are rendered from `definition.sourceHandles`, so a branching
 * node (conditional: TRUE / FALSE) shows one labelled connection point
 * per branch, and the branch a connection belongs to is the handle's id
 * — never its position.
 */

export interface JourneyNodeShellProps {
  /** The node id in graph_data. */
  id: string;
  /** graph_data.nodes[].data — the node's configuration. */
  data: Record<string, unknown>;
  type: AnyJourneyNodeType | string;
  selected?: boolean;
  /** Field-level errors for this node, if the graph has been validated. */
  errors?: Record<string, string>;
  /** Called with the handle id a connection is being dragged from. */
  onStartConnect?: (nodeId: string, sourceHandle: string) => void;
}

function UnknownNodeBody({ type }: { type: string }) {
  return (
    <div className="rounded-lg border border-dashed border-red-300 bg-red-50 px-3 py-2">
      <div className="text-xs font-semibold text-red-700">Unknown node</div>
      <div className="font-mono text-[11px] text-red-600">{type}</div>
    </div>
  );
}

export function JourneyNodeShell({
  id,
  data,
  type,
  selected = false,
  errors,
  onStartConnect,
}: JourneyNodeShellProps) {
  const definition: JourneyNodeDefinition | undefined = getJourneyNode(type);

  // A saved flow can contain a type this build does not know about (an
  // older or newer deploy). Render it visibly rather than crashing the
  // whole canvas or silently dropping the node on the next save.
  if (!definition) {
    return <UnknownNodeBody type={type} />;
  }

  const Icon = definition.icon;
  const summary = definition.summarize?.(data) ?? null;
  const hasErrors = errors !== undefined && Object.keys(errors).length > 0;

  return (
    <div
      data-testid={`journey-node-${id}`}
      data-node-type={definition.type}
      data-node-category={definition.category}
      className={`relative w-52 rounded-lg border bg-white shadow-sm transition ${
        hasErrors
          ? 'border-red-400 ring-1 ring-red-200'
          : selected
            ? 'border-indigo-500 ring-2 ring-indigo-200'
            : 'border-slate-200'
      }`}
    >
      {definition.hasTargetHandle && (
        <span
          data-handle="target"
          aria-hidden="true"
          className="absolute -top-1.5 left-1/2 h-3 w-3 -translate-x-1/2 rounded-full border-2 border-white bg-slate-400"
        />
      )}

      <div className="flex items-start gap-2 px-3 py-2">
        <span
          className="mt-0.5 flex h-6 w-6 flex-shrink-0 items-center justify-center rounded"
          style={{ backgroundColor: definition.background, color: definition.color }}
        >
          <Icon className="h-3.5 w-3.5" />
        </span>
        <div className="min-w-0 flex-1">
          <div className="truncate text-sm font-medium text-slate-900">{definition.label}</div>
          <div className="text-[11px] uppercase tracking-wide text-slate-400">{definition.category}</div>
          {summary && <div className="mt-1 truncate text-xs text-slate-600">{summary}</div>}
          {hasErrors && (
            <div className="mt-1 text-[11px] font-medium text-red-600">
              {Object.values(errors)[0]}
            </div>
          )}
        </div>
      </div>

      {/*
        One labelled connection point per declared branch. With a single
        'next' handle the label is redundant, so only a multi-branch node
        shows them — which is exactly the conditional node today.
      */}
      <div className="flex justify-end gap-3 border-t border-slate-100 px-3 py-1">
        {definition.sourceHandles.map((handle) => (
          <button
            key={handle.id}
            type="button"
            data-handle="source"
            data-source-handle={handle.id}
            title={`Connect from ${handle.label}`}
            onMouseDown={() => onStartConnect?.(id, handle.id)}
            className="flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wide text-slate-400 hover:text-indigo-600"
          >
            {definition.sourceHandles.length > 1 && <span>{handle.label}</span>}
            <span className="h-2.5 w-2.5 rounded-full border-2 border-white bg-slate-400" />
          </button>
        ))}
      </div>
    </div>
  );
}

/**
 * The map React Flow's `nodeTypes` prop takes: every registered type
 * mapped to a component. Built from the registry, so a node can never be
 * in the palette without being renderable.
 *
 * All 32 entries point at the same shell on purpose — see this file's
 * docblock.
 */
export const journeyNodeTypes: Record<string, ComponentType<JourneyNodeShellProps>> =
  Object.fromEntries(
    JOURNEY_NODE_DEFINITIONS.map((definition) => [definition.type, JourneyNodeShell]),
  );

export default JourneyNodeShell;
