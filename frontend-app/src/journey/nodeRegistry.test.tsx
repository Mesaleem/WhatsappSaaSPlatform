import { render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import {
  ALL_JOURNEY_NODE_TYPES,
  JOURNEY_NODE_DEFINITIONS,
  JOURNEY_PALETTE_NODES,
  JOURNEY_PALETTE_NODE_TYPES,
  RUNTIME_EXECUTABLE_NODE_TYPES,
  extractTemplateVariables,
  getJourneyNode,
  isHttpUrl,
  isKnownJourneyNodeType,
  isRuntimeExecutableNodeType,
  journeyNodesByCategory,
  nonExecutableNodeTypes,
  validateJourneyGraph,
  validateJourneyNodeConfig,
} from './nodeRegistry';
import { JourneyNodeShell, journeyNodeTypes } from './JourneyNodeShell';
import {
  CONDITIONAL_FALSE_HANDLE,
  CONDITIONAL_TRUE_HANDLE,
  JOURNEY_NODE_CATEGORIES,
  MAX_REPLY_BUTTONS,
} from '../types/journeyNodes';

/**
 * Phase 5 — Journey / Automation: 27-node palette foundation.
 *
 * The bar this task sets for PASS is explicit: no node may be merely
 * displayed without a stable type and a configuration contract behind it.
 * These tests enforce exactly that, node by node, rather than spot-
 * checking a few.
 */

const EXPECTED = {
  message: ['prompt', 'text', 'image', 'video', 'document', 'audio', 'sticker'],
  interactive: ['list', 'external_url', 'reply_button', 'location', 'location_request', 'address_request'],
  advanced: ['flow', 'api', 'payment', 'template', 'conditional', 'catalog', 'product', 'agent', 'rag', 'human_intervention'],
  utility: ['code', 'email', 'journey', 'delay'],
} as const;

const ALL_27 = [...EXPECTED.message, ...EXPECTED.interactive, ...EXPECTED.advanced, ...EXPECTED.utility];

const LEGACY = ['trigger', 'message', 'question', 'condition', 'save_lead'];

// ====================================================================
// 1. Registration
// ====================================================================
describe('registry: all 27 node types', () => {
  it('registers exactly 27 palette nodes', () => {
    expect(JOURNEY_PALETTE_NODES).toHaveLength(27);
    expect(JOURNEY_PALETTE_NODE_TYPES).toEqual(ALL_27);
  });

  it.each(ALL_27)('registers "%s" with a resolvable definition', (type) => {
    const definition = getJourneyNode(type);

    expect(definition, `${type} is not registered`).toBeDefined();
    expect(definition!.type).toBe(type);
  });

  it.each(ALL_27)('gives "%s" a label, description, icon and colour', (type) => {
    const definition = getJourneyNode(type)!;

    expect(definition.label.trim()).not.toBe('');
    expect(definition.description.trim()).not.toBe('');
    // lucide-react ships forwardRef components, which are objects, not
    // functions — so assert "is a renderable component reference", not
    // "is a function". The rendering suite below proves they actually draw.
    expect(definition.icon, `${type} has no icon`).toBeTruthy();
    expect(['function', 'object']).toContain(typeof definition.icon);
    expect(definition.color).toMatch(/^#[0-9a-f]{6}$/i);
  });

  it('node identity is the type string and every one is unique', () => {
    const types = JOURNEY_NODE_DEFINITIONS.map((d) => d.type);

    expect(new Set(types).size).toBe(types.length);
    // Never an array index: removing the first entry must not renumber anything.
    expect(getJourneyNode('delay')!.type).toBe('delay');
  });

  it('also registers the five legacy types so saved journeys still render', () => {
    for (const type of LEGACY) {
      expect(isKnownJourneyNodeType(type), `${type} must stay renderable`).toBe(true);
      expect(getJourneyNode(type)!.legacy).toBe(true);
    }
  });

  it('keeps legacy types out of the palette', () => {
    for (const type of LEGACY) {
      expect(JOURNEY_PALETTE_NODE_TYPES).not.toContain(type);
    }
  });

  it('exposes 32 types in total for the backend allow-list', () => {
    expect(ALL_JOURNEY_NODE_TYPES).toHaveLength(32);
  });
});

// ====================================================================
// 2. Categories
// ====================================================================
describe('registry: categories', () => {
  it.each(JOURNEY_NODE_CATEGORIES)('groups the right nodes under "%s"', (category) => {
    const types = journeyNodesByCategory(category).map((d) => d.type);

    expect(types).toEqual([...EXPECTED[category]]);
  });

  it('accounts for every palette node in exactly one category', () => {
    const counted = JOURNEY_NODE_CATEGORIES.flatMap((c) => journeyNodesByCategory(c).map((d) => d.type));

    expect(counted.sort()).toEqual([...ALL_27].sort());
  });
});

// ====================================================================
// 3. Configuration contracts
// ====================================================================
describe('registry: configuration contracts', () => {
  it.each(ALL_27)('"%s" has a configuration schema and a default config', (type) => {
    const definition = getJourneyNode(type)!;

    expect(Array.isArray(definition.configSchema)).toBe(true);
    expect(definition.defaultConfig).toBeTypeOf('object');
  });

  /** The PASS bar: displayed in the palette is not enough. */
  it.each(ALL_27)('"%s" declares at least one configurable or explicitly empty field set', (type) => {
    const definition = getJourneyNode(type)!;

    // human_intervention is the only node whose fields are all optional;
    // it still declares them, which is the contract being checked.
    expect(definition.configSchema.length).toBeGreaterThan(0);

    for (const field of definition.configSchema) {
      expect(field.key.trim()).not.toBe('');
      expect(field.label.trim()).not.toBe('');
      expect(field.type).toBeTypeOf('string');
    }
  });

  it.each(ALL_27)('"%s" has unique field keys', (type) => {
    const keys = getJourneyNode(type)!.configSchema.map((f) => f.key);

    expect(new Set(keys).size).toBe(keys.length);
  });

  it('a default config never violates its own schema for non-required-only nodes', () => {
    // A freshly dropped node may be incomplete (that is what validation is
    // for), but a default must never be actively INVALID — e.g. a delay
    // seeded with 0, or an api seeded with a bad method.
    for (const definition of JOURNEY_PALETTE_NODES) {
      const errors = validateJourneyNodeConfig(definition.type, definition.defaultConfig);

      // "Fill this in" errors on fields the schema marks required are
      // expected on a freshly dropped node. Classify by the schema flag
      // rather than by the wording of the message, so a node whose custom
      // validator phrases its own required-message ("Add at least one
      // section.") is not mistaken for an actively-invalid default.
      const requiredKeys = new Set(
        definition.configSchema.filter((field) => field.required).map((field) => field.key),
      );
      const nonRequired = Object.entries(errors).filter(([key]) => !requiredKeys.has(key));

      expect(nonRequired, `${definition.type} default config: ${JSON.stringify(nonRequired)}`).toEqual([]);
    }
  });
});

// ====================================================================
// 4. Provider / capability metadata
// ====================================================================
describe('registry: provider and capability metadata', () => {
  it.each(ALL_27)('"%s" declares which providers it can run on', (type) => {
    const providers = getJourneyNode(type)!.providers;

    expect(providers, `${type} has no provider metadata`).toBeDefined();
    expect(providers!.length).toBeGreaterThan(0);

    for (const provider of providers!) {
      // Only slugs that exist in the backend providers table.
      expect(['qr', 'meta', 'none']).toContain(provider);
    }
  });

  it('only uses capability slugs seeded on the backend', () => {
    // Phase1FoundationSeeder::CAPABILITIES, in full. The first seven are
    // Phase 1's; the last five were seeded by Phase 5 Task 7 to close the
    // gap Task 6 disclosed. JourneyNodeEntitlementTest asserts the same
    // agreement from the database side, against this very file.
    const seeded = [
      'whatsapp_send',
      'whatsapp_groups',
      'crm',
      'journey_automation',
      'ads',
      'social',
      'ai',
      'commerce',
      'payments',
      'external_api',
      'custom_code',
      'email',
    ];

    for (const definition of JOURNEY_NODE_DEFINITIONS) {
      for (const capability of definition.capabilities ?? []) {
        expect(seeded, `${definition.type} invented capability "${capability}"`).toContain(capability);
      }
    }
  });

  it('marks Meta-dependent nodes as meta-only', () => {
    for (const type of ['template', 'flow', 'catalog', 'product', 'list', 'reply_button', 'address_request']) {
      expect(getJourneyNode(type)!.providers, type).toEqual(['meta']);
    }
  });

  it('marks AI-dependent nodes with the ai capability', () => {
    for (const type of ['prompt', 'agent', 'rag']) {
      expect(getJourneyNode(type)!.capabilities, type).toContain('ai');
    }
  });

  it('marks platform nodes as provider-independent', () => {
    for (const type of ['conditional', 'delay', 'api', 'code', 'email', 'journey', 'payment']) {
      expect(getJourneyNode(type)!.providers, type).toEqual(['none']);
    }
  });

  it('lets QR send plain and media messages', () => {
    for (const type of ['text', 'image', 'video', 'document', 'audio', 'sticker']) {
      expect(getJourneyNode(type)!.providers, type).toContain('qr');
    }
  });
});

// ====================================================================
// 5. Validation
// ====================================================================
describe('validation: required fields', () => {
  it('reports a field-level error, not just a banner', () => {
    const errors = validateJourneyNodeConfig('text', {});

    expect(errors.text).toBe('Message is required.');
  });

  it('accepts a complete config', () => {
    expect(validateJourneyNodeConfig('text', { text: 'hello' })).toEqual({});
  });

  it('treats whitespace as empty', () => {
    expect(validateJourneyNodeConfig('text', { text: '   ' }).text).toBeDefined();
  });
});

describe('validation: URLs', () => {
  it.each(['image', 'video', 'document', 'audio', 'sticker', 'external_url'])(
    'rejects a non-http URL on "%s"',
    (type) => {
      const key = type === 'external_url' ? 'url' : 'mediaUrl';
      const errors = validateJourneyNodeConfig(type, {
        [key]: 'javascript:alert(1)',
        buttonText: 'Go',
      });

      expect(errors[key]).toContain('valid http(s) URL');
    },
  );

  it('accepts https and http', () => {
    expect(isHttpUrl('https://cdn.example.com/a.jpg')).toBe(true);
    expect(isHttpUrl('http://cdn.example.com/a.jpg')).toBe(true);
  });

  it('rejects relative paths and other schemes', () => {
    expect(isHttpUrl('/files/a.jpg')).toBe(false);
    expect(isHttpUrl('ftp://example.com/a.jpg')).toBe(false);
    expect(isHttpUrl('javascript:alert(1)')).toBe(false);
  });
});

describe('validation: reply buttons', () => {
  it(`rejects more than ${MAX_REPLY_BUTTONS}`, () => {
    const buttons = [1, 2, 3, 4].map((n) => ({ id: `b${n}`, title: `B${n}` }));
    const errors = validateJourneyNodeConfig('reply_button', { body: 'Pick', buttons });

    expect(errors.buttons).toContain(`at most ${MAX_REPLY_BUTTONS}`);
  });

  it(`accepts exactly ${MAX_REPLY_BUTTONS}`, () => {
    const buttons = [1, 2, 3].map((n) => ({ id: `b${n}`, title: `B${n}` }));

    expect(validateJourneyNodeConfig('reply_button', { body: 'Pick', buttons })).toEqual({});
  });

  it('rejects a button with no label', () => {
    const errors = validateJourneyNodeConfig('reply_button', {
      body: 'Pick',
      buttons: [{ id: 'b1', title: '  ' }],
    });

    expect(errors.buttons).toContain('label');
  });

  it('declares the cap on the field descriptor too', () => {
    const field = getJourneyNode('reply_button')!.configSchema.find((f) => f.key === 'buttons');

    expect(field!.maxItems).toBe(MAX_REPLY_BUTTONS);
  });
});

describe('validation: numeric fields', () => {
  it('rejects a delay of zero or less', () => {
    expect(validateJourneyNodeConfig('delay', { amount: 0, unit: 'minutes' }).amount).toBeDefined();
    expect(validateJourneyNodeConfig('delay', { amount: -3, unit: 'minutes' }).amount).toBeDefined();
  });

  it('accepts a positive delay in a supported unit', () => {
    expect(validateJourneyNodeConfig('delay', { amount: 2, unit: 'hours' })).toEqual({});
  });

  it('rejects an unsupported delay unit', () => {
    expect(validateJourneyNodeConfig('delay', { amount: 2, unit: 'fortnights' }).unit).toBeDefined();
  });

  it('range-checks latitude and longitude', () => {
    expect(validateJourneyNodeConfig('location', { latitude: 91, longitude: 0 }).latitude).toBeDefined();
    expect(validateJourneyNodeConfig('location', { latitude: 0, longitude: 181 }).longitude).toBeDefined();
    expect(validateJourneyNodeConfig('location', { latitude: 12.9, longitude: 77.6 })).toEqual({});
  });

  it('range-checks RAG top K', () => {
    expect(validateJourneyNodeConfig('rag', { knowledgeBaseId: 'kb', topK: 0 }).topK).toBeDefined();
    expect(validateJourneyNodeConfig('rag', { knowledgeBaseId: 'kb', topK: 3 })).toEqual({});
  });
});

describe('validation: required identifiers', () => {
  it.each([
    ['template', 'templateId'],
    ['journey', 'journeyId'],
    ['rag', 'knowledgeBaseId'],
    ['catalog', 'catalogId'],
    ['product', 'productId'],
    ['flow', 'flowId'],
    ['agent', 'agentId'],
  ])('"%s" requires %s', (type, key) => {
    expect(validateJourneyNodeConfig(type, {})[key]).toBeDefined();
  });

  it('requires an API method and url', () => {
    const errors = validateJourneyNodeConfig('api', {});

    expect(errors.method ?? errors.url).toBeDefined();
    expect(validateJourneyNodeConfig('api', { method: 'PATCH', url: 'https://x.test' }).method).toBeDefined();
    expect(validateJourneyNodeConfig('api', { method: 'GET', url: 'https://x.test' })).toEqual({});
  });

  it('requires an email recipient, subject and body', () => {
    const errors = validateJourneyNodeConfig('email', {});

    expect(errors.to).toBeDefined();
    expect(errors.subject).toBeDefined();
    expect(errors.body).toBeDefined();
  });

  it('rejects a malformed literal email address but allows a variable', () => {
    expect(validateJourneyNodeConfig('email', { to: 'nope', subject: 's', body: 'b' }).to).toBeDefined();
    expect(validateJourneyNodeConfig('email', { to: 'ops@example.com', subject: 's', body: 'b' })).toEqual({});
    expect(validateJourneyNodeConfig('email', { to: '{{lead_email}}', subject: 's', body: 'b' })).toEqual({});
  });

  it('requires at least one list section with rows', () => {
    expect(validateJourneyNodeConfig('list', { body: 'b', buttonText: 'Go', sections: [] }).sections).toBeDefined();
    expect(
      validateJourneyNodeConfig('list', { body: 'b', buttonText: 'Go', sections: [{ title: 'S', rows: [] }] }).sections,
    ).toBeDefined();
    expect(
      validateJourneyNodeConfig('list', {
        body: 'b',
        buttonText: 'Go',
        sections: [{ title: 'S', rows: [{ id: 'r1', title: 'Row' }] }],
      }),
    ).toEqual({});
  });
});

describe('validation: unknown node type', () => {
  it('is rejected rather than silently accepted', () => {
    expect(validateJourneyNodeConfig('teleport', {}).type).toContain('Unknown node type');
    expect(isKnownJourneyNodeType('teleport')).toBe(false);
  });
});

// ====================================================================
// 6. Conditional branches
// ====================================================================
describe('legacy question node (Phase 7 Task 5)', () => {
  const base = { prompt_text: 'Pick one', variable_name: 'choice' };

  it('accepts a text question without options', () => {
    expect(validateJourneyNodeConfig('question', { ...base, input_type: 'text' })).toEqual({});
    expect(validateJourneyNodeConfig('question', base)).toEqual({});
  });

  it('requires options for a button or list question, each with a title', () => {
    expect(validateJourneyNodeConfig('question', { ...base, input_type: 'buttons', options: [] }).options).toContain('at least one option');
    expect(validateJourneyNodeConfig('question', { ...base, input_type: 'list' }).options).toContain('at least one option');
    expect(validateJourneyNodeConfig('question', { ...base, input_type: 'buttons', options: [{ id: '', title: ' ' }] }).options).toContain('title');
    expect(validateJourneyNodeConfig('question', { ...base, input_type: 'buttons', options: [{ id: 'y', title: 'Yes' }] })).toEqual({});
  });
});

describe('conditional node branches', () => {
  it('declares explicit TRUE and FALSE handles', () => {
    const handles = getJourneyNode('conditional')!.sourceHandles;

    expect(handles.map((h) => h.id)).toEqual([CONDITIONAL_TRUE_HANDLE, CONDITIONAL_FALSE_HANDLE]);
    expect(handles.map((h) => h.label)).toEqual(['TRUE', 'FALSE']);
  });

  it('is the only palette node with more than one outgoing branch', () => {
    const branching = JOURNEY_PALETTE_NODES.filter((d) => d.sourceHandles.length > 1).map((d) => d.type);

    expect(branching).toEqual(['conditional']);
  });

  it('requires at least one condition', () => {
    expect(validateJourneyNodeConfig('conditional', { conditions: [] }).conditions).toBeDefined();
  });

  it('requires every condition to name a variable and an operator', () => {
    expect(
      validateJourneyNodeConfig('conditional', { conditions: [{ variable: '', operator: 'equals' }] }).conditions,
    ).toContain('variable');
    expect(
      validateJourneyNodeConfig('conditional', { conditions: [{ variable: 'x' }] }).conditions,
    ).toContain('operator');
  });

  it('needs a value for value operators but not for is set / is not set (Phase 7 Task 4)', () => {
    expect(
      validateJourneyNodeConfig('conditional', { conditions: [{ variable: 'x', operator: 'contains', value: '' }] }).conditions,
    ).toContain('value');
    expect(
      validateJourneyNodeConfig('conditional', { conditions: [{ variable: 'x', operator: 'exists' }] }).conditions,
    ).toBeUndefined();
    expect(
      validateJourneyNodeConfig('conditional', { conditions: [{ variable: 'x', operator: 'not_exists' }] }).conditions,
    ).toBeUndefined();
  });

  it('needs a numeric value for number comparisons (Phase 7 Task 4)', () => {
    for (const operator of ['greater_than', 'less_than', 'greater_or_equal', 'less_or_equal']) {
      expect(
        validateJourneyNodeConfig('conditional', { conditions: [{ variable: 'x', operator, value: 'ten' }] }).conditions,
      ).toContain('numeric');
      expect(
        validateJourneyNodeConfig('conditional', { conditions: [{ variable: 'x', operator, value: '10' }] }).conditions,
      ).toBeUndefined();
    }
  });

  it('rejects an outgoing edge that does not name its branch', () => {
    const errors = validateJourneyGraph(
      [{ id: 'n1', type: 'conditional', data: { conditions: [{ variable: 'x', operator: 'equals' }] } }],
      [{ source: 'n1' }],
    );

    expect(errors.n1.branches).toContain('true, false');
  });

  it('accepts edges that name true and false', () => {
    const errors = validateJourneyGraph(
      [{ id: 'n1', type: 'conditional', data: { conditions: [{ variable: 'x', operator: 'equals' }] } }],
      [
        { source: 'n1', sourceHandle: 'true' },
        { source: 'n1', sourceHandle: 'false' },
      ],
    );

    expect(errors.n1).toBeUndefined();
  });

  it('leaves the legacy condition node alone — its branch lives on the edge', () => {
    const errors = validateJourneyGraph(
      [{ id: 'n1', type: 'condition', data: { variable: 'answer' } }],
      [{ source: 'n1' }],
    );

    expect(errors.n1).toBeUndefined();
  });
});

// ====================================================================
// 7. Rendering
// ====================================================================
describe('node rendering', () => {
  it('registers a component for every type, React Flow nodeTypes shaped', () => {
    expect(Object.keys(journeyNodeTypes).sort()).toEqual([...ALL_JOURNEY_NODE_TYPES].sort());

    for (const component of Object.values(journeyNodeTypes)) {
      expect(component).toBeTypeOf('function');
    }
  });

  it.each(ALL_27)('renders "%s" with its icon, label and category', (type) => {
    const definition = getJourneyNode(type)!;

    render(<JourneyNodeShell id={`n_${type}`} type={type} data={definition.defaultConfig} />);

    const node = screen.getByTestId(`journey-node-n_${type}`);

    expect(node).toHaveAttribute('data-node-type', type);
    expect(node).toHaveAttribute('data-node-category', definition.category);
    expect(within(node).getByText(definition.label)).toBeInTheDocument();
    expect(node.querySelector('svg')).not.toBeNull();
  });

  it('shows a configured summary once a node has one', () => {
    render(<JourneyNodeShell id="n1" type="delay" data={{ amount: 5, unit: 'minutes' }} />);

    expect(within(screen.getByTestId('journey-node-n1')).getByText('5 minutes')).toBeInTheDocument();
  });

  it('renders one labelled handle per branch on a conditional node', () => {
    render(<JourneyNodeShell id="n1" type="conditional" data={{ conditions: [] }} />);

    const node = screen.getByTestId('journey-node-n1');

    expect(node.querySelectorAll('[data-handle="source"]')).toHaveLength(2);
    expect(node.querySelector('[data-source-handle="true"]')).not.toBeNull();
    expect(node.querySelector('[data-source-handle="false"]')).not.toBeNull();
    expect(within(node).getByText('TRUE')).toBeInTheDocument();
    expect(within(node).getByText('FALSE')).toBeInTheDocument();
  });

  it('renders a single unlabelled handle on a linear node', () => {
    render(<JourneyNodeShell id="n1" type="text" data={{ text: 'hi' }} />);

    const node = screen.getByTestId('journey-node-n1');

    expect(node.querySelectorAll('[data-handle="source"]')).toHaveLength(1);
    expect(within(node).queryByText('Next')).not.toBeInTheDocument();
  });

  it('gives the entry node no target handle', () => {
    render(<JourneyNodeShell id="n1" type="trigger" data={{}} />);

    expect(screen.getByTestId('journey-node-n1').querySelector('[data-handle="target"]')).toBeNull();
  });

  it('renders an unknown node visibly instead of crashing the canvas', () => {
    render(<JourneyNodeShell id="n1" type="teleport" data={{}} />);

    expect(screen.getByText('Unknown node')).toBeInTheDocument();
    expect(screen.getByText('teleport')).toBeInTheDocument();
  });

  it.each(LEGACY)('still renders the legacy "%s" node', (type) => {
    render(<JourneyNodeShell id={`n_${type}`} type={type} data={{}} />);

    expect(screen.getByTestId(`journey-node-n_${type}`)).toHaveAttribute('data-node-type', type);
  });

  it('surfaces a field error on the node itself', () => {
    render(<JourneyNodeShell id="n1" type="text" data={{}} errors={{ text: 'Message is required.' }} />);

    expect(within(screen.getByTestId('journey-node-n1')).getByText('Message is required.')).toBeInTheDocument();
  });
});

// ====================================================================
// 8. Security
// ====================================================================
describe('security', () => {
  it('declares no secret-bearing field on any node', () => {
    const forbidden = ['password', 'secret', 'token', 'apikey', 'api_key', 'privatekey', 'smtp'];

    for (const definition of JOURNEY_NODE_DEFINITIONS) {
      for (const field of definition.configSchema) {
        const key = field.key.toLowerCase();

        for (const word of forbidden) {
          expect(key.includes(word), `${definition.type}.${field.key} looks like a credential field`).toBe(false);
        }
      }
    }
  });

  it('points the API and payment nodes at a server-side credential instead', () => {
    const api = getJourneyNode('api')!.configSchema.find((f) => f.key === 'credentialRef');
    const payment = getJourneyNode('payment')!.configSchema.find((f) => f.key === 'gatewayRef');

    expect(api!.help).toContain('server');
    expect(payment!.help).toContain('server');
  });

  it('never renders a credential in the node body', () => {
    render(
      <JourneyNodeShell
        id="n1"
        type="api"
        data={{ method: 'GET', url: 'https://api.example.com', headers: [{ key: 'Authorization', value: 'Bearer SUPERSECRET' }] }}
      />,
    );

    expect(screen.getByTestId('journey-node-n1').textContent).not.toContain('SUPERSECRET');
  });

  it('does not evaluate a code node', () => {
    // Rendering a code node must not execute it. If it did, this would
    // throw rather than returning the line-count summary.
    render(<JourneyNodeShell id="n1" type="code" data={{ language: 'javascript', code: 'throw new Error("executed")' }} />);

    expect(within(screen.getByTestId('journey-node-n1')).getByText('1 line')).toBeInTheDocument();
  });
});

// ====================================================================
// 9. Helpers
// ====================================================================
describe('helpers', () => {
  it('extracts {{variables}} from message text', () => {
    expect(extractTemplateVariables('Hi {{name}}, order {{ order_id }} is ready. {{name}}')).toEqual([
      'name',
      'order_id',
    ]);
  });
});

// P5-7 — "in the palette" is not "executable": the runtime list is a strict
// subset of the registry (the backend asserts it equals its own list).
describe('runtime-executable node types', () => {
  it('lists only registered types, the five legacy ones included', () => {
    for (const type of RUNTIME_EXECUTABLE_NODE_TYPES) {
      expect(isKnownJourneyNodeType(type), type).toBe(true);
    }
    for (const legacy of ['trigger', 'message', 'question', 'condition', 'save_lead']) {
      expect(isRuntimeExecutableNodeType(legacy)).toBe(true);
    }
    expect(ALL_JOURNEY_NODE_TYPES.filter((t) => !isRuntimeExecutableNodeType(t))).toHaveLength(20);
  });

  it('reports each blocking type of a graph once', () => {
    expect(nonExecutableNodeTypes([{ type: 'trigger' }, { type: 'api' }, { type: 'text' }, { type: 'api' }, { type: 'email' }])).toEqual(['api', 'email']);
    expect(nonExecutableNodeTypes([{ type: 'trigger' }, { type: 'delay' }])).toEqual([]);
  });
});
