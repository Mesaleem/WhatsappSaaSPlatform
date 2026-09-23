import { useState } from 'react';
import { Loader2, X, XCircle } from 'lucide-react';
import crmService from '../../services/crmService';
import ConfirmModal from '../common/ConfirmModal';
import { inputClass } from '../common/Card';
import { READ_ONLY_TITLE, TagPicker } from './CrmUi';
import { describeApiError } from '../../utils/apiError';
import {
  CRM_LEAD_STATUSES,
  crmLabel,
  type CrmAssignee,
  type CrmBulkSummary,
  type CrmLeadStatus,
  type CrmMessageResponse,
  type CrmTagRef,
} from '../../types/crm';

/**
 * Phase 6 — CRM Task 9. The bulk action bar for the CRM lead list.
 *
 * Every action is ONE request to a /crm/leads/bulk/* endpoint carrying the
 * selected ids — never one request per lead. The server applies it all or
 * nothing: on any error nothing was changed, so this bar never reports or
 * simulates partial success; it shows the server's message and asks the
 * page to re-read. On success it reports the server's own counts
 * (changed / unchanged) and the page clears the selection and re-reads.
 *
 * Only operations that exist individually are offered: assign, unassign,
 * status, add tag, remove tag. No delete, no contact reassignment.
 */

type Pending =
  | { kind: 'assign' }
  | { kind: 'unassign' }
  | { kind: 'status' };

const btn =
  'rounded-lg border border-indigo-200 bg-white px-3 py-1.5 text-xs font-medium text-indigo-700 hover:bg-indigo-50 disabled:cursor-not-allowed disabled:opacity-50';

function summaryText(label: string, s: CrmBulkSummary): string {
  return `${label}: ${s.changed} changed, ${s.unchanged} unchanged.`;
}

export default function CrmBulkActionBar({
  selectedIds,
  assignees,
  readOnly,
  onDone,
  onFailed,
  onClear,
}: {
  selectedIds: number[];
  assignees: CrmAssignee[];
  readOnly: boolean;
  /** Called after a successful bulk request with a user-facing summary. */
  onDone: (message: string) => void;
  /** Called after a rejected request with the server's (sanitized) message. */
  onFailed: (message: string) => void;
  onClear: () => void;
}) {
  const [busy, setBusy] = useState(false);
  const [pending, setPending] = useState<Pending | null>(null);
  const [assigneeId, setAssigneeId] = useState('');
  const [status, setStatus] = useState<CrmLeadStatus>('contacted');
  const [reason, setReason] = useState('');

  const count = selectedIds.length;
  const disabled = busy || readOnly || count === 0;

  const run = (label: string, call: () => Promise<CrmMessageResponse<CrmBulkSummary>>, fallback: string) => {
    if (busy) return;
    setBusy(true);
    call()
      .then((res) => {
        setPending(null);
        onDone(summaryText(label, res.data));
      })
      .catch((err: unknown) => {
        setPending(null);
        onFailed(describeApiError(err, fallback).message);
      })
      .finally(() => setBusy(false));
  };

  const ids = [...selectedIds];

  return (
    <div
      className="flex flex-wrap items-center gap-2 rounded-xl border border-indigo-200 bg-indigo-50/60 px-4 py-2.5"
      data-testid="crm-bulk-bar"
      role="region"
      aria-label="Bulk actions"
    >
      <span className="text-sm font-semibold text-indigo-900" data-testid="crm-bulk-count">
        {count} selected
      </span>
      <span className="text-xs text-indigo-700/80">on this page only</span>
      {busy && <Loader2 className="h-4 w-4 animate-spin text-indigo-600" aria-label="Working" />}

      <div className="flex flex-wrap items-center gap-2 sm:ml-auto">
        <button type="button" className={btn} disabled={disabled} title={readOnly ? READ_ONLY_TITLE : undefined} onClick={() => setPending({ kind: 'assign' })}>
          Assign
        </button>
        <button type="button" className={btn} disabled={disabled} title={readOnly ? READ_ONLY_TITLE : undefined} onClick={() => setPending({ kind: 'unassign' })}>
          Unassign
        </button>
        <button type="button" className={btn} disabled={disabled} title={readOnly ? READ_ONLY_TITLE : undefined} onClick={() => setPending({ kind: 'status' })}>
          Change status
        </button>
        <TagPicker
          label="Add tag"
          excludeIds={[]}
          disabled={disabled}
          onPick={(tag: CrmTagRef) => run(`Tag “${tag.name}” added`, () => crmService.bulkAttachTag(ids, tag.id), 'Failed to add the tag.')}
        />
        <TagPicker
          label="Remove tag"
          excludeIds={[]}
          disabled={disabled}
          onPick={(tag: CrmTagRef) => run(`Tag “${tag.name}” removed`, () => crmService.bulkDetachTag(ids, tag.id), 'Failed to remove the tag.')}
        />
        <button
          type="button"
          onClick={onClear}
          disabled={busy}
          className="flex items-center gap-1 rounded-lg px-2 py-1.5 text-xs font-medium text-slate-600 hover:bg-white disabled:opacity-50"
        >
          <X className="h-3.5 w-3.5" />
          Clear selection
        </button>
      </div>

      {pending?.kind === 'unassign' && (
        <ConfirmModal
          title="Unassign leads"
          message={`Remove the owner from ${count} selected ${count === 1 ? 'lead' : 'leads'}? Leads that are already unassigned stay as they are.`}
          confirmLabel="Unassign"
          variant="default"
          isLoading={busy}
          onConfirm={() => run('Unassigned', () => crmService.bulkAssign(ids, null), 'Failed to unassign the leads.')}
          onCancel={() => setPending(null)}
        />
      )}

      {pending?.kind === 'assign' && (
        <BulkModal
          title="Assign leads"
          busy={busy}
          confirmLabel="Assign"
          confirmDisabled={assigneeId === ''}
          onConfirm={() => run('Assigned', () => crmService.bulkAssign(ids, Number(assigneeId)), 'Failed to assign the leads.')}
          onCancel={() => setPending(null)}
        >
          <p className="text-sm text-slate-600">
            Assign {count} selected {count === 1 ? 'lead' : 'leads'} to:
          </p>
          <select aria-label="Bulk assignee" className={inputClass} value={assigneeId} onChange={(e) => setAssigneeId(e.target.value)}>
            <option value="">Choose a team member…</option>
            {assignees.map((a) => (
              <option key={a.id} value={String(a.id)}>
                {a.name}
              </option>
            ))}
          </select>
          {assignees.length === 0 && <p className="mt-2 text-xs text-slate-500">No eligible team members are available.</p>}
        </BulkModal>
      )}

      {pending?.kind === 'status' && (
        <BulkModal
          title="Change status"
          busy={busy}
          confirmLabel={`Set ${count} to ${crmLabel(status)}`}
          onConfirm={() => run(`Status set to ${crmLabel(status)}`, () => crmService.bulkStatus(ids, status, reason), 'Failed to change the status.')}
          onCancel={() => setPending(null)}
        >
          <label className="block text-xs font-medium text-slate-600">
            New status
            <select aria-label="Bulk status" className={inputClass} value={status} onChange={(e) => setStatus(e.target.value as CrmLeadStatus)}>
              {CRM_LEAD_STATUSES.map((s) => (
                <option key={s} value={s}>
                  {crmLabel(s)}
                </option>
              ))}
            </select>
          </label>
          {status === 'not_converted' && (
            <label className="mt-3 block text-xs font-medium text-slate-600">
              Reason (optional)
              <input className={inputClass} value={reason} maxLength={255} onChange={(e) => setReason(e.target.value)} />
            </label>
          )}
          <p className="mt-3 text-sm text-slate-600" data-testid="crm-bulk-status-confirm">
            This will set {count} selected {count === 1 ? 'lead' : 'leads'} to <strong>{crmLabel(status)}</strong>. The server checks
            every lead first; if any cannot change, none are changed.
          </p>
        </BulkModal>
      )}
    </div>
  );
}

function BulkModal({
  title,
  busy,
  confirmLabel,
  confirmDisabled,
  onConfirm,
  onCancel,
  children,
}: {
  title: string;
  busy: boolean;
  confirmLabel: string;
  confirmDisabled?: boolean;
  onConfirm: () => void;
  onCancel: () => void;
  children: React.ReactNode;
}) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl" role="dialog" aria-label={title} data-testid="crm-bulk-modal">
        <div className="flex items-center justify-between">
          <h3 className="text-base font-semibold text-slate-900">{title}</h3>
          <button type="button" onClick={onCancel} disabled={busy} className="text-slate-400 hover:text-slate-600" aria-label="Close">
            <XCircle className="h-5 w-5" />
          </button>
        </div>
        <div className="mt-4">{children}</div>
        <div className="mt-5 flex justify-end gap-2">
          <button
            type="button"
            onClick={onCancel}
            disabled={busy}
            className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60"
          >
            Cancel
          </button>
          <button
            type="button"
            onClick={onConfirm}
            disabled={busy || confirmDisabled}
            className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60"
          >
            {busy && <Loader2 className="h-4 w-4 animate-spin" />}
            {confirmLabel}
          </button>
        </div>
      </div>
    </div>
  );
}
