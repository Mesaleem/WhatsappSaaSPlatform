/**
 * Phase 5 — Journey / Automation: 27-node palette foundation.
 *
 * The TYPED half of the node contract. Every node's configuration shape
 * lives here; the runtime half (label, icon, category, field descriptors,
 * validation, handles) lives in `src/journey/nodeRegistry.tsx`, which is
 * the single canonical registry everything else reads.
 *
 * FOUNDATION ONLY. Nothing here executes anything — these are the
 * persistence and validation contracts a later Journey runtime task will
 * consume.
 *
 * PERSISTENCE: every config below is stored inside the EXISTING
 * whatsapp_flows.graph_data JSON, on a node's own `data` object. No new
 * table and no migration — see the registry's own docblock for why the
 * existing storage model already represents all of this.
 */

import type { JourneyNodeData } from './journey';

// ===================================================================
// Categories, providers, capabilities
// ===================================================================

export type JourneyNodeCategory = 'message' | 'interactive' | 'advanced' | 'utility';

export const JOURNEY_NODE_CATEGORIES: JourneyNodeCategory[] = [
  'message',
  'interactive',
  'advanced',
  'utility',
];

export const JOURNEY_NODE_CATEGORY_LABELS: Record<JourneyNodeCategory, string> = {
  message: 'Message',
  interactive: 'Interactive',
  advanced: 'Advanced',
  utility: 'Utility',
};

/**
 * Provider slugs, taken verbatim from the backend `providers` table as
 * seeded by Phase1FoundationSeeder — 'qr', 'meta', 'none'. No new slug is
 * invented here.
 *
 * The task brief calls the third one "platform". That is exactly what the
 * seeded 'none' provider already means ("No WhatsApp Provider" — the row
 * used for capabilities that need no WhatsApp engine at all), so a node
 * that runs regardless of engine is tagged 'none' rather than given a
 * duplicate name. JOURNEY_PROVIDER_LABELS below is where "Platform" is
 * shown to a human.
 */
export type JourneyNodeProvider = 'qr' | 'meta' | 'none';

export const JOURNEY_PROVIDER_LABELS: Record<JourneyNodeProvider, string> = {
  qr: 'QR (Baileys)',
  meta: 'Meta Cloud API',
  none: 'Platform',
};

/**
 * Capability slugs, taken verbatim from the backend `capabilities` table
 * as seeded by Phase1FoundationSeeder. No slug is invented here.
 *
 * Phase 5 Task 7 seeded the five below the divider, closing the gap Task
 * 6 disclosed (commerce / payment / api / code / email had no capability
 * to declare). Agent and RAG deliberately did NOT get slugs of their
 * own — they are expressed by the existing 'ai' capability.
 *
 * This is metadata for UX and for the backend drift test. It is NOT
 * authorization: JourneyNodeAuthorizer re-derives every one of these
 * requirements server-side from its own catalog and the account's own
 * entitlements. See that class's trust-boundary docblock.
 */
export type JourneyNodeCapability =
  | 'whatsapp_send'
  | 'whatsapp_groups'
  | 'crm'
  | 'journey_automation'
  | 'ads'
  | 'social'
  | 'ai'
  // --- Phase 5 Task 7 ---
  | 'commerce'
  | 'payments'
  | 'external_api'
  | 'custom_code'
  | 'email';

// ===================================================================
// The 27 node types (plus the 5 that already exist on saved journeys)
// ===================================================================

export type JourneyMessageNodeType =
  | 'prompt'
  | 'text'
  | 'image'
  | 'video'
  | 'document'
  | 'audio'
  | 'sticker';

export type JourneyInteractiveNodeType =
  | 'list'
  | 'external_url'
  | 'reply_button'
  | 'location'
  | 'location_request'
  | 'address_request';

export type JourneyAdvancedNodeType =
  | 'flow'
  | 'api'
  | 'payment'
  | 'template'
  | 'conditional'
  | 'catalog'
  | 'product'
  | 'agent'
  | 'rag'
  | 'human_intervention'
  /** Phase 8 Task 16 — LLM-based intent routing. Draft-only: see JourneyNodeCatalog on the backend. */
  | 'classifier';

export type JourneyUtilityNodeType = 'code' | 'email' | 'journey' | 'delay' | 'end';

/** The 27 nodes this task introduces. */
export type JourneyPaletteNodeType =
  | JourneyMessageNodeType
  | JourneyInteractiveNodeType
  | JourneyAdvancedNodeType
  | JourneyUtilityNodeType;

/**
 * The five node types saved journeys already contain
 * (WhatsAppFlow::NODE_TYPES, WhatsAppJourneyEngine). They stay valid
 * forever: an existing flow must keep loading, and the engine still
 * executes exactly these. They are registered so the canvas can render
 * them, but are NOT offered in the new palette — 'text' supersedes
 * 'message', 'conditional' supersedes 'condition'.
 */
export type JourneyLegacyNodeType = 'trigger' | 'message' | 'question' | 'condition' | 'save_lead';

export type AnyJourneyNodeType = JourneyPaletteNodeType | JourneyLegacyNodeType;

// ===================================================================
// Per-node configuration contracts
// ===================================================================

export interface PromptNodeConfig {
  /** System / AI instruction that seeds the conversation. */
  prompt: string;
  /** Optional model hint; the RUNTIME resolves the actual model + credentials server-side. */
  model?: string;
}

export interface TextNodeConfig {
  text: string;
  /** {{token}} names referenced by `text`; derived, not authored. */
  variables?: string[];
}

export interface MediaNodeConfig {
  mediaUrl: string;
  caption?: string;
}

export type ImageNodeConfig = MediaNodeConfig;
export type VideoNodeConfig = MediaNodeConfig;

export interface DocumentNodeConfig extends MediaNodeConfig {
  filename?: string;
}

export interface AudioNodeConfig {
  mediaUrl: string;
}

export interface StickerNodeConfig {
  mediaUrl: string;
}

export interface ListRow {
  id: string;
  title: string;
  description?: string;
}

export interface ListSection {
  title: string;
  rows: ListRow[];
}

export interface ListNodeConfig {
  body: string;
  buttonText: string;
  sections: ListSection[];
}

/**
 * Task 24 — Connexxa parity. Each row across every section gets its OWN
 * outgoing handle (mirrors backend JourneyActionConfig::LIST_ROW_HANDLES).
 * Position i, flattened across sections in document order, maps to
 * element i here — the same order ListConfigModal stores sections/rows
 * in and the same order the backend's WhatsAppJourneyEngine flattens
 * them when routing a reply. Capped at 10 — WhatsApp's own Cloud API
 * limit on total rows across a list message's sections (the existing
 * 'list' node validate() below already enforces this count).
 */
export const LIST_ROW_HANDLE_IDS = ['row_1', 'row_2', 'row_3', 'row_4', 'row_5', 'row_6', 'row_7', 'row_8', 'row_9', 'row_10'] as const;
export const MAX_LIST_ROWS = LIST_ROW_HANDLE_IDS.length;

export interface ReplyButton {
  id: string;
  title: string;
}

/** WhatsApp allows at most three quick-reply buttons — enforced in schema AND UI validation. */
export const MAX_REPLY_BUTTONS = 3;

/**
 * Task 24 — Connexxa parity. Same per-option handle as LIST_ROW_HANDLE_IDS,
 * for a 'reply_button' node (mirrors backend JourneyActionConfig::
 * REPLY_BUTTON_HANDLES). Capped at MAX_REPLY_BUTTONS.
 */
export const REPLY_BUTTON_HANDLE_IDS = ['button_1', 'button_2', 'button_3'] as const;

export interface ReplyButtonNodeConfig {
  body: string;
  buttons: ReplyButton[];
}

export interface ExternalUrlNodeConfig {
  body?: string;
  buttonText: string;
  url: string;
}

export interface LocationNodeConfig {
  latitude: number;
  longitude: number;
  name?: string;
  address?: string;
}

export interface LocationRequestNodeConfig {
  body: string;
}

export interface AddressRequestNodeConfig {
  body: string;
}

export interface FlowNodeConfig {
  flowId: string;
  body?: string;
}

/**
 * Task 25 — Connexxa parity. The 'journey' (sub-journey) node's jump
 * mode. Absent `mode` is the pre-Task-25 shape and means 'static' with
 * the journey's own default entry — every journey saved before this
 * feature keeps meaning exactly what it always meant, fully backward
 * compatible (see JourneyActionConfig::journeyError()'s docblock on
 * the backend for the full save-time contract).
 */
export type SubJourneyMode = 'static' | 'dynamic';

export interface SubJourneyNodeConfig {
  /** Absent/'static' = legacy shape, unchanged meaning. */
  mode?: SubJourneyMode;
  /** Static only: the target journey's id (as a string, matching the picker's <select> value). */
  journeyId?: string;
  /** Static only: an explicit node id to jump straight into; empty = the target journey's own default entry. */
  startNodeId?: string;
  /** Dynamic only: a {{ }}-renderable expression for the target journey id (Task 22's renderText/var_local/var_system). */
  journeyIdTemplate?: string;
  /** Dynamic only: a {{ }}-renderable expression for the target node id. */
  nodeIdTemplate?: string;
}

export type ApiMethod = 'GET' | 'POST';

export interface ApiKeyValue {
  key: string;
  value: string;
  /**
   * P5-6 — set by the server on a credential value it stores encrypted;
   * `value` is then only the mask. Sending the pair back unchanged keeps
   * the stored secret.
   */
  masked?: boolean;
}

export interface ApiNodeConfig {
  method: ApiMethod;
  url: string;
  /**
   * SECURITY: header/query/body values are stored in graph_data as
   * plaintext JSON and are readable by anyone who can open the flow.
   * A credential belongs in a server-side integration record, never
   * here. `credentialRef` names such a record by id; the runtime
   * resolves it server-side and the secret never reaches this config,
   * the API response, or the browser. See the registry's security note.
   */
  headers?: ApiKeyValue[];
  query?: ApiKeyValue[];
  body?: string;
  credentialRef?: string;
  /** { contextVariable: jsonPath } — where to put pieces of the response. */
  responseMapping?: ApiKeyValue[];
}

/**
 * Provider-independent on purpose: no gateway is named, and no key,
 * secret or merchant credential is part of this contract. A later task
 * binds this to whichever gateway the tenant has configured server-side.
 */
export interface PaymentNodeConfig {
  amount: number;
  currency: string;
  description?: string;
  /** Names a server-side gateway configuration; never a credential. */
  gatewayRef?: string;
}

export interface TemplateNodeConfig {
  /** message_templates.id — resolved against the existing Meta template system. */
  templateId: string;
  /**
   * Ordered name/value pairs, the same shape every other key/value field
   * on a node uses — NOT a Record. Meta template parameters are ordered,
   * and an array survives a JSON round-trip through `graph_data` with its
   * order intact, which an object's key order does not guarantee.
   */
  variables?: ApiKeyValue[];
}

/**
 * Mirrors backend JourneyConditionEvaluator::OPERATORS (Phase 7 Task 4) —
 * the engine refuses anything else. Text operators compare trimmed,
 * case-insensitive text; the four numeric ones need numbers on both sides.
 */
export type ConditionalOperator =
  | 'equals'
  | 'not_equals'
  | 'contains'
  | 'not_contains'
  | 'starts_with'
  | 'ends_with'
  | 'exists'
  | 'not_exists'
  | 'greater_than'
  | 'less_than'
  | 'greater_or_equal'
  | 'less_or_equal';

/** Operators that take no value. */
export const UNARY_CONDITIONAL_OPERATORS: ConditionalOperator[] = ['exists', 'not_exists'];

/** Operators whose value must be a number. */
export const NUMERIC_CONDITIONAL_OPERATORS: ConditionalOperator[] = ['greater_than', 'less_than', 'greater_or_equal', 'less_or_equal'];

export interface ConditionalRule {
  variable: string;
  operator: ConditionalOperator;
  value?: string;
}

/**
 * Branch identity is EXPLICIT: the node declares its outgoing handles and
 * an edge records which one it leaves from (`sourceHandle`). Nothing
 * depends on where a box sits on the canvas.
 *
 * Task 23 — Connexxa IF/ELSE-IF/ELSE parity. `groups`, when present and
 * non-empty, switches the node into multi-branch mode: each group is its
 * own IF/ELSE-IF test (own conditions + match), tested top-down, first
 * match wins, its own outgoing handle is CONDITIONAL_GROUP_HANDLE_IDS[i].
 * No match falls to the fixed CONDITIONAL_ELSE_HANDLE. `conditions`/
 * `match` stay exactly as they always were — the single-branch TRUE/FALSE
 * shape every journey saved before this feature already uses — and are
 * simply ignored once `groups` is non-empty (set only when the author
 * explicitly adds an ELSE IF branch; see nodeRegistry.tsx's
 * 'conditionGroups' field). Backend mirror: WhatsAppFlow's
 * JourneyConditionEvaluator::evaluateGroups() /
 * JourneyActionConfig::CONDITIONAL_GROUP_HANDLES.
 */
export interface ConditionalNodeConfig {
  conditions: ConditionalRule[];
  /** How multiple rules combine. */
  match?: 'all' | 'any';
  /** Task 23 — multi-branch mode; see this interface's docblock. */
  groups?: ConditionalGroup[];
}

export interface ConditionalGroup {
  conditions: ConditionalRule[];
  match?: 'all' | 'any';
}

export const CONDITIONAL_TRUE_HANDLE = 'true';
export const CONDITIONAL_FALSE_HANDLE = 'false';

/** Task 23 — up to 5 IF/ELSE-IF branches (mirrors JourneyActionConfig::CONDITIONAL_GROUP_HANDLES; same 5-slot cap convention as CLASSIFIER_BRANCH_HANDLES). */
export const CONDITIONAL_GROUP_HANDLE_IDS = ['group_1', 'group_2', 'group_3', 'group_4', 'group_5'] as const;
export const CONDITIONAL_MAX_GROUPS = CONDITIONAL_GROUP_HANDLE_IDS.length;
/** Task 23 — the fixed fallback handle when no group matches (mirrors JourneyActionConfig::CONDITIONAL_ELSE_HANDLE). */
export const CONDITIONAL_ELSE_HANDLE = 'else';

export interface CatalogNodeConfig {
  catalogId: string;
  productIds?: string[];
  body?: string;
}

export interface ProductNodeConfig {
  productId: string;
  catalogId?: string;
  body?: string;
}

export interface AgentNodeConfig {
  /** A display label (selects nothing). */
  agentId?: string;
  /** Phase 8 Task 11 — id of a registered AI agent of this account; when set, instructions are optional. */
  registeredAgentId?: string;
  instructions?: string;
}

export interface RagNodeConfig {
  knowledgeBaseId: string;
  queryVariable?: string;
  topK?: number;
}

export interface HumanInterventionNodeConfig {
  queueId?: string;
  message?: string;
}

/**
 * Phase 8 Task 16 — LLM-based intent routing: an input variable is
 * classified against up to 5 named branches, each with its own outgoing
 * handle (CLASSIFIER_BRANCH_HANDLES), the same "branch identity is
 * explicit, never inferred from canvas geometry" contract ConditionalNodeConfig
 * uses. Draft-only today — not in RUNTIME_EXECUTABLE_NODE_TYPES, so this
 * is a configuration contract only; nothing executes it yet.
 */
export interface JourneyClassifierBranch {
  label: string;
  /** Optional free-text description of the branch, passed to the model as context. */
  intent?: string;
}

export interface ClassifierNodeConfig {
  inputVariable: string;
  outputVariable: string;
  branches?: JourneyClassifierBranch[];
  /** Optional model override; empty defers to the account's configured default (see the AI provider/model selector). */
  model?: string;
}

export const CLASSIFIER_BRANCH_HANDLES = ['branch_1', 'branch_2', 'branch_3', 'branch_4', 'branch_5'] as const;

/**
 * CONFIGURATION CONTRACT ONLY. This code is never evaluated in the
 * browser, and a later task must execute it in a server-side sandbox —
 * never through eval(), new Function(), or any PHP eval equivalent.
 */
export interface CodeNodeConfig {
  language: 'javascript';
  code: string;
  inputMapping?: ApiKeyValue[];
  outputMapping?: ApiKeyValue[];
}

export interface EmailNodeConfig {
  to: string;
  subject: string;
  body: string;
}

export interface JourneyRefNodeConfig {
  journeyId: string;
}

export type DelayUnit = 'seconds' | 'minutes' | 'hours' | 'days';

export const DELAY_UNITS: DelayUnit[] = ['seconds', 'minutes', 'hours', 'days'];

export interface DelayNodeConfig {
  amount: number;
  unit: DelayUnit;
}

/**
 * Canvas-only terminator. UI sugar, never sent to the backend: a journey
 * with no outgoing edge from its last real node already completes the
 * session today (WhatsAppJourneyEngine::advance()'s `if (!$next) { ...
 * complete($session); }` branch) — this node exists purely so the builder
 * shows that fact as a card instead of a dangling connector. See
 * `stripEndNodes()` in JourneyBuilderPage.tsx, which removes every 'end'
 * node (and any edge into one) from the payload before it is saved.
 */
export interface EndNodeConfig {
  body: string;
}

/** Maps every palette node type to its configuration contract. */
export interface JourneyNodeConfigMap {
  prompt: PromptNodeConfig;
  text: TextNodeConfig;
  image: ImageNodeConfig;
  video: VideoNodeConfig;
  document: DocumentNodeConfig;
  audio: AudioNodeConfig;
  sticker: StickerNodeConfig;
  list: ListNodeConfig;
  external_url: ExternalUrlNodeConfig;
  reply_button: ReplyButtonNodeConfig;
  location: LocationNodeConfig;
  location_request: LocationRequestNodeConfig;
  address_request: AddressRequestNodeConfig;
  flow: FlowNodeConfig;
  api: ApiNodeConfig;
  payment: PaymentNodeConfig;
  template: TemplateNodeConfig;
  conditional: ConditionalNodeConfig;
  catalog: CatalogNodeConfig;
  product: ProductNodeConfig;
  agent: AgentNodeConfig;
  rag: RagNodeConfig;
  human_intervention: HumanInterventionNodeConfig;
  classifier: ClassifierNodeConfig;
  code: CodeNodeConfig;
  email: EmailNodeConfig;
  journey: JourneyRefNodeConfig;
  delay: DelayNodeConfig;
  end: EndNodeConfig;
}

export type JourneyNodeConfig = JourneyNodeConfigMap[JourneyPaletteNodeType];

/**
 * What actually lands in graph_data.nodes[].data.
 *
 * A node's own config keys are merged onto the SAME `data` object the
 * five legacy node types already use, so one saved flow can hold both
 * shapes and the existing engine keeps reading its own fields untouched.
 */
export type JourneyNodeDataFor<T extends AnyJourneyNodeType> =
  T extends JourneyPaletteNodeType ? JourneyNodeData & Partial<JourneyNodeConfigMap[T]> : JourneyNodeData;

// ===================================================================
// Field descriptors — what the config form renders and validates
// ===================================================================

export type JourneyFieldType =
  | 'text'
  | 'textarea'
  | 'url'
  | 'number'
  | 'select'
  | 'variable'
  | 'buttons'
  | 'sections'
  | 'conditions'
  /** Task 23 — the conditional node's IF/ELSE-IF/ELSE branches; see ConditionalNodeConfig's docblock. */
  | 'conditionGroups'
  | 'keyvalue'
  /** A repeatable list of plain strings (e.g. catalog product IDs). */
  | 'strings'
  | 'code'
  | 'json'
  /** Phase 8 Task 10 — one of the edited account's knowledge bases (options loaded from the API). */
  | 'knowledgeBase'
  /** Phase 8 Task 11 — one of the edited account's registered AI agents (options loaded from the API). */
  | 'aiAgent'
  /** Phase 8 Task 15 — one of the edited account's saved API connections (options loaded from the API). */
  | 'apiConnection'
  /** Phase 8 Task 16 — the classifier node's up-to-5 named branches, positionally mapped to CLASSIFIER_BRANCH_HANDLES. */
  | 'classifierBranches'
  /** Task 25 — one of the edited account's OTHER journeys, for a sub-journey node's Static-mode target (options loaded from the API). */
  | 'subJourney';

export interface JourneyFieldOption {
  value: string;
  label: string;
}

/**
 * A single configuration field. Drives both the rendered form and
 * field-level validation, so a node can never be "in the palette" without
 * a machine-readable configuration contract — which is exactly the bar
 * this task sets for PASS.
 */
export interface JourneyNodeField {
  key: string;
  label: string;
  type: JourneyFieldType;
  required?: boolean;
  /** Phase 8 Task 11 — `required` is waived while this other field of the node has a value. */
  requiredUnless?: string;
  placeholder?: string;
  help?: string;
  options?: JourneyFieldOption[];
  min?: number;
  max?: number;
  /** Cap on a repeatable field's item count (reply buttons: 3). */
  maxItems?: number;
}

/** An outgoing connection point. `id` is the edge's `sourceHandle`. */
export interface JourneyNodeHandle {
  id: string;
  label: string;
}

/** Field-level errors, keyed by JourneyNodeField.key. */
export type JourneyNodeConfigErrors = Record<string, string>;
