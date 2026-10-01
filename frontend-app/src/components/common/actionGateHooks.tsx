import { useCallback, useState, type ReactNode } from 'react';
import { useAuth } from '../../core/context/AuthContext';
import { useTenant } from '../../core/context/TenantContext';
import { ClientPickerModal, type GateNoticeAction } from './ActionGate';

/**
 * Final hardening §23 — the hooks/helpers behind ActionGate.tsx's components
 * (kept apart so that file exports components only). UX only: nothing here
 * authorizes anything; the backend remains the authority.
 */

/**
 * `guard(action, purpose)` runs `action` at once when a tenant is resolved;
 * for a Super Admin in Global View it first opens the client picker and runs
 * `action` right after a client is chosen. Render `picker` somewhere in the page.
 */
/**
 * Owner request (2026-09-30): `platformFallback` — on Social / Ads pages a
 * Super Admin with no client selected works on its own "Platform (Super
 * Admin)" account (user.platform_crm_account; backend target.account falls
 * back to it), so no client has to be picked when that account exists.
 */
export function useClientGate(options: { platformFallback?: boolean } = {}): {
  needsClient: boolean;
  guard: (action: () => void, purpose: string) => void;
  openPicker: (purpose: string) => void;
  picker: ReactNode;
} {
  const { isSuperAdmin, user } = useAuth();
  const { selectedAccountId } = useTenant();
  const needsClient = isSuperAdmin() && selectedAccountId === null && !(options.platformFallback && user?.platform_crm_account);
  const [pending, setPending] = useState<{ purpose: string; action: (() => void) | null } | null>(null);

  const guard = useCallback(
    (action: () => void, purpose: string) => {
      if (needsClient) {
        setPending({ purpose, action });
      } else {
        action();
      }
    },
    [needsClient],
  );

  const openPicker = useCallback((purpose: string) => setPending({ purpose, action: null }), []);

  const picker = pending ? (
    <ClientPickerModal
      purpose={pending.purpose}
      onCancel={() => setPending(null)}
      onSelected={() => {
        const next = pending.action;
        setPending(null);
        next?.();
      }}
    />
  ) : null;

  return { needsClient, guard, openPicker, picker };
}

/**
 * Where a user can go to get a missing plan capability or module: a Super
 * Admin manages the selected client's entitlements under Accounts; a tenant
 * user who may manage subscriptions goes to Billing & Plans; anyone else is
 * told to ask their administrator (null — no link they could not open).
 */
export function useUpgradePath(): { action: GateNoticeAction | null; hint: string } {
  const auth = useAuth();
  // Defensive: partial AuthContext values (test doubles) may omit helpers.
  const can = (permission: string) => (typeof auth.hasPermission === 'function' ? auth.hasPermission(permission) : false);
  const moduleOn = (module: 'billing') => (typeof auth.hasModule === 'function' ? auth.hasModule(module) : false);

  if (auth.isSuperAdmin()) {
    return { action: { label: 'Open Accounts', to: '/admin/accounts' }, hint: "Enable it for this client from the client's entitlements under Accounts." };
  }

  if (can('manage-subscriptions') && moduleOn('billing')) {
    return { action: { label: 'View plans', to: '/billing' }, hint: 'Upgrade the plan under Billing & Plans to include it.' };
  }

  return { action: null, hint: 'Ask your account administrator to upgrade the plan.' };
}

/**
 * A `returnTo` target from the query string, accepted only when it is an
 * in-app path (starts with a single '/'), never an absolute or
 * protocol-relative URL — so it cannot be used as an open redirect.
 */
export function safeReturnTo(value: string | null): string | null {
  if (!value || !value.startsWith('/') || value.startsWith('//') || value.includes('\\')) return null;
  return value;
}
