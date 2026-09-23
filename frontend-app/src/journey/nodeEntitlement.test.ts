import { describe, expect, it } from 'vitest';

import { PLATFORM_PROVIDER, journeyNodeAvailability } from './nodeEntitlement';
import { JOURNEY_PALETTE_NODES, getJourneyNode } from './nodeRegistry';

/**
 * Phase 5 Task 7 — entitlement UX.
 *
 * These tests pin the CLIENT-SIDE mirror of JourneyNodeAuthorizer's
 * rule. They are explicitly not security tests: the server refuses an
 * unentitled node with 403 JOURNEY_NODE_NOT_ENTITLED whatever this
 * helper returns, which JourneyNodeEntitlementTest proves on the
 * backend. What is worth testing here is that the mirror does not
 * DISAGREE with the server — a palette that offers a node the API will
 * refuse, or hides one it would accept, is a UX bug either way.
 */

const NOTHING: Record<string, boolean> = {};

const ALL: Record<string, boolean> = Object.fromEntries(
  JOURNEY_PALETTE_NODES.flatMap((d) => (d.capabilities ?? []).map((c) => [c, true])),
);

describe('journeyNodeAvailability: capabilities', () => {
  it('allows a node whose capabilities are all held', () => {
    const result = journeyNodeAvailability(getJourneyNode('api')!, {
      capabilities: { external_api: true },
      provider: 'meta',
    });

    expect(result).toEqual({ available: true, reason: null });
  });

  it('blocks a node whose capability is missing, and names it', () => {
    const result = journeyNodeAvailability(getJourneyNode('api')!, {
      capabilities: NOTHING,
      provider: 'meta',
    });

    expect(result.available).toBe(false);
    expect(result.reason).toContain('external_api');
  });

  it('requires every capability on a node that declares more than one', () => {
    // catalog needs whatsapp_send AND commerce.
    const halfEntitled = journeyNodeAvailability(getJourneyNode('catalog')!, {
      capabilities: { whatsapp_send: true },
      provider: 'meta',
    });

    expect(halfEntitled.available).toBe(false);
    expect(halfEntitled.reason).toContain('commerce');

    const fullyEntitled = journeyNodeAvailability(getJourneyNode('catalog')!, {
      capabilities: { whatsapp_send: true, commerce: true },
      provider: 'meta',
    });

    expect(fullyEntitled.available).toBe(true);
  });

  it('allows a node that declares no capability at all', () => {
    for (const type of ['delay', 'conditional']) {
      expect(
        journeyNodeAvailability(getJourneyNode(type)!, { capabilities: NOTHING, provider: null }).available,
        type,
      ).toBe(true);
    }
  });

  it('does not invent a denial before the capability map has loaded', () => {
    // An older cached /auth/me, or a request still in flight. The server
    // is still the gate; guessing "denied" here would hide the palette
    // from an entitled tenant.
    expect(journeyNodeAvailability(getJourneyNode('api')!, { capabilities: undefined, provider: 'meta' }).available).toBe(true);
    expect(journeyNodeAvailability(getJourneyNode('api')!, { capabilities: null, provider: 'meta' }).available).toBe(true);
  });
});

describe('journeyNodeAvailability: providers', () => {
  it('blocks a Meta-only node on a QR account', () => {
    const result = journeyNodeAvailability(getJourneyNode('reply_button')!, {
      capabilities: ALL,
      provider: 'qr',
    });

    expect(result.available).toBe(false);
    expect(result.reason).toContain('qr');
  });

  it('allows the same node on a Meta account', () => {
    expect(
      journeyNodeAvailability(getJourneyNode('reply_button')!, { capabilities: ALL, provider: 'meta' }).available,
    ).toBe(true);
  });

  it('allows a dual-provider node on either engine', () => {
    for (const provider of ['qr', 'meta']) {
      expect(
        journeyNodeAvailability(getJourneyNode('text')!, { capabilities: ALL, provider }).available,
        provider,
      ).toBe(true);
    }
  });

  it("treats 'none' as 'no engine required', not 'only accounts without one'", () => {
    // The exact bug this was: a platform node declaring providers:['none']
    // must be available to a Meta account, a QR account AND an account
    // with no engine at all.
    const api = getJourneyNode('api')!;

    expect(api.providers).toContain(PLATFORM_PROVIDER);

    for (const provider of ['meta', 'qr', null]) {
      expect(
        journeyNodeAvailability(api, { capabilities: { external_api: true }, provider }).available,
        `provider=${provider}`,
      ).toBe(true);
    }
  });

  it('explains that a WhatsApp node needs a connected provider when there is none', () => {
    const result = journeyNodeAvailability(getJourneyNode('text')!, { capabilities: ALL, provider: null });

    expect(result.available).toBe(false);
    expect(result.reason).toContain('WhatsApp provider');
  });
});

describe('journeyNodeAvailability: super admin', () => {
  it('never blocks a Super Admin, matching the server-side bypass', () => {
    for (const definition of JOURNEY_PALETTE_NODES) {
      expect(
        journeyNodeAvailability(definition, { capabilities: NOTHING, provider: null, isSuperAdmin: true }).available,
        definition.type,
      ).toBe(true);
    }
  });
});

describe('journeyNodeAvailability: whole palette', () => {
  it('opens every palette node to a fully entitled Meta tenant', () => {
    for (const definition of JOURNEY_PALETTE_NODES) {
      const result = journeyNodeAvailability(definition, { capabilities: ALL, provider: 'meta' });

      expect(result.available, `${definition.type}: ${result.reason}`).toBe(true);
    }
  });

  it('opens exactly the non-Meta-only nodes to a fully entitled QR tenant', () => {
    const open = JOURNEY_PALETTE_NODES.filter(
      (d) => journeyNodeAvailability(d, { capabilities: ALL, provider: 'qr' }).available,
    ).map((d) => d.type);

    // Everything except the nodes whose registry metadata says Meta-only.
    const expected = JOURNEY_PALETTE_NODES.filter((d) => !(d.providers ?? []).every((p) => p === 'meta')).map(
      (d) => d.type,
    );

    expect(open).toEqual(expected);
  });
});
