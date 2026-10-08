import type { Account, AccountModule } from '../types/account';

/**
 * Pure "does this one account have this module enabled" check, with no
 * opinion on WHICH account should be checked (caller's own, or a
 * Super Admin/Agent's currently selected client) or on the Super Admin
 * bypass — callers decide both. Extracted out of
 * AuthContext.hasModule() so useEffectiveHasModule (below) can run the
 * exact same rule against TenantContext.selectedAccount instead of
 * always the caller's own account.
 *
 * Prefers the backend's hierarchy-capped `effective_modules` (see
 * Account::effectiveModules()) over the raw `allowed_modules` column,
 * falling back to `allowed_modules` only when `effective_modules`
 * wasn't included in this particular response (e.g. a list endpoint
 * that doesn't append it). `allowed_modules === null` means "every
 * module enabled" (Account::hasModuleEnabled()'s own default).
 */
export function accountHasModule(account: Account | null | undefined, module: AccountModule): boolean {
  if (!account) return false;
  if (account.effective_modules) {
    return account.effective_modules.includes(module);
  }
  const allowedModules = account.allowed_modules ?? null;
  return allowedModules === null || allowedModules.includes(module);
}
