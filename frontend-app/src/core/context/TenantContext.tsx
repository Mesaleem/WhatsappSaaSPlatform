import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react';
import accountService from '../../services/accountService';
import type { Account } from '../../types/account';
import { setSelectedAccountId } from '../api/axiosInstance';
import { useAuth } from './AuthContext';

const STORAGE_KEY = 'super_admin_selected_account_id';

function readStoredAccountId(): number | null {
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);
    const parsed = raw ? Number(raw) : NaN;
    return Number.isFinite(parsed) ? parsed : null;
  } catch {
    // localStorage can throw in private-browsing/blocked-storage contexts —
    // fall back to "no selection" rather than failing the whole app shell.
    return null;
  }
}

interface TenantContextValue {
  /**
   * Every account the caller may switch into: every tenant for Super
   * Admin, or just the caller's own Sub-Clients for an Agent (see
   * `canSwitchClients`). Empty for a plain Client Admin/User/Social
   * Marketer.
   */
  accounts: Account[];
  isLoadingAccounts: boolean;
  /**
   * True for Super Admin, and for an Agent (Account.account_type ===
   * 'agent') — the two caller types TenantIsolationMiddleware allows to
   * override the tenant via ?account_id= (Super Admin unrestricted, an
   * Agent scoped to its own Sub-Client tree). False for everyone else,
   * who always act on their own single account.
   */
  canSwitchClients: boolean;
  /** null = "All Clients (Global View)" for Super Admin, or "acting as my own Agent account" for an Agent. Always null for anyone else. */
  selectedAccountId: number | null;
  /** The full Account record for selectedAccountId, or null in global/own-account view. */
  selectedAccount: Account | null;
  selectAccount: (id: number | null) => void;
  refreshAccounts: () => Promise<void>;
}

const TenantContext = createContext<TenantContextValue | undefined>(undefined);

/**
 * Super Admin Multi-Tenant Scoping — global context so the Header's
 * "Select Client" dropdown and every tenant-scoped page (Dashboard, Send
 * Alert, Analytics, Team Users) agree on which client is currently being
 * acted on. The actual HTTP scoping happens in axiosInstance's request
 * interceptor (setSelectedAccountId) so pages don't need to read this
 * context just to make a correctly-scoped API call — they only read it to
 * render the switcher/labels.
 */
export function TenantProvider({ children }: { children: ReactNode }) {
  const { isAuthenticated, isSuperAdmin, user } = useAuth();
  const superAdmin = isAuthenticated && isSuperAdmin();
  // Agent Client-Switcher: an Agent (never Super Admin) whose OWN account
  // is of type 'agent' may act as any of its own Sub-Clients, the same
  // mechanism Super Admin already has, scoped by TenantIsolationMiddleware
  // to accounts whose agent_id matches the Agent's own account id.
  const isAgentCaller = isAuthenticated && !superAdmin && user?.account?.account_type === 'agent';
  const canSwitchClients = superAdmin || isAgentCaller;

  const [accounts, setAccounts] = useState<Account[]>([]);
  const [isLoadingAccounts, setIsLoadingAccounts] = useState(false);
  // True only once a list request has SUCCEEDED. Before that, `accounts`
  // is just the initial [] (or a failed load's []), which says nothing
  // about whether a stored selection is still valid.
  const [hasLoadedAccounts, setHasLoadedAccounts] = useState(false);
  const [selectedAccountId, setSelectedAccountIdState] = useState<number | null>(readStoredAccountId);

  const selectAccount = useCallback((id: number | null) => {
    setSelectedAccountIdState(id);
    try {
      if (id === null) {
        window.localStorage.removeItem(STORAGE_KEY);
      } else {
        window.localStorage.setItem(STORAGE_KEY, String(id));
      }
    } catch {
      // Selection still works for this session via React state even if it
      // can't be persisted.
    }
  }, []);

  const refreshAccounts = useCallback(async () => {
    if (!canSwitchClients) return;
    setIsLoadingAccounts(true);
    try {
      // list() is paginated at 15/page by default; the switcher needs
      // every account, so request a high ceiling in one page rather than
      // adding pagination UI to a header dropdown. For an Agent caller,
      // AccountController::index() already scopes this to just their own
      // Sub-Clients (callerAgentScopeId()) — no separate endpoint needed.
      const res = await accountService.list({ per_page: 100 });
      setAccounts(res.data);
      setHasLoadedAccounts(true);
    } catch {
      setAccounts([]);
    } finally {
      setIsLoadingAccounts(false);
    }
  }, [canSwitchClients]);

  useEffect(() => {
    if (canSwitchClients) {
      void refreshAccounts();
    } else {
      setAccounts([]);
    }
  }, [canSwitchClients, refreshAccounts]);

  // A selection restored from localStorage (or one whose account was since
  // deleted) can point at an account the freshly-loaded list no longer
  // contains — drop it rather than keep silently scoping every request to
  // an account_id the middleware will 404 on.
  //
  // [Bugfix] Only after a list has actually loaded. This effect used to run
  // on the first render, before refreshAccounts() had even flagged itself
  // as loading, with accounts === [] — so every page reload wiped the
  // stored selection and put a Super Admin back in "All Clients (Global
  // View)" (e.g. CRM Leads then hid "Add lead"). The server still rejects
  // an invalid or foreign ?account_id= (TenantIsolationMiddleware), so
  // keeping the selection until the list arrives exposes nothing.
  useEffect(() => {
    if (!hasLoadedAccounts || isLoadingAccounts || selectedAccountId === null) return;
    if (!accounts.some((a) => a.id === selectedAccountId)) {
      selectAccount(null);
    }
  }, [accounts, hasLoadedAccounts, isLoadingAccounts, selectedAccountId, selectAccount]);

  const effectiveSelectedAccountId = canSwitchClients ? selectedAccountId : null;

  useEffect(() => {
    setSelectedAccountId(effectiveSelectedAccountId);
  }, [effectiveSelectedAccountId]);

  const selectedAccount = useMemo(
    () => (effectiveSelectedAccountId ? accounts.find((a) => a.id === effectiveSelectedAccountId) ?? null : null),
    [accounts, effectiveSelectedAccountId],
  );

  const value = useMemo<TenantContextValue>(
    () => ({
      accounts,
      isLoadingAccounts,
      canSwitchClients,
      selectedAccountId: effectiveSelectedAccountId,
      selectedAccount,
      selectAccount,
      refreshAccounts,
    }),
    [accounts, isLoadingAccounts, canSwitchClients, effectiveSelectedAccountId, selectedAccount, selectAccount, refreshAccounts],
  );

  return <TenantContext.Provider value={value}>{children}</TenantContext.Provider>;
}

export function useTenant(): TenantContextValue {
  const ctx = useContext(TenantContext);
  if (!ctx) {
    throw new Error('useTenant must be used within a TenantProvider');
  }
  return ctx;
}
