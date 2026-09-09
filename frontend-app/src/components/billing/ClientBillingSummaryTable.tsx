import { useCallback, useEffect, useMemo, useState } from 'react';
import { Users2, XCircle } from 'lucide-react';
import billingService from '../../services/billingService';
import { useTenant } from '../../core/context/TenantContext';
import { TableCard } from '../common/Card';
import { Pagination, SearchInput, StatusFilterSelect } from '../common/DataTableControls';
import { TableSkeletonRows } from '../common/Skeleton';
import type { ClientBillingSummaryRow, ClientPaymentStatus } from '../../types/billing';

function formatMoney(amount: string | null): string {
  if (amount === null) return '—';
  return `₹ ${Number(amount).toFixed(2)}`;
}

function formatDate(value: string | null): string {
  if (!value) return '—';
  return new Date(value).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

const STATUS_OPTIONS: { value: ClientPaymentStatus; label: string }[] = [
  { value: 'active', label: 'Active' },
  { value: 'overdue', label: 'Overdue' },
];

function PaymentStatusBadge({ status }: { status: ClientPaymentStatus }) {
  const cls =
    status === 'active'
      ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
      : 'bg-red-50 text-red-700 border-red-200';
  return (
    <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium capitalize ${cls}`}>
      {status}
    </span>
  );
}

/**
 * Billing & Plans Module Overhaul — Super Admin Billing Overview. Replaces
 * the old "No tenant account to bill" error block: shown to super_admin
 * in place of the tenant self-service checkout/invoice UI (see
 * BillingPage's docblock for why Super Admin doesn't get that UI too).
 * The Header's client switcher (?account_id=, via the axios interceptor)
 * narrows this same table to one client rather than swapping to a
 * different screen — the established global/one-account pattern used
 * across Analytics, Team Users and Message Logs.
 */
export default function ClientBillingSummaryTable() {
  const { selectedAccountId } = useTenant();
  const [rows, setRows] = useState<ClientBillingSummaryRow[]>([]);
  const [scope, setScope] = useState<'account' | 'global'>('global');
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [perPage, setPerPage] = useState(15);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const filters = useMemo(
    () => ({ search, status: statusFilter, from, to }),
    [search, statusFilter, from, to],
  );

  const load = useCallback(
    async (pageToLoad: number, perPageToLoad: number) => {
      setIsLoading(true);
      setError(null);
      try {
        const res = await billingService.getClientSummary(pageToLoad, perPageToLoad, filters);
        setRows(res.data);
        setScope(res.scope);
        setPage(res.current_page);
        setLastPage(res.last_page);
        setTotal(res.total);
      } catch {
        setError('Failed to load the billing summary.');
      } finally {
        setIsLoading(false);
      }
    },
    [filters],
  );

  useEffect(() => {
    void load(1, perPage);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [load, selectedAccountId, perPage]);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-900">
            <Users2 className="h-4 w-4 text-indigo-600" />
            Client Billing Summary
          </h2>
          <p className="mt-1 text-sm text-slate-500">
            {scope === 'global'
              ? 'Plan, usage and payment status for every client on the platform.'
              : 'Select "All Clients" in the header to see every client again.'}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          <SearchInput value={search} onChange={setSearch} placeholder="Search by client name…" />
          <StatusFilterSelect
            value={statusFilter}
            onChange={setStatusFilter}
            options={STATUS_OPTIONS}
            allLabel="All payment statuses"
          />
          <div className="flex items-center gap-2">
            <input
              type="date"
              value={from}
              onChange={(e) => setFrom(e.target.value)}
              className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 w-40"
              aria-label="From date"
            />
            <span className="text-sm text-slate-400">to</span>
            <input
              type="date"
              value={to}
              onChange={(e) => setTo(e.target.value)}
              className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 w-40"
              aria-label="To date"
            />
          </div>
        </div>
      </div>

      {error && (
        <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          <XCircle className="h-4 w-4 flex-shrink-0" />
          {error}
        </div>
      )}

      <TableCard>
        <table className="min-w-full divide-y divide-slate-200 text-sm">
          <thead className="bg-slate-50 text-left text-xs font-medium uppercase text-slate-500">
            <tr>
              <th className="px-6 py-3">Client</th>
              <th className="px-6 py-3">Plan</th>
              <th className="px-6 py-3">Amount</th>
              <th className="px-6 py-3">Quota Used / Total</th>
              <th className="px-6 py-3">Payment Status</th>
              <th className="px-6 py-3">Renewal Date</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading ? (
              <TableSkeletonRows columns={6} />
            ) : rows.length > 0 ? (
              rows.map((row) => (
                <tr key={row.account_id}>
                  <td className="px-6 py-3 font-medium text-slate-900">{row.company_name}</td>
                  <td className="px-6 py-3 text-slate-600">{row.plan_label ?? '—'}</td>
                  <td className="px-6 py-3 text-slate-600">{formatMoney(row.amount)}</td>
                  <td className="px-6 py-3 text-slate-600">
                    {row.total_allocated_messages !== null
                      ? `${(row.used_messages ?? 0).toLocaleString()} / ${row.total_allocated_messages.toLocaleString()}`
                      : row.used_messages !== null
                        ? `${row.used_messages.toLocaleString()} / Unlimited`
                        : '—'}
                  </td>
                  <td className="px-6 py-3">
                    <PaymentStatusBadge status={row.payment_status} />
                  </td>
                  <td className="px-6 py-3 text-slate-600">{formatDate(row.renewal_date)}</td>
                </tr>
              ))
            ) : (
              <tr>
                <td colSpan={6} className="px-6 py-6 text-center text-slate-400">
                  No clients match this filter.
                </td>
              </tr>
            )}
          </tbody>
        </table>
        <Pagination
          page={page}
          lastPage={lastPage}
          total={total}
          perPage={perPage}
          onPageChange={(p) => void load(p, perPage)}
          onPerPageChange={(pp) => setPerPage(pp)}
        />
      </TableCard>
    </div>
  );
}
