import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { Eye, EyeOff, KeyRound, Loader2, Pencil, Plus, RefreshCw, ShieldCheck, Trash2, UserPlus, XCircle } from 'lucide-react';
import { roleLabel } from '../../theme/signalIndigo';
import teamService from '../../services/teamService';
import adminUserService from '../../services/adminUserService';
import accountService from '../../services/accountService';
import type { AssignableRole, InviteTeamUserPayload, TeamUser, TeamUsersScope, UpdateTeamUserPayload } from '../../types/team';
import type { Account } from '../../types/account';
import { useAuth } from '../../core/context/AuthContext';
import { useTenant } from '../../core/context/TenantContext';
import { ClearFiltersButton, Pagination, SearchInput, StatusFilterSelect } from '../../components/common/DataTableControls';
import { TableSkeletonRows } from '../../components/common/Skeleton';
import { extractErrorMessage as extractMessage } from '../../utils/apiError';
import ConfirmModal from '../../components/common/ConfirmModal';

/**
 * Corrected Unified Client & Admin User Creation — Auto-Generate option
 * for the invite form's password field. Same shape as
 * CreateAccountModal.tsx's helper (kept local to each page, matching
 * this codebase's existing convention of self-contained page files
 * rather than a shared cross-page util for a ~10-line helper).
 */
function generatePassword(): string {
  const upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
  const lower = 'abcdefghijkmnopqrstuvwxyz';
  const digits = '23456789';
  const symbols = '!@#$%^&*';
  const all = upper + lower + digits + symbols;
  const pick = (set: string) => set[Math.floor(Math.random() * set.length)];
  const required = [pick(upper), pick(lower), pick(digits), pick(symbols)];
  const rest = Array.from({ length: 8 }, () => pick(all));
  return [...required, ...rest].sort(() => Math.random() - 0.5).join('');
}

/**
 * Table Layout & Filter Standardization — Team Users has no per-row
 * "Suspended"/"Expired" concept (that's account-level, see AccountsPage);
 * the only status this table's rows actually carry is TeamUser.is_active,
 * so the status filter here is Active/Inactive rather than the
 * Active/Suspended/Expired set used on the account-scoped tables.
 */
const USER_STATUS_OPTIONS = [
  { value: 'active', label: 'Active' },
  { value: 'inactive', label: 'Inactive' },
];

const inputClass =
  'mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100';


function formatDate(value: string): string {
  return new Date(value).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

/**
 * User-to-Client Mapping & Form Upgrade — every user is now explicitly
 * linked to a Client Account on creation. A caller who can pick an
 * account — Super Admin (every tenant) or an Agent (its own Sub-Clients
 * only, via the Agent Client-Switcher) — gets a real, mandatory
 * dropdown; a plain tenant Admin/User/Social Marketer (account scope)
 * gets a read-only display of their own account, since they can only
 * ever add to it anyway (server-side account_id is never accepted from
 * the client either way — see TeamController::store()'s docblock).
 */
function ClientField({
  canPickAccount,
  accounts,
  value,
  onChange,
  fixedLabel,
}: {
  canPickAccount: boolean;
  accounts: Account[];
  value: number | '';
  onChange: (id: number | '') => void;
  fixedLabel: string;
}) {
  if (!canPickAccount) {
    return (
      <div>
        <label className="text-sm font-medium text-slate-700">Client</label>
        <p className={`${inputClass} bg-slate-50 text-slate-500`}>{fixedLabel}</p>
      </div>
    );
  }

  return (
    <div>
      <label className="text-sm font-medium text-slate-700">
        Client <span className="text-red-500">*</span>
      </label>
      <select
        value={value}
        onChange={(e) => onChange(e.target.value ? Number(e.target.value) : '')}
        className={inputClass}
      >
        <option value="">Select a client…</option>
        {accounts.map((a) => (
          <option key={a.id} value={a.id}>
            {a.company_name}
          </option>
        ))}
      </select>
    </div>
  );
}

/** Multi-Role Assignment — checkbox grid, replacing the previous single-role <select>. */
function RoleCheckboxList({
  roles,
  selected,
  onChange,
}: {
  roles: AssignableRole[];
  selected: string[];
  onChange: (next: string[]) => void;
}) {
  const toggle = (name: string) => {
    onChange(selected.includes(name) ? selected.filter((r) => r !== name) : [...selected, name]);
  };

  return (
    <div className="grid grid-cols-2 gap-2">
      {roles.map((role) => (
        <label
          key={role.id}
          className={`flex items-center gap-2 rounded-lg border px-3 py-2 text-sm transition ${
            selected.includes(role.name) ? 'border-indigo-300 bg-indigo-50/50 text-indigo-900' : 'border-slate-200 text-slate-700'
          }`}
        >
          <input
            type="checkbox"
            checked={selected.includes(role.name)}
            onChange={() => toggle(role.name)}
            className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
          />
          {roleLabel(role.name)}
        </label>
      ))}
    </div>
  );
}

function InviteUserModal({
  roles,
  accounts,
  canPickAccount,
  fixedAccountLabel,
  defaultAccountId,
  onClose,
  onInvited,
}: {
  roles: AssignableRole[];
  accounts: Account[];
  canPickAccount: boolean;
  fixedAccountLabel: string;
  defaultAccountId: number | '';
  onClose: () => void;
  onInvited: () => void;
}) {
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [phone, setPhone] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [accountId, setAccountId] = useState<number | ''>(defaultAccountId);
  const [selectedRoles, setSelectedRoles] = useState<string[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [isSaving, setIsSaving] = useState(false);

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    if (!name.trim() || !email.trim() || !password.trim() || selectedRoles.length === 0) {
      setError('Full name, email, password and at least one role are required.');
      return;
    }
    if (canPickAccount && accountId === '') {
      setError('Select a client to map this user to.');
      return;
    }
    if (password.length < 8) {
      setError('Password must be at least 8 characters.');
      return;
    }
    setError(null);
    setIsSaving(true);
    const payload: InviteTeamUserPayload = {
      name: name.trim(),
      email: email.trim(),
      phone_number: phone.trim() || null,
      password,
      role_names: selectedRoles,
    };
    try {
      await teamService.inviteUser(payload, canPickAccount ? (accountId as number) : undefined);
      onInvited();
    } catch (err) {
      setError(extractMessage(err, 'Could not add this team member.'));
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
        <div className="flex items-center justify-between">
          <h3 className="text-base font-semibold text-slate-900">Invite team member</h3>
          <button onClick={onClose} className="text-slate-400 hover:text-slate-600">
            <XCircle className="h-5 w-5" />
          </button>
        </div>

        <form onSubmit={(e) => void handleSubmit(e)} className="mt-4 space-y-4">
          <ClientField
            canPickAccount={canPickAccount}
            accounts={accounts}
            value={accountId}
            onChange={setAccountId}
            fixedLabel={fixedAccountLabel}
          />
          <div>
            <label className="text-sm font-medium text-slate-700">Full name <span className="text-red-500">*</span></label>
            <input value={name} onChange={(e) => setName(e.target.value)} className={inputClass} autoFocus />
          </div>
          <div>
            <label className="text-sm font-medium text-slate-700">Email <span className="text-red-500">*</span></label>
            <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} className={inputClass} />
          </div>
          <div>
            <label className="text-sm font-medium text-slate-700">Phone Number</label>
            <input value={phone} onChange={(e) => setPhone(e.target.value)} className={inputClass} placeholder="+91 98765 43210" />
          </div>
          <div>
            <label className="text-sm font-medium text-slate-700">Password <span className="text-red-500">*</span></label>
            <div className="mt-1.5 flex gap-2">
              <div className="relative flex-1">
                <input
                  type={showPassword ? 'text' : 'password'}
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  placeholder="Min. 8 characters"
                  className={`${inputClass} mt-0 pr-9`}
                  autoComplete="new-password"
                />
                <button
                  type="button"
                  onClick={() => setShowPassword((v) => !v)}
                  className="absolute inset-y-0 right-0 flex items-center px-2.5 text-slate-400 hover:text-slate-600"
                  aria-label={showPassword ? 'Hide password' : 'Show password'}
                >
                  {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                </button>
              </div>
              <button
                type="button"
                onClick={() => {
                  setPassword(generatePassword());
                  setShowPassword(true);
                }}
                className="flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
              >
                <RefreshCw className="h-3.5 w-3.5" />
                Auto-Generate
              </button>
            </div>
            <p className="mt-1 text-xs text-slate-500">
              Share this with them directly — there is no automated invite email yet.
            </p>
          </div>
          <div>
            <label className="text-sm font-medium text-slate-700">
              Roles <span className="text-red-500">*</span>
            </label>
            <div className="mt-1.5">
              <RoleCheckboxList roles={roles} selected={selectedRoles} onChange={setSelectedRoles} />
            </div>
          </div>

          {error && (
            <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
              <XCircle className="h-4 w-4 flex-shrink-0" />
              {error}
            </div>
          )}

          <div className="flex justify-end gap-3">
            <button
              type="button"
              onClick={onClose}
              className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={isSaving || roles.length === 0}
              className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60"
            >
              {isSaving ? <Loader2 className="h-4 w-4 animate-spin" /> : <UserPlus className="h-4 w-4" />}
              Add member
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

/**
 * Super Admin Edit & Deactivation Capabilities — Super-Admin-only "Edit"
 * action, opening this modal for any tenant's team member (name, email,
 * phone, role). Reuses the same RoleCheckboxList as the invite form;
 * `roles` already excludes 'admin'/'super_admin' (TeamController::roles()),
 * so promoting someone to Admin is not possible from here — matching
 * store()'s/update()'s server-side validation. No password field (that's
 * ResetPasswordModal's job) and no Client field (an existing user's
 * account_id never changes here).
 */
function EditUserModal({
  targetUser,
  roles,
  accountId,
  onClose,
  onUpdated,
}: {
  targetUser: TeamUser;
  roles: AssignableRole[];
  /** Passed only when the caller is Super Admin — see teamService.updateUser()'s accountId param. */
  accountId?: number;
  onClose: () => void;
  onUpdated: () => void;
}) {
  const [name, setName] = useState(targetUser.name);
  const [email, setEmail] = useState(targetUser.email);
  const [phone, setPhone] = useState(targetUser.phone_number ?? '');
  const [selectedRoles, setSelectedRoles] = useState<string[]>(targetUser.roles.map((r) => r.name));
  const [error, setError] = useState<string | null>(null);
  const [isSaving, setIsSaving] = useState(false);

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    if (!name.trim() || !email.trim() || selectedRoles.length === 0) {
      setError('Full name, email and at least one role are required.');
      return;
    }
    setError(null);
    setIsSaving(true);
    const payload: UpdateTeamUserPayload = {
      name: name.trim(),
      email: email.trim(),
      phone_number: phone.trim() || null,
      role_names: selectedRoles,
    };
    try {
      await teamService.updateUser(targetUser.id, payload, accountId);
      onUpdated();
    } catch (err) {
      setError(extractMessage(err, 'Could not update this team member.'));
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
        <div className="flex items-center justify-between">
          <h3 className="text-base font-semibold text-slate-900">Edit {targetUser.name}</h3>
          <button onClick={onClose} className="text-slate-400 hover:text-slate-600">
            <XCircle className="h-5 w-5" />
          </button>
        </div>

        <form onSubmit={(e) => void handleSubmit(e)} className="mt-4 space-y-4">
          <div>
            <label className="text-sm font-medium text-slate-700">Full name <span className="text-red-500">*</span></label>
            <input value={name} onChange={(e) => setName(e.target.value)} className={inputClass} autoFocus />
          </div>
          <div>
            <label className="text-sm font-medium text-slate-700">Email <span className="text-red-500">*</span></label>
            <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} className={inputClass} />
          </div>
          <div>
            <label className="text-sm font-medium text-slate-700">Phone Number</label>
            <input value={phone} onChange={(e) => setPhone(e.target.value)} className={inputClass} placeholder="+91 98765 43210" />
          </div>
          <div>
            <label className="text-sm font-medium text-slate-700">
              Roles <span className="text-red-500">*</span>
            </label>
            <div className="mt-1.5">
              <RoleCheckboxList roles={roles} selected={selectedRoles} onChange={setSelectedRoles} />
            </div>
          </div>

          {error && (
            <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
              <XCircle className="h-4 w-4 flex-shrink-0" />
              {error}
            </div>
          )}

          <div className="flex justify-end gap-3">
            <button
              type="button"
              onClick={onClose}
              className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={isSaving || roles.length === 0}
              className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60"
            >
              {isSaving && <Loader2 className="h-4 w-4 animate-spin" />}
              Save changes
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

/**
 * Absolute Super Admin Control — Password Override. Super Admin only
 * (gated by the caller in UsersPage; the backend re-checks isSuperAdmin()
 * regardless). Sets a brand-new password directly, no current-password
 * confirmation required — see AdminUserController::changePassword.
 */
function ResetPasswordModal({
  targetUser,
  onClose,
  onDone,
}: {
  targetUser: TeamUser;
  onClose: () => void;
  onDone: () => void;
}) {
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [isSaving, setIsSaving] = useState(false);

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    if (password.length < 8) {
      setError('Password must be at least 8 characters.');
      return;
    }
    setError(null);
    setIsSaving(true);
    try {
      await adminUserService.changePassword(targetUser.id, password);
      onDone();
    } catch (err) {
      setError(extractMessage(err, 'Could not update this password.'));
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
        <div className="flex items-center justify-between">
          <h3 className="text-base font-semibold text-slate-900">Reset password for {targetUser.name}</h3>
          <button onClick={onClose} className="text-slate-400 hover:text-slate-600">
            <XCircle className="h-5 w-5" />
          </button>
        </div>

        <form onSubmit={(e) => void handleSubmit(e)} className="mt-4 space-y-4">
          <div>
            <label className="text-sm font-medium text-slate-700">New password <span className="text-red-500">*</span></label>
            <input
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="Min. 8 characters"
              className={inputClass}
              autoComplete="new-password"
              autoFocus
            />
            <p className="mt-1 text-xs text-slate-500">
              {targetUser.name} will be signed out of every active session once this is saved.
            </p>
          </div>

          {error && (
            <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
              <XCircle className="h-4 w-4 flex-shrink-0" />
              {error}
            </div>
          )}

          <div className="flex justify-end gap-3">
            <button
              type="button"
              onClick={onClose}
              className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={isSaving}
              className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60"
            >
              {isSaving ? <Loader2 className="h-4 w-4 animate-spin" /> : <KeyRound className="h-4 w-4" />}
              Set password
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

/**
 * UI overhaul — Team Users page. Tenant Admin only (route-gated
 * permission="manage-team"). Backed by the new TeamController: users are
 * created immediately with an admin-chosen password (no email invite
 * infrastructure exists in this codebase yet — see TeamController's
 * docblock), and the account owner (Account::owner) cannot be
 * deactivated, edited, or removed from here.
 *
 * SUPER ADMIN EDIT & DEACTIVATION CAPABILITIES refactor (supersedes the
 * prior "Super Admin is read-only here" boundary): Super Admin now sees
 * "Edit", "Deactivate/Activate" and "Reset Password" for any tenant's
 * team member, via ?account_id= exactly like every other cross-tenant
 * action in this app. "Invite Member", "Remove" and "Permissions" stay
 * exclusively Client Admin's — the first two because Super Admin was
 * deliberately not asked to gain them here, the last because the
 * Granular Permission Matrix operates on direct per-user Spatie grants
 * that only make sense in the context of one specific tenant's own
 * Admin managing their own team (see TeamController::MANAGED_PERMISSIONS'
 * docblock). All four of these boundaries are enforced server-side by
 * TeamController regardless of this UI. "Permissions" is additionally
 * hidden for the viewer's own row and for any row that itself holds
 * 'admin' — 'social_marketer' keeps every other action here (a prior,
 * deliberate design choice, see RolePermissionSeeder) but never
 * Permissions, which the spec scopes to "Client Admin" specifically.
 */
export default function UsersPage() {
  const { user: currentUser, isSuperAdmin, isReadOnly, hasRole } = useAuth();
  const navigate = useNavigate();
  // Super Admin Multi-Tenant Scoping: not read directly (the axios
  // interceptor attaches it), but load() must depend on it so switching
  // the Header's client selector re-fetches this page for the newly
  // selected tenant instead of continuing to show stale data.
  const { selectedAccountId, selectedAccount, canSwitchClients } = useTenant();
  const superAdmin = isSuperAdmin();
  const [users, setUsers] = useState<TeamUser[]>([]);
  const [scope, setScope] = useState<TeamUsersScope>('account');
  const [roles, setRoles] = useState<AssignableRole[]>([]);
  const [accounts, setAccounts] = useState<Account[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [showInvite, setShowInvite] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [resetPasswordUser, setResetPasswordUser] = useState<TeamUser | null>(null);
  // Super Admin Edit & Deactivation Capabilities — Super-Admin-only Edit
  // action (name/email/phone/role); Client Admin has no equivalent
  // trigger for this modal today (not asked for in this refactor).
  const [editUser, setEditUser] = useState<TeamUser | null>(null);
  // Client-User Mapping Filter — Super Admin only (global scope).
  const [clientFilter, setClientFilter] = useState('');

  // Universal Table Controls — client-side, since TeamController::index()
  // returns a full flat array with no server-side pagination/search/filter
  // support (disclosed pragmatic scope decision, see audit report).
  const [search, setSearch] = useState('');
  const [roleFilter, setRoleFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(15);

  // Universal Clear Filters Button — see DataTableControls.tsx's
  // docblock. Resets every filter this table exposes, including the
  // Super-Admin-only client filter.
  const hasActiveFilters =
    search !== '' || roleFilter !== '' || statusFilter !== '' || clientFilter !== '' || from !== '' || to !== '';
  const clearFilters = () => {
    setSearch('');
    setRoleFilter('');
    setStatusFilter('');
    setClientFilter('');
    setFrom('');
    setTo('');
  };

  const load = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const [usersRes, roleList] = await Promise.all([teamService.listUsers(), teamService.listAssignableRoles()]);
      setUsers(usersRes.data);
      setScope(usersRes.scope);
      setRoles(roleList);
    } catch (err) {
      setError(extractMessage(err, 'Failed to load team members.'));
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load, selectedAccountId]);

  // User-to-Client Mapping — a caller who can pick an account (Super
  // Admin: every tenant; Agent: its own Sub-Clients only, via the Agent
  // Client-Switcher — accountService.list() is already scoped
  // server-side by AccountController::index()'s callerAgentScopeId())
  // needs the full list for the Invite modal's mandatory dropdown and
  // the "Filter by Client" control, independent of whichever client (if
  // any) is selected in the header right now.
  useEffect(() => {
    if (!canSwitchClients) return;
    accountService
      .list({ per_page: 1000 })
      .then((res) => setAccounts(res.data))
      .catch(() => {
        // Non-fatal — the Invite modal's dropdown/filter simply render empty; the rest of the page still works.
      });
  }, [canSwitchClients]);

  const handleToggle = async (u: TeamUser) => {
    setBusyId(u.id);
    try {
      await teamService.toggleUser(u.id, canSwitchClients ? u.account_id : undefined);
      await load();
    } catch (err) {
      setError(extractMessage(err, 'Could not update this team member.'));
    } finally {
      setBusyId(null);
    }
  };

  const [pendingRemove, setPendingRemove] = useState<TeamUser | null>(null);

  const handleRemove = (u: TeamUser) => {
    setPendingRemove(u);
  };

  const confirmRemove = async () => {
    if (!pendingRemove) return;
    const u = pendingRemove;
    setBusyId(u.id);
    try {
      await teamService.removeUser(u.id, canSwitchClients ? u.account_id : undefined);
      await load();
      setPendingRemove(null);
    } catch (err) {
      setError(extractMessage(err, 'Could not remove this team member.'));
      setPendingRemove(null);
    } finally {
      setBusyId(null);
    }
  };

  const roleFilterOptions = useMemo(
    () => roles.map((r) => ({ value: r.name, label: roleLabel(r.name) })),
    [roles],
  );

  const clientFilterOptions = useMemo(
    () => accounts.map((a) => ({ value: String(a.id), label: a.company_name })),
    [accounts],
  );

  const filteredUsers = useMemo(() => {
    const term = search.trim().toLowerCase();
    // Joined-date range is compared on the calendar day only (not time),
    // matching the plain <input type="date"> value the picker produces.
    const fromDay = from || null;
    const toDay = to || null;
    return users.filter((u) => {
      if (term && !u.name.toLowerCase().includes(term) && !u.email.toLowerCase().includes(term)) return false;
      if (roleFilter && !u.roles.some((r) => r.name === roleFilter)) return false;
      if (statusFilter === 'active' && !u.is_active) return false;
      if (statusFilter === 'inactive' && u.is_active) return false;
      if (clientFilter && String(u.account_id) !== clientFilter) return false;
      const joinedDay = u.created_at.slice(0, 10);
      if (fromDay && joinedDay < fromDay) return false;
      if (toDay && joinedDay > toDay) return false;
      return true;
    });
  }, [users, search, roleFilter, statusFilter, clientFilter, from, to]);

  useEffect(() => {
    setPage(1);
  }, [search, roleFilter, statusFilter, clientFilter, from, to]);

  // Super Admin Client Provisioning refactor — User Limits. Only
  // meaningful in account scope, where `users` IS the full headcount for
  // exactly one account; the global cross-tenant view has no single
  // account to compare against (the Invite modal's own 403 from the
  // server — see TeamController::store() — is the backstop there).
  const ownAccount = currentUser?.account ?? null;
  const reachedUserLimit =
    scope === 'account' && ownAccount?.max_users_limit != null && users.length >= ownAccount.max_users_limit;

  const total = filteredUsers.length;
  const lastPage = Math.max(1, Math.ceil(total / perPage));
  const currentPage = Math.min(page, lastPage);
  const pagedUsers = filteredUsers.slice((currentPage - 1) * perPage, currentPage * perPage);

  return (
    <div className="p-6">
      <div className="w-full space-y-6">
        <div className="flex items-center justify-between">
          <div>
            <h2 className="text-lg font-semibold text-slate-900">Team Users</h2>
            <p className="mt-1 text-sm text-slate-500">
              {scope === 'global'
                ? 'Every user across every client account. Pick a Client in the Invite form to map a new member to it.'
                : 'Everyone with access to your account.'}
            </p>
          </div>
          {/* Super Admin / Client Admin RBAC boundary refactor: Super
              Admin no longer creates regular team users at all (only the
              single Primary Client Admin, at account-setup time, via
              AccountController::store — a different endpoint entirely).
              Note this condition was previously `scope === 'account' ||
              superAdmin`, which was ALWAYS true (scope is always
              'account' for a non-Super-Admin caller, and the second
              clause covered Super Admin unconditionally) — i.e. it never
              actually hid this button for anyone; `!superAdmin` is the
              first real gate this button has had. */}
          {!superAdmin && (
            <button
              onClick={() => setShowInvite(true)}
              disabled={(roles.length === 0 && !isLoading) || isReadOnly() || reachedUserLimit}
              title={
                isReadOnly()
                  ? 'Action disabled: Subscription expired.'
                  : reachedUserLimit
                    ? 'User limit reached. Contact Super Admin to upgrade.'
                    : undefined
              }
              className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60"
            >
              <Plus className="h-4 w-4" />
              Invite Member
            </button>
          )}
        </div>

        {error && (
          <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <XCircle className="h-4 w-4 flex-shrink-0" />
            {error}
          </div>
        )}

        {reachedUserLimit && (
          <div className="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <XCircle className="h-4 w-4 flex-shrink-0" />
            User limit reached. Contact Super Admin to upgrade.
          </div>
        )}

        <div className="flex flex-wrap items-center gap-3">
          <SearchInput value={search} onChange={setSearch} placeholder="Search by name or email…" />
          <StatusFilterSelect value={roleFilter} onChange={setRoleFilter} options={roleFilterOptions} allLabel="All roles" />
          <StatusFilterSelect value={statusFilter} onChange={setStatusFilter} options={USER_STATUS_OPTIONS} allLabel="All statuses" />
          {scope === 'global' && (
            <StatusFilterSelect value={clientFilter} onChange={setClientFilter} options={clientFilterOptions} allLabel="All clients" />
          )}
          <div className="flex items-center gap-2">
            <input
              type="date"
              value={from}
              onChange={(e) => setFrom(e.target.value)}
              className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 w-40"
              aria-label="Joined from"
            />
            <span className="text-sm text-slate-400">to</span>
            <input
              type="date"
              value={to}
              onChange={(e) => setTo(e.target.value)}
              className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 w-40"
              aria-label="Joined to"
            />
          </div>
          <ClearFiltersButton active={hasActiveFilters} onClear={clearFilters} />
        </div>

        <div className="rounded-xl border border-slate-200 bg-white shadow-sm">
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-slate-200 text-sm">
              <thead className="bg-slate-50 text-left text-xs font-medium uppercase text-slate-500">
                <tr>
                  <th className="px-6 py-3">Name</th>
                  {scope === 'global' && <th className="px-6 py-3">Client</th>}
                  <th className="px-6 py-3">Email</th>
                  <th className="px-6 py-3">Phone</th>
                  <th className="px-6 py-3">Roles</th>
                  <th className="px-6 py-3">Status</th>
                  <th className="px-6 py-3">Joined</th>
                  <th className="px-6 py-3 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {isLoading ? (
                  <TableSkeletonRows columns={scope === 'global' ? 8 : 7} />
                ) : pagedUsers.length > 0 ? (
                  pagedUsers.map((u) => {
                    const isSelf = currentUser?.id === u.id;
                    return (
                      <tr key={u.id}>
                        <td className="px-6 py-3 font-medium text-slate-900">
                          {u.name}
                          {isSelf && <span className="ml-2 text-xs font-normal text-slate-400">(you)</span>}
                        </td>
                        {scope === 'global' && (
                          <td className="px-6 py-3 text-slate-600">{u.account?.company_name ?? '—'}</td>
                        )}
                        <td className="px-6 py-3 text-slate-600">{u.email}</td>
                        <td className="px-6 py-3 text-slate-600">{u.phone_number ?? '—'}</td>
                        <td className="px-6 py-3 text-slate-600">{u.roles.map((r) => roleLabel(r.name)).join(', ') || '—'}</td>
                        <td className="px-6 py-3">
                          <span
                            className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium ${
                              u.is_active
                                ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                                : 'border-slate-200 bg-slate-50 text-slate-500'
                            }`}
                          >
                            {u.is_active ? 'Active' : 'Inactive'}
                          </span>
                        </td>
                        <td className="px-6 py-3 text-slate-500">{formatDate(u.created_at)}</td>
                        <td className="px-6 py-3 text-right">
                          <div className="flex flex-wrap justify-end gap-3">
                            {/* Super Admin Edit & Deactivation Capabilities —
                                Edit is Super-Admin-only (opens name/email/
                                phone/role editing across any tenant). */}
                            {superAdmin && (
                              <button
                                onClick={() => setEditUser(u)}
                                disabled={busyId === u.id}
                                className="inline-flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-700 disabled:opacity-60"
                              >
                                <Pencil className="h-3 w-3" />
                                Edit
                              </button>
                            )}
                            {/* Granular Permission Matrix — restricted to the
                                literal 'admin' role (Client Admin), which
                                naturally excludes Super Admin (role name
                                'super_admin', not 'admin') without a separate
                                check — so this is never shown in the Super
                                Admin view. Also hidden for the viewer's own
                                row and when the TARGET holds 'admin' (an
                                Admin already has every managed permission via
                                role; see TeamController's
                                MANAGED_PERMISSIONS docblock — matrix
                                customization only makes sense for a
                                non-Admin, non-self team member). Backend
                                mirrors all of this in
                                TeamController::permissions()/updatePermissions(). */}
                            {hasRole('admin') && !isSelf && !u.roles.some((r) => r.name === 'admin') && (
                              <button
                                onClick={() => navigate(`/team/permissions?user=${u.id}`)}
                                disabled={busyId === u.id}
                                className="inline-flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-700 disabled:opacity-60"
                              >
                                <ShieldCheck className="h-3 w-3" />
                                Permissions
                              </button>
                            )}
                            {isSuperAdmin() && (
                              <button
                                onClick={() => setResetPasswordUser(u)}
                                disabled={busyId === u.id}
                                className="inline-flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-700 disabled:opacity-60"
                              >
                                <KeyRound className="h-3 w-3" />
                                Reset Password
                              </button>
                            )}
                            {/* Super Admin Edit & Deactivation Capabilities —
                                Deactivate/Activate is now available to BOTH
                                Super Admin (any tenant's user) and Client
                                Admin (their own team), matching
                                TeamController::toggle() no longer rejecting
                                Super Admin. Fully HIDDEN (not merely
                                disabled) on the caller's own row — Super
                                Admin's own account never appears in this
                                list (account_id is always null for that
                                role), so isSelf is only ever true for a
                                Client Admin viewing themselves. */}
                            {!isSelf && (
                              <button
                                onClick={() => void handleToggle(u)}
                                disabled={busyId === u.id}
                                className="text-xs font-medium text-indigo-600 hover:text-indigo-700 disabled:opacity-60"
                              >
                                {u.is_active ? 'Deactivate' : 'Activate'}
                              </button>
                            )}
                            {/* Remove stays Client-Admin-only (Super Admin
                                was not asked to gain this action here) and
                                is fully hidden — not merely disabled — on
                                the caller's own row. */}
                            {!superAdmin && !isSelf && (
                              <button
                                onClick={() => handleRemove(u)}
                                disabled={busyId === u.id}
                                className="inline-flex items-center gap-1 text-xs font-medium text-red-600 hover:text-red-700 disabled:opacity-40"
                              >
                                {busyId === u.id ? <Loader2 className="h-3 w-3 animate-spin" /> : <Trash2 className="h-3 w-3" />}
                                Remove
                              </button>
                            )}
                          </div>
                        </td>
                      </tr>
                    );
                  })
                ) : (
                  <tr>
                    <td colSpan={scope === 'global' ? 8 : 7} className="px-6 py-6 text-center text-slate-400">
                      {users.length === 0 ? 'No team members yet.' : 'No team members match the current filters.'}
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>

          <Pagination
            page={currentPage}
            lastPage={lastPage}
            total={total}
            perPage={perPage}
            onPageChange={setPage}
            onPerPageChange={setPerPage}
          />
        </div>
      </div>

      {showInvite && (
        <InviteUserModal
          roles={roles}
          accounts={accounts}
          canPickAccount={canSwitchClients}
          fixedAccountLabel={selectedAccount?.company_name ?? ownAccount?.company_name ?? 'Your account'}
          defaultAccountId={canSwitchClients ? (selectedAccountId ?? '') : ''}
          onClose={() => setShowInvite(false)}
          onInvited={() => {
            setShowInvite(false);
            void load();
          }}
        />
      )}

      {editUser && (
        <EditUserModal
          targetUser={editUser}
          roles={roles}
          accountId={superAdmin ? editUser.account_id : undefined}
          onClose={() => setEditUser(null)}
          onUpdated={() => {
            setEditUser(null);
            void load();
          }}
        />
      )}

      {resetPasswordUser && (
        <ResetPasswordModal
          targetUser={resetPasswordUser}
          onClose={() => setResetPasswordUser(null)}
          onDone={() => setResetPasswordUser(null)}
        />
      )}

      {pendingRemove && (
        <ConfirmModal
          title="Remove team member"
          message={`Remove ${pendingRemove.name} from the team? This cannot be undone.`}
          confirmLabel="Remove"
          variant="danger"
          isLoading={busyId === pendingRemove.id}
          onConfirm={() => void confirmRemove()}
          onCancel={() => setPendingRemove(null)}
        />
      )}
    </div>
  );
}
