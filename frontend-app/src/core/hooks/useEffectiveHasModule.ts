import { useCallback } from 'react';
import { useAuth } from '../context/AuthContext';
import { useTenant } from '../context/TenantContext';
import { accountHasModule } from '../../utils/accountModules';
import type { AccountModule } from '../../types/account';

/**
 * Module-gate check for nav visibility and route access that respects
 * whichever account a Super Admin/Agent is CURRENTLY ACTING AS
 * (TenantContext.selectedAccount), falling back to the caller's own
 * account when nothing is selected (a plain Client user, or a Super
 * Admin/Agent in "global view" / acting as themselves).
 *
 * Why this exists instead of just using AuthContext.hasModule(): that
 * one intentionally always checks the CALLER's own account, which is
 * correct for "is this feature available to me, the logged-in user" but
 * wrong for "is this feature available for the Sub-Client I just
 * switched to" — an Agent's own account rarely has every module its
 * onboarded clients do, so gating their nav/routes on the Agent's own
 * account hid pages (e.g. WhatsApp Setup, and the addon-number list on
 * it) for clients that DO have the module, even though the backend
 * (EnsureModuleEnabledMiddleware) already correctly scopes to the
 * selected account via TenantIsolationMiddleware's account_id — only
 * the frontend gate disagreed. Super Admin is unaffected either way
 * (AuthContext.hasModule() already bypasses for them).
 */
export function useEffectiveHasModule(): (module: AccountModule) => boolean {
  const { user, isSuperAdmin } = useAuth();
  const { selectedAccount } = useTenant();

  return useCallback(
    (module: AccountModule) => {
      if (isSuperAdmin()) return true;
      const account = selectedAccount ?? user?.account;
      return accountHasModule(account, module);
    },
    [isSuperAdmin, selectedAccount, user],
  );
}
