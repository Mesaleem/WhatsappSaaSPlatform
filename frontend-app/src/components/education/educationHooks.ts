import { useAuth } from '../../core/context/AuthContext';
import { useTenant } from '../../core/context/TenantContext';

/**
 * Who is looking at Education right now.
 *
 * needsClient  a Super Admin with NO client selected. There is deliberately no "own account" or Platform
 *              fallback (unlike the CRM): the backend answers 422 without a target, so the page asks first.
 * readOnly     expired subscription — the backend 403s writes; the UI disables them first.
 * canManage    holds `manage-education` (Super Admin bypasses). Hides write controls only; the API decides.
 */
export function useEducationContext() {
  const { isSuperAdmin, isReadOnly, hasPermission } = useAuth();
  const { selectedAccountId } = useTenant();
  const superAdmin = isSuperAdmin();

  return {
    needsClient: superAdmin && selectedAccountId === null,
    readOnly: isReadOnly(),
    canManage: superAdmin || hasPermission('manage-education'),
    selectedAccountId,
  };
}

export const EDUCATION_DEFAULT_PER_PAGE = 20;
