import type { ComponentType } from 'react';
import {
  Bot,
  Clock,
  Code,
  CreditCard,
  Database,
  ExternalLink,
  FileText,
  GitBranch,
  Globe,
  Headphones,
  Home,
  Image,
  LayoutTemplate,
  List,
  Mail,
  MapPin,
  MessageCircle,
  MessageSquare,
  Mic,
  MousePointerClick,
  Navigation,
  Package,
  PlayCircle,
  Route,
  ShoppingBag,
  Smile,
  Sparkles,
  Split,
  Square,
  Target,
  UserPlus,
  Video,
  Workflow,
} from 'lucide-react';

import {
  CONDITIONAL_ELSE_HANDLE,
  CONDITIONAL_FALSE_HANDLE,
  CONDITIONAL_GROUP_HANDLE_IDS,
  CONDITIONAL_MAX_GROUPS,
  CONDITIONAL_TRUE_HANDLE,
  DELAY_UNITS,
  LIST_ROW_HANDLE_IDS,
  MAX_LIST_ROWS,
  MAX_REPLY_BUTTONS,
  NUMERIC_CONDITIONAL_OPERATORS,
  REPLY_BUTTON_HANDLE_IDS,
  UNARY_CONDITIONAL_OPERATORS,
  type AnyJourneyNodeType,
  type ConditionalGroup,
  type ConditionalOperator,
  type JourneyNodeCapability,
  type JourneyNodeCategory,
  type JourneyNodeConfigErrors,
  type JourneyNodeField,
  type JourneyNodeHandle,
  type JourneyNodeProvider,
  type JourneyPaletteNodeType,
  type ListSection,
  type ReplyButton,
  type SubJourneyMode,
} from '../types/journeyNodes';

/**
 * Phase 5 — Journey / Automation: THE canonical Journey node registry.
 *
 * ===================================================================
 * WHY ONE REGISTRY
 * ===================================================================
 * Before this task, node metadata lived in three unrelated places that
 * had to be kept in step by hand: NODE_TYPE_LABELS in types/journey.ts,
 * NODE_TYPE_META (icon + colours) inside JourneyBuilderPage.tsx, and
 * WhatsAppFlow::NODE_TYPES on the backend. Adding 27 nodes across three
 * hand-maintained lists is how a node ends up in a palette with no
 * configuration contract behind it. Everything the frontend knows about
 * a node now comes from this one file; the palette, the canvas, the
 * config form and the validator all read it, and the backend's own
 * allow-list is asserted against it by test.
 *
 * ===================================================================
 * SCOPE: FOUNDATION ONLY
 * ===================================================================
 * Nothing here executes. No Graph API call, no queue, no delay worker,
 * no code evaluation, no AI call. These are contracts a later Journey
 * runtime task consumes.
 *
 * ===================================================================
 * PERSISTENCE: NO MIGRATION (inspected first)
 * ===================================================================
 * There are no journeys / journey_versions / journey_nodes / journey_runs
 * / journey_run_steps tables in this schema. The real, shipped model is
 * `whatsapp_flows` (one row per flow, with a `graph_data` JSON column
 * holding {nodes:[{id,type,position,data}], edges:[{id,source,target,...}]})
 * plus `whatsapp_flow_sessions` for in-flight runs. Because a node's
 * configuration is ALREADY free-form JSON on `data`, every contract in
 * types/journeyNodes.ts persists as-is. No schema limitation was found,
 * so no migration was written.
 *
 * ===================================================================
 * PROVIDER / CAPABILITY METADATA IS UX, NOT AUTHORIZATION
 * ===================================================================
 * `providers` and `capabilities` use the slugs already seeded in the
 * backend `providers` / `capabilities` tables (Phase1FoundationSeeder) —
 * no duplicate vocabulary is invented. They exist so the palette can
 * explain why a node will not run for this tenant yet.
 *
 * They are NOT a security boundary. Hiding or dimming a node in a
 * browser prevents nothing. A later execution task must independently
 * revalidate tenant -> entitlement -> capability -> provider -> node type
 * -> configuration on the server, exactly as the Public API paths
 * already do for sends.
 *
 * ===================================================================
 * CAPABILITY GAPS, DISCLOSED RATHER THAN INVENTED
 * ===================================================================
 * The seeded capability list is: whatsapp_send, whatsapp_groups, crm,
 * journey_automation, ads, social, ai. Three node families have no
 * seeded equivalent and therefore declare no capability rather than a
 * made-up one:
 *   - commerce (catalog, product) — no 'commerce' capability exists;
 *     they are tagged provider 'meta' (they are Cloud API features) and
 *     capability whatsapp_send.
 *   - payment — deliberately provider-independent per the brief, and no
 *     'payments' capability exists.
 *   - email / code / api — platform utilities with no seeded capability.
 * Adding capability rows is a backend decision, not something a frontend
 * registry should decide unilaterally.
 */

export interface JourneyNodeDefinition {
  type: AnyJourneyNodeType;
  label: string;
  description: string;
  category: JourneyNodeCategory;
  icon: ComponentType<{ className?: string; size?: number | string }>;
  color: string;
  background: string;
  /** Field descriptors: what the config form renders and what gets validated. */
  configSchema: JourneyNodeField[];
  /** Seeded capability slugs this node needs at runtime. UX metadata only. */
  capabilities?: JourneyNodeCapability[];
  /** Seeded provider slugs this node can run on. UX metadata only. */
  providers?: JourneyNodeProvider[];
  /**
   * Outgoing connection points. More than one = an explicitly branching
   * node. Almost always a fixed array. Task 23 — 'conditional' alone
   * uses the function form, so a legacy saved node (no `data.groups`)
   * keeps rendering its original 2 fixed TRUE/FALSE handles pixel-for-
   * pixel, and only a node the author has explicitly switched into
   * multi-branch mode grows the group_1..N + else handles — see
   * journeySourceHandles() below, the one place this is ever resolved.
   */
  sourceHandles: JourneyNodeHandle[] | ((data: Record<string, unknown>) => JourneyNodeHandle[]);
  /** False only for the entry node, which nothing may connect into. */
  hasTargetHandle: boolean;
  /** Seed written into graph_data when a node is dropped on the canvas. */
  defaultConfig: Record<string, unknown>;
  /** One-line summary shown on the node body once configured. */
  summarize?: (config: Record<string, unknown>) => string | null;
  /** Cross-field rules a single field descriptor cannot express. */
  validate?: (config: Record<string, unknown>) => JourneyNodeConfigErrors;
  /** True for the five pre-existing types: rendered, never offered in the palette. */
  legacy?: boolean;
}

// -------------------------------------------------------------------
// Shared helpers
// -------------------------------------------------------------------

const NEXT: JourneyNodeHandle[] = [{ id: 'next', label: 'Next' }];

/**
 * Phase 8 Task 16 — the classifier's 5 fixed branch slots (see
 * JourneyActionConfig::CLASSIFIER_BRANCH_HANDLES on the backend; these
 * ids must match exactly). A branch's label is per-instance DATA
 * (config.branches[i].label), not part of the handle itself — the
 * handle only fixes HOW MANY slots exist and their wire identity,
 * exactly like CONDITIONAL_TRUE_HANDLE/CONDITIONAL_FALSE_HANDLE do for
 * the 2-handle conditional node.
 */
const CLASSIFIER_BRANCH_HANDLE_IDS = ['branch_1', 'branch_2', 'branch_3', 'branch_4', 'branch_5'] as const;
const CLASSIFIER_HANDLES: JourneyNodeHandle[] = CLASSIFIER_BRANCH_HANDLE_IDS.map((id, i) => ({ id, label: `Branch ${i + 1}` }));


const str = (config: Record<string, unknown>, key: string): string =>
  typeof config[key] === 'string' ? (config[key] as string).trim() : '';

const arr = (config: Record<string, unknown>, key: string): unknown[] =>
  Array.isArray(config[key]) ? (config[key] as unknown[]) : [];

type ConditionalRuleLike = { variable?: string; operator?: ConditionalOperator; value?: string };

/**
 * Task 23 — the single-branch rule-list validation the 'conditional'
 * node has always applied (JourneyConditionEvaluator's own rules on the
 * backend), extracted so it validates ONE branch whether that branch is
 * the legacy single set of conditions or one group in multi-branch
 * mode — the engine evaluates every branch by this exact same logic
 * either way, so the two editors must agree on it too.
 */
const conditionGroupError = (conditions: ConditionalRuleLike[]): string | null => {
  if (conditions.length === 0) {
    return 'Add at least one condition.';
  }

  if (conditions.some((r) => !r.variable || !r.variable.trim())) {
    return 'Every condition needs a variable.';
  }

  if (conditions.some((r) => !r.operator)) {
    return 'Every condition needs an operator.';
  }

  const needsValue = (r: ConditionalRuleLike) => !!r.operator && !UNARY_CONDITIONAL_OPERATORS.includes(r.operator);

  if (conditions.some((r) => needsValue(r) && r.operator !== 'equals' && r.operator !== 'not_equals' && (r.value ?? '') === '')) {
    return 'Every condition needs a value (except "is set" / "is not set").';
  }

  if (
    conditions.some(
      (r) =>
        !!r.operator &&
        NUMERIC_CONDITIONAL_OPERATORS.includes(r.operator) &&
        ((r.value ?? '').trim() === '' || !Number.isFinite(Number((r.value ?? '').trim()))),
    )
  ) {
    return 'Number comparisons need a numeric value.';
  }

  return null;
};

const truncate = (value: string, max = 48): string =>
  value.length > max ? `${value.slice(0, max - 1)}…` : value;

/**
 * A URL is acceptable when it parses AND speaks http(s). A relative path
 * or a `javascript:` URL is not a media URL, and accepting either here
 * would push the problem to a server that has to reject it anyway.
 */
export function isHttpUrl(value: string): boolean {
  try {
    const parsed = new URL(value);

    return parsed.protocol === 'http:' || parsed.protocol === 'https:';
  } catch {
    return false;
  }
}

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/** `{{token}}` names referenced by a body of text. */
/**
 * Phase 8 Task 7 — an AI node's variable fields must be names the runtime
 * can store and `{{ }}` can read back (mirror of the backend's
 * JourneyActionConfig::VARIABLE_NAME_PATTERN). Empty is left to `required`.
 */
const AI_VARIABLE_NAME = /^[A-Za-z0-9_.-]{1,64}$/;

/** Phase 8 Task 10 — mirror of JourneyActionConfig::RAG_DEFAULT_TOP_K / config('ai.knowledge.max_results'). */
const RAG_DEFAULT_TOP_K = 3;
const RAG_MAX_TOP_K = 20;

function aiVariableErrors(config: Record<string, unknown>, keys: string[]): JourneyNodeConfigErrors {
  const errors: JourneyNodeConfigErrors = {};

  for (const key of keys) {
    const value = config[key];

    if (typeof value === 'string' && value.trim() !== '' && !AI_VARIABLE_NAME.test(value.trim())) {
      errors[key] = 'Use only letters, digits, _ . - (at most 64 characters).';
    }
  }

  return errors;
}

export function extractTemplateVariables(text: string): string[] {
  const found = new Set<string>();

  for (const match of text.matchAll(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g)) {
    found.add(match[1]);
  }

  return Array.from(found);
}

const mediaFields = (withCaption: boolean, extra: JourneyNodeField[] = []): JourneyNodeField[] => [
  {
    key: 'mediaUrl',
    label: 'Media URL',
    type: 'url',
    required: true,
    placeholder: 'https://cdn.example.com/file',
    help: 'A public http(s) link the WhatsApp provider can fetch.',
  },
  ...extra,
  ...(withCaption
    ? [{ key: 'caption', label: 'Caption', type: 'textarea' as const, placeholder: 'Optional caption' }]
    : []),
];

const mediaSummary = (config: Record<string, unknown>): string | null => {
  const url = str(config, 'mediaUrl');

  return url ? truncate(url.split('/').pop() || url) : null;
};

// ===================================================================
// 1. MESSAGE — 7
// ===================================================================

const MESSAGE_NODES: JourneyNodeDefinition[] = [
  {
    type: 'prompt',
    label: 'Prompt',
    description: 'Ask the AI and save its reply to a variable (uses AI credits).',
    category: 'message',
    icon: Sparkles,
    color: '#7c3aed',
    background: '#f5f3ff',
    capabilities: ['ai'],
    providers: ['none'],
    sourceHandles: NEXT,
    hasTargetHandle: true,
    defaultConfig: { prompt: '', outputVariable: 'ai_response' },
    configSchema: [
      { key: 'prompt', label: 'Prompt', type: 'textarea', required: true, help: 'Sent to the AI as written; {{variables}} are filled from this conversation. Nothing else from the chat is sent. Never put an API key here — credentials live server-side.' },
      { key: 'outputVariable', label: 'Save reply as', type: 'variable', required: true, placeholder: 'ai_response', help: 'Use it later as {{ai_response}} (letters, digits, _ . -).' },
      { key: 'model', label: 'Model hint', type: 'text', placeholder: 'Optional', help: 'Used only if your platform administrator allows that model; otherwise the default model runs.' },
    ],
    validate: (c): JourneyNodeConfigErrors => aiVariableErrors(c, ['outputVariable']),
    summarize: (c) => (str(c, 'prompt') ? truncate(str(c, 'prompt')) : null),
  },
  {
    type: 'text',
    label: 'Text',
    description: 'Standard text message, with {{variables}}.',
    category: 'message',
    icon: MessageSquare,
    color: '#2563eb',
    background: '#eff6ff',
    capabilities: ['whatsapp_send'],
    providers: ['qr', 'meta'],
    sourceHandles: NEXT,
    hasTargetHandle: true,
    defaultConfig: { text: '' },
    configSchema: [
      { key: 'text', label: 'Message', type: 'textarea', required: true, placeholder: 'Hi {{name}}, ...', help: 'Use {{variable}} for anything collected earlier in the journey.' },
    ],
    summarize: (c) => (str(c, 'text') ? truncate(str(c, 'text')) : null),
  },
  {
    type: 'image',
    label: 'Image',
    description: 'Send an image, with an optional caption.',
    category: 'message',
    icon: Image,
    color: '#0891b2',
    background: '#ecfeff',
    capabilities: ['whatsapp_send'],
    providers: ['qr', 'meta'],
    sourceHandles: NEXT,
    hasTargetHandle: true,
    defaultConfig: { mediaUrl: '' },
    configSchema: mediaFields(true),
    summarize: mediaSummary,
  },
  {
    type: 'video',
    label: 'Video',
    description: 'Send a video, with an optional caption.',
    category: 'message',
    icon: Video,
    color: '#0891b2',
    background: '#ecfeff',
    capabilities: ['whatsapp_send'],
    providers: ['qr', 'meta'],
    sourceHandles: NEXT,
    hasTargetHandle: true,
    defaultConfig: { mediaUrl: '' },
    configSchema: mediaFields(true),
    summarize: mediaSummary,
  },
  {
    type: 'document',
    label: 'Document',
    description: 'Send a PDF or other document attachment.',
    category: 'message',
    icon: FileText,
    color: '#0891b2',
    background: '#ecfeff',
    capabilities: ['whatsapp_send'],
    providers: ['qr', 'meta'],
    sourceHandles: NEXT,
    hasTargetHandle: true,
    defaultConfig: { mediaUrl: '' },
    configSchema: mediaFields(true, [
      { key: 'filename', label: 'Filename', type: 'text', placeholder: 'invoice.pdf' },
    ]),
    summarize: (c) => str(c, 'filename') || mediaSummary(c),
  },
  {
    type: 'audio',
    label: 'Audio',
    description: 'Send a voice note or audio file.',
    category: 'message',
    icon: Mic,
    color: '#0891b2',
    background: '#ecfeff',
    capabilities: ['whatsapp_send'],
    providers: ['qr', 'meta'],
    sourceHandles: NEXT,
    hasTargetHandle: true,
    defaultConfig: { mediaUrl: '' },
    configSchema: mediaFields(false),
    summarize: mediaSummary,
  },
  {
    type: 'sticker',
    label: 'Sticker',
    description: 'Send a WhatsApp sticker.',
    category: 'message',
    icon: Smile,
    color: '#0891b2',
    background: '#ecfeff',
    capabilities: ['whatsapp_send'],
    providers: ['qr', 'meta'],
    sourceHandles: NEXT,
    hasTargetHandle: true,
    defaultConfig: { mediaUrl: '' },
    configSchema: mediaFields(false),
    summarize: mediaSummary,
  },
];

// ===================================================================
// 2. INTERACTIVE — 6
// ===================================================================

const INTERACTIVE_NODES: JourneyNodeDefinition[] = [
  {
    type: 'list',
    label: 'List',
    description: 'WhatsApp interactive list — each row gets its own outgoing branch.',
    category: 'interactive',
    icon: List,
    color: '#7c3aed',
    background: '#f5f3ff',
    capabilities: ['whatsapp_send'],
    providers: ['meta'],
    // Task 24 — Connexxa parity: one handle PER ROW (flattened across
    // every section, in document order), instead of a single NEXT edge.
    // Mirrors 'conditional's own data-aware sourceHandles (nodeRegistry's
    // journeySourceHandles() is the one place this is ever resolved).
    // Position i always maps to LIST_ROW_HANDLE_IDS[i] — the SAME
    // mapping the backend's WhatsAppJourneyEngine::flattenListRows() /
    // JourneyActionConfig::LIST_ROW_HANDLES use when routing a reply.
    sourceHandles: (data) => {
      const sections = (Array.isArray(data.sections) ? data.sections : []) as ListSection[];
      const rows = sections.flatMap((s) => (Array.isArray(s.rows) ? s.rows : []));

      return rows.slice(0, MAX_LIST_ROWS).map((row, i) => ({
        id: LIST_ROW_HANDLE_IDS[i],
        label: row.title?.trim() || `Row ${i + 1}`,
      }));
    },
    hasTargetHandle: true,
    defaultConfig: { body: '', buttonText: 'Choose', sections: [] },
    configSchema: [
      { key: 'body', label: 'Body', type: 'textarea', required: true },
      { key: 'buttonText', label: 'Button text', type: 'text', required: true, placeholder: 'Choose' },
      { key: 'sections', label: 'Sections', type: 'sections', required: true, help: 'At least one section, each with at least one row.' },
    ],
    validate: (c): JourneyNodeConfigErrors => {
      // WhatsApp's own interactive-list limits (Cloud API) — not a UI
      // preference. See ListConfigModal.tsx, which caps input to the
      // same numbers so a journey can't get here over the limit.
      const sections = arr(c, 'sections') as { title?: string; rows?: { title?: string; description?: string }[] }[];

      if (str(c, 'buttonText').length > 20) {
        return { buttonText: 'WhatsApp allows at most 20 characters for the list button text.' };
      }

      if (sections.length === 0) {
        return { sections: 'Add at least one section.' };
      }

      if (sections.length > 10) {
        return { sections: `WhatsApp allows at most 10 sections (this has ${sections.length}).` };
      }

      if (sections.some((s) => !Array.isArray(s.rows) || s.rows.length === 0)) {
        return { sections: 'Every section needs at least one row.' };
      }

      const totalRows = sections.reduce((sum, s) => sum + (Array.isArray(s.rows) ? s.rows.length : 0), 0);

      if (totalRows > 10) {
        return { sections: `WhatsApp allows at most 10 rows total across all sections (this has ${totalRows}).` };
      }

      for (const s of sections) {
        if ((s.title ?? '').length > 24) {
          return { sections: 'Every section title must be 24 characters or fewer.' };
        }

        for (const r of s.rows ?? []) {
          if ((r.title ?? '').length > 24) {
            return { sections: 'Every row title must be 24 characters or fewer.' };
          }

          if ((r.description ?? '').length > 72) {
            return { sections: 'Every row description must be 72 characters or fewer.' };
          }
        }
      }

      return {};
    },
    summarize: (c) => {
      const rows = (arr(c, 'sections') as { rows?: unknown[] }[]).reduce(
        (total, s) => total + (Array.isArray(s.rows) ? s.rows.length : 0),
        0,
      );

      return rows ? `${rows} row${rows === 1 ? '' : 's'}` : null;
    },
  },
  {
    type: 'external_url',
    label: 'External URL',
    description: 'Message with a link/CTA button to an external URL.',
    category: 'interactive',
    icon: ExternalLink,
    color: '#7c3aed',
    background: '#f5f3ff',
    capabilities: ['whatsapp_send'],
    providers: ['meta'],
    sourceHandles: NEXT,
    hasTargetHandle: true,
    defaultConfig: { buttonText: '', url: '' },
    configSchema: [
      { key: 'body', label: 'Body', type: 'textarea' },
      { key: 'buttonText', label: 'Button text', type: 'text', required: true },
      { key: 'url', label: 'URL', type: 'url', required: true, placeholder: 'https://example.com' },
    ],
    summarize: (c) => (str(c, 'url') ? truncate(str(c, 'url')) : null),
  },
  {
    type: 'reply_button',
    label: 'Reply Buttons',
    description: `Quick reply buttons — at most ${MAX_REPLY_BUTTONS}, each its own outgoing branch.`,
    category: 'interactive',
    icon: MousePointerClick,
    color: '#7c3aed',
    background: '#f5f3ff',
    capabilities: ['whatsapp_send'],
    providers: ['meta'],
    // Task 24 — Connexxa parity: one handle PER BUTTON instead of a
    // single NEXT edge. Same journeySourceHandles() mechanism as
    // 'list'/'conditional' above. Position i maps to
    // REPLY_BUTTON_HANDLE_IDS[i] — the SAME mapping the backend's
    // JourneyActionConfig::REPLY_BUTTON_HANDLES uses when routing a reply.
    sourceHandles: (data) => {
      const buttons = (Array.isArray(data.buttons) ? data.buttons : []) as ReplyButton[];

      return buttons.slice(0, MAX_REPLY_BUTTONS).map((button, i) => ({
        id: REPLY_BUTTON_HANDLE_IDS[i],
        label: button.title?.trim() || `Button ${i + 1}`,
      }));
    },
    hasTargetHandle: true,
    defaultConfig: { body: '', buttons: [] },
    configSchema: [
      { key: 'body', label: 'Body', type: 'textarea', required: true },
      { key: 'buttons', label: 'Buttons', type: 'buttons', required: true, maxItems: MAX_REPLY_BUTTONS, help: `WhatsApp allows at most ${MAX_REPLY_BUTTONS}.` },
    ],
    validate: (c): JourneyNodeConfigErrors => {
      const buttons = arr(c, 'buttons') as { title?: string }[];

      if (buttons.length === 0) {
        return { buttons: 'Add at least one button.' };
      }

      if (buttons.length > MAX_REPLY_BUTTONS) {
        return { buttons: `WhatsApp allows at most ${MAX_REPLY_BUTTONS} reply buttons.` };
      }

      if (buttons.some((b) => !b.title || !b.title.trim())) {
        return { buttons: 'Every button needs a label.' };
      }

      return {};
    },
    summarize: (c) => {
      const n = arr(c, 'buttons').length;

      return n ? `${n} button${n === 1 ? '' : 's'}` : null;
    },
  },
  {
    type: 'location',
    label: 'Location',
    description: 'Share a static location.',
    category: 'interactive',
    icon: MapPin,
    color: '#0d9488',
    background: '#f0fdfa',
    capabilities: ['whatsapp_send'],
    providers: ['qr', 'meta'],
    sourceHandles: NEXT,
    hasTargetHandle: true,
    defaultConfig: { latitude: 0, longitude: 0 },
    configSchema: [
      { key: 'latitude', label: 'Latitude', type: 'number', required: true, min: -90, max: 90 },
      { key: 'longitude', label: 'Longitude', type: 'number', required: true, min: -180, max: 180 },
      { key: 'name', label: 'Name', type: 'text' },
      { key: 'address', label: 'Address', type: 'text' },
    ],
    summarize: (c) => str(c, 'name') || null,
  },
  {
    type: 'location_request',
    label: 'Request Location',
    description: "Ask the customer to share their location.",
    category: 'interactive',
    icon: Navigation,
    color: '#0d9488',
    background: '#f0fdfa',
    capabilities: ['whatsapp_send'],
    providers: ['meta'],
    sourceHandles: NEXT,
    hasTargetHandle: true,
    defaultConfig: { body: '' },
    configSchema: [{ key: 'body', label: 'Body', type: 'textarea', required: true }],
    summarize: (c) => (str(c, 'body') ? truncate(str(c, 'body')) : null),
  },
  {
    type: 'address_request',
    label: 'Request Address',
    description: 'WhatsApp native address collection.',
    category: 'interactive',
    icon: Home,
    color: '#0d9488',
    background: '#f0fdfa',
    capabilities: ['whatsapp_send'],
    providers: ['meta'],
    sourceHandles: NEXT,
    hasTargetHandle: true,
    defaultConfig: { body: '' },
    configSchema: [{ key: 'body', label: 'Body', type: 'textarea', required: true }],
    summarize: (c) => (str(c, 'body') ? truncate(str(c, 'body')) : null),
  },
];

// ===================================================================
// 3. ADVANCED — 10
// ===================================================================

const ADVANCED_NODES: JourneyNodeDefinition[] = [
  {
    type: 'flow',
    label: 'WhatsApp Flow',
    description: 'Launch a Meta-native WhatsApp Flow.',
    category: 'advanced',
    icon: Workflow,
    color: '#c026d3',
    background: '#fdf4ff',
    capabilities: ['whatsapp_send'],
    providers: ['meta'],
    sourceHandles: NEXT,
    hasTargetHandle: true,
    defaultConfig: { flowId: '', flowCta: '', screenName: '' },
    configSchema: [
      { key: 'flowId', label: 'Meta Flow ID', type: 'text', required: true },
      { key: 'flowCta', label: 'Button label (CTA)', type: 'text', required: true, help: "Shown on the button that opens the Flow — Meta's flow_cta." },
      { key: 'screenName', label: 'Starting screen', type: 'text', required: true, help: 'The first screen id to open in the Flow, as defined in Meta Flow Builder.' },
      { key: 'body', label: 'Body', type: 'textarea' },
    ],
    summarize: (c) => str(c, 'flowId') || null,
  },
  {
    type: 'api',
    label: 'API Request',
    description: 'Call an external REST endpoint (GET/POST).',
    category: 'advanced',
    icon: Globe,
    color: '#ea580c',
    background: '#fff7ed',
    // Phase 5 Task 7: capability seeded; platform provider (no WhatsApp engine involved).
    capabilities: ['external_api'],
    providers: ['none'],
    sourceHandles: NEXT,
    hasTargetHandle: true,
    defaultConfig: { method: 'GET', url: '' },
    configSchema: [
      // Phase 8 Task 15 — "Manage All APIs": pick a saved connection to
      // reuse its base URL/headers/query instead of entering them below.
      // A REAL, authorized reference (checked on save by
      // WhatsAppFlowController::assertApiConnectionsOwned()) — unlike the
      // pre-existing `credentialRef` text field below, which names
      // nothing and resolves nothing yet.
      { key: 'apiConnectionId', label: 'Saved API Connection', type: 'apiConnection', help: 'Optional — fills this node from a connection saved in "Manage API Connections".' },
      { key: 'method', label: 'Method', type: 'select', required: true, options: [{ value: 'GET', label: 'GET' }, { value: 'POST', label: 'POST' }] },
      { key: 'url', label: 'URL', type: 'url', required: true, placeholder: 'https://api.example.com/v1/thing' },
      { key: 'headers', label: 'Headers', type: 'keyvalue', help: 'Values of credential-like headers (e.g. Authorization) are encrypted on save and never shown again — leave a saved value blank to keep it.' },
      { key: 'query', label: 'Query parameters', type: 'keyvalue', help: 'Values of credential-like parameters are encrypted on save and never shown again.' },
      { key: 'body', label: 'Request body', type: 'textarea' },
      { key: 'credentialRef', label: 'Server credential', type: 'text', help: 'Names a credential configured on the server. The secret itself never reaches this form.' },
      { key: 'responseMapping', label: 'Response mapping', type: 'keyvalue', help: 'variable = json.path' },
    ],
    validate: (c): JourneyNodeConfigErrors => {
      const method = str(c, 'method');

      return method === 'GET' || method === 'POST' ? {} : { method: 'Choose GET or POST.' };
    },
    summarize: (c) => (str(c, 'url') ? `${str(c, 'method') || 'GET'} ${truncate(str(c, 'url'), 32)}` : null),
  },
  {
    type: 'payment',
    label: 'Payment',
    description: 'Collect a payment. Gateway-independent configuration.',
    category: 'advanced',
    icon: CreditCard,
    color: '#ea580c',
    background: '#fff7ed',
    // Phase 5 Task 7: capability seeded; platform provider (no WhatsApp engine involved).
    capabilities: ['payments'],
    providers: ['none'],
    sourceHandles: NEXT,
    hasTargetHandle: true,
    defaultConfig: { amount: 0, currency: 'INR' },
    configSchema: [
      { key: 'amount', label: 'Amount', type: 'number', required: true, min: 0 },
      { key: 'currency', label: 'Currency', type: 'text', required: true, placeholder: 'INR' },
      { key: 'description', label: 'Description', type: 'text' },
      { key: 'gatewayRef', label: 'Gateway configuration', type: 'text', help: 'Names a gateway configured on the server. No key or secret is stored on the node.' },
    ],
    validate: (c): JourneyNodeConfigErrors => (Number(c.amount) > 0 ? {} : { amount: 'Amount must be greater than 0.' }),
    summarize: (c) => (Number(c.amount) > 0 ? `${str(c, 'currency') || ''} ${c.amount}`.trim() : null),
  },
  {
    type: 'template',
    label: 'Template',
    description: 'Send an approved Meta WhatsApp template.',
    category: 'advanced',
    icon: LayoutTemplate,
    color: '#c026d3',
    background: '#fdf4ff',
    capabilities: ['whatsapp_send'],
    providers: ['meta'],
    sourceHandles: NEXT,
    hasTargetHandle: true,
    defaultConfig: { templateId: '' },
    configSchema: [
      { key: 'templateId', label: 'Template', type: 'text', required: true, help: 'Resolved against the existing Meta template system (message_templates).' },
      { key: 'variables', label: 'Variables', type: 'keyvalue' },
    ],
    summarize: (c) => str(c, 'templateId') || null,
  },
  {
    type: 'conditional',
    label: 'Conditional',
    description: 'Branch on a condition. TRUE/FALSE by default, or add ELSE IF branches for up to 5 outcomes + ELSE.',
    category: 'advanced',
    icon: GitBranch,
    color: '#d97706',
    background: '#fffbeb',
    // Phase 5 Task 7: pure control flow. 'none' declares "no WhatsApp
    // engine required", so this runs on every account — it does NOT
    // mean "only accounts without an engine". See
    // JourneyNodeCatalog::PLATFORM_PROVIDER on the backend.
    providers: ['none'],
    // Task 23 — Connexxa IF/ELSE-IF/ELSE parity. A legacy node (no
    // `data.groups`) keeps its original 2 fixed TRUE/FALSE handles,
    // pixel-for-pixel; only once the author explicitly adds an ELSE IF
    // branch (nodeRegistry.tsx's 'conditionGroups' field) does this grow
    // group_1..N + a trailing 'else'. See journeySourceHandles() and
    // ConditionalNodeConfig's docblock.
    sourceHandles: (data) => {
      const groups = Array.isArray(data.groups) ? (data.groups as ConditionalGroup[]) : [];

      if (groups.length === 0) {
        return [
          { id: CONDITIONAL_TRUE_HANDLE, label: 'TRUE' },
          { id: CONDITIONAL_FALSE_HANDLE, label: 'FALSE' },
        ];
      }

      return [
        ...groups.map((_, i) => ({ id: CONDITIONAL_GROUP_HANDLE_IDS[i], label: i === 0 ? 'IF' : `ELSE IF ${i}` })),
        { id: CONDITIONAL_ELSE_HANDLE, label: 'ELSE' },
      ];
    },
    hasTargetHandle: true,
    defaultConfig: { conditions: [], match: 'all' },
    configSchema: [
      { key: 'conditions', label: 'Branches', type: 'conditionGroups', required: true },
    ],
    validate: (c): JourneyNodeConfigErrors => {
      const groups = Array.isArray(c.groups) ? (c.groups as ConditionalGroup[]) : [];

      if (groups.length === 0) {
        const error = conditionGroupError(arr(c, 'conditions') as ConditionalRuleLike[]);

        return error ? { conditions: error } : {};
      }

      if (groups.length > CONDITIONAL_MAX_GROUPS) {
        return { conditions: `A Conditional node may have at most ${CONDITIONAL_MAX_GROUPS} branches.` };
      }

      for (const group of groups) {
        const error = conditionGroupError((group.conditions ?? []) as ConditionalRuleLike[]);

        if (error) return { conditions: error };
      }

      return {};
    },
    summarize: (c) => {
      const groups = Array.isArray(c.groups) ? (c.groups as ConditionalGroup[]) : [];

      if (groups.length === 0) {
        const n = arr(c, 'conditions').length;

        return n ? `${n} condition${n === 1 ? '' : 's'}` : null;
      }

      return `${groups.length} branch${groups.length === 1 ? '' : 'es'} + ELSE`;
    },
  },
  {
    type: 'catalog',
    label: 'Catalog',
    description: 'Send a single or multi-product catalog message.',
    category: 'advanced',
    icon: ShoppingBag,
    color: '#c026d3',
    background: '#fdf4ff',
    // Phase 5 Task 7: needs BOTH the commerce entitlement and the
    // ability to send a WhatsApp message to deliver it.
    capabilities: ['whatsapp_send', 'commerce'],
    providers: ['meta'],
    sourceHandles: NEXT,
    hasTargetHandle: true,
    defaultConfig: { catalogId: '' },
    configSchema: [
      { key: 'catalogId', label: 'Catalog ID', type: 'text', required: true },
      { key: 'productIds', label: 'Product IDs', type: 'strings', help: 'One product ID per row.' },
      { key: 'body', label: 'Body', type: 'textarea' },
    ],
    summarize: (c) => str(c, 'catalogId') || null,
  },
  {
    type: 'product',
    label: 'Product',
    description: 'Send a single product detail message.',
    category: 'advanced',
    icon: Package,
    color: '#c026d3',
    background: '#fdf4ff',
    // Phase 5 Task 7: needs BOTH the commerce entitlement and the
    // ability to send a WhatsApp message to deliver it.
    capabilities: ['whatsapp_send', 'commerce'],
    providers: ['meta'],
    sourceHandles: NEXT,
    hasTargetHandle: true,
    defaultConfig: { productId: '' },
    configSchema: [
      { key: 'productId', label: 'Product ID', type: 'text', required: true },
      { key: 'catalogId', label: 'Catalog ID', type: 'text' },
      { key: 'body', label: 'Body', type: 'textarea' },
    ],
    summarize: (c) => str(c, 'productId') || null,
  },
  {
    type: 'agent',
    label: 'AI Agent',
    description: 'A registered AI agent (optionally with tools) or one bounded AI reply from instructions (uses AI credits).',
    category: 'advanced',
    icon: Bot,
    color: '#7c3aed',
    background: '#f5f3ff',
    capabilities: ['ai'],
    providers: ['none'],
    sourceHandles: NEXT,
    hasTargetHandle: true,
    defaultConfig: { agentId: '', registeredAgentId: '', outputVariable: 'agent_response' },
    configSchema: [
      { key: 'registeredAgentId', label: 'Registered agent', type: 'aiAgent', help: "Only this account's AI agents are offered; the server checks ownership again. Leave empty for a single bounded reply without tools." },
      { key: 'agentId', label: 'Label', type: 'text', help: 'A name shown on the canvas. Agent credentials are resolved server-side and never stored on the node.' },
      { key: 'instructions', label: 'Instructions', type: 'textarea', required: true, requiredUnless: 'registeredAgentId', help: '{{variables}} are filled from this conversation. Required without a registered agent; with one, an optional task for it.' },
      { key: 'inputVariable', label: 'Input variable', type: 'variable', placeholder: 'Optional — e.g. an answer collected earlier' },
      { key: 'outputVariable', label: 'Save reply as', type: 'variable', required: true, placeholder: 'agent_response' },
    ],
    validate: (c): JourneyNodeConfigErrors => {
      const errors: JourneyNodeConfigErrors = aiVariableErrors(c, ['inputVariable', 'outputVariable']);
      const registered = c.registeredAgentId;
      const hasRegistered = registered !== undefined && registered !== null && String(registered).trim() !== '';

      if (hasRegistered && !/^[1-9][0-9]*$/.test(String(registered).trim())) {
        errors.registeredAgentId = 'Choose one of your AI agents.';
      }

      return errors;
    },
    summarize: (c) => str(c, 'agentId') || (str(c, 'registeredAgentId') ? `AI agent #${str(c, 'registeredAgentId')}` : null),
  },
  {
    type: 'rag',
    label: 'Knowledge Base',
    description: 'Answer from one of your knowledge bases and save the reply to a variable (uses AI credits).',
    category: 'advanced',
    icon: Database,
    color: '#7c3aed',
    background: '#f5f3ff',
    capabilities: ['ai'],
    providers: ['none'],
    sourceHandles: NEXT,
    hasTargetHandle: true,
    defaultConfig: { knowledgeBaseId: '', topK: RAG_DEFAULT_TOP_K, outputVariable: 'rag_answer' },
    configSchema: [
      { key: 'knowledgeBaseId', label: 'Knowledge base', type: 'knowledgeBase', required: true, help: "Only this account's knowledge bases are offered; the server checks ownership again." },
      { key: 'queryVariable', label: 'Question variable', type: 'variable', required: true, placeholder: 'e.g. an answer collected earlier', help: 'The customer question to search for.' },
      { key: 'topK', label: 'Passages to use (top K)', type: 'number', min: 1, max: RAG_MAX_TOP_K },
      { key: 'outputVariable', label: 'Save answer as', type: 'variable', required: true, placeholder: 'rag_answer', help: 'Empty when nothing relevant is found — branch on it with a Conditional node.' },
    ],
    validate: (c): JourneyNodeConfigErrors => {
      const errors: JourneyNodeConfigErrors = aiVariableErrors(c, ['queryVariable', 'outputVariable']);
      const kb = c.knowledgeBaseId;

      if (kb !== undefined && kb !== null && kb !== '' && !/^[1-9][0-9]*$/.test(String(kb).trim())) {
        errors.knowledgeBaseId = 'Choose one of your knowledge bases.';
      }

      if (c.topK !== undefined && c.topK !== null && c.topK !== '') {
        const topK = Number(c.topK);

        if (!Number.isInteger(topK) || topK < 1 || topK > RAG_MAX_TOP_K) {
          errors.topK = `Top K must be a whole number between 1 and ${RAG_MAX_TOP_K}.`;
        }
      }

      return errors;
    },
    summarize: (c) => (str(c, 'knowledgeBaseId') ? `Knowledge base #${str(c, 'knowledgeBaseId')}` : null),
  },
  {
    type: 'human_intervention',
    label: 'Human Handover',
    description: 'Transfer the conversation to a live agent.',
    category: 'advanced',
    icon: Headphones,
    color: '#dc2626',
    background: '#fef2f2',
    capabilities: ['crm'],
    providers: ['none'],
    sourceHandles: NEXT,
    hasTargetHandle: true,
    defaultConfig: {},
    configSchema: [
      { key: 'queueId', label: 'Queue', type: 'text' },
      { key: 'message', label: 'Handover message', type: 'textarea' },
    ],
    summarize: (c) => str(c, 'queueId') || null,
  },
  {
    type: 'classifier',
    label: 'Classifier',
    description: 'Route to one of up to 5 branches by classifying an input with AI (uses AI credits).',
    category: 'advanced',
    icon: Split,
    color: '#7c3aed',
    background: '#f5f3ff',
    capabilities: ['ai'],
    providers: ['none'],
    // Branch identity is the handle id, never the position on the canvas —
    // same contract as the conditional node's TRUE/FALSE handles above.
    sourceHandles: CLASSIFIER_HANDLES,
    hasTargetHandle: true,
    defaultConfig: { inputVariable: '', outputVariable: 'classifier_branch', branches: [{ label: '' }, { label: '' }] },
    configSchema: [
      { key: 'inputVariable', label: 'Input variable', type: 'variable', required: true, placeholder: 'e.g. an answer collected earlier' },
      { key: 'outputVariable', label: 'Save matched branch as', type: 'variable', required: true, placeholder: 'classifier_branch' },
      { key: 'branches', label: 'Branches', type: 'classifierBranches', required: true, help: 'Up to 5. Each branch here is positionally wired to one of this node\'s 5 outgoing connectors.' },
    ],
    validate: (c): JourneyNodeConfigErrors => {
      const errors: JourneyNodeConfigErrors = aiVariableErrors(c, ['inputVariable', 'outputVariable']);
      const branches = arr(c, 'branches') as { label?: string }[];

      if (branches.length === 0) {
        errors.branches = 'Add at least one branch.';
      } else if (branches.length > CLASSIFIER_BRANCH_HANDLE_IDS.length) {
        errors.branches = `A Classifier node may have at most ${CLASSIFIER_BRANCH_HANDLE_IDS.length} branches.`;
      } else if (branches.some((b) => !b.label || !b.label.trim())) {
        errors.branches = 'Every branch needs a label.';
      }

      return errors;
    },
    summarize: (c) => {
      const branches = arr(c, 'branches') as { label?: string }[];

      return branches.length ? `${branches.length} branch${branches.length === 1 ? '' : 'es'}` : null;
    },
  },
];

// ===================================================================
// 4. UTILITY — 4
// ===================================================================

const UTILITY_NODES: JourneyNodeDefinition[] = [
  {
    type: 'code',
    label: 'Code',
    // Task 26 — Connexxa parity, scoped: NOT real JavaScript. Executing
    // arbitrary journey-author script on this server is a real
    // sandbox-escape/RCE surface this platform should not carry (no
    // embedded JS engine is reliably available on a stock XAMPP
    // install, and shelling to Node.js is worse, not better) — see
    // the backend's JourneyCodeSandbox docblock for the full reasoning,
    // confirmed with the account owner before this was built. Instead
    // this runs a tiny, closed expression language of this platform's
    // own.
    description: "Compute a value from this journey's variables — not JavaScript; a small built-in expression language (see the editor's own help text).",
    category: 'utility',
    icon: Code,
    color: '#475569',
    background: '#f8fafc',
    // Phase 5 Task 7: capability seeded; platform provider (no WhatsApp engine involved).
    capabilities: ['custom_code'],
    providers: ['none'],
    sourceHandles: NEXT,
    hasTargetHandle: true,
    defaultConfig: { code: '', outputVariable: '' },
    configSchema: [
      {
        key: 'code',
        label: 'Code',
        type: 'code',
        required: true,
        help:
          "Not JavaScript. A small expression language: let x = 1; if (var_local.score >= 50) { output = 'pass'; } else { output = 'fail'; } " +
          'Supports: let/assignment, if/else, numbers, strings, true/false/null, + - * / %, == != < <= > >=, && || !, ?: — ' +
          'and var_local.<path> / var_system.<path> to read this journey\'s own variables. No loops, no function calls.',
      },
      {
        key: 'outputVariable',
        label: 'Output variable',
        type: 'variable',
        required: true,
        help: "The value of the script's last `output = ...;` assignment is stored here (empty if the script never sets one).",
      },
    ],
    summarize: (c) => {
      const lines = str(c, 'code').split('\n').filter(Boolean).length;

      return lines ? `${lines} line${lines === 1 ? '' : 's'}` : null;
    },
  },
  {
    type: 'email',
    label: 'Email',
    description: 'Send an email notification.',
    category: 'utility',
    icon: Mail,
    color: '#475569',
    background: '#f8fafc',
    // Phase 5 Task 7: capability seeded; platform provider (no WhatsApp engine involved).
    capabilities: ['email'],
    providers: ['none'],
    sourceHandles: NEXT,
    hasTargetHandle: true,
    defaultConfig: { to: '', subject: '', body: '' },
    configSchema: [
      { key: 'to', label: 'To', type: 'text', required: true, placeholder: 'ops@example.com', help: 'SMTP credentials come from the server configuration, never from this node.' },
      { key: 'subject', label: 'Subject', type: 'text', required: true },
      { key: 'body', label: 'Body', type: 'textarea', required: true },
    ],
    validate: (c): JourneyNodeConfigErrors => {
      const to = str(c, 'to');

      if (!to) {
        return {};
      }

      // A {{variable}} recipient is resolved at runtime and cannot be
      // pattern-checked here; a literal address must still look like one.
      if (to.includes('{{')) {
        return {};
      }

      return EMAIL_RE.test(to) ? {} : { to: 'Enter a valid email address.' };
    },
    summarize: (c) => str(c, 'subject') || str(c, 'to') || null,
  },
  {
    type: 'journey',
    label: 'Sub-journey',
    description: 'Jump into another journey — Static (pick a journey, optionally a specific node) or Dynamic (runtime-variable-bound journey/node IDs), then continue.',
    category: 'utility',
    icon: Route,
    color: '#475569',
    background: '#f8fafc',
    capabilities: ['journey_automation'],
    providers: ['none'],
    sourceHandles: NEXT,
    hasTargetHandle: true,
    // Task 25 — Connexxa parity. Absent `mode` is the pre-Task-25 shape
    // (static, default entry) — fully backward compatible. All 5
    // fields below are always rendered (configSchema has no
    // conditional-visibility mechanism — see JourneyNodeField; every
    // other multi-mode node in this registry, e.g. 'agent', follows
    // the same always-show-every-field convention), each one's help
    // text naming which mode it applies to.
    defaultConfig: { mode: 'static', journeyId: '', startNodeId: '', journeyIdTemplate: '', nodeIdTemplate: '' },
    configSchema: [
      {
        key: 'mode',
        label: 'Jump mode',
        type: 'select',
        options: [
          { value: 'static', label: 'Static — pick a journey' },
          { value: 'dynamic', label: 'Dynamic — journey/node ID from a variable' },
        ],
        help: 'Static always jumps to the same journey. Dynamic resolves the target at run time from {{ variable }} expressions.',
      },
      {
        key: 'journeyId',
        label: 'Journey',
        type: 'subJourney',
        help: 'Static mode only. The journey to run.',
      },
      {
        key: 'startNodeId',
        label: 'Start node id (optional)',
        type: 'text',
        help: "Static mode only. Leave empty to start at the journey's own default entry. Enter a specific node's id to jump straight to it.",
      },
      {
        key: 'journeyIdTemplate',
        label: 'Journey ID expression',
        type: 'text',
        placeholder: '{{ var_local.targetJourneyId }}',
        help: 'Dynamic mode only. A {{ variable }} expression resolving to the target journey\'s id at run time.',
      },
      {
        key: 'nodeIdTemplate',
        label: 'Node ID expression',
        type: 'text',
        placeholder: '{{ var_local.targetNodeId }}',
        help: 'Dynamic mode only. A {{ variable }} expression resolving to the target node id at run time.',
      },
    ],
    validate: (c): JourneyNodeConfigErrors => {
      const mode: SubJourneyMode = str(c, 'mode') === 'dynamic' ? 'dynamic' : 'static';

      if (mode === 'dynamic') {
        return str(c, 'journeyIdTemplate') ? {} : { journeyIdTemplate: 'Enter a journey ID expression, or switch to Static mode.' };
      }

      return str(c, 'journeyId') ? {} : { journeyId: 'Pick a journey, or switch to Dynamic mode.' };
    },
    summarize: (c) => {
      if (str(c, 'mode') === 'dynamic') {
        return str(c, 'journeyIdTemplate') ? `→ ${str(c, 'journeyIdTemplate')}` : null;
      }

      return str(c, 'journeyId') || null;
    },
  },
  {
    type: 'end',
    label: 'End',
    description: 'Ends the conversation. Canvas-only — never sent to the server (see EndNodeConfig).',
    category: 'utility',
    icon: Square,
    color: '#475569',
    background: '#f8fafc',
    providers: ['none'],
    // Terminal: nothing may connect out of an End node.
    sourceHandles: [],
    hasTargetHandle: true,
    defaultConfig: { body: 'Thank you for connecting with us.' },
    configSchema: [
      { key: 'body', label: 'End Message', type: 'textarea', required: true },
    ],
    summarize: (c) => (str(c, 'body') ? truncate(str(c, 'body')) : null),
  },
  {
    type: 'delay',
    label: 'Delay',
    description: 'Wait before continuing.',
    category: 'utility',
    icon: Clock,
    color: '#475569',
    background: '#f8fafc',
    // Phase 5 Task 7: pure control flow. 'none' declares "no WhatsApp
    // engine required", so this runs on every account — it does NOT
    // mean "only accounts without an engine". See
    // JourneyNodeCatalog::PLATFORM_PROVIDER on the backend.
    providers: ['none'],
    sourceHandles: NEXT,
    hasTargetHandle: true,
    defaultConfig: { amount: 1, unit: 'minutes' },
    configSchema: [
      { key: 'amount', label: 'Amount', type: 'number', required: true, min: 1 },
      { key: 'unit', label: 'Unit', type: 'select', required: true, options: DELAY_UNITS.map((u) => ({ value: u, label: u })) },
    ],
    validate: (c): JourneyNodeConfigErrors => {
      const errors: JourneyNodeConfigErrors = {};
      const amount = Number(c.amount);

      if (!Number.isFinite(amount) || amount <= 0) {
        errors.amount = 'Delay must be greater than 0.';
      }

      if (!DELAY_UNITS.includes(str(c, 'unit') as never)) {
        errors.unit = `Unit must be one of: ${DELAY_UNITS.join(', ')}.`;
      }

      return errors;
    },
    summarize: (c) => (Number(c.amount) > 0 ? `${c.amount} ${str(c, 'unit')}` : null),
  },
];

// ===================================================================
// LEGACY — the five types saved journeys already contain
// ===================================================================

/**
 * Registered so an existing flow still renders with a proper icon,
 * label and handles. `legacy: true` keeps them out of the new palette:
 * 'text' supersedes 'message' and 'conditional' supersedes 'condition',
 * but nothing rewrites a saved flow, and the engine still executes these
 * exact types.
 */
const LEGACY_NODES: JourneyNodeDefinition[] = [
  {
    type: 'trigger',
    // Display-only rename to "Start" (matches the reference builder this
    // card mirrors) — the `type` stays the literal string 'trigger'
    // unchanged: HasJourneyGraph::entryNode() and WhatsAppJourneyEngine
    // both find the entry node by type === 'trigger' (backend, not
    // touched here), so renaming the type string would break every
    // existing saved flow's entry-point resolution.
    label: 'Start',
    description: 'Where the journey starts.',
    category: 'utility',
    icon: PlayCircle,
    color: '#7c3aed',
    background: '#f5f3ff',
    providers: ['none'],
    sourceHandles: NEXT,
    hasTargetHandle: false,
    defaultConfig: {},
    configSchema: [],
    legacy: true,
  },
  {
    type: 'message',
    label: 'Send Message',
    description: 'Legacy text message node. New journeys use Text.',
    category: 'message',
    icon: MessageSquare,
    color: '#2563eb',
    background: '#eff6ff',
    capabilities: ['whatsapp_send'],
    providers: ['qr', 'meta'],
    sourceHandles: NEXT,
    hasTargetHandle: true,
    defaultConfig: { text: '' },
    configSchema: [{ key: 'text', label: 'Message', type: 'textarea', required: true }],
    summarize: (c) => (str(c, 'text') ? truncate(str(c, 'text')) : null),
    legacy: true,
  },
  {
    type: 'question',
    label: 'Ask Question',
    description: 'Legacy question node with text/button/list input.',
    category: 'interactive',
    icon: MessageCircle,
    color: '#0891b2',
    background: '#ecfeff',
    capabilities: ['whatsapp_send'],
    providers: ['qr', 'meta'],
    sourceHandles: NEXT,
    hasTargetHandle: true,
    defaultConfig: {},
    configSchema: [
      { key: 'prompt_text', label: 'Question', type: 'textarea', required: true },
      { key: 'variable_name', label: 'Save answer as', type: 'variable', required: true },
    ],
    // Phase 7 Task 5 — mirrors backend JourneyActionConfig: a button or
    // list question the engine would refuse (no options) is caught here,
    // before the save publishes it.
    validate: (c): JourneyNodeConfigErrors => {
      const inputType = str(c, 'input_type') || 'text';

      if (inputType === 'text') {
        return {};
      }

      const options = arr(c, 'options') as { id?: string; title?: string }[];

      if (options.length === 0) {
        return { options: 'Add at least one option for a button or list question.' };
      }

      if (options.some((o) => !(o.id ?? '').trim() && !(o.title ?? '').trim())) {
        return { options: 'Every option needs a title.' };
      }

      return {};
    },
    summarize: (c) => (str(c, 'prompt_text') ? truncate(str(c, 'prompt_text')) : null),
    legacy: true,
  },
  {
    type: 'condition',
    label: 'Condition',
    description: 'Legacy branching node. New journeys use Conditional.',
    category: 'advanced',
    icon: Target,
    color: '#d97706',
    background: '#fffbeb',
    providers: ['none'],
    // Legacy branching is carried on the EDGE (edge.condition /
    // edge.is_default), not on a source handle — preserved exactly, so
    // saved flows keep working.
    sourceHandles: NEXT,
    hasTargetHandle: true,
    defaultConfig: {},
    configSchema: [{ key: 'variable', label: 'Variable', type: 'variable', required: true }],
    summarize: (c) => str(c, 'variable') || null,
    legacy: true,
  },
  {
    type: 'save_lead',
    label: 'Save Lead',
    description: 'Legacy node that stores collected answers as a lead.',
    category: 'utility',
    icon: UserPlus,
    color: '#16a34a',
    background: '#f0fdf4',
    capabilities: ['crm'],
    providers: ['none'],
    sourceHandles: NEXT,
    hasTargetHandle: true,
    defaultConfig: {},
    configSchema: [
      { key: 'name_variable', label: 'Name variable', type: 'variable' },
      { key: 'email_variable', label: 'Email variable', type: 'variable' },
      { key: 'phone_variable', label: 'Phone variable', type: 'variable' },
    ],
    legacy: true,
  },
];

// ===================================================================
// The registry
// ===================================================================

/** The 27 nodes this task introduces, in palette order. */
export const JOURNEY_PALETTE_NODES: JourneyNodeDefinition[] = [
  ...MESSAGE_NODES,
  ...INTERACTIVE_NODES,
  ...ADVANCED_NODES,
  ...UTILITY_NODES,
];

/** Every registered node, palette + legacy. */
export const JOURNEY_NODE_DEFINITIONS: JourneyNodeDefinition[] = [
  ...JOURNEY_PALETTE_NODES,
  ...LEGACY_NODES,
];

const BY_TYPE = new Map<string, JourneyNodeDefinition>(
  JOURNEY_NODE_DEFINITIONS.map((definition) => [definition.type, definition]),
);

/** Node identity is its `type` string. Never an array index, never a position. */
export function getJourneyNode(type: string): JourneyNodeDefinition | undefined {
  return BY_TYPE.get(type);
}

export function isKnownJourneyNodeType(type: string): boolean {
  return BY_TYPE.has(type);
}

/**
 * Task 23 — the ONE place `sourceHandles` is ever resolved. Every call
 * site that used to read `definition.sourceHandles` directly now calls
 * this instead, passing the owning node's own `data` — see
 * JourneyNodeDefinition.sourceHandles' docblock for why that matters
 * (only 'conditional' actually varies by data; every other type's
 * function ignores the argument and returns its fixed array exactly as
 * before).
 */
export function journeySourceHandles(
  definition: JourneyNodeDefinition | undefined,
  data: Record<string, unknown> | undefined,
  fallback: JourneyNodeHandle[] = [{ id: 'next', label: 'Next' }],
): JourneyNodeHandle[] {
  if (!definition) return fallback;

  return typeof definition.sourceHandles === 'function' ? definition.sourceHandles(data ?? {}) : definition.sourceHandles;
}

/** Palette nodes for one category, in registry order. */
export function journeyNodesByCategory(category: JourneyNodeCategory): JourneyNodeDefinition[] {
  return JOURNEY_PALETTE_NODES.filter((definition) => definition.category === category);
}

/** Every type string the backend must accept. */
export const ALL_JOURNEY_NODE_TYPES: string[] = JOURNEY_NODE_DEFINITIONS.map((d) => d.type);

/**
 * P5-7 — RUNTIME TRUTH, mirrored from the backend's authoritative
 * JourneyNodeCatalog::RUNTIME_EXECUTABLE_TYPES (JourneyRuntimeSafetyTest
 * fails on any disagreement). "Exists in the palette" is not "can run":
 * a node outside this list can be placed and saved as a DRAFT, but a
 * journey containing it cannot be published or switched on — the server
 * answers 422 JOURNEY_NOT_PUBLISHABLE whatever this list says. UX only.
 */
export const RUNTIME_EXECUTABLE_NODE_TYPES: readonly string[] = [
  'trigger', 'message', 'question', 'condition', 'save_lead',
  'delay', 'conditional', 'text', 'image', 'video', 'document', 'audio',
  'prompt', 'agent', 'rag',
  // Phase 3 — Meta Template: wired into WhatsAppJourneyEngine via the
  // existing TemplateMessageDispatcher. Mirrors backend's
  // JourneyNodeCatalog::RUNTIME_EXECUTABLE_TYPES.
  'template',
  // Phase 4 — Meta Catalog/Product: wired into WhatsAppJourneyEngine via
  // the existing send() primitive. Mirrors backend's
  // JourneyNodeCatalog::RUNTIME_EXECUTABLE_TYPES.
  'catalog', 'product',
  // Phase 5 — Meta Flow: wired into WhatsAppJourneyEngine via the
  // existing send() primitive. Mirrors backend's
  // JourneyNodeCatalog::RUNTIME_EXECUTABLE_TYPES.
  'flow',
  // Task 24 — Connexxa parity: List and Reply Buttons each get a
  // genuine per-option outgoing handle. Mirrors backend's
  // JourneyNodeCatalog::RUNTIME_EXECUTABLE_TYPES.
  'list', 'reply_button',
  // Task 26 — Connexxa parity, scoped: NOT real JavaScript — a tiny
  // closed expression language of this platform's own (JourneyCodeSandbox
  // on the backend). Mirrors backend's JourneyNodeCatalog::RUNTIME_EXECUTABLE_TYPES.
  'code',
];

export function isRuntimeExecutableNodeType(type: string): boolean {
  return RUNTIME_EXECUTABLE_NODE_TYPES.includes(type);
}

/** Distinct node types in a graph that the runtime cannot execute yet. */
export function nonExecutableNodeTypes(nodes: ReadonlyArray<{ type: string }>): string[] {
  return [...new Set(nodes.map((n) => n.type).filter((type) => !isRuntimeExecutableNodeType(type)))];
}

export const JOURNEY_PALETTE_NODE_TYPES: JourneyPaletteNodeType[] = JOURNEY_PALETTE_NODES.map(
  (d) => d.type as JourneyPaletteNodeType,
);

// ===================================================================
// Validation
// ===================================================================

/**
 * Field-level errors for one node's configuration, keyed by field key so
 * a form can render each message against its own input. Required-ness,
 * URL shape and numeric range come from the field descriptors; anything
 * cross-field (max 3 buttons, delay > 0, at least one condition) comes
 * from the definition's own validate().
 *
 * Deliberately NOT dependent on the HTML `required` attribute: the
 * builder's form is submitted through application code, and a native
 * constraint bubble would block it before these messages could render —
 * the same trap the Template Manager hit in Phase 4.
 */
export function validateJourneyNodeConfig(
  type: string,
  config: Record<string, unknown>,
): JourneyNodeConfigErrors {
  const definition = getJourneyNode(type);

  if (!definition) {
    return { type: `Unknown node type "${type}".` };
  }

  const errors: JourneyNodeConfigErrors = {};

  for (const field of definition.configSchema) {
    const value = config[field.key];

    const waived =
      field.requiredUnless !== undefined &&
      config[field.requiredUnless] !== undefined &&
      config[field.requiredUnless] !== null &&
      String(config[field.requiredUnless]).trim() !== '';

    if (field.required && !waived) {
      const empty =
        value === undefined ||
        value === null ||
        (typeof value === 'string' && value.trim() === '') ||
        (Array.isArray(value) && value.length === 0);

      if (empty) {
        errors[field.key] = `${field.label} is required.`;
        continue;
      }
    }

    if (value === undefined || value === null || value === '') {
      continue;
    }

    if (field.type === 'url' && typeof value === 'string' && !isHttpUrl(value.trim())) {
      errors[field.key] = `${field.label} must be a valid http(s) URL.`;
      continue;
    }

    if (field.type === 'number') {
      const numeric = Number(value);

      if (!Number.isFinite(numeric)) {
        errors[field.key] = `${field.label} must be a number.`;
        continue;
      }

      if (field.min !== undefined && numeric < field.min) {
        errors[field.key] = `${field.label} must be at least ${field.min}.`;
        continue;
      }

      if (field.max !== undefined && numeric > field.max) {
        errors[field.key] = `${field.label} must be at most ${field.max}.`;
        continue;
      }
    }

    if (field.type === 'select' && field.options && typeof value === 'string') {
      if (!field.options.some((option) => option.value === value)) {
        errors[field.key] = `${field.label} is not a supported value.`;
        continue;
      }
    }

    if (field.maxItems !== undefined && Array.isArray(value) && value.length > field.maxItems) {
      errors[field.key] = `${field.label}: at most ${field.maxItems} allowed.`;
    }
  }

  return { ...errors, ...(definition.validate?.(config) ?? {}) };
}

/**
 * Graph-level checks the per-node validator cannot see.
 *
 * A conditional node's branches are identified by `sourceHandle`, never
 * by where the target box sits, so an edge leaving one without a handle
 * id is ambiguous and is reported here rather than silently guessed at
 * execution time.
 */
export function validateJourneyGraph(
  nodes: { id: string; type: string; data?: Record<string, unknown> }[],
  edges: { source: string; sourceHandle?: string | null }[],
): Record<string, JourneyNodeConfigErrors> {
  const errors: Record<string, JourneyNodeConfigErrors> = {};

  for (const node of nodes) {
    const nodeErrors = validateJourneyNodeConfig(node.type, node.data ?? {});
    const definition = getJourneyNode(node.type);

    const handles = journeySourceHandles(definition, node.data);
    if (definition && handles.length > 1 && !definition.legacy) {
      const outgoing = edges.filter((edge) => edge.source === node.id);
      const valid = new Set(handles.map((handle) => handle.id));

      if (outgoing.some((edge) => !edge.sourceHandle || !valid.has(edge.sourceHandle))) {
        nodeErrors.branches = `Every connection leaving this node must use one of: ${[...valid].join(', ')}.`;
      }
    }

    if (Object.keys(nodeErrors).length > 0) {
      errors[node.id] = nodeErrors;
    }
  }

  return errors;
}
