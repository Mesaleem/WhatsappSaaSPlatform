import { useCallback, useEffect, useMemo, useState } from 'react';
import { AxiosError } from 'axios';
import { Download, FileText, History, RefreshCw } from 'lucide-react';
import { useAuth } from '../../core/context/AuthContext';
import auditLogService from '../../services/auditLogService';
import type { AuditLogFilters, LoginAuditLog, LoginAuditStatus } from '../../types/auditLog';
import type { ApiErrorResponse } from '../../types/auth';
import { PageHeader, PageShell } from '../../components/common/PageShell';
import { TableCard } from '../../components/common/Card';
import { ClearFiltersButton, Pagination, SearchInput, StatusFilterSelect } from '../../components/common/DataTableControls';
import { TableSkeletonRows } from '../../components/common/Skeleton';

/**
 * Surfaces the backend's real error message (e.g. a missing-table 500
 * while a pending migration hasn't been run yet) instead of a fixed
 * generic string, so this screen is self-diagnosable without needing
 * the server's own log file.
 */
function extractMessage(err: unknown, fallback: string): string {
  const axiosErr = err as AxiosError<ApiErrorResponse>;
  return axiosErr.response?.data?.message ?? fallback;
}

const STATUS_OPTIONS: { value: LoginAuditStatus; label: string }[] = [
  { value: 'success', label: 'Success' },
  { value: 'failed', label: 'Failed' },
];

/** Universal Table & Filter Standardization — the platform's exact 3 seeded role slugs (RolePermissionSeeder), not a free-text field. */
const ROLE_OPTIONS: { value: string; label: string }[] = [
  { value: 'super_admin', label: 'Super Admin' },
  { value: 'admin', label: 'Admin' },
  { value: 'user', label: 'User' },
];

const filterInputClass =
  'rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100';

const STATUS_BADGE: Record<LoginAuditStatus, string> = {
  success: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  failed: 'bg-red-50 text-red-700 ring-red-600/20',
};

function formatDateTime(value: string | null): string {
  if (!value) return '—';
  return new Date(value).toLocaleString();
}

/**
 * Role-Based Login Audit Logging Architecture — filters shown are
 * role-aware: a plain 'user' only ever sees their own login rows (the
 * backend already narrows this — see AuditLogController::scopedQuery()),
 * so the Search/Role filters (which only make sense across multiple
 * people) are hidden for them; Super Admin additionally sees a "Client"
 * column when no account is selected in the header switcher (scope ===
 * 'global'). Export buttons mirror the CSV/PDF pattern already
 * established by ExportController/BillingController on the backend.
 */
export default function AuditLogsPage() {
  const { isSuperAdmin, hasRole } = useAuth();
  const superAdmin = isSuperAdmin();
  const isAdmin = hasRole('admin');
  const canFilterByPerson = superAdmin || isAdmin;

  const [logs, setLogs] = useState<LoginAuditLog[]>([]);
  const [scope, setScope] = useState<'account' | 'global'>('account');
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [perPage, setPerPage] = useState(15);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<LoginAuditStatus | ''>('');
  const [role, setRole] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [isExporting, setIsExporting] = useState<'csv' | 'pdf' | null>(null);

  const filters: AuditLogFilters = useMemo(
    () => ({ search, status, role, from, to }),
    [search, status, role, from, to],
  );

  const hasActiveFilters = search !== '' || status !== '' || role !== '' || from !== '' || to !== '';
  const clearFilters = () => {
    setSearch('');
    setStatus('');
    setRole('');
    setFrom('');
    setTo('');
  };

  const load = useCallback(
    async (pageToLoad: number) => {
      setIsLoading(true);
      setError(null);
      try {
        const res = await auditLogService.list(pageToLoad, perPage, filters);
        setLogs(res.data);
        setScope(res.scope);
        setPage(res.current_page);
        setLastPage(res.last_page);
        setTotal(res.total);
      } catch (err) {
        setError(extractMessage(err, 'Failed to load the login audit log.'));
      } finally {
        setIsLoading(false);
      }
    },
    [perPage, filters],
  );

  useEffect(() => {
    void load(1);
  }, [load]);

  const handleExport = async (format: 'csv' | 'pdf') => {
    setIsExporting(format);
    setError(null);
    try {
      if (format === 'csv') {
        await auditLogService.downloadCsv(filters);
      } else {
        await auditLogService.downloadPdf(filters);
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Export failed. Please try again.');
    } finally {
      setIsExporting(null);
    }
  };

  const showClientColumn = scope === 'global';

  return (
    <PageShell maxWidthClassName="max-w-full">
      <PageHeader
        icon={History}
        title="Audit Logs & Login History"
        subtitle={
          superAdmin
            ? 'Platform-wide login attempts. Select a client in the header to narrow this to one account.'
            : isAdmin
              ? "Login history for every user on your account."
              : 'Your own login history.'
        }
        actions={
          <div className="flex items-center gap-2">
            <button
              onClick={() => void load(page)}
              className="rounded-lg border border-slate-300 bg-white p-2 text-slate-600 hover:bg-slate-50"
              aria-label="Refresh"
              title="Refresh"
            >
              <RefreshCw className="h-4 w-4" />
            </button>
            <button
              onClick={() => void handleExport('csv')}
              disabled={isExporting !== null}
              className="flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60"
            >
              <Download className="h-4 w-4" />
              {isExporting === 'csv' ? 'Exporting…' : 'CSV'}
            </button>
            <button
              onClick={() => void handleExport('pdf')}
              disabled={isExporting !== null}
              className="flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60"
            >
              <FileText className="h-4 w-4" />
              {isExporting === 'pdf' ? 'Exporting…' : 'PDF'}
            </button>
          </div>
        }
      />

      <div className="flex flex-wrap items-center gap-3">
        {canFilterByPerson && (
          <SearchInput value={search} onChange={setSearch} placeholder="Search by email or name…" />
        )}
        <StatusFilterSelect
          value={status}
          onChange={(v) => setStatus(v as LoginAuditStatus | '')}
          options={STATUS_OPTIONS}
          allLabel="All statuses"
        />
        {canFilterByPerson && (
          <StatusFilterSelect value={role} onChange={setRole} options={ROLE_OPTIONS} allLabel="All roles" />
        )}
        <div className="flex items-center gap-2">
          <input
            type="date"
            value={from}
            onChange={(e) => setFrom(e.target.value)}
            className={`${filterInputClass} w-40`}
            aria-label="From date"
          />
          <span className="text-sm text-slate-400">to</span>
          <input
            type="date"
            value={to}
            onChange={(e) => setTo(e.target.value)}
            className={`${filterInputClass} w-40`}
            aria-label="To date"
          />
        </div>
        <ClearFiltersButton active={hasActiveFilters} onClear={clearFilters} />
      </div>

      {error && (
        <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{error}</div>
      )}

      <TableCard>
        <table className="w-full min-w-[880px] divide-y divide-slate-200 text-sm">
          <thead className="bg-slate-50">
            <tr className="text-left text-xs font-medium uppercase tracking-wide text-slate-500">
              {showClientColumn && <th className="px-4 py-3">Client</th>}
              <th className="px-4 py-3">User</th>
              <th className="px-4 py-3">Role</th>
              <th className="px-4 py-3">Status</th>
              <th className="px-4 py-3">IP Address</th>
              <th className="px-4 py-3">Logged In At</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading ? (
              <TableSkeletonRows columns={showClientColumn ? 6 : 5} />
            ) : logs.length === 0 ? (
              <tr>
                <td colSpan={showClientColumn ? 6 : 5} className="px-4 py-10 text-center text-slate-400">
                  No login attempts recorded for these filters.
                </td>
              </tr>
            ) : (
              logs.map((log) => (
                <tr key={log.id} className="hover:bg-slate-50">
                  {showClientColumn && (
                    <td className="px-4 py-3 text-slate-600">{log.account?.company_name ?? '—'}</td>
                  )}
                  <td className="px-4 py-3">
                    <div className="font-medium text-slate-900">{log.user?.name ?? 'Unknown'}</div>
                    <div className="text-xs text-slate-500">{log.email ?? log.user?.email}</div>
                  </td>
                  <td className="px-4 py-3 capitalize text-slate-600">{log.role ?? '—'}</td>
                  <td className="px-4 py-3">
                    <span
                      className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize ring-1 ring-inset ${STATUS_BADGE[log.status]}`}
                    >
                      {log.status}
                    </span>
                  </td>
                  <td className="px-4 py-3 font-mono text-xs text-slate-500">{log.ip_address ?? '—'}</td>
                  <td className="px-4 py-3 text-slate-600">{formatDateTime(log.logged_in_at)}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
        <Pagination
          page={page}
          lastPage={lastPage}
          total={total}
          perPage={perPage}
          onPageChange={(p) => void load(p)}
          onPerPageChange={(pp) => setPerPage(pp)}
        />
      </TableCard>
    </PageShell>
  );
}
