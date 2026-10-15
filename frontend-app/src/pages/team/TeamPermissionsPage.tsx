import { useCallback, useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { AlertCircle, CheckCircle2, Contact, Loader2, MessageSquare, Radio, Settings2, Share2, ShieldCheck } from 'lucide-react';
import { roleLabel } from '../../theme/signalIndigo';
import teamService from '../../services/teamService';
import { useAuth } from '../../core/context/AuthContext';
import { extractErrorMessage as extractMessage } from '../../utils/apiError';
import type { TeamUser } from '../../types/team';
import { MATRIX_GROUPS, MATRIX_PERMISSION_LABELS, type MatrixPermission, type TeamMemberPermissions } from '../../types/permissions';

const GROUP_ICONS: Record<string, typeof MessageSquare> = {
  whatsapp: MessageSquare,
  whatsapp_admin: Settings2,
  crm: Contact,
  social: Share2,
  ads: Radio,
};
import DismissibleAlert from '../../components/common/DismissibleAlert';

const inputClass =
  'mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100';

/**
 * Client Admin Granular Permission Matrix (/team/permissions).
 *
 * SUPER ADMIN / CLIENT ADMIN RBAC BOUNDARY REFACTOR: this page moved here
 * from pages/users/PermissionsMatrixPage.tsx and is now EXCLUSIVELY the
 * Client Admin's own workspace — the route (see App.tsx) is guarded with
 * `strictRole="admin"`, which (unlike ProtectedRoute's plain `role` prop)
 * does NOT bypass for Super Admin, so this screen is genuinely
 * unreachable by Super Admin, not merely hidden from a nav link. Every
 * cross-tenant `?account_id=`/Super-Admin branch that the old
 * PermissionsMatrixPage carried has been removed as dead code, not just
 * hidden — there is no code path left in this file that can act on any
 * account other than the caller's own. Backend enforcement mirrors this:
 * TeamController::permissions()/updatePermissions() both now reject any
 * caller who does not hold the 'admin' role, independent of this route
 * guard.
 *
 * ROOT-CAUSE DESIGN NOTE (unchanged from the original implementation —
 * see backend TeamController::MANAGED_PERMISSIONS' docblock and the
 * Client Provisioning refactor's audit report): this screen edits DIRECT
 * per-user permission grants, never a Role's permission set — Spatie
 * roles are global across every tenant in this app (config/permission.php
 * has 'teams' => false), so editing a Role here would silently change
 * permissions for every OTHER tenant sharing that role name. A checkbox
 * that is checked because the user's ROLE already grants it (not this
 * matrix) is shown checked but disabled — it can't be revoked from this
 * per-user screen.
 *
 * MODULE-AWARE FILTERING (new in this refactor): "Filter available CRUD
 * options in the matrix so Client Admin can ONLY grant permissions for
 * modules enabled on their Client Account by Super Admin." The WhatsApp
 * Module group is shown only if the account has 'chatbot' or
 * 'send_alert' enabled (exactly the two Account::MODULES slugs the 5
 * WhatsApp checkboxes map onto — see TeamController's docblock: whatsapp.*
 * -> ChatbotRuleController CRUD, i.e. 'chatbot'; Send Messages ->
 * 'send-messages', i.e. 'send_alert'). The Social Ads Module group is
 * shown only if 'meta_ads' is enabled (AdCampaignController's index/
 * launch/updateCplThreshold, which the 4 Social Ads checkboxes map onto).
 * Uses AuthContext::hasModule() directly against the caller's OWN
 * account — no extra API call needed, since /auth/me already returns
 * `account.allowed_modules`.
 */
function PermissionGroup({
  title,
  icon: Icon,
  permissions,
  direct,
  effective,
  onToggle,
  hint,
}: {
  title: string;
  hint?: string;
  icon: typeof MessageSquare;
  permissions: readonly MatrixPermission[];
  direct: MatrixPermission[];
  effective: MatrixPermission[];
  onToggle: (permission: MatrixPermission) => void;
}) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
      <div className="flex items-center gap-2 text-sm font-semibold text-slate-900">
        <Icon className="h-4 w-4 text-emerald-700" />
        {title}
      </div>
      {hint && <p className="mt-1 text-xs text-slate-500">{hint}</p>}
      <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3">
        {permissions.map((permission) => {
          const isEffective = effective.includes(permission);
          const isDirect = direct.includes(permission);
          const inheritedOnly = isEffective && !isDirect;
          return (
            <label
              key={permission}
              className={`flex items-center gap-2 rounded-lg border px-3 py-2 text-sm transition ${
                isEffective ? 'border-emerald-200 bg-emerald-50/50 text-emerald-950' : 'border-slate-200 text-slate-700'
              } ${inheritedOnly ? 'opacity-70' : ''}`}
              title={inheritedOnly ? 'Granted via this user’s role — change their role to revoke it.' : undefined}
            >
              <input
                type="checkbox"
                checked={isEffective}
                disabled={inheritedOnly}
                onChange={() => onToggle(permission)}
                className="h-4 w-4 rounded border-slate-300 text-emerald-700 focus:ring-emerald-500 disabled:opacity-60"
              />
              <span>
                {MATRIX_PERMISSION_LABELS[permission]}
                {inheritedOnly && <span className="ml-1 text-[10px] uppercase tracking-wide text-emerald-500">(via role)</span>}
              </span>
            </label>
          );
        })}
      </div>
    </div>
  );
}

export default function TeamPermissionsPage() {
  const { hasModule } = useAuth();

  const [searchParams, setSearchParams] = useSearchParams();

  const [users, setUsers] = useState<TeamUser[]>([]);
  const [isLoadingUsers, setIsLoadingUsers] = useState(true);
  const [listError, setListError] = useState<string | null>(null);

  const selectedUserId = searchParams.get('user');
  // Permissions Matrix Dropdown Filtering — an 'admin' team member
  // already holds every managed permission via their role (see
  // TeamController::MANAGED_PERMISSIONS' docblock) and can't be edited
  // here (the backend's revoke-then-grant would have no visible effect,
  // since role-derived permissions can't be revoked from this per-user
  // screen). Filtered out of both the picker AND the id lookup, so a
  // stale ?user=<admin-id> link can't still load their (non-editable)
  // matrix.
  const nonAdminUsers = useMemo(() => users.filter((u) => !u.roles.some((r) => r.name === 'admin')), [users]);
  const selectedUser = useMemo(
    () => nonAdminUsers.find((u) => String(u.id) === selectedUserId) ?? null,
    [nonAdminUsers, selectedUserId],
  );

  const [permissions, setPermissions] = useState<TeamMemberPermissions | null>(null);
  const [isLoadingPermissions, setIsLoadingPermissions] = useState(false);
  const [permError, setPermError] = useState<string | null>(null);
  const [isSaving, setIsSaving] = useState(false);
  const [saved, setSaved] = useState(false);

  // Module & Feature Access filtering — see this file's docblock. Only the groups whose modules
  // the client's plan has are shown (QA fixes, 2026-10-14: CRM, social and setup groups added).
  const availableGroups = useMemo(() => MATRIX_GROUPS.filter((g) => g.modules.some((m) => hasModule(m))), [hasModule]);

  const loadUsers = useCallback(async () => {
    setIsLoadingUsers(true);
    setListError(null);
    try {
      // Client Admin workspace only — GET /team/users is always scoped
      // to the caller's own account_id by TenantIsolationMiddleware, so
      // this list is strictly "team members belonging to my account_id."
      const res = await teamService.listUsers();
      setUsers(res.data);
    } catch (err) {
      setListError(extractMessage(err, 'Failed to load team members.'));
    } finally {
      setIsLoadingUsers(false);
    }
  }, []);

  useEffect(() => {
    void loadUsers();
  }, [loadUsers]);

  const loadPermissions = useCallback(async () => {
    if (!selectedUser) {
      setPermissions(null);
      return;
    }
    setIsLoadingPermissions(true);
    setPermError(null);
    setSaved(false);
    try {
      const data = await teamService.getPermissions(selectedUser.id);
      setPermissions(data);
    } catch (err) {
      setPermError(extractMessage(err, 'Failed to load this user’s permissions.'));
    } finally {
      setIsLoadingPermissions(false);
    }
  }, [selectedUser]);

  useEffect(() => {
    void loadPermissions();
  }, [loadPermissions]);

  const handleToggle = (permission: MatrixPermission) => {
    setPermissions((prev) => {
      if (!prev) return prev;
      const isEffective = prev.effective.includes(permission);
      // Inherited-via-role checkboxes are rendered disabled and never
      // reach this handler for the "revoke" direction; toggling here
      // only ever adds/removes a DIRECT grant.
      const nextDirect = isEffective
        ? prev.direct.filter((p) => p !== permission)
        : [...prev.direct, permission];
      const nextEffective = isEffective
        ? prev.effective.filter((p) => p !== permission)
        : [...prev.effective, permission];
      return { ...prev, direct: nextDirect, effective: nextEffective };
    });
    setSaved(false);
  };

  const handleSave = async () => {
    if (!selectedUser || !permissions) return;
    setIsSaving(true);
    setPermError(null);
    try {
      const res = await teamService.updatePermissions(selectedUser.id, { permissions: permissions.direct });
      setPermissions(res.data);
      setSaved(true);
    } catch (err) {
      setPermError(extractMessage(err, 'Could not save these permissions.'));
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <div className="p-6">
      <div className="w-full max-w-3xl space-y-6">
        <div>
          <h2 className="flex items-center gap-2 text-lg font-semibold text-slate-900">
            <ShieldCheck className="h-5 w-5 text-emerald-700" />
            Granular Permission Matrix
          </h2>
          <p className="mt-1 text-sm text-slate-500">
            Choose exactly what one team member can do — WhatsApp, CRM, social media and ads — beyond what their role
            already grants.
          </p>
        </div>

        {listError && (
          <DismissibleAlert className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <AlertCircle className="h-4 w-4 flex-shrink-0" />
            {listError}
          </DismissibleAlert>
        )}

        <div>
          <label className="text-sm font-medium text-slate-700">Team Member</label>
          <select
            value={selectedUserId ?? ''}
            onChange={(e) => setSearchParams(e.target.value ? { user: e.target.value } : {})}
            className={inputClass}
            disabled={isLoadingUsers}
          >
            <option value="">Select a team member…</option>
            {nonAdminUsers.map((u) => (
              <option key={u.id} value={u.id}>
                {u.name} — {u.roles.map((r) => roleLabel(r.name)).join(', ') || 'No role'}
              </option>
            ))}
          </select>
        </div>

        {!isLoadingUsers && nonAdminUsers.length === 0 && (
          <div className="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
            <AlertCircle className="h-4 w-4 flex-shrink-0" />
            All team members in this account are Admins with full access.
          </div>
        )}

        {availableGroups.length === 0 && (
          <div className="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <AlertCircle className="h-4 w-4 flex-shrink-0" />
            Your plan has no features that can be shared with team members yet.
          </div>
        )}

        {!selectedUser ? (
          <p className="rounded-xl border border-dashed border-slate-300 px-5 py-8 text-center text-sm text-slate-400">
            Select a team member above to view or edit their permission matrix.
          </p>
        ) : isLoadingPermissions ? (
          <div className="flex justify-center py-10">
            <Loader2 className="h-5 w-5 animate-spin text-slate-400" />
          </div>
        ) : permError ? (
          <DismissibleAlert className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <AlertCircle className="h-4 w-4 flex-shrink-0" />
            {permError}
          </DismissibleAlert>
        ) : permissions ? (
          <>
            {availableGroups.map((group) => (
              <PermissionGroup
                key={group.key}
                title={group.title}
                hint={group.hint}
                icon={GROUP_ICONS[group.key] ?? ShieldCheck}
                permissions={group.permissions}
                direct={permissions.direct}
                effective={permissions.effective}
                onToggle={handleToggle}
              />
            ))}

            {availableGroups.length > 0 && (
              <div className="flex items-center gap-3">
                <button
                  onClick={() => void handleSave()}
                  disabled={isSaving}
                  className="flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 disabled:opacity-60"
                >
                  {isSaving && <Loader2 className="h-4 w-4 animate-spin" />}
                  Save Permissions
                </button>
                {saved && !isSaving && (
                  <span className="flex items-center gap-1.5 text-xs text-emerald-600">
                    <CheckCircle2 className="h-3.5 w-3.5" />
                    Saved.
                  </span>
                )}
              </div>
            )}
          </>
        ) : null}
      </div>
    </div>
  );
}
