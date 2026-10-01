import { useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { Building2, Info, X } from 'lucide-react';
import { useTenant } from '../../core/context/TenantContext';
import { setSelectedAccountId } from '../../core/api/axiosInstance';
import { inputClass } from './Card';

/**
 * Final hardening §23 — "the user should be able to click and understand
 * what needs to happen next". Shared building blocks that replace
 * unexplained `disabled` primary actions with a clickable prerequisite
 * step. They are UX only: every operation behind them is still authorized
 * by the backend (tenant isolation, permission, module, capability,
 * subscription, provider state) exactly as before — nothing here grants
 * anything, it only explains and routes.
 *
 *   ClientPickerModal — a Super Admin in "All Clients (Global View)" (or an
 *     Agent acting as itself where a client is required) picks the client to
 *     act for, then the original action continues. The Header switcher is
 *     hidden on small screens, so "select a client above" alone was not
 *     always actionable.
 *   GateNoticeModal — explains a missing prerequisite / entitlement and
 *     offers the next step (a link), instead of a greyed-out button.
 */

export interface GateNoticeAction {
  label: string;
  to: string;
}

export function GateNoticeModal({
  title,
  message,
  action,
  secondary,
  onClose,
  testId = 'gate-notice',
}: {
  title: string;
  message: ReactNode;
  action?: GateNoticeAction | null;
  /** An optional second button (e.g. "Continue anyway" when a prerequisite is only recommended). */
  secondary?: { label: string; onClick: () => void } | null;
  onClose: () => void;
  testId?: string;
}) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4" role="dialog" aria-modal="true" aria-labelledby={`${testId}-title`}>
      <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl" data-testid={testId}>
        <div className="flex items-start justify-between gap-3">
          <div className="flex items-start gap-2">
            <Info className="mt-0.5 h-5 w-5 flex-shrink-0 text-indigo-600" />
            <h2 id={`${testId}-title`} className="text-base font-semibold text-slate-900">
              {title}
            </h2>
          </div>
          <button type="button" onClick={onClose} className="text-slate-400 hover:text-slate-600" aria-label="Dismiss">
            <X className="h-4 w-4" />
          </button>
        </div>
        <div className="mt-3 text-sm text-slate-600">{message}</div>
        <div className="mt-5 flex flex-wrap justify-end gap-2">
          <button type="button" onClick={onClose} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
            Close
          </button>
          {secondary && (
            <button type="button" onClick={secondary.onClick} className="rounded-lg border border-indigo-200 px-4 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-50">
              {secondary.label}
            </button>
          )}
          {action && (
            <Link to={action.to} onClick={onClose} className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">
              {action.label}
            </Link>
          )}
        </div>
      </div>
    </div>
  );
}

export function ClientPickerModal({
  purpose,
  onCancel,
  onSelected,
}: {
  /** What the user was trying to do, e.g. "connect a Meta account". */
  purpose: string;
  onCancel: () => void;
  onSelected: (accountId: number) => void;
}) {
  const { accounts, isLoadingAccounts, selectAccount } = useTenant();
  const [choice, setChoice] = useState('');

  const confirm = () => {
    const id = Number(choice);
    if (!Number.isFinite(id) || id <= 0) return;
    selectAccount(id);
    // Scope the very next request right away: TenantProvider applies the
    // selection to axios in an effect, which runs after a child's own
    // mount effects (e.g. a modal that loads data as soon as it opens).
    setSelectedAccountId(id);
    onSelected(id);
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4" role="dialog" aria-modal="true" aria-labelledby="client-picker-title">
      <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl" data-testid="client-picker">
        <div className="flex items-start justify-between gap-3">
          <div className="flex items-start gap-2">
            <Building2 className="mt-0.5 h-5 w-5 flex-shrink-0 text-indigo-600" />
            <h2 id="client-picker-title" className="text-base font-semibold text-slate-900">
              Choose a client first
            </h2>
          </div>
          <button type="button" onClick={onCancel} className="text-slate-400 hover:text-slate-600" aria-label="Close">
            <X className="h-4 w-4" />
          </button>
        </div>
        <p className="mt-3 text-sm text-slate-600">
          You are viewing all clients. Choose the client account you want to {purpose} for — it becomes the selected client for the whole app (you can change it any time from
          the client switcher).
        </p>
        <label className="mt-4 block text-sm font-medium text-slate-700">
          Client
          <select className={inputClass} value={choice} onChange={(e) => setChoice(e.target.value)} aria-label="Client to act for" data-testid="client-picker-select">
            <option value="">{isLoadingAccounts ? 'Loading clients…' : '— select a client —'}</option>
            {accounts.map((account) => (
              <option key={account.id} value={account.id}>
                {account.company_name}
              </option>
            ))}
          </select>
        </label>
        {!isLoadingAccounts && accounts.length === 0 && <p className="mt-2 text-xs text-slate-500">No client accounts are available yet — create one under Accounts first.</p>}
        <div className="mt-5 flex justify-end gap-2">
          <button type="button" onClick={onCancel} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
            Cancel
          </button>
          <button
            type="button"
            onClick={confirm}
            disabled={choice === ''}
            title={choice === '' ? 'Choose a client from the list first.' : undefined}
            className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60"
          >
            Continue
          </button>
        </div>
      </div>
    </div>
  );
}

/** The in-page empty state for "no client selected", with an actionable button (the Header switcher is hidden on small screens). */
export function SelectClientNotice({ message, purpose, onSelect }: { message: string; purpose?: string; onSelect: () => void }) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-500" data-testid="select-client-notice">
      <span className="flex items-start gap-3">
        <Building2 className="mt-0.5 h-4 w-4 flex-shrink-0" />
        {message}
      </span>
      <button type="button" onClick={onSelect} className="rounded-lg border border-indigo-200 bg-white px-3 py-1.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-50" data-testid="select-client-button">
        Choose a client{purpose ? ` to ${purpose}` : ''}
      </button>
    </div>
  );
}
