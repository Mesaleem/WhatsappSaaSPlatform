import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import JourneyBuilderPage from './JourneyBuilderPage';
import journeyService from '../../services/journeyService';
import {
  JOURNEY_PALETTE_NODES,
  JOURNEY_PALETTE_NODE_TYPES,
} from '../../journey/nodeRegistry';
import { JOURNEY_NODE_CATEGORIES } from '../../types/journeyNodes';
import type { SaveFlowPayload, WhatsAppFlow } from '../../types/journey';

/**
 * Phase 5 — Journey / Automation: 27 Node Palette & React Flow Node
 * Schema Foundation, page level.
 *
 * nodeRegistry.test.tsx proves the CONTRACT (every type registered,
 * typed, validated, branch-explicit). This file proves the contract is
 * actually reachable from the builder, which is the task's PASS bar:
 * "do not mark PASS if any node is merely displayed in the sidebar but
 * lacks a stable type/configuration contract". So it covers:
 *
 *   1. all 27 appear in the palette, under their declared category;
 *   2. clicking one creates a node seeded with its registry default;
 *   3. its configuration is editable through the schema-driven form and
 *      survives into the save payload (configuration persistence);
 *   4. the 3-reply-button cap is enforced in the UI, not only in schema;
 *   5. invalid configuration blocks save with a FIELD-level message,
 *      not an HTML `required` bubble;
 *   6. a journey saved before this task still loads, still renders, and
 *      still re-saves byte-identically (no destructive migration);
 *   7. nothing credential-shaped is rendered or persisted.
 *
 * journeyService is mocked because it is the only seam this page talks
 * to; TenantContext is mocked because the page only reads
 * selectedAccountId from it to re-fetch.
 */

vi.mock('../../services/journeyService', () => ({
  default: {
    list: vi.fn(),
    get: vi.fn(),
    create: vi.fn(),
    update: vi.fn(),
    remove: vi.fn(),
    toggle: vi.fn(),
    sessions: vi.fn(),
    test: vi.fn(),
  },
}));

vi.mock('../../core/context/TenantContext', () => ({
  useTenant: () => ({ selectedAccountId: 1, selectedAccount: null }),
}));

/*
  Phase 5 Task 7 — the palette greys out a node the account is not
  entitled to. This fixture is a Meta-engine tenant holding every
  capability, so these tests keep asserting what they were written to
  assert (structure, configuration, persistence) rather than becoming
  entitlement tests. Entitlement UX has its own suite:
  src/journey/nodeEntitlement.test.ts, and the SERVER-side gate is
  JourneyNodeEntitlementTest.
*/
const ALL_CAPABILITIES: Record<string, boolean> = {
  whatsapp_send: true,
  whatsapp_groups: true,
  crm: true,
  journey_automation: true,
  ads: true,
  social: true,
  ai: true,
  commerce: true,
  payments: true,
  external_api: true,
  custom_code: true,
  email: true,
};

/** Mutable so one describe below can under-entitle the tenant. */
const authState = {
  capabilities: { ...ALL_CAPABILITIES } as Record<string, boolean>,
  engineType: 'meta' as string | null,
};

vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({
    user: {
      id: 1,
      name: 'Tenant Admin',
      capabilities: authState.capabilities,
      account: { id: 1, current_subscription: { engine_type: authState.engineType } },
    },
    isSuperAdmin: () => false,
  }),
}));

const journeys = journeyService as unknown as {
  list: Mock;
  create: Mock;
  update: Mock;
  toggle: Mock;
  remove: Mock;
};

/** A journey saved BEFORE this task: only the five legacy node types. */
const legacyFlow: WhatsAppFlow = {
  id: 7,
  account_id: 1,
  name: 'Legacy welcome',
  trigger_type: 'keyword',
  trigger_value: 'hi',
  graph_data: {
    nodes: [
      { id: 'trigger_1', type: 'trigger', position: { x: 40, y: 200 }, data: {} },
      { id: 'message_1', type: 'message', position: { x: 320, y: 200 }, data: { text: 'Hello there' } },
      {
        id: 'question_1',
        type: 'question',
        position: { x: 600, y: 200 },
        data: { prompt_text: 'Your name?', variable_name: 'user_name', input_type: 'text' },
      },
      { id: 'condition_1', type: 'condition', position: { x: 880, y: 200 }, data: { variable: 'user_name' } },
      { id: 'save_lead_1', type: 'save_lead', position: { x: 1160, y: 200 }, data: { name_variable: 'user_name' } },
    ],
    edges: [
      { id: 'edge_1', source: 'trigger_1', target: 'message_1' },
      { id: 'edge_2', source: 'message_1', target: 'question_1' },
      { id: 'edge_3', source: 'question_1', target: 'condition_1' },
      { id: 'edge_4', source: 'condition_1', target: 'save_lead_1', condition: { operator: 'exists' } },
    ],
  },
  is_active: true,
  created_at: null,
  updated_at: null,
};

function clone<T>(value: T): T {
  return JSON.parse(JSON.stringify(value)) as T;
}

/** Renders the page and opens the editor on a brand-new journey. */
async function openNewEditor(user: ReturnType<typeof userEvent.setup>) {
  journeys.list.mockResolvedValue([]);
  render(<JourneyBuilderPage />);
  await screen.findByText('New Journey');
  await user.click(screen.getByText('New Journey'));
  await screen.findByTestId('palette-node-text');
}

/** Renders the page, loads `flow`, and opens it for editing. */
async function openExistingEditor(user: ReturnType<typeof userEvent.setup>, flow: WhatsAppFlow) {
  journeys.list.mockResolvedValue([flow]);
  render(<JourneyBuilderPage />);
  await screen.findByText('Edit');
  await user.click(screen.getByText('Edit'));
  await screen.findByTestId('palette-node-text');
}

/**
 * Clicks the canvas node of the given type so its editor panel opens.
 * Selection happens on mousedown, and the node label also appears in the
 * palette — so this targets the canvas element by its type, not by text.
 */
async function selectCanvasNode(user: ReturnType<typeof userEvent.setup>, type: string) {
  const node = document.querySelector(`[data-node-type="${type}"]`);

  expect(node, `no ${type} node on the canvas`).toBeTruthy();
  await user.click(node as HTMLElement);
}

/** The Save button — its label sits beside an icon, so match by role. */
function saveButton() {
  return screen.getByRole('button', { name: /Save Journey/ });
}

/** The last graph_data the page tried to persist. */
function savedPayload(mock: Mock): SaveFlowPayload {
  const args = mock.mock.calls.at(-1)!;

  return (args.length > 1 ? args[1] : args[0]) as SaveFlowPayload;
}

beforeEach(() => {
  vi.clearAllMocks();
  authState.capabilities = { ...ALL_CAPABILITIES };
  authState.engineType = 'meta';
  journeys.list.mockResolvedValue([]);
  journeys.create.mockResolvedValue(legacyFlow);
  journeys.update.mockResolvedValue(legacyFlow);
});

// ====================================================================
// 1. Palette
// ====================================================================
describe('palette', () => {
  it('renders every one of the 27 palette node types', async () => {
    const user = userEvent.setup();
    await openNewEditor(user);

    expect(JOURNEY_PALETTE_NODE_TYPES).toHaveLength(27);

    for (const type of JOURNEY_PALETTE_NODE_TYPES) {
      expect(screen.getByTestId(`palette-node-${type}`), `${type} missing from palette`).toBeTruthy();
    }
  });

  it('puts each node in its declared category group', async () => {
    const user = userEvent.setup();
    await openNewEditor(user);

    for (const category of JOURNEY_NODE_CATEGORIES) {
      const group = screen.getByTestId(`palette-category-${category}`);
      const expected = JOURNEY_PALETTE_NODES.filter((d) => d.category === category);

      for (const definition of expected) {
        expect(
          within(group).getByTestId(`palette-node-${definition.type}`),
          `${definition.type} is not under ${category}`,
        ).toBeTruthy();
      }
    }
  });

  it('keeps the five legacy engine types out of the palette', async () => {
    const user = userEvent.setup();
    await openNewEditor(user);

    for (const legacy of ['message', 'question', 'condition', 'save_lead']) {
      expect(screen.queryByTestId(`palette-node-${legacy}`), `${legacy} should not be addable`).toBeNull();
    }
  });
});

// ====================================================================
// 2. Node creation + configuration persistence
// ====================================================================
describe('node creation and configuration persistence', () => {
  it('creates a node seeded with its registry default configuration', async () => {
    const user = userEvent.setup();
    await openNewEditor(user);

    await user.click(screen.getByTestId('palette-node-delay'));
    await user.click(saveButton());

    await waitFor(() => expect(journeys.create).toHaveBeenCalled());

    const delay = savedPayload(journeys.create).graph_data.nodes.find((n) => n.type === 'delay')!;

    // Seeded from defaultConfig — NOT an empty object the form has to
    // special-case, and NOT an index-based identity.
    expect(delay.data.amount).toBe(1);
    expect(delay.data.unit).toBe('minutes');
    expect(delay.id).toMatch(/^delay_/);
  });

  it('persists a schema-driven edit into the save payload', async () => {
    const user = userEvent.setup();
    await openNewEditor(user);

    await user.click(screen.getByTestId('palette-node-text'));
    await selectCanvasNode(user, 'text');

    const form = await screen.findByTestId('node-config-text');
    const textarea = within(form).getByTestId('node-field-text').querySelector('textarea')!;

    // userEvent treats `{{` as the escape for a literal `{`, so the
    // doubled braces below type a real `{{user_name}}` mustache.
    await user.type(textarea, 'Hi {{{{user_name}}');
    await user.click(saveButton());

    await waitFor(() => expect(journeys.create).toHaveBeenCalled());

    const node = savedPayload(journeys.create).graph_data.nodes.find((n) => n.type === 'text')!;

    expect(node.data.text).toBe('Hi {{user_name}}');
  });

  it('renders the node type’s own form, never a fallback editor', async () => {
    const user = userEvent.setup();
    await openNewEditor(user);

    // Regression: every non-legacy type used to fall through to the
    // Save Lead panel. Spot-check a node from each palette category.
    for (const type of ['text', 'reply_button', 'api', 'delay']) {
      await user.click(screen.getByTestId(`palette-node-${type}`));
    }

    await selectCanvasNode(user, 'api');

    const form = await screen.findByTestId('node-config-api');

    expect(within(form).getByTestId('node-field-url')).toBeTruthy();
    expect(within(form).getByTestId('node-field-method')).toBeTruthy();
    expect(screen.queryByText('Save Lead')).toBeNull();
  });

  it('caps reply buttons at three in the UI as well as the schema', async () => {
    const user = userEvent.setup();
    await openNewEditor(user);

    await user.click(screen.getByTestId('palette-node-reply_button'));
    await selectCanvasNode(user, 'reply_button');

    const field = within(await screen.findByTestId('node-config-reply_button')).getByTestId(
      'node-field-buttons',
    );

    for (let i = 1; i <= 3; i += 1) {
      const add = within(field).getByRole('button', { name: new RegExp(`Add button \\(${i - 1}/3\\)`) });
      expect((add as HTMLButtonElement).disabled).toBe(false);
      await user.click(add);
    }

    const capped = within(field).getByRole('button', { name: /Add button \(3\/3\)/ });

    expect((capped as HTMLButtonElement).disabled).toBe(true);
  });
});

// ====================================================================
// 3. Validation
// ====================================================================
describe('configuration validation', () => {
  it('blocks save and shows a field-level message for an incomplete node', async () => {
    const user = userEvent.setup();
    await openNewEditor(user);

    await user.click(screen.getByTestId('palette-node-external_url'));
    await user.click(saveButton());

    // Field-level, from the registry validator — not an HTML `required`
    // bubble, which would never have reached application code.
    expect(await screen.findByTestId('field-error-buttonText')).toBeTruthy();
    expect(journeys.create).not.toHaveBeenCalled();
  });

  it('rejects a non-http URL before the request is made', async () => {
    const user = userEvent.setup();
    await openNewEditor(user);

    await user.click(screen.getByTestId('palette-node-external_url'));
    await selectCanvasNode(user, 'external_url');

    const form = await screen.findByTestId('node-config-external_url');

    await user.type(within(form).getByTestId('node-field-buttonText').querySelector('input')!, 'Open');
    await user.type(within(form).getByTestId('node-field-url').querySelector('input')!, 'javascript:alert(1)');
    await user.click(saveButton());

    expect(await screen.findByTestId('field-error-url')).toBeTruthy();
    expect(journeys.create).not.toHaveBeenCalled();
  });

  it('saves once the configuration is complete', async () => {
    const user = userEvent.setup();
    await openNewEditor(user);

    await user.click(screen.getByTestId('palette-node-external_url'));
    await selectCanvasNode(user, 'external_url');

    const form = await screen.findByTestId('node-config-external_url');

    await user.type(within(form).getByTestId('node-field-buttonText').querySelector('input')!, 'Open');
    await user.type(
      within(form).getByTestId('node-field-url').querySelector('input')!,
      'https://example.com/offer',
    );
    await user.click(saveButton());

    await waitFor(() => expect(journeys.create).toHaveBeenCalled());
  });
});

// ====================================================================
// 4. Backward compatibility
// ====================================================================
describe('existing journeys', () => {
  it('renders a journey saved before this task, with all five legacy nodes', async () => {
    const user = userEvent.setup();
    await openExistingEditor(user, clone(legacyFlow));

    expect(screen.getByText('Send Message')).toBeTruthy();
    expect(screen.getByText('Ask Question')).toBeTruthy();
    expect(screen.getByText('Condition')).toBeTruthy();
    expect(screen.getByText('Save Lead')).toBeTruthy();
  });

  it('keeps the legacy hand-written panels for the five engine types', async () => {
    const user = userEvent.setup();
    await openExistingEditor(user, clone(legacyFlow));

    await selectCanvasNode(user, 'question');

    // The bespoke question panel, not the schema-driven form.
    expect(screen.getByText('Save Answer As Variable')).toBeTruthy();
    expect(screen.queryByTestId('node-config-question')).toBeNull();
  });

  it('re-saves an untouched legacy journey without rewriting its graph', async () => {
    const user = userEvent.setup();
    await openExistingEditor(user, clone(legacyFlow));

    await user.click(saveButton());

    await waitFor(() => expect(journeys.update).toHaveBeenCalled());

    // No invented sourceHandle on the legacy condition edge, no seeded
    // config, no renamed ids: a journey that loaded is a journey that
    // round-trips.
    expect(savedPayload(journeys.update).graph_data).toEqual(legacyFlow.graph_data);
  });

  it('does not crash on a node type this build has never heard of', async () => {
    const user = userEvent.setup();
    const futureFlow = clone(legacyFlow);

    futureFlow.graph_data.nodes.push({
      id: 'future_1',
      // Deliberately outside the union: a flow saved by a newer deploy.
      type: 'quantum_teleport' as never,
      position: { x: 40, y: 400 },
      data: {},
    });

    await openExistingEditor(user, futureFlow);

    // Rendered visibly with its raw type rather than taking the canvas down.
    expect(screen.getByText('quantum_teleport')).toBeTruthy();

    await selectCanvasNode(user, 'quantum_teleport');

    expect(await screen.findByTestId('unknown-node-type')).toBeTruthy();
  });
});

// ====================================================================
// 5. Security
// ====================================================================
describe('security', () => {
  it('offers no credential field on the nodes that need one server-side', async () => {
    const user = userEvent.setup();
    await openNewEditor(user);

    await user.click(screen.getByTestId('palette-node-api'));
    await selectCanvasNode(user, 'api');

    const form = await screen.findByTestId('node-config-api');
    const text = form.textContent!.toLowerCase();

    // The node names a SERVER-SIDE credential; it never collects one.
    expect(within(form).getByTestId('node-field-credentialRef')).toBeTruthy();
    for (const forbidden of ['api key', 'api secret', 'access token', 'bearer token', 'password']) {
      expect(text, `"${forbidden}" is offered as a node field`).not.toContain(forbidden);
    }
    expect(form.querySelector('input[type="password"]')).toBeNull();
  });

  it('stores code without executing it', async () => {
    const user = userEvent.setup();
    await openNewEditor(user);

    await user.click(screen.getByTestId('palette-node-code'));
    await selectCanvasNode(user, 'code');

    const form = await screen.findByTestId('node-config-code');
    const editor = within(form).getByTestId('node-field-code').querySelector('textarea')!;

    // If this were ever evaluated, the assignment below would land on
    // globalThis and the assertion after it would fail.
    await user.type(editor, 'globalThis.__journey_code_ran = true;');
    await user.click(saveButton());

    await waitFor(() => expect(journeys.create).toHaveBeenCalled());

    expect((globalThis as Record<string, unknown>).__journey_code_ran).toBeUndefined();
    expect(form.querySelector('script')).toBeNull();

    const node = savedPayload(journeys.create).graph_data.nodes.find((n) => n.type === 'code')!;

    expect(node.data.code).toBe('globalThis.__journey_code_ran = true;');
  });

  it('never writes journey configuration to browser storage', async () => {
    const setItem = vi.spyOn(Storage.prototype, 'setItem');
    const user = userEvent.setup();

    await openNewEditor(user);
    // A delay is valid straight from its registry default, so the save
    // actually reaches the service and the assertion below is about
    // storage rather than about validation.
    await user.click(screen.getByTestId('palette-node-delay'));
    await user.click(saveButton());

    await waitFor(() => expect(journeys.create).toHaveBeenCalled());

    expect(setItem).not.toHaveBeenCalled();
    setItem.mockRestore();
  });
});

// ====================================================================
// 6. Entitlement UX (Phase 5 Task 7)
// ====================================================================
describe('entitlement UX', () => {
  it('marks every palette node available to a fully entitled Meta tenant', async () => {
    const user = userEvent.setup();
    await openNewEditor(user);

    for (const type of JOURNEY_PALETTE_NODE_TYPES) {
      const button = screen.getByTestId(`palette-node-${type}`) as HTMLButtonElement;

      expect(button.disabled, `${type} should be available`).toBe(false);
      expect(button.dataset.entitled).toBe('true');
    }
  });

  it('greys out a node whose capability the account lacks, and says why', async () => {
    authState.capabilities = { ...ALL_CAPABILITIES, external_api: false };

    const user = userEvent.setup();
    await openNewEditor(user);

    const api = screen.getByTestId('palette-node-api') as HTMLButtonElement;

    expect(api.disabled).toBe(true);
    expect(api.dataset.entitled).toBe('false');
    expect(api.title).toContain('external_api');

    // Everything else stays usable — one missing capability is not a
    // reason to disable the palette.
    expect((screen.getByTestId('palette-node-text') as HTMLButtonElement).disabled).toBe(false);
  });

  it('greys out Meta-only nodes on a QR account', async () => {
    authState.engineType = 'qr';

    const user = userEvent.setup();
    await openNewEditor(user);

    for (const metaOnly of ['reply_button', 'list', 'template', 'flow', 'catalog']) {
      const button = screen.getByTestId(`palette-node-${metaOnly}`) as HTMLButtonElement;

      expect(button.disabled, `${metaOnly} should be blocked on QR`).toBe(true);
      expect(button.title).toContain('qr');
    }

    // A dual-provider node and a platform node both stay available.
    expect((screen.getByTestId('palette-node-text') as HTMLButtonElement).disabled).toBe(false);
    expect((screen.getByTestId('palette-node-delay') as HTMLButtonElement).disabled).toBe(false);
  });

  it('cannot add a node the palette has greyed out', async () => {
    authState.capabilities = { ...ALL_CAPABILITIES, custom_code: false };

    const user = userEvent.setup();
    await openNewEditor(user);

    await user.click(screen.getByTestId('palette-node-code'));
    await user.click(saveButton());

    await waitFor(() => expect(journeys.create).toHaveBeenCalled());

    // The click did nothing: the saved graph holds only the trigger.
    const types = savedPayload(journeys.create).graph_data.nodes.map((n) => n.type);

    expect(types).toEqual(['trigger']);
  });

  it('surfaces the backend 403 when the server disagrees with the palette', async () => {
    // The palette is UX; this is what actually protects the system. The
    // client believes it is entitled, the server refuses, and the
    // operator sees the server's reason rather than a silent failure.
    journeys.create.mockRejectedValue({
      response: {
        status: 403,
        data: {
          message: "The 'api' node requires the 'external_api' capability, which this account does not hold.",
          error_code: 'JOURNEY_NODE_NOT_ENTITLED',
        },
      },
    });

    const user = userEvent.setup();
    await openNewEditor(user);

    await user.click(screen.getByTestId('palette-node-api'));
    await selectCanvasNode(user, 'api');

    const form = await screen.findByTestId('node-config-api');
    await user.type(within(form).getByTestId('node-field-url').querySelector('input')!, 'https://x.test/y');

    await user.click(saveButton());

    expect(await screen.findByText(/external_api/)).toBeTruthy();
  });
});
