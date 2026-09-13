/**
 * Module 5 — No-Code WhatsApp Journey Builder. Mirrors backend-api's
 * App\Models\WhatsAppFlow / WhatsAppFlowSession + WhatsAppFlowController's
 * JSON shape. See WhatsAppJourneyEngine's class docblock (backend) for
 * the authoritative node-type contract — this file mirrors it 1:1.
 */

export type FlowTriggerType = 'keyword' | 'ctwa_referral' | 'default';

export type JourneyNodeType = 'trigger' | 'message' | 'question' | 'condition' | 'save_lead';

export type QuestionInputType = 'text' | 'buttons' | 'list';

export type QuestionValidationType = 'none' | 'number' | 'email' | 'phone';

export type ConditionOperator = 'equals' | 'not_equals' | 'contains' | 'exists';

export const TRIGGER_TYPE_LABELS: Record<FlowTriggerType, string> = {
  keyword: 'Keyword',
  ctwa_referral: 'Click-to-WhatsApp Ad',
  default: 'Default (catch-all)',
};

export const NODE_TYPE_LABELS: Record<JourneyNodeType, string> = {
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

export type FlowSessionStatus = 'active' | 'completed' | 'expired';

export interface WhatsAppFlowSession {
  id: number;
  account_id: number;
  flow_id: number;
  phone_number: string;
  current_node_id: string | null;
  context_data: Record<string, unknown>;
  status: FlowSessionStatus;
  last_interaction_at: string | null;
  created_at: string | null;
}
