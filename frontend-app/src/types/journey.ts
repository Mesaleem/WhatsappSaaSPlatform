/**
 * Module 5 — No-Code WhatsApp Journey Builder. Mirrors backend-api's
 * App\Models\WhatsAppFlow / WhatsAppFlowSession + WhatsAppFlowController's
 * JSON shape. See WhatsAppJourneyEngine's class docblock (backend) for
 * the authoritative node-type contract — this file mirrors it 1:1.
 */

import type { AnyJourneyNodeType } from './journeyNodes';

export type FlowTriggerType = 'keyword' | 'ctwa_referral' | 'default';

/**
 * Phase 5 — Journey / Automation: widened to every registered node type.
 *
 * The five original values are now the `legacy` half of
 * `src/journey/nodeRegistry.tsx`; the 27 added by the node-palette task
 * are the other half. Saved flows containing the original five keep
 * loading and keep executing unchanged — nothing rewrites them.
 */
export type JourneyNodeType = AnyJourneyNodeType;

/** The five types the shipped WhatsAppJourneyEngine actually executes today. */
export type JourneyLegacyEngineNodeType = 'trigger' | 'message' | 'question' | 'condition' | 'save_lead';

export type QuestionInputType = 'text' | 'buttons' | 'list';

export type QuestionValidationType = 'none' | 'number' | 'email' | 'phone';

export type ConditionOperator = 'equals' | 'not_equals' | 'contains' | 'exists';

export const TRIGGER_TYPE_LABELS: Record<FlowTriggerType, string> = {
  keyword: 'Keyword',
  ctwa_referral: 'Click-to-WhatsApp Ad',
  default: 'Default (catch-all)',
};

/**
 * Legacy labels, kept for the five engine-executed types. Every other
 * label comes from the node registry — see getJourneyNode(type).label.
 */
export const NODE_TYPE_LABELS: Record<JourneyLegacyEngineNodeType, string> = {
  trigger: 'Trigger',
  message: 'Send Message',
  question: 'Ask Question',
  condition: 'Condition',
  save_lead: 'Save Lead',
};

export interface JourneyNodeOption {
  id: string;
  title: string;
}

export interface QuestionValidation {
  type: QuestionValidationType;
  error_message?: string;
}

/** Union of every node type's `data` shape — a node only reads the fields matching its own `type`. */
export interface JourneyNodeData {
  // message
  text?: string;
  // question
  prompt_text?: string;
  variable_name?: string;
  input_type?: QuestionInputType;
  options?: JourneyNodeOption[];
  button_text?: string;
  validation?: QuestionValidation;
  // condition
  variable?: string;
  // save_lead
  name_variable?: string;
  email_variable?: string;
  phone_variable?: string;
  completion_message?: string;
  /**
   * Phase 5 — Journey / Automation: the 27 palette nodes keep their
   * configuration under their own registry-declared field keys in this
   * same `data` object (see JourneyNodeConfigMap in ./journeyNodes for
   * the per-type shapes, and nodeRegistry's configSchema for the keys).
   *
   * The index signature is what makes that honest: without it this
   * interface silently claimed a `delay` node's data had no `amount`,
   * even though the builder was already writing one. Legacy keys above
   * keep their exact types — an index signature widens what is allowed,
   * not what is declared.
   */
  [key: string]: unknown;
}

export interface JourneyNode {
  id: string;
  type: JourneyNodeType;
  position: { x: number; y: number };
  data: JourneyNodeData;
}

export interface JourneyEdgeCondition {
  operator: ConditionOperator;
  value?: string;
}

export interface JourneyEdge {
  id: string;
  source: string;
  target: string;
  /** Only meaningful for edges leaving a 'condition' node — see WhatsAppJourneyEngine::resolveConditionTarget(). */
  condition?: JourneyEdgeCondition;
  /** Marks this as a condition node's "else" branch. */
  is_default?: boolean;
  /**
   * Phase 5 — Journey / Automation: which declared source handle this
   * edge leaves from, e.g. 'true' / 'false' on a `conditional` node.
   *
   * EXPLICIT BRANCH IDENTITY: a later execution engine reads this, never
   * the geometry of the canvas, so re-arranging boxes can never silently
   * swap a TRUE branch for a FALSE one. Optional and absent on every
   * edge saved before this task — a legacy 'condition' node keeps
   * carrying its branch on `condition` / `is_default` instead, unchanged.
   */
  sourceHandle?: string;
}

export interface JourneyGraph {
  nodes: JourneyNode[];
  edges: JourneyEdge[];
}

export interface WhatsAppFlow {
  id: number;
  account_id: number;
  name: string;
  trigger_type: FlowTriggerType;
  trigger_value: string | null;
  graph_data: JourneyGraph;
  /** Phase 7 Task 2 — the immutable version new sessions start on (graph_data is the latest saved working copy). */
  published_version_id?: number | null;
  is_active: boolean;
  created_at: string | null;
  updated_at: string | null;
}

export interface SaveFlowPayload {
  name: string;
  trigger_type: FlowTriggerType;
  trigger_value: string | null;
  graph_data: JourneyGraph;
  is_active?: boolean;
}

/** 'waiting' / 'failed' / 'cancelled' — Phase 7 Task 1 temporal backbone; 'blocked' — Task 1.6 (account lost Journey entitlement; state kept). */
export type FlowSessionStatus = 'active' | 'waiting' | 'blocked' | 'completed' | 'expired' | 'failed' | 'cancelled';

export interface WhatsAppFlowSession {
  id: number;
  account_id: number;
  flow_id: number;
  /** Phase 7 Task 2 — the version this session is pinned to. */
  flow_version_id?: number | null;
  phone_number: string;
  current_node_id: string | null;
  context_data: Record<string, unknown>;
  status: FlowSessionStatus;
  /** When a 'waiting' session is due to resume. */
  wait_until?: string | null;
  attempts?: number;
  /** Why the last resumed step failed ('failed' sessions keep it). */
  last_error?: string | null;
  last_interaction_at: string | null;
  created_at: string | null;
}
