import type { JourneyNodeDefinition } from './nodeRegistry';
import type { JourneyNodeProvider } from '../types/journeyNodes';

/**
 * Phase 5 Task 7 — Journey node entitlement, for UX ONLY.
 *
 * THIS IS NOT SECURITY. It mirrors JourneyNodeAuthorizer's rule so the
 * palette can grey out a node the account cannot use and say why,
 * instead of letting an operator configure one and discover on Save
 * that it was never available. The server re-derives every one of these
 * decisions from the account's own entitlements and its own
 * subscription's engine_type, and a 403 from it is the real answer. A
 * tampered browser gains nothing here: the worst it achieves is showing
 * an enabled button whose Save is refused.
 *
 * The two rules are kept deliberately identical to the backend:
 *   - capabilities are an AND (every declared slug must be held);
 *   - providers are an OR, EXCEPT that a list containing 'none' waives
 *     the engine requirement entirely — 'none' on a node means "needs no
 *     WhatsApp engine", not "only accounts without one". See
 *     JourneyNodeCatalog::PLATFORM_PROVIDER.
 */

/** Matches the backend's JourneyNodeCatalog::PLATFORM_PROVIDER. */
export const PLATFORM_PROVIDER: JourneyNodeProvider = 'none';

export interface JourneyEntitlementContext {
  /** { capability_slug => granted }, straight from /auth/me. */
  capabilities: Record<string, boolean> | null | undefined;
  /** The account's engine, from its current subscription. Null when it has none. */
  provider: string | null | undefined;
  /** Super Admin is never blocked by a toggle they control — same bypass the backend gives. */
  isSuperAdmin?: boolean;
}

export interface JourneyNodeAvailability {
  available: boolean;
  /** Null when available; otherwise a short sentence for a tooltip. */
  reason: string | null;
}

const AVAILABLE: JourneyNodeAvailability = { available: true, reason: null };

export function journeyNodeAvailability(
  definition: Pick<JourneyNodeDefinition, 'label' | 'capabilities' | 'providers'>,
  context: JourneyEntitlementContext,
): JourneyNodeAvailability {
  if (context.isSuperAdmin) {
    return AVAILABLE;
  }

  const providers = definition.providers ?? [];
  const needsEngine = providers.length > 0 && !providers.includes(PLATFORM_PROVIDER);

  if (needsEngine && (!context.provider || !providers.includes(context.provider as JourneyNodeProvider))) {
    return {
      available: false,
      reason: context.provider
        ? `${definition.label} is not available on your WhatsApp provider (${context.provider}).`
        : `${definition.label} needs a connected WhatsApp provider.`,
    };
  }

  // No capability map yet (still loading, or an older /auth/me response):
  // do not invent a denial the server has not made. The Save request is
  // still authorized server-side.
  if (!context.capabilities) {
    return AVAILABLE;
  }

  for (const capability of definition.capabilities ?? []) {
    if (!context.capabilities[capability]) {
      return {
        available: false,
        reason: `${definition.label} requires the "${capability}" capability, which this account does not hold.`,
      };
    }
  }

  return AVAILABLE;
}
