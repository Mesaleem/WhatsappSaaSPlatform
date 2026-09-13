import { useCallback, useEffect, useMemo, useState } from 'react';
import { AxiosError } from 'axios';
import { ClipboardList, RefreshCw, Users } from 'lucide-react';
import messageLogsService from '../../services/messageLogsService';
import type {
  MessageDispatchLog,
  MessageDispatchLogFilters,
  MessageDispatchRecipientType,
  MessageDispatchSource,
  MessageDispatchStatus,
} from '../../types/messageLog';
import type { ApiErrorResponse } from '../../types/auth';
import { PageHeader, PageShell } from '../../components/common/PageShell';
import { TableCard } from '../../components/common/Card';
import { ClearFiltersButton, Pagination, SearchInput, StatusFilterSelect } from '../../components/common/DataTableControls';
import { TableSkeletonRows } from '../../components/common/Skeleton';

/** Same pattern as AuditLogsPage.tsx's extractMessage() — surfaces the backend's real error (e.g. a missing-table 500 before the message_dispatch_logs migration has run) instead of a fixed generic string. */
function extractMessage(err: unknown, fallback: string): string {
  const axiosErr = err as AxiosError<ApiErrorResponse>;
  return axiosErr.response?.data?.message ?? fallback;
}

const STATUS_OPTIONS: { value: MessageDispatchStatus; label: string }[] = [
  { value: 'sent', label: 'Sent' },
  { value: 'failed', label: 'Failed' },
  // Group Messaging Phase 4 — a group dispatch batch sits here between
  // enqueue and ProcessGroupDispatchJob resolving it.
  { value: 'queued', label: 'Queued' },
];

const SOURCE_OPTIONS: { value: MessageDispatchSource; label: string }[] = [
  { value: 'web_ui', label: 'Web (Send Alert)' },
  { value: 'web_template', label: 'Web (Template)' },
  { value: 'api', label: 'Developer API' },
  { value: 'chatbot', label: 'Chatbot' },
  { value: 'journey', label: 'Journey Builder' },
];

// Group Messaging Phase 5.
const RECIPIENT_TYPE_OPTIONS: { value: MessageDispatchRecipientType; label: string }[] = [
  { value: 'individual', label: 'Individual' },
  { value: 'group', label: 'Group' },
];

const SOURCE_BADGE: Record<MessageDispatchSource, { label: string; className: string }> = {
  web_ui: { label: 'Web', className: 'bg-indigo-50 text-indigo-700 ring-indigo-600/20' },
  web_template: { label: 'Template', className: 'bg-violet-50 text-violet-700 ring-violet-600/20' },
  api: { label: 'API', className: 'bg-amber-50 text-amber-700 ring-amber-600/20' },
  chatbot: { label: 'Chatbot', className: 'bg-cyan-50 text-cyan-700 ring-cyan-600/20' },
  journey: { label: 'Journey', className: 'bg-fuchsia-50 text-fuchsia-700 ring-fuchsia-600/20' },
};

const STATUS_BADGE: Record<MessageDispatchStatus, string> = {
  sent: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  failed: 'bg-red-50 text-red-700 ring-red-600/20',
  queued: 'bg-amber-50 text-amber-700 ring-amber-600/20',
};

const filterInputClass =
  'rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100';

function formatDateTime(value: string | null): string {
  if (!value) return '—';
  return new Date(value).toLocaleString();
}

/**
 * [New feature, disclosed] Message Logs / Audit Trail — unified dispatch
 * log across every send pathway (Send Alert, Send Template, Chatbot,
 * Journey Builder — web and external-API entry points alike), backed by
 * `message_dispatch_logs` / MessageDispatchLogController. Deliberately
 * separate from the pre-existing "Recent Transactions" grid embedded in
 * AnalyticsPage.tsx (that one reads `payment_alerts` via
 * MessageLogController/`/alerts/logs` and is left untouched — see
 * MessageDispatchLogController's own docblock).
 *
 * The "Media Attachment" column will read "No" for every row today — see
 * MessageDispatchLog.has_media's docblock in types/messageLog.ts for why
 * (no dispatch pathway in this codebase can attach media yet).
 */
export default function MessageLogsPage() {
  const [logs, setLogs] = useState<MessageDispatchLog[]>([]);
  const [scope, setScope] = useState<'account' | 'global'>('account');
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [perPage, setPerPage] = useState(15);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<MessageDispatchStatus | ''>('');
  const [source, setSource] = useState<MessageDispatchSource | ''>('');
  // Group Messaging Phase 5.
  const [recipientType, setRecipientType] = useState<MessageDispatchRecipientType | ''>('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const filters: MessageDispatchLogFilters = useMemo(
    () => ({ search, status, source, recipient_type: recipientType, from, to }),
    [search, status, source, recipientType, from, to],
  );

  const hasActiveFilters =
    search !== '' || status !== '' || source !== '' || recipientType !== '' || from !== '' || to !== '';
  const clearFilters = () => {
    setSearch('');
    setStatus('');
    setSource('');
    setRecipientType('');
    setFrom('');
    setTo('');
  };

  const load = useCallback(
    async (pageToLoad: number) => {
      setIsLoading(true);
      setError(null);
      try {
        const res = await messageLogsService.list(pageToLoad, perPage, filters);
        setLogs(res.data);
        setScope(res.scope);
        setPage(res.current_page);
        setLastPage(res.last_page);
        setTotal(res.total);
      } catch (err) {
        setError(extractMessage(err, 'Failed to load the message logs.'));
      } finally {
        setIsLoading(false);
      }
    },
    [perPage, filters],
  );

  useEffect(() => {
    void load(1);
  }, [load]);

  const showClientColumn = scope === 'global';

  return (
    <PageShell maxWidthClassName="max-w-full">
      <PageHeader
        icon={ClipboardList}
        title="Message Logs"
        subtitle="Every outbound WhatsApp send attempt, across Send Alert, Send Template, Chatbot and Journey Builder — web and Developer API alike."
        actions={
          <button
            onClick={() => void load(page)}
            className="rounded-lg border border-slate-300 bg-white p-2 text-slate-600 hover:bg-slate-50"
            aria-label="Refresh"
            title="Refresh"
          >
            <RefreshCw className="h-4 w-4" />
          </button>
        }
      />

      <div className="flex flex-wrap items-center gap-3">
        <SearchInput value={search} onChange={setSearch} placeholder="Search by phone, template, or group name…" />
        <StatusFilterSelect
          value={status}
          onChange={(v) => setStatus(v as MessageDispatchStatus | '')}
          options={STATUS_OPTIONS}
          allLabel="All statuses"
        />
        <StatusFilterSelect
          value={source}
          onChange={(v) => setSource(v as MessageDispatchSource | '')}
          options={SOURCE_OPTIONS}
          allLabel="All sources"
        />
        {/* Group Messaging Phase 5. */}
        <StatusFilterSelect
          value={recipientType}
          onChange={(v) => setRecipientType(v as MessageDispatchRecipientType | '')}
          options={RECIPIENT_TYPE_OPTIONS}
          allLabel="All types"
        />
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
        <table className="w-full min-w-[900px] divide-y divide-slate-200 text-sm">
          <thead className="bg-slate-50">
            <tr className="text-left text-xs font-medium uppercase tracking-wide text-slate-500">
              {showClientColumn && <th className="px-4 py-3">Client</th>}
              <th className="px-4 py-3">Recipient Phone / Group Name</th>
              <th className="px-4 py-3">Source</th>
              <th className="px-4 py-3">Template / Message</th>
              <th className="px-4 py-3">Media Attachment</th>
              <th className="px-4 py-3">Status</th>
              <th className="px-4 py-3">Timestamp</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading ? (
              <TableSkeletonRows columns={showClientColumn ? 7 : 6} />
            ) : logs.length === 0 ? (
              <tr>
                <td colSpan={showClientColumn ? 7 : 6} className="px-4 py-10 text-center text-slate-400">
                  No message dispatch attempts recorded for these filters.
                </td>
              </tr>
            ) : (
              logs.map((log) => (
                <tr key={log.id} className="hover:bg-slate-50">
                  {showClientColumn && (
                    <td className="px-4 py-3 text-slate-600">{log.account?.company_name ?? '—'}</td>
                  )}
                  <td className="px-4 py-3">
                    {log.recipient_type === 'group' ? (
                      <div className="flex items-center gap-1.5">
                        <span className="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 ring-1 ring-inset ring-indigo-600/20">
                          <Users className="h-3 w-3" />
                          Group
                        </span>
                        <span className="truncate text-xs font-semibold text-slate-800">
                          {log.group_name ?? '—'} ({log.recipient_count})
                        </span>
                      </div>
                    ) : (
                      <span className="font-mono text-xs text-slate-700">{log.recipient_phone}</span>
                    )}
                  </td>
                  <td className="px-4 py-3">
                    <span
                      className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${SOURCE_BADGE[log.source].className}`}
                    >
                      {SOURCE_BADGE[log.source].label}
                    </span>
                  </td>
                  <td className="px-4 py-3 max-w-xs">
                    {log.template_name && (
                      <div className="truncate text-xs font-semibold text-slate-800">{log.template_name}</div>
                    )}
                    <div className="truncate text-xs text-slate-500">{log.message_preview ?? '—'}</div>
                  </td>
                  <td className="px-4 py-3 text-slate-600">{log.has_media ? 'Yes' : 'No'}</td>
                  <td className="px-4 py-3">
                    <span
                      className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize ring-1 ring-inset ${STATUS_BADGE[log.status]}`}
                      title={log.status === 'failed' ? (log.error_reason ?? undefined) : undefined}
                    >
                      {log.status}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-slate-600">{formatDateTime(log.created_at)}</td>
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
