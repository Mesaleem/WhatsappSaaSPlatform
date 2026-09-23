import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useAuth } from '../../core/context/AuthContext';
import { useTenant } from '../../core/context/TenantContext';
import crmService from '../../services/crmService';
import type { CrmAssignee, CrmLeadFilters, CrmTagRef } from '../../types/crm';
import { describeApiError } from '../../utils/apiError';
import { buildCrmUrl, parseCrmUrl, type CrmUrlState } from './crmFilters';

/**
 * Phase 6 — CRM Task 8. Hooks shared by the CRM pages. No global store,
 * no query library (the app has none): each page owns its own data and
 * re-reads the server after a mutation, which is the existing convention.
 */

/**
 * Who is looking at the CRM right now.
 *
 * needsClient  a Super Admin with no client selected AND no own platform
 *              CRM account — only then would every /api/crm/* call 422, so
 *              pages show the "select a client" prompt. With a platform
 *              account (user.platform_crm_account), no selection means "my
 *              own CRM" and the backend (EnsureCrmTargetAccount) targets it.
 * selectedClientName  the switcher's selected client, when one is selected
 *              (shown as "For client: …" when adding a lead).
 * readOnly     AuthContext::isReadOnly() — expired subscription. The backend
 *              403s mutations (SubscriptionGuardMiddleware); the UI
 *              disables them first, the existing convention.
 */
export function useCrmContext() {
  const { isSuperAdmin, isReadOnly, user } = useAuth();
  const { selectedAccountId, selectedAccount } = useTenant();
  const needsClient = isSuperAdmin() && selectedAccountId === null && !user?.platform_crm_account;
  return {
    needsClient,
    readOnly: isReadOnly(),
    selectedAccountId,
    selectedClientName: selectedAccountId !== null ? (selectedAccount?.company_name ?? null) : null,
  };
}

/** Lead filter + pagination state, kept in the URL query string. */
export function useCrmUrlState() {
  const [params, setParams] = useSearchParams();
  const state = useMemo(() => parseCrmUrl(params), [params]);

  const write = useCallback(
    (next: CrmUrlState) => {
      setParams(buildCrmUrl(next));
    },
    [setParams],
  );

  /** Merge a filter change; any filter change goes back to page 1. Other filters are kept. */
  const setFilters = useCallback(
    (patch: Partial<CrmLeadFilters>) => {
      const filters: CrmLeadFilters = { ...state.filters, ...patch };
      (Object.keys(patch) as (keyof CrmLeadFilters)[]).forEach((key) => {
        const value = filters[key];
        if (value === undefined || value === '' || (Array.isArray(value) && value.length === 0)) {
          delete filters[key];
        }
      });
      write({ ...state, filters, page: 1 });
    },
    [state, write],
  );

  const clearFilters = useCallback(() => write({ ...state, filters: {}, page: 1 }), [state, write]);
  const setPage = useCallback((page: number) => write({ ...state, page }), [state, write]);
  const setPerPage = useCallback((perPage: number) => write({ ...state, perPage, page: 1 }), [state, write]);

  return { ...state, setFilters, clearFilters, setPage, setPerPage };
}

/**
 * One server read, keyed. `key` is everything the request depends on
 * (serialized); when it changes a new request is made and the previous
 * one's response is ignored (so a slow, stale response can never
 * overwrite a newer filter's results). `key === null` disables the query
 * (e.g. a Super Admin with no client selected).
 *
 * State is only ever written from the request's own callbacks, never
 * synchronously inside the effect. `reload()` re-reads the same key
 * without flashing the loading state (used after a mutation);
 * `setData()` applies the server's returned row immediately.
 */
export function useCrmQuery<T>(key: string | null, fetcher: () => Promise<T>, errorFallback: string) {
  const fetcherRef = useRef(fetcher);
  useEffect(() => {
    fetcherRef.current = fetcher;
  });

  const [token, setToken] = useState(0);
  const [result, setResult] = useState<{
    key: string;
    data: T | null;
    error: string | null;
    status: number | null;
  } | null>(null);

  useEffect(() => {
    if (key === null) return;
    let cancelled = false;
    fetcherRef
      .current()
      .then((data) => {
        if (!cancelled) setResult({ key, data, error: null, status: null });
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        const described = describeApiError(err, errorFallback);
        setResult({ key, data: null, error: described.message, status: described.status });
      });
    return () => {
      cancelled = true;
    };
  }, [key, token, errorFallback]);

  const reload = useCallback(() => setToken((t) => t + 1), []);
  const setData = useCallback((update: (current: T) => T) => {
    setResult((r) => (r && r.data !== null ? { ...r, data: update(r.data) } : r));
  }, []);

  const current = result !== null && result.key === key ? result : null;

  return {
    data: current?.data ?? null,
    error: current?.error ?? null,
    status: current?.status ?? null,
    isLoading: key !== null && current === null,
    reload,
    setData,
  };
}

/**
 * Eligible assignees — GET /api/crm/assignees, the backend's own
 * eligibility rule (CrmLead::assigneeIsEligible). One request per page
 * mount / client switch, never per lead.
 */
export function useCrmAssignees(enabled: boolean, accountKey: number | null) {
  const query = useCrmQuery<CrmAssignee[]>(
    enabled ? `assignees:${accountKey ?? 'own'}` : null,
    () => crmService.assignees(),
    'Failed to load assignees.',
  );
  return { assignees: query.data ?? [], error: query.error !== null };
}

/**
 * Names for tag ids that appear in the URL filter. One request (the first
 * 100 tags — the endpoint's cap) per page mount / client switch; a tag
 * outside that page is still filterable and is shown as "Tag #id".
 */
export function useCrmTagIndex(enabled: boolean, accountKey: number | null) {
  const query = useCrmQuery<CrmTagRef[]>(
    enabled ? `tag-index:${accountKey ?? 'own'}` : null,
    () => crmService.listTags('', 1, 100).then((res) => res.data.map((t) => ({ id: t.id, name: t.name }))),
    'Failed to load tags.',
  );
  const tags = useMemo(() => query.data ?? [], [query.data]);
  const nameOf = useCallback((id: number) => tags.find((t) => t.id === id)?.name ?? `Tag #${id}`, [tags]);
  return { tags, nameOf, reload: query.reload };
}

/** The app's existing inline toast pattern (see CommentRulesPage), as a hook. */
export function useCrmToast() {
  const [toast, setToast] = useState<string | null>(null);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  const show = useCallback((message: string) => {
    setToast(message);
    if (timer.current) clearTimeout(timer.current);
    timer.current = setTimeout(() => setToast(null), 4000);
  }, []);

  useEffect(
    () => () => {
      if (timer.current) clearTimeout(timer.current);
    },
    [],
  );

  return { toast, show };
}
