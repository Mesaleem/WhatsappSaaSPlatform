import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react';
import axiosInstance, { AUTH_TOKEN_KEY, AUTH_EVENT_UNAUTHORIZED } from '../api/axiosInstance';
import type {
  ApiErrorResponse,
  LoginCredentials,
  LoginResponse,
  MeResponse,
  User,
} from '../../types/auth';
import type { AccountModule } from '../../types/account';
import { AxiosError } from 'axios';

interface AuthContextValue {
  user: User | null;
  isAuthenticated: boolean;
  isLoading: boolean;
  login: (credentials: LoginCredentials) => Promise<User>;
  logout: () => Promise<void>;
  /** Re-fetches /auth/me and updates the cached user — e.g. after a profile edit. */
  refreshUser: () => Promise<void>;
  hasPermission: (permission: string) => boolean;
  hasRole: (roleName: string) => boolean;
  isSuperAdmin: () => boolean;
  /**
   * Dynamic Navigation Engine — single source of truth for "is this
   * Account::MODULES slug enabled for the current user's tenant account",
   * shared by AppLayout's sidebar filter and ProtectedRoute's route guard
   * so the two can never disagree. Always true for Super Admin (no
   * tenant account to be restricted by). `allowed_modules === null`
   * means "every module enabled" — the default until a Super Admin
   * explicitly narrows it via Manage Clients (mirrors
   * Account::hasModuleEnabled() on the backend).
   *
   * 3-Tier Hierarchy & Agent-Client Scope Engine (Phase 2): for a Client
   * nested under an Agent, this also honors whatever the Agent CURRENTLY
   * has enabled, via the account's `effective_modules` (see
   * Account::effectiveModules() on the backend) rather than the raw
   * `allowed_modules` column alone.
   */
  hasModule: (module: AccountModule) => boolean;
  /**
   * Read-Only Subscription Expired Mode: true for a non-Super-Admin whose
   * account is suspended or whose current subscription is not active.
   * Never true for Super Admin (no tenant account to be blocked by).
   * Computed synchronously from the cached `user` on every render, so
   * it's correct from first paint rather than only after a mutating
   * request has already been rejected once. Pages use this to disable
   * mutation controls (Send Alert, Create User, Add Chatbot Rule, Save
   * Settings, ...) while leaving every read view fully accessible — the
   * backend's SubscriptionGuardMiddleware enforces the same GET-vs-
   * mutating split server-side as the authoritative backstop.
   */
  isReadOnly: () => boolean;
}

const AuthContext = createContext<AuthContextValue | undefined>(undefined);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  const clearSession = useCallback(() => {
    localStorage.removeItem(AUTH_TOKEN_KEY);
    setUser(null);
  }, []);

  const hydrate = useCallback(async () => {
    const token = localStorage.getItem(AUTH_TOKEN_KEY);
    if (!token) {
      setIsLoading(false);
      return;
    }
    try {
      const { data } = await axiosInstance.get<MeResponse>('/auth/me');
      setUser(data.user);
    } catch {
      clearSession();
    } finally {
      setIsLoading(false);
    }
  }, [clearSession]);

  useEffect(() => {
    void hydrate();
  }, [hydrate]);

  useEffect(() => {
    const onUnauthorized = () => {
      clearSession();
    };
    window.addEventListener(AUTH_EVENT_UNAUTHORIZED, onUnauthorized);
    return () => {
      window.removeEventListener(AUTH_EVENT_UNAUTHORIZED, onUnauthorized);
    };
  }, [clearSession]);

  const login = useCallback(async (credentials: LoginCredentials): Promise<User> => {
    try {
      const { data } = await axiosInstance.post<LoginResponse>('/auth/login', credentials);
      localStorage.setItem(AUTH_TOKEN_KEY, data.token);
      setUser(data.user);
      return data.user;
    } catch (err) {
      const axiosErr = err as AxiosError<ApiErrorResponse>;
      const message = axiosErr.response?.data?.message ?? 'Unable to sign in. Please try again.';
      throw new Error(message);
    }
  }, []);

  const logout = useCallback(async () => {
    try {
      await axiosInstance.post('/auth/logout');
    } finally {
      clearSession();
    }
  }, [clearSession]);

  const hasPermission = useCallback(
    (permission: string) => user?.permissions.includes(permission) ?? false,
    [user],
  );

  // Dynamic Multi-Role Sidebar Aggregation refactor — ROOT-CAUSE FIX:
  // both of these previously read a single `user.role` object
  // (`user?.role.name === ...`), so a user holding more than one role
  // could be misclassified depending on which role the backend's
  // (now-fixed) roles.first() happened to return. Both now check
  // membership across the FULL `user.roles` array the backend sends.
  const hasRole = useCallback(
    (roleName: string) => user?.roles.some((r) => r.name === roleName) ?? false,
    [user],
  );

  const isSuperAdmin = useCallback(
    () => user?.roles.some((r) => r.name === 'super_admin') ?? false,
    [user],
  );

  const hasModule = useCallback(
    (module: AccountModule) => {
      if (isSuperAdmin()) return true;
      const account = user?.account;
      if (!account) return false;
      // 3-Tier Hierarchy & Agent-Client Scope Engine (Phase 2) — prefer
      // the backend's authoritative, already-hierarchy-capped
      // effective_modules (see Account::effectiveModules()) over the
      // raw allowed_modules column. `account` here is whichever account
      // the current user belongs to — an Agent's own Agent account, or
      // a Client's own (possibly Agent-owned) account — so this one
      // check already covers both cases the spec calls out separately;
      // effective_modules is what makes a Client's check also honor its
      // Agent's current grants, without duplicating that intersection
      // logic here. Falls back to the pre-Phase-2 allowed_modules check
      // only when effective_modules isn't present (e.g. a cached user
      // object from before the next /auth/me refresh) — identical
      // behavior to before this phase existed.
      if (account.effective_modules) {
        return account.effective_modules.includes(module);
      }
      const allowedModules = account.allowed_modules ?? null;
      return allowedModules === null || allowedModules.includes(module);
    },
    [user, isSuperAdmin],
  );

  const isReadOnly = useCallback(() => {
    if (!user || isSuperAdmin()) return false;
    const account = user.account;
    if (!account) return false;
    return account.status !== 'active' || account.current_subscription?.status !== 'active';
  }, [user, isSuperAdmin]);

  const value = useMemo<AuthContextValue>(
    () => ({
      user,
      isAuthenticated: !!user,
      isLoading,
      login,
      logout,
      refreshUser: hydrate,
      hasPermission,
      hasRole,
      isSuperAdmin,
      hasModule,
      isReadOnly,
    }),
    [user, isLoading, login, logout, hydrate, hasPermission, hasRole, isSuperAdmin, hasModule, isReadOnly],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return ctx;
}
