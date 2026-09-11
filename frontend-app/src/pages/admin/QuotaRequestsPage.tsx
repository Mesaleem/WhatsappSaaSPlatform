import { useCallback, useEffect, useState } from 'react';
import { AxiosError } from 'axios';
import { CheckCircle2, Loader2, RefreshCw, XCircle, Zap } from 'lucide-react';
import quotaRequestService from '../../services/quotaRequestService';
import type { ApiErrorResponse } from '../../types/auth';
import type { QuotaRequest, QuotaRequestStatus } from '../../types/quotaRequest';
import { PageHeader, PageShell } from '../../components/common/PageShell';
import { TableCard } from '../../components/common/Card';
import { Pagination, StatusFilterSelect } from '../../components/common/DataTableControls';
import { TableSkeletonRows } from '../../components/common/Skeleton';

function extractMessage(err: unknown, fallback: string): string {
  const axiosErr = err as AxiosError<ApiErrorResponse>;
  return axiosErr.response?.data?.message ?? fallback;
}

const STATUS_OPTIONS: { value: QuotaRequestStatus | 'all'; label: string }[] = [
  { value: 'pending', label: 'Pending' },
  { value: 'approved', label: 'Approved' },
  { value: 'rejected', label: 'Rejected' },
  { value: 'all', label: 'All' },
];

const STATUS_BADGE: Record<QuotaRequestStatus, string> = {
  pending: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  approved: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  rejected: 'bg-red-50 text-red-700 ring-red-600/20',
};

function formatDateTime(value: string | null): string {
  if (!value) return '—';
  return new Date(value).toLocaleString();
}

/**
 * Quota Exhaustion Request Workflow — Super Admin review/approval queue.
 * Route-gated permission="manage-billing-settings" (see App.tsx), the
 * same tier as this page's admin/billing siblings (gateway/mail
 * settings) — approving a request mutates a tenant's subscription quota
 * and generates a real invoice, which is billing administration.
 *
 * Approving a request shows the exact success text the spec named
 * ("Extra quota added to your account. Invoice generated under Billing
 * & Plans.") to the Super Admin performing the approval — there is no
 * live push to the requesting Client Admin's own session; they see the
 * new invoice the next time they open Billing & Plans. See this
 * refactor's audit report for the disclosed interpretation.
 */
export default function QuotaRequestsPage() {
  const [status, setStatus] = useState<QuotaRequestStatus | 'all'>('pending');
  const [requests, setRequests] = useState<QuotaRequest[]>([]);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [perPage, setPerPage] = useState(15);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [approvingId, setApprovingId] = useState<number | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  const load = useCallback(
    async (pageToLoad: number) => {
      setIsLoading(true);
      setError(null);
      try {
        const res = await quotaRequestService.list(status, pageToLoad, perPage);
        setRequests(res.data);
        setPage(res.current_page);
        setLastPage(res.last_page);
        setTotal(res.total);
      } catch (err) {
        setError(extractMessage(err, 'Failed to load quota top-up requests.'));
      } finally {
        setIsLoading(false);
      }
    },
    [status, perPage],
  );

  useEffect(() => {
    void load(1);
  }, [load]);

  const handleApprove = async (request: QuotaRequest) => {
    setApprovingId(request.id);
    setError(null);
    setSuccessMessage(null);
    try {
      await quotaRequestService.approve(request.id);
      // Exact text required by the spec.
      setSuccessMessage('Extra quota added to your account. Invoice generated under Billing & Plans.');
      void load(page);
    } catch (err) {
      setError(extractMessage(err, 'Could not approve this request. Please try again.'));
    } finally {
      setApprovingId(null);
    }
  };

  return (
    <PageShell maxWidthClassName="max-w-full">
      <PageHeader
        icon={Zap}
        title="Quota Top-Up Requests"
        subtitle="Review and approve Client Admin requests for extra message quota. Approving credits the tenant's subscription and generates an invoice."
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
        <StatusFilterSelect
          value={status === 'all' ? '' : status}
          onChange={(v) => setStatus((v || 'all') as QuotaRequestStatus | 'all')}
          options={STATUS_OPTIONS.filter((o) => o.value !== 'all').map((o) => ({ value: o.value, label: o.label }))}
          allLabel="All statuses"
        />
      </div>

      {successMessage && (
        <div className="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
          <CheckCircle2 className="h-4 w-4 flex-shrink-0" />
          {successMessage}
        </div>
      )}
      {error && (
        <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          <XCircle className="h-4 w-4 flex-shrink-0" />
          {error}
        </div>
      )}

      <TableCard>
        <table className="w-full min-w-[880px] divide-y divide-slate-200 text-sm">
          <thead className="bg-slate-50">
            <tr className="text-left text-xs font-medium uppercase tracking-wide text-slate-500">
              <th className="px-4 py-3">Client</th>
              <th className="px-4 py-3">Requested By</th>
              <th className="px-4 py-3">Extra Messages</th>
              <th className="px-4 py-3">Reason</th>
              <th className="px-4 py-3">Status</th>
              <th className="px-4 py-3">Submitted</th>
              <th className="px-4 py-3 text-right">Action</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading ? (
              <TableSkeletonRows columns={7} />
            ) : requests.length === 0 ? (
              <tr>
                <td colSpan={7} className="px-4 py-10 text-center text-slate-400">
                  No quota top-up requests for this filter.
                </td>
              </tr>
            ) : (
              requests.map((request) => (
                <tr key={request.id} className="hover:bg-slate-50">
                  <td className="px-4 py-3 text-slate-700">{request.account?.company_name ?? `Account #${request.account_id}`}</td>
                  <td className="px-4 py-3">
                    <div className="font-medium text-slate-900">{request.requestedBy?.name ?? '—'}</div>
                    <div className="text-xs text-slate-500">{request.requestedBy?.email}</div>
                  </td>
                  <td className="px-4 py-3 font-mono text-slate-700">{request.requested_extra_messages.toLocaleString()}</td>
                  <td className="px-4 py-3 max-w-xs truncate text-slate-600" title={request.reason ?? undefined}>
                    {request.reason ?? '—'}
                  </td>
                  <td className="px-4 py-3">
                    <span
                      className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize ring-1 ring-inset ${STATUS_BADGE[request.status]}`}
                    >
                      {request.status}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-slate-600">{formatDateTime(request.created_at)}</td>
                  <td className="px-4 py-3 text-right">
                    {request.status === 'pending' ? (
                      <button
                        onClick={() => void handleApprove(request)}
                        disabled={approvingId === request.id}
                        className="inline-flex items-center gap-1 rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60"
                      >
                        {approvingId === request.id ? (
                          <Loader2 className="h-3 w-3 animate-spin" />
                        ) : (
                          <CheckCircle2 className="h-3 w-3" />
                        )}
                        Approve
                      </button>
                    ) : (
                      <span className="text-xs text-slate-400">
                        {request.status === 'approved' ? 'Approved' : 'Rejected'}
                        {request.reviewed_at ? ` · ${formatDateTime(request.reviewed_at)}` : ''}
                      </span>
                    )}
                  </td>
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
