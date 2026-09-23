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
  Route,
  ShoppingBag,
  Smile,
  Sparkles,
  Target,
  UserPlus,
  Video,
  Workflow,
  Zap,
} from 'lucide-react';

import {
  CONDITIONAL_FALSE_HANDLE,
  CONDITIONAL_TRUE_HANDLE,
  DELAY_UNITS,
  MAX_REPLY_BUTTONS,
  type AnyJourneyNodeType,
  type JourneyNodeCapability,
  type JourneyNodeCategory,
  type JourneyNodeConfigErrors,
  type JourneyNodeField,
  type JourneyNodeHandle,
  type JourneyNodeProvider,
  type JourneyPaletteNodeType,
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
  /** Outgoing connection points. More than one = an explicitly branching node. */
  sourceHandles: JourneyNodeHandle[];
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

const str = (config: Record<string, unknown>, key: string): string =>
  typeof config[key] === 'string' ? (config[key] as string).trim() : '';

const arr = (config: Record<string, unknown>, key: string): unknown[] =>
  Array.isArray(config[key]) ? (config[key] as unknown[]) : [];

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
    description: 'System / AI initial prompt that seeds the conversation.',
    category: 'message',
    icon: Sparkles,
    color: '#7c3aed',
    background: '#f5f3ff',
    capabilities: ['ai'],
    providers: ['none'],
    sourceHandles: NEXT,
    hasTargetHandle: true,
    defaultConfig: { prompt: '' },
    configSchema: [
      { key: 'prompt', label: 'System prompt', type: 'textarea', required: true, help: 'Instructions for the AI. Never put an API key here — credentials live server-side.' },
      { key: 'model', label: 'Model hint', type: 'text', placeholder: 'Optional' },
    ],
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
    description: 'WhatsApp interactive list with selectable rows.',
    category: 'interactive',
    icon: List,
    color: '#7c3aed',
    background: '#f5f3ff',
    capabilities: ['whatsapp_send'],
    providers: ['meta'],
    sourceHandles: NEXT,
    hasTargetHandle: true,
    defaultConfig: { body: '', buttonText: 'Choose', sections: [] },
    configSchema: [
      { key: 'body', label: 'Body', type: 'textarea', required: true },
      { key: 'buttonText', label: 'Button text', type: 'text', required: true, placeholder: 'Choose' },
      { key: 'sections', label: 'Sections', type: 'sections', required: true, help: 'At least one section, each with at least one row.' },
    ],
    validate: (c): JourneyNodeConfigErrors => {
      const sections = arr(c, 'sections') as { title?: string; rows?: unknown[] }[];

      if (sections.length === 0) {
        return { sections: 'Add at least one section.' };
      }

      if (sections.some((s) => !Array.isArray(s.rows) || s.rows.length === 0)) {
        return { sections: 'Every section needs at least one row.' };
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
    description: `Quick reply buttons — at most ${MAX_REPLY_BUTTONS}.`,
    category: 'interactive',
    icon: MousePointerClick,
    color: '#7c3aed',
    background: '#f5f3ff',
    capabilities: ['whatsapp_send'],
    providers: ['meta'],
    sourceHandles: NEXT,
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
    defaultConfig: { flowId: '' },
    configSchema: [
      { key: 'flowId', label: 'Meta Flow ID', type: 'text', required: true },
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
      { key: 'method', label: 'Method', type: 'select', required: true, options: [{ value: 'GET', label: 'GET' }, { value: 'POST', label: 'POST' }] },
      { key: 'url', label: 'URL', type: 'url', required: true, placeholder: 'https://api.example.com/v1/thing' },
      { key: 'headers', label: 'Headers', type: 'keyvalue', help: 'Never put a token or secret here — it is stored in plain text. Use a server-side credential reference.' },
      { key: 'query', label: 'Query parameters', type: 'keyvalue' },
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
    description: 'Branch on a condition. Has explicit TRUE and FALSE outputs.',
    category: 'advanced',
    icon: GitBranch,
    color: '#d97706',
    background: '#fffbeb',
    // Phase 5 Task 7: pure control flow. 'none' declares "no WhatsApp
    // engine required", so this runs on every account — it does NOT
    // mean "only accounts without an engine". See
    // JourneyNodeCatalog::PLATFORM_PROVIDER on the backend.
    providers: ['none'],
    // Branch identity is the handle id, never the position on the canvas.
    sourceHandles: [
      { id: CONDITIONAL_TRUE_HANDLE, label: 'TRUE' },
      { id: CONDITIONAL_FALSE_HANDLE, label: 'FALSE' },
    ],
    hasTargetHandle: true,
    defaultConfig: { conditions: [], match: 'all' },
    configSchema: [
      { key: 'conditions', label: 'Conditions', type: 'conditions', required: true },
      { key: 'match', label: 'Match', type: 'select', options: [{ value: 'all', label: 'All conditions' }, { value: 'any', label: 'Any condition' }] },
    ],
    validate: (c): JourneyNodeConfigErrors => {
      const conditions = arr(c, 'conditions') as { variable?: string; operator?: string }[];

      if (conditions.length === 0) {
        return { conditions: 'Add at least one condition.' };
      }

      if (conditions.some((r) => !r.variable || !r.variable.trim())) {
        return { conditions: 'Every condition needs a variable.' };
      }

      if (conditions.some((r) => !r.operator)) {
        return { conditions: 'Every condition needs an operator.' };
      }

      return {};
    },
    summarize: (c) => {
      const n = arr(c, 'conditions').length;

      return n ? `${n} condition${n === 1 ? '' : 's'}` : null;
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
    description: 'Hand the conversation to a configured AI agent.',
    category: 'advanced',
    icon: Bot,
    color: '#7c3aed',
    background: '#f5f3ff',
    capabilities: ['ai'],
    providers: ['none'],
    sourceHandles: NEXT,
    hasTargetHandle: true,
    defaultConfig: { agentId: '' },
    configSchema: [
      { key: 'agentId', label: 'Agent', type: 'text', required: true, help: 'Agent credentials are resolved server-side and never stored on the node.' },
      { key: 'instructions', label: 'Instructions', type: 'textarea' },
    ],
    summarize: (c) => str(c, 'agentId') || null,
  },
  {
    type: 'rag',
    label: 'Knowledge Base',
    description: 'Answer from a knowledge base / vector search.',
    category: 'advanced',
    icon: Database,
    color: '#7c3aed',
    background: '#f5f3ff',
    capabilities: ['ai'],
    providers: ['none'],
    sourceHandles: NEXT,
    hasTargetHandle: true,
    defaultConfig: { knowledgeBaseId: '', topK: 3 },
    configSchema: [
      { key: 'knowledgeBaseId', label: 'Knowledge base', type: 'text', required: true },
      { key: 'queryVariable', label: 'Query variable', type: 'variable', placeholder: 'last_message' },
      { key: 'topK', label: 'Results (top K)', type: 'number', min: 1, max: 50 },
    ],
    validate: (c): JourneyNodeConfigErrors => {
      if (c.topK === undefined || c.topK === null || c.topK === '') {
        return {};
      }

      const topK = Number(c.topK);

      return Number.isFinite(topK) && topK >= 1 && topK <= 50 ? {} : { topK: 'Top K must be between 1 and 50.' };
    },
    summarize: (c) => str(c, 'knowledgeBaseId') || null,
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
];

// ===================================================================
// 4. UTILITY — 4
// ===================================================================

const UTILITY_NODES: JourneyNodeDefinition[] = [
  {
    type: 'code',
    label: 'Code',
    description: 'Run custom logic. Executed server-side in a sandbox, never in the browser.',
    category: 'utility',
    icon: Code,
    color: '#475569',
    background: '#f8fafc',
    // Phase 5 Task 7: capability seeded; platform provider (no WhatsApp engine involved).
    capabilities: ['custom_code'],
    providers: ['none'],
    sourceHandles: NEXT,
    hasTargetHandle: true,
    defaultConfig: { language: 'javascript', code: '' },
    configSchema: [
      { key: 'language', label: 'Language', type: 'select', required: true, options: [{ value: 'javascript', label: 'JavaScript' }] },
      { key: 'code', label: 'Code', type: 'code', required: true, help: 'Stored only. This is never evaluated in the browser.' },
      { key: 'inputMapping', label: 'Inputs', type: 'keyvalue' },
      { key: 'outputMapping', label: 'Outputs', type: 'keyvalue' },
    ],
    validate: (c): JourneyNodeConfigErrors => (str(c, 'language') === 'javascript' ? {} : { language: 'Only JavaScript is supported.' }),
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
    description: 'Run another journey, then continue.',
    category: 'utility',
    icon: Route,
    color: '#475569',
    background: '#f8fafc',
    capabilities: ['journey_automation'],
    providers: ['none'],
    sourceHandles: NEXT,
    hasTargetHandle: true,
    defaultConfig: { journeyId: '' },
    configSchema: [{ key: 'journeyId', label: 'Journey', type: 'text', required: true }],
    summarize: (c) => str(c, 'journeyId') || null,
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
    label: 'Trigger',
    description: 'Where the journey starts.',
    category: 'utility',
    icon: Zap,
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

/** Palette nodes for one category, in registry order. */
export function journeyNodesByCategory(category: JourneyNodeCategory): JourneyNodeDefinition[] {
  return JOURNEY_PALETTE_NODES.filter((definition) => definition.category === category);
}

/** Every type string the backend must accept. */
export const ALL_JOURNEY_NODE_TYPES: string[] = JOURNEY_NODE_DEFINITIONS.map((d) => d.type);

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

    if (field.required) {
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

    if (definition && definition.sourceHandles.length > 1 && !definition.legacy) {
      const outgoing = edges.filter((edge) => edge.source === node.id);
      const valid = new Set(definition.sourceHandles.map((handle) => handle.id));

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
