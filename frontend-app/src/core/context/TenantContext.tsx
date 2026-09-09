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
  /** Every registered client account. Populated for Super Admin only. */
  accounts: Account[];
  isLoadingAccounts: boolean;
  /** null = "All Clients (Global View)". Always null for a non-Super-Admin. */
  selectedAccountId: number | null;
  /** The full Account record for selectedAccountId, or null in global view. */
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
  const { isAuthenticated, isSuperAdmin } = useAuth();
  const superAdmin = isAuthenticated && isSuperAdmin();

  const [accounts, setAccounts] = useState<Account[]>([]);
  const [isLoadingAccounts, setIsLoadingAccounts] = useState(false);
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
    if (!superAdmin) return;
    setIsLoadingAccounts(true);
    try {
      // list() is paginated at 15/page by default; the switcher needs
      // every account, so request a high ceiling in one page rather than
      // adding pagination UI to a header dropdown.
      const res = await accountService.list({ per_page: 100 });
      setAccounts(res.data);
    } catch {
      setAccounts([]);
    } finally {
      setIsLoadingAccounts(false);
    }
  }, [superAdmin]);

  useEffect(() => {
    if (superAdmin) {
      void refreshAccounts();
    } else {
      setAccounts([]);
    }
  }, [superAdmin, refreshAccounts]);

  // A selection restored from localStorage (or one whose account was since
  // deleted) can point at an account the freshly-loaded list no longer
  // contains — drop it rather than keep silently scoping every request to
  // an account_id the middleware will 404 on.
  useEffect(() => {
    if (isLoadingAccounts || selectedAccountId === null) return;
    if (!accounts.some((a) => a.id === selectedAccountId)) {
      selectAccount(null);
    }
  }, [accounts, isLoadingAccounts, selectedAccountId, selectAccount]);

  const effectiveSelectedAccountId = superAdmin ? selectedAccountId : null;

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
      selectedAccountId: effectiveSelectedAccountId,
      selectedAccount,
      selectAccount,
      refreshAccounts,
    }),
    [accounts, isLoadingAccounts, effectiveSelectedAccountId, selectedAccount, selectAccount, refreshAccounts],
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
