import { useEffect, useState } from 'react';
import { useAuth } from '../../core/context/AuthContext';
import { useTenant } from '../../core/context/TenantContext';
import industryService from '../../services/industryService';
import type { IndustryContext } from '../../types/education';

/** "industry.module" keys the context marks usable — the same shape `/auth/me` returns for the caller's own account. */
export function usableKeysOf(context: IndustryContext[]): string[] {
  return context.flatMap((industry) => industry.modules.filter((m) => m.allowed).map((m) => `${industry.industry}.${m.key}`));
}

/** AppLayout is also rendered by tests without a TenantProvider; production always has one. No selection then. */
function useSelectedAccountId(): number | null {
  try {
    // eslint-disable-next-line react-hooks/rules-of-hooks
    return useTenant().selectedAccountId ?? null;
  } catch {
    return null;
  }
}

/**
 * Which industry modules the sidebar / route guard may offer (UX only — the API enforces).
 *
 *  - A tenant user (or an Agent acting as itself): `/auth/me` → `user.industry_modules`.
 *  - A Super Admin / Agent who SELECTED a client: that client's `/industry/context` (the server applies the
 *    selected client's industry, capability, module, permission and subscription for this user). The
 *    request is keyed by the selected account; an answer for a previous selection is never used (stale
 *    response protection), and `isLoading` stays true until the current selection's answer arrives.
 *  - A Super Admin with NO selection: nothing — no fallback to any client or the Platform account.
 */
export function useIndustryModuleKeys(): { keys: string[]; isLoading: boolean } {
  const { user, isSuperAdmin } = useAuth();
  const selectedAccountId = useSelectedAccountId();
  const superAdmin = isSuperAdmin();
  const isAgent = !superAdmin && user?.account?.account_type === 'agent';
  const delegated = (superAdmin || isAgent) && selectedAccountId !== null;

  const [result, setResult] = useState<{ account: number; keys: string[] } | null>(null);

  useEffect(() => {
    if (!delegated || selectedAccountId === null) return;
    let cancelled = false;
    industryService
      .context()
      .then((context) => {
        if (!cancelled) setResult({ account: selectedAccountId, keys: usableKeysOf(context) });
      })
      .catch(() => {
        // A client that cannot use industries (or an error) simply shows no industry navigation.
        if (!cancelled) setResult({ account: selectedAccountId, keys: [] });
      });
    return () => {
      cancelled = true;
    };
  }, [delegated, selectedAccountId]);

  if (!delegated) {
    return { keys: superAdmin ? [] : (user?.industry_modules ?? []), isLoading: false };
  }
  const current = result !== null && result.account === selectedAccountId ? result : null;
  return { keys: current?.keys ?? [], isLoading: current === null };
}
