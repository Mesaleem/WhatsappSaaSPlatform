import { Fragment, useCallback, useEffect, useState } from 'react';
import { AlertCircle, ChevronDown, ChevronRight } from 'lucide-react';
import activityLogService from '../../services/activityLogService';
import accountService from '../../services/accountService';
import type { ActivityActionType, ActivityLog, ActivityLogFilters } from '../../types/activityLog';
import type { Account } from '../../types/account';
import { TableCard } from '../../components/common/Card';
import { ClearFiltersButton, Pagination, StatusFilterSelect } from '../../components/common/DataTableControls';
import { TableSkeletonRows } from '../../components/common/Skeleton';
import { extractErrorMessage } from '../../utils/apiError';

const ACTION_TYPE_OPTIONS: { value: ActivityActionType; label: string }[] = [
  { value: 'create', label: 'Create' },
  { value: 'update', label: 'Update' },
  { value: 'delete', label: 'Delete' },
  { value: 'toggle', label: 'Toggle' },
];

const ACTION_BADGE: Record<ActivityActionType, string> = {
  create: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  update: 'bg-sky-50 text-sky-700 ring-sky-600/20',
  toggle: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  delete: 'bg-red-50 text-red-700 ring-red-600/20',
};

const EMPTY_FILTERS: ActivityLogFilters = {};

/**
 * IMPLEMENT: Dynamic Route Master with Super-Admin Bypass & Global Audit
 * Tracking — requirement 4's "Super-Admin Audit Trail UI". Distinct from
 * the existing `/audit-logs` page (login attempts only, see
 * AuditLogsPage.tsx) — this one lists every CRUD mutation the
 * LogsActivity trait recorded, filterable by Agent, Sub-Client, Date and
 * Module exactly per spec.
 */
export default function ActivityLogsPage() {
  const [logs, setLogs] = useState<ActivityLog[]>([]);
  const [modules, setModules] = useState<string[]>([]);
  const [agents, setAgents] = useState<Account[]>([]);
  const [subClients, setSubClients] = useState<Account[]>([]);

  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);

  const [agentId, setAgentId] = useState<number | ''>('');
  const [accountId, setAccountId] = useState<number | ''>('');
  const [moduleName, setModuleName] = useState('');
  const [actionType, setActionType] = useState<ActivityActionType | ''>('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');

  const [expandedId, setExpandedId] = useState<number | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Built fresh inside load() below (not closed over from a
  // component-body variable) so useCallback's dependency array can name
  // the individual primitives it actually reads, rather than an object
  // literal that would otherwise be a new reference every render.
  const buildFilters = (): ActivityLogFilters => ({
    agent_id: agentId || undefined,
    account_id: accountId || undefined,
    module_name: moduleName || undefined,
    action_type: actionType || undefined,
    from: from || undefined,
    to: to || undefined,
  });
  const filtersActive = JSON.stringify(buildFilters()) !== JSON.stringify(EMPTY_FILTERS);

  const load = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const response = await activityLogService.list(page, perPage, {
        agent_id: agentId || undefined,
        account_id: accountId || undefined,
        module_name: moduleName || undefined,
        action_type: actionType || undefined,
        from: from || undefined,
        to: to || undefined,
      });
      setLogs(response.data);
      setLastPage(response.last_page);
      setTotal(response.total);
    } catch (err) {
      setError(extractErrorMessage(err, 'Failed to load the activity log.'));
    } finally {
      setIsLoading(false);
    }
  }, [page, perPage, agentId, accountId, moduleName, actionType, from, to]);

  useEffect(() => {
    void load();
  }, [load]);

  useEffect(() => {
    void activityLogService.listModules().then(setModules);
    void accountService.listAgents().then(setAgents);
  }, []);

  // Sub-Client dropdown narrows to the selected Agent's own Sub-Clients —
  // matches this app's existing "Filter by Agent" -> Sub-Client cascade
  // convention (AccountsPage.tsx).
  useEffect(() => {
    if (!agentId) {
      setSubClients([]);
      setAccountId('');
      return;
    }
    let cancelled = false;
    accountService.list({ account_type: 'client', agent_id: agentId, per_page: 100 }).then((res) => {
      if (!cancelled) setSubClients(res.data);
    });
    return () => {
      cancelled = true;
    };
  }, [agentId]);

  const clearFilters = () => {
    setAgentId('');
    setAccountId('');
    setModuleName('');
    setActionType('');
    setFrom('');
    setTo('');
    setPage(1);
  };

  return (
    <div className="p-6">
      <div className="w-full space-y-6">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">Activity Logs</h1>
          <p className="mt-1 text-sm text-slate-500">
            Every Create/Edit/Delete/Toggle action performed by Agents and Clients across the platform.
          </p>
        </div>

      <div className="flex flex-wrap items-center gap-2">
        <select
          value={agentId}
          onChange={(e) => {
            setAgentId(e.target.value ? Number(e.target.value) : '');
            setPage(1);
          }}
          className="rounded-lg border border-slate-300 bg-white py-2 pl-3 pr-8 text-sm text-slate-700 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
        >
          <option value="">All Agents</option>
          {agents.map((agent) => (
            <option key={agent.id} value={agent.id}>
              {agent.company_name}
            </option>
          ))}
        </select>

        <select
          value={accountId}
          disabled={!agentId}
          onChange={(e) => {
            setAccountId(e.target.value ? Number(e.target.value) : '');
            setPage(1);
          }}
          className="rounded-lg border border-slate-300 bg-white py-2 pl-3 pr-8 text-sm text-slate-700 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 disabled:cursor-not-allowed disabled:opacity-60"
        >
          <option value="">{agentId ? 'All Sub-Clients' : 'Select an Agent first'}</option>
          {subClients.map((client) => (
            <option key={client.id} value={client.id}>
              {client.company_name}
            </option>
          ))}
        </select>

        <select
          value={moduleName}
          onChange={(e) => {
            setModuleName(e.target.value);
            setPage(1);
          }}
          className="rounded-lg border border-slate-300 bg-white py-2 pl-3 pr-8 text-sm text-slate-700 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
        >
          <option value="">All Modules</option>
          {modules.map((module) => (
            <option key={module} value={module}>
              {module}
            </option>
          ))}
        </select>

        <StatusFilterSelect
          value={actionType}
          onChange={(value) => {
            setActionType(value as ActivityActionType | '');
            setPage(1);
          }}
          options={ACTION_TYPE_OPTIONS}
          allLabel="All Actions"
        />

        <input
          type="date"
          value={from}
          onChange={(e) => {
            setFrom(e.target.value);
            setPage(1);
          }}
          className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
        />
        <span className="text-sm text-slate-400">to</span>
        <input
          type="date"
          value={to}
          onChange={(e) => {
            setTo(e.target.value);
            setPage(1);
          }}
          className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
        />

        <ClearFiltersButton active={filtersActive} onClear={clearFilters} />
      </div>

      {error && (
        <div role="alert" className="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />
          <span>{error}</span>
        </div>
      )}

      <TableCard>
        <table className="min-w-full divide-y divide-slate-200 text-sm">
          <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
            <tr>
              <th className="px-4 py-2.5" />
              <th className="px-4 py-2.5">When</th>
              <th className="px-4 py-2.5">User</th>
              <th className="px-4 py-2.5">Agent</th>
              <th className="px-4 py-2.5">Sub-Client</th>
              <th className="px-4 py-2.5">Module</th>
              <th className="px-4 py-2.5">Action</th>
              <th className="px-4 py-2.5">Route</th>
              <th className="px-4 py-2.5">IP</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading ? (
              <TableSkeletonRows rows={perPage > 10 ? 10 : perPage} columns={9} />
            ) : logs.length === 0 ? (
              <tr>
                <td colSpan={9} className="px-4 py-6 text-center text-slate-400">
                  No activity recorded yet.
                </td>
              </tr>
            ) : (
              logs.map((log) => (
                <Fragment key={log.id}>
                  <tr key={log.id} className="hover:bg-slate-50">
                    <td className="px-4 py-2.5">
                      <button
                        type="button"
                        onClick={() => setExpandedId(expandedId === log.id ? null : log.id)}
                        className="text-slate-400 hover:text-slate-600"
                      >
                        {expandedId === log.id ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                      </button>
                    </td>
                    <td className="px-4 py-2.5 whitespace-nowrap text-slate-500">{new Date(log.created_at).toLocaleString()}</td>
                    <td className="px-4 py-2.5 text-slate-900">{log.user?.name ?? '—'}</td>
                    <td className="px-4 py-2.5 text-slate-500">{log.agent?.company_name ?? '—'}</td>
                    <td className="px-4 py-2.5 text-slate-500">{log.account?.company_name ?? '—'}</td>
                    <td className="px-4 py-2.5 text-slate-500">{log.module_name}</td>
                    <td className="px-4 py-2.5">
                      <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${ACTION_BADGE[log.action_type]}`}>
                        {log.action_type}
                      </span>
                    </td>
                    <td className="px-4 py-2.5 font-mono text-xs text-slate-500">{log.route_path ?? '—'}</td>
                    <td className="px-4 py-2.5 text-slate-500">{log.ip_address ?? '—'}</td>
                  </tr>
                  {expandedId === log.id && (
                    <tr key={`${log.id}-detail`}>
                      <td colSpan={9} className="bg-slate-50 px-4 py-3">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                          <div>
                            <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">Old Values</div>
                            <pre className="mt-1 max-h-48 overflow-auto rounded-lg bg-white p-2 text-xs text-slate-700 ring-1 ring-slate-200">
                              {log.old_values ? JSON.stringify(log.old_values, null, 2) : '—'}
                            </pre>
                          </div>
                          <div>
                            <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">New Values</div>
                            <pre className="mt-1 max-h-48 overflow-auto rounded-lg bg-white p-2 text-xs text-slate-700 ring-1 ring-slate-200">
                              {log.new_values ? JSON.stringify(log.new_values, null, 2) : '—'}
                            </pre>
                          </div>
                        </div>
                      </td>
                    </tr>
                  )}
                </Fragment>
              ))
            )}
          </tbody>
        </table>
        <Pagination page={page} lastPage={lastPage} total={total} perPage={perPage} onPageChange={setPage} onPerPageChange={setPerPage} />
      </TableCard>
      </div>
    </div>
  );
}
