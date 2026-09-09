import { useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Pencil, Plus, RefreshCw, Timer } from 'lucide-react';
import accountService from '../../services/accountService';
import type { Account, AccountStatus } from '../../types/account';
import type { BillingModel, EngineType } from '../../types/subscription';
import CreateAccountModal from '../../components/admin/CreateAccountModal';
import { TableCard } from '../../components/common/Card';
import { Pagination, SearchInput, StatusFilterSelect } from '../../components/common/DataTableControls';
import { TableSkeletonRows } from '../../components/common/Skeleton';

const ENGINE_BADGE: Record<EngineType, string> = {
  qr: 'bg-blue-50 text-blue-700 ring-blue-600/20',
  meta: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
};

const ENGINE_LABEL: Record<EngineType, string> = {
  qr: 'QR',
  meta: 'Meta',
};

const STATUS_BADGE: Record<AccountStatus, string> = {
  active: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  suspended: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  expired: 'bg-red-50 text-red-700 ring-red-600/20',
};

const BILLING_LABEL: Record<BillingModel, string> = {
  flat_quota: 'Flat Quota',
  per_message: 'Per Message',
  unlimited: 'Unlimited',
};

function formatRate(rate: string | null): string {
  if (rate === null) return '—';
  return `₹${Number(rate).toFixed(2)}/msg`;
}

const STATUS_OPTIONS: { value: AccountStatus; label: string }[] = [
  { value: 'active', label: 'Active' },
  { value: 'suspended', label: 'Suspended' },
  { value: 'expired', label: 'Expired' },
];

export default function AccountsPage() {
  // Super Admin Dashboard Overhaul — "Active Clients" card's
  // click-to-filter (navigates here as /admin/accounts?status=active).
  // Read once on mount only; the dropdown below is the source of truth
  // after that, same as every other filter on this page.
  const [searchParams] = useSearchParams();

  const [accounts, setAccounts] = useState<Account[]>([]);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [perPage, setPerPage] = useState(15);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<AccountStatus | ''>(
    (searchParams.get('status') as AccountStatus | null) ?? '',
  );
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [modalState, setModalState] = useState<{ open: boolean; account: Account | null }>({
    open: false,
    account: null,
  });

  const load = useCallback(
    async (pageToLoad: number) => {
      setIsLoading(true);
      setError(null);
      try {
        const res = await accountService.list({
          page: pageToLoad,
          per_page: perPage,
          search: search || undefined,
          status: statusFilter || undefined,
          from: from || undefined,
          to: to || undefined,
        });
        setAccounts(res.data);
        setPage(res.current_page);
        setLastPage(res.last_page);
        setTotal(res.total);
      } catch {
        setError('Failed to load accounts.');
      } finally {
        setIsLoading(false);
      }
    },
    [perPage, search, statusFilter, from, to],
  );

  useEffect(() => {
    void load(1);
  }, [load]);

  const openCreate = () => setModalState({ open: true, account: null });
  const openEdit = (account: Account) => setModalState({ open: true, account });
  const closeModal = () => setModalState({ open: false, account: null });
  const handleSaved = () => {
    closeModal();
    void load(page);
  };

  return (
    <div className="p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">Accounts</h1>
          <p className="mt-1 text-sm text-slate-500">
            {total} account{total === 1 ? '' : 's'} provisioned
          </p>
        </div>
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
            onClick={openCreate}
            className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700"
          >
            <Plus className="h-4 w-4" />
            New Account
          </button>
        </div>
      </div>

      <div className="mt-4 flex flex-wrap items-center gap-3">
        <SearchInput value={search} onChange={setSearch} placeholder="Search by company or phone…" />
        <StatusFilterSelect
          value={statusFilter}
          onChange={(v) => setStatusFilter(v as AccountStatus | '')}
          options={STATUS_OPTIONS}
          allLabel="All statuses"
        />
        <div className="flex items-center gap-2">
          <input
            type="date"
            value={from}
            onChange={(e) => setFrom(e.target.value)}
            className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 w-40"
            aria-label="Provisioned from"
          />
          <span className="text-sm text-slate-400">to</span>
          <input
            type="date"
            value={to}
            onChange={(e) => setTo(e.target.value)}
            className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 w-40"
            aria-label="Provisioned to"
          />
        </div>
      </div>

      {error && (
        <div className="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          {error}
        </div>
      )}

      <div className="mt-6">
      <TableCard>
        <table className="w-full min-w-[960px] divide-y divide-slate-200 text-sm">
          <thead className="bg-slate-50">
            <tr className="text-left text-xs font-medium uppercase tracking-wide text-slate-500">
              <th className="px-4 py-3">Company</th>
              <th className="px-4 py-3">Owner Phone</th>
              <th className="px-4 py-3">Engine</th>
              <th className="px-4 py-3">Billing Model</th>
              <th className="px-4 py-3">Rate</th>
              <th className="px-4 py-3">Usage</th>
              <th className="px-4 py-3">Status</th>
              <th className="px-4 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading ? (
              <TableSkeletonRows columns={8} />
            ) : accounts.length === 0 ? (
              <tr>
                <td colSpan={8} className="px-4 py-10 text-center text-slate-400">
                  No accounts yet. Click "New Account" to provision one.
                </td>
              </tr>
            ) : (
              accounts.map((account) => {
                const sub = account.current_subscription;
                const used = sub?.used_messages ?? 0;
                const allocated = sub?.total_allocated_messages ?? null;
                const pct = allocated ? Math.min(100, Math.round((used / allocated) * 100)) : 0;

                return (
                  <tr key={account.id} className="hover:bg-slate-50">
                    <td className="px-4 py-3">
                      <div className="font-medium text-slate-900">{account.company_name}</div>
                      {account.owner && (
                        <div className="text-xs text-slate-500">{account.owner.email}</div>
                      )}
                    </td>
                    <td className="px-4 py-3 text-slate-600">{account.primary_phone ?? '—'}</td>
                    <td className="px-4 py-3">
                      {sub ? (
                        <span
                          className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${ENGINE_BADGE[sub.engine_type]}`}
                        >
                          {ENGINE_LABEL[sub.engine_type]}
                        </span>
                      ) : (
                        '—'
                      )}
                    </td>
                    <td className="px-4 py-3 text-slate-600">
                      {sub ? BILLING_LABEL[sub.billing_model] : '—'}
                    </td>
                    <td className="px-4 py-3 text-slate-600">
                      {sub && sub.billing_model === 'per_message' ? formatRate(sub.rate_per_message) : '—'}
                    </td>
                    <td className="px-4 py-3">
                      {!sub ? (
                        '—'
                      ) : sub.billing_model === 'unlimited' ? (
                        <span className="text-xs text-slate-500">Unlimited</span>
                      ) : (
                        <div className="w-32">
                          <div className="h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                            <div
                              className={`h-full rounded-full ${
                                pct >= 100 ? 'bg-red-500' : pct >= 80 ? 'bg-amber-500' : 'bg-indigo-500'
                              }`}
                              style={{ width: `${pct}%` }}
                            />
                          </div>
                          <div className="mt-1 text-xs text-slate-500">
                            {used.toLocaleString()} / {allocated?.toLocaleString() ?? '—'}
                          </div>
                          {sub.status !== 'active' && (
                            <div className="text-xs font-medium capitalize text-red-600">{sub.status}</div>
                          )}
                        </div>
                      )}
                    </td>
                    <td className="px-4 py-3">
                      <span
                        className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize ring-1 ring-inset ${STATUS_BADGE[account.status]}`}
                      >
                        {account.status}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex items-center justify-end gap-2">
                        <button
                          onClick={() => openEdit(account)}
                          className="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-700"
                          aria-label="Edit account"
                          title="Edit"
                        >
                          <Pencil className="h-4 w-4" />
                        </button>
                        <button
                          onClick={() => openEdit(account)}
                          className="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-700"
                          aria-label="Extend subscription"
                          title="Extend subscription"
                        >
                          <Timer className="h-4 w-4" />
                        </button>
                      </div>
                    </td>
                  </tr>
                );
              })
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
      </div>

      {modalState.open && (
        <CreateAccountModal account={modalState.account} onClose={closeModal} onSaved={handleSaved} />
      )}
    </div>
  );
}
