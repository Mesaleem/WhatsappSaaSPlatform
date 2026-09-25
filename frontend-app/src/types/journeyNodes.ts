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
  | 'human_intervention';

export type JourneyUtilityNodeType = 'code' | 'email' | 'journey' | 'delay';

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

export interface ReplyButton {
  id: string;
  title: string;
}

/** WhatsApp allows at most three quick-reply buttons — enforced in schema AND UI validation. */
export const MAX_REPLY_BUTTONS = 3;

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
 */
export interface ConditionalNodeConfig {
  conditions: ConditionalRule[];
  /** How multiple rules combine. */
  match?: 'all' | 'any';
}

export const CONDITIONAL_TRUE_HANDLE = 'true';
export const CONDITIONAL_FALSE_HANDLE = 'false';

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
  agentId: string;
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
  code: CodeNodeConfig;
  email: EmailNodeConfig;
  journey: JourneyRefNodeConfig;
  delay: DelayNodeConfig;
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
  | 'keyvalue'
  /** A repeatable list of plain strings (e.g. catalog product IDs). */
  | 'strings'
  | 'code'
  | 'json';

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
