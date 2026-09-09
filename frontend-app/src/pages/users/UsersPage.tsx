import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react';
import { KeyRound, Loader2, Plus, Trash2, UserPlus, XCircle } from 'lucide-react';
import { roleLabel } from '../../theme/signalIndigo';
import teamService from '../../services/teamService';
import adminUserService from '../../services/adminUserService';
import type { AssignableRole, InviteTeamUserPayload, TeamUser, TeamUsersScope } from '../../types/team';
import { useAuth } from '../../core/context/AuthContext';
import { useTenant } from '../../core/context/TenantContext';
import { Pagination, SearchInput, StatusFilterSelect } from '../../components/common/DataTableControls';
import { TableSkeletonRows } from '../../components/common/Skeleton';
import { extractErrorMessage as extractMessage } from '../../utils/apiError';

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

function InviteUserModal({
  roles,
  onClose,
  onInvited,
}: {
  roles: AssignableRole[];
  onClose: () => void;
  onInvited: () => void;
}) {
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [roleName, setRoleName] = useState(roles[0]?.name ?? '');
  const [error, setError] = useState<string | null>(null);
  const [isSaving, setIsSaving] = useState(false);

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    if (!name.trim() || !email.trim() || !password.trim() || !roleName) {
      setError('All fields are required.');
      return;
    }
    if (password.length < 8) {
      setError('Password must be at least 8 characters.');
      return;
    }
    setError(null);
    setIsSaving(true);
    const payload: InviteTeamUserPayload = { name: name.trim(), email: email.trim(), password, role_name: roleName };
    try {
      await teamService.inviteUser(payload);
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
          <div>
            <label className="text-sm font-medium text-slate-700">Full name</label>
            <input value={name} onChange={(e) => setName(e.target.value)} className={inputClass} autoFocus />
          </div>
          <div>
            <label className="text-sm font-medium text-slate-700">Email</label>
            <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} className={inputClass} />
          </div>
          <div>
            <label className="text-sm font-medium text-slate-700">Temporary password</label>
            <input
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="Min. 8 characters"
              className={inputClass}
              autoComplete="new-password"
            />
            <p className="mt-1 text-xs text-slate-500">
              Share this with them directly — there is no automated invite email yet.
            </p>
          </div>
          <div>
            <label className="text-sm font-medium text-slate-700">Role</label>
            <select value={roleName} onChange={(e) => setRoleName(e.target.value)} className={inputClass}>
              {roles.map((role) => (
                <option key={role.id} value={role.name}>
                  {roleLabel(role.name)}
                </option>
              ))}
            </select>
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
            <label className="text-sm font-medium text-slate-700">New password</label>
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
 * deactivated or removed from here. Super Admin additionally gets a
 * Reset Password action per row (Absolute Super Admin Control).
 */
export default function UsersPage() {
  const { user: currentUser, isSuperAdmin, isReadOnly } = useAuth();
  // Super Admin Multi-Tenant Scoping: not read directly (the axios
  // interceptor attaches it), but load() must depend on it so switching
  // the Header's client selector re-fetches this page for the newly
  // selected tenant instead of continuing to show stale data.
  const { selectedAccountId } = useTenant();
  const [users, setUsers] = useState<TeamUser[]>([]);
  const [scope, setScope] = useState<TeamUsersScope>('account');
  const [roles, setRoles] = useState<AssignableRole[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [showInvite, setShowInvite] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [resetPasswordUser, setResetPasswordUser] = useState<TeamUser | null>(null);

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

  const handleToggle = async (u: TeamUser) => {
    setBusyId(u.id);
    try {
      await teamService.toggleUser(u.id);
      await load();
    } catch (err) {
      setError(extractMessage(err, 'Could not update this team member.'));
    } finally {
      setBusyId(null);
    }
  };

  const handleRemove = async (u: TeamUser) => {
    if (!confirm(`Remove ${u.name} from the team? This cannot be undone.`)) return;
    setBusyId(u.id);
    try {
      await teamService.removeUser(u.id);
      await load();
    } catch (err) {
      setError(extractMessage(err, 'Could not remove this team member.'));
      setBusyId(null);
    }
  };

  const roleFilterOptions = useMemo(
    () => roles.map((r) => ({ value: r.name, label: roleLabel(r.name) })),
    [roles],
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
      const joinedDay = u.created_at.slice(0, 10);
      if (fromDay && joinedDay < fromDay) return false;
      if (toDay && joinedDay > toDay) return false;
      return true;
    });
  }, [users, search, roleFilter, statusFilter, from, to]);

  useEffect(() => {
    setPage(1);
  }, [search, roleFilter, statusFilter, from, to]);

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
                ? 'Every user across every client account. Select a client in the header to invite a new member.'
                : 'Everyone with access to your account.'}
            </p>
          </div>
          {scope === 'account' && (
            <button
              onClick={() => setShowInvite(true)}
              disabled={(roles.length === 0 && !isLoading) || isReadOnly()}
              title={isReadOnly() ? 'Action disabled: Subscription expired.' : undefined}
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

        <div className="flex flex-wrap items-center gap-3">
          <SearchInput value={search} onChange={setSearch} placeholder="Search by name or email…" />
          <StatusFilterSelect value={roleFilter} onChange={setRoleFilter} options={roleFilterOptions} allLabel="All roles" />
          <StatusFilterSelect value={statusFilter} onChange={setStatusFilter} options={USER_STATUS_OPTIONS} allLabel="All statuses" />
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
        </div>

        <div className="rounded-xl border border-slate-200 bg-white shadow-sm">
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-slate-200 text-sm">
              <thead className="bg-slate-50 text-left text-xs font-medium uppercase text-slate-500">
                <tr>
                  <th className="px-6 py-3">Name</th>
                  {scope === 'global' && <th className="px-6 py-3">Client</th>}
                  <th className="px-6 py-3">Email</th>
                  <th className="px-6 py-3">Role</th>
                  <th className="px-6 py-3">Status</th>
                  <th className="px-6 py-3">Joined</th>
                  <th className="px-6 py-3 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {isLoading ? (
                  <TableSkeletonRows columns={scope === 'global' ? 7 : 6} />
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
                          <div className="flex justify-end gap-3">
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
                            <button
                              onClick={() => void handleToggle(u)}
                              disabled={busyId === u.id}
                              className="text-xs font-medium text-indigo-600 hover:text-indigo-700 disabled:opacity-60"
                            >
                              {u.is_active ? 'Deactivate' : 'Activate'}
                            </button>
                            <button
                              onClick={() => void handleRemove(u)}
                              disabled={busyId === u.id || isSelf}
                              title={isSelf ? 'You cannot remove your own account' : undefined}
                              className="inline-flex items-center gap-1 text-xs font-medium text-red-600 hover:text-red-700 disabled:opacity-40"
                            >
                              {busyId === u.id ? <Loader2 className="h-3 w-3 animate-spin" /> : <Trash2 className="h-3 w-3" />}
                              Remove
                            </button>
                          </div>
                        </td>
                      </tr>
                    );
                  })
                ) : (
                  <tr>
                    <td colSpan={scope === 'global' ? 7 : 6} className="px-6 py-6 text-center text-slate-400">
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
          onClose={() => setShowInvite(false)}
          onInvited={() => {
            setShowInvite(false);
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
    </div>
  );
}
