import { useEffect, useRef, useState, type ReactNode } from 'react';
import { AlertCircle, Building2, Inbox, Loader2, Plus, Tag, X } from 'lucide-react';
import crmService from '../../services/crmService';
import PromptModal from '../common/PromptModal';
import { describeApiError } from '../../utils/apiError';
import {
  CRM_LEAD_STATUSES,
  crmLabel,
  type CrmAssignee,
  type CrmLead,
  type CrmLeadStatus,
  type CrmTag,
  type CrmTagRef,
} from '../../types/crm';

/**
 * Phase 6 — CRM Task 8. Small presentational building blocks shared by the
 * CRM pages, in the app's existing Tailwind vocabulary (slate/indigo, the
 * rounded-lg controls of DataTableControls, the ConfirmModal/PromptModal
 * dialogs). No second design system.
 */

export const READ_ONLY_TITLE = 'Action disabled: Subscription expired.';

const STATUS_STYLES: Record<CrmLeadStatus, string> = {
  new: 'bg-sky-50 text-sky-700 ring-sky-200',
  contacted: 'bg-amber-50 text-amber-700 ring-amber-200',
  converted: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
  not_converted: 'bg-slate-100 text-slate-600 ring-slate-200',
};

export function StatusBadge({ status }: { status: CrmLeadStatus }) {
  return (
    <span
      className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${STATUS_STYLES[status] ?? STATUS_STYLES.not_converted}`}
      data-testid="crm-status-badge"
    >
      {crmLabel(status)}
    </span>
  );
}

export function TagChips({
  tags,
  onRemove,
  busyTagId,
  disabled,
}: {
  tags: CrmTagRef[];
  onRemove?: (tag: CrmTagRef) => void;
  busyTagId?: number | null;
  disabled?: boolean;
}) {
  if (tags.length === 0) return <span className="text-xs text-slate-400">—</span>;
  return (
    <div className="flex flex-wrap gap-1">
      {tags.map((tag) => (
        <span
          key={tag.id}
          className="inline-flex items-center gap-1 rounded-md bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700"
          data-testid="crm-tag-chip"
        >
          {tag.name}
          {onRemove && (
            <button
              type="button"
              onClick={(e) => {
                e.stopPropagation();
                onRemove(tag);
              }}
              disabled={disabled || busyTagId === tag.id}
              aria-label={`Remove tag ${tag.name}`}
              className="rounded text-indigo-400 hover:text-indigo-700 disabled:opacity-40"
            >
              {busyTagId === tag.id ? <Loader2 className="h-3 w-3 animate-spin" /> : <X className="h-3 w-3" />}
            </button>
          )}
        </span>
      ))}
    </div>
  );
}

export function NoClientSelected({ what }: { what: string }) {
  return (
    <div className="flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-500" data-testid="crm-no-client">
      <Building2 className="mt-0.5 h-4 w-4 flex-shrink-0" />
      Select a client from the switcher at the top of the page to view their {what}.
    </div>
  );
}

export function ErrorBanner({ message }: { message: string }) {
  return (
    <div role="alert" className="flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
      <AlertCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
      {message}
    </div>
  );
}

export function EmptyState({ title, hint, children }: { title: string; hint?: string; children?: ReactNode }) {
  return (
    <div className="flex flex-col items-center justify-center px-4 py-10 text-center" data-testid="crm-empty">
      <Inbox className="h-6 w-6 text-slate-300" />
      <p className="mt-2 text-sm font-medium text-slate-700">{title}</p>
      {hint && <p className="mt-1 text-xs text-slate-500">{hint}</p>}
      {children}
    </div>
  );
}

export function Toast({ message }: { message: string | null }) {
  if (!message) return null;
  return (
    <div role="status" className="fixed bottom-6 right-6 z-50 rounded-lg bg-slate-900 px-4 py-3 text-sm font-medium text-white shadow-lg">
      {message}
    </div>
  );
}

export function Spinner() {
  return (
    <div className="flex items-center justify-center py-10" data-testid="crm-loading">
      <Loader2 className="h-5 w-5 animate-spin text-slate-400" />
    </div>
  );
}

const selectClass =
  'rounded-lg border border-slate-300 bg-white py-1.5 pl-2 pr-7 text-sm text-slate-700 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 disabled:cursor-not-allowed disabled:opacity-50';

/**
 * Status change through the dedicated PATCH /crm/leads/{id}/status. Offers
 * exactly the four canonical statuses; every transition is decided by the
 * server. Choosing "Not Converted" asks for the optional reason the
 * backend accepts.
 */
export function StatusControl({
  lead,
  disabled,
  onUpdated,
  onError,
}: {
  lead: CrmLead;
  disabled?: boolean;
  onUpdated: (lead: CrmLead, message: string) => void;
  onError: (message: string) => void;
}) {
  const [busy, setBusy] = useState(false);
  const [askReason, setAskReason] = useState(false);

  const submit = (status: CrmLeadStatus, reason?: string) => {
    setBusy(true);
    crmService
      .changeStatus(lead.id, status, reason)
      .then((res) => onUpdated(res.data, res.message))
      .catch((err: unknown) => onError(describeApiError(err, 'Failed to change the status.').message))
      .finally(() => {
        setBusy(false);
        setAskReason(false);
      });
  };

  return (
    <>
      <select
        aria-label="Change status"
        value={lead.status}
        disabled={disabled || busy}
        title={disabled ? READ_ONLY_TITLE : undefined}
        onClick={(e) => e.stopPropagation()}
        onChange={(e) => {
          const next = e.target.value as CrmLeadStatus;
          if (next === lead.status) return;
          if (next === 'not_converted') {
            setAskReason(true);
          } else {
            submit(next);
          }
        }}
        className={selectClass}
      >
        {CRM_LEAD_STATUSES.map((s) => (
          <option key={s} value={s}>
            {crmLabel(s)}
          </option>
        ))}
      </select>
      {askReason && (
        <PromptModal
          title="Mark as Not Converted"
          message="Optionally record why this lead did not convert."
          label="Reason (optional)"
          confirmLabel="Save status"
          isLoading={busy}
          onSubmit={(reason) => submit('not_converted', reason)}
          onCancel={() => setAskReason(false)}
        />
      )}
    </>
  );
}

/**
 * Assign / reassign / unassign through PATCH /crm/leads/{id}/assignee.
 * Options are exactly GET /crm/assignees (the backend's eligibility rule).
 * A current owner who is no longer eligible is still shown — history is
 * not rewritten — but cannot be re-picked.
 */
export function AssigneeControl({
  lead,
  assignees,
  disabled,
  onUpdated,
  onError,
}: {
  lead: CrmLead;
  assignees: CrmAssignee[];
  disabled?: boolean;
  onUpdated: (lead: CrmLead, message: string) => void;
  onError: (message: string) => void;
}) {
  const [busy, setBusy] = useState(false);
  const current = lead.assigned_user;
  const currentIsListed = current ? assignees.some((a) => a.id === current.id) : true;

  return (
    <select
      aria-label="Change assignee"
      value={lead.assigned_user_id === null ? '' : String(lead.assigned_user_id)}
      disabled={disabled || busy}
      title={disabled ? READ_ONLY_TITLE : undefined}
      onClick={(e) => e.stopPropagation()}
      onChange={(e) => {
        const next = e.target.value === '' ? null : Number(e.target.value);
        setBusy(true);
        crmService
          .changeAssignee(lead.id, next)
          .then((res) => onUpdated(res.data, res.message))
          .catch((err: unknown) => onError(describeApiError(err, 'Failed to change the assignee.').message))
          .finally(() => setBusy(false));
      }}
      className={selectClass}
    >
      <option value="">Unassigned</option>
      {current && !currentIsListed && (
        <option value={String(current.id)} disabled>
          {current.name} (not eligible)
        </option>
      )}
      {assignees.map((a) => (
        <option key={a.id} value={String(a.id)}>
          {a.name}
        </option>
      ))}
    </select>
  );
}

/**
 * Server-searched tag chooser (GET /crm/tags?search= — a case-insensitive
 * prefix match). Used both to attach a tag to a lead and to add a tag to a
 * filter. Never builds its own tag list: every option is a row the API
 * returned for this account.
 */
export function TagPicker({
  excludeIds,
  onPick,
  disabled,
  label = 'Add tag',
}: {
  excludeIds: number[];
  onPick: (tag: CrmTagRef) => void;
  disabled?: boolean;
  label?: string;
}) {
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState('');
  const [options, setOptions] = useState<CrmTag[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const wrapper = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    if (!open) return;
    let cancelled = false;
    const timer = setTimeout(() => {
      setLoading(true);
      setError(null);
      crmService
        .listTags(search, 1, 20)
        .then((res) => {
          if (!cancelled) setOptions(res.data);
        })
        .catch((err: unknown) => {
          if (!cancelled) setError(describeApiError(err, 'Failed to load tags.').message);
        })
        .finally(() => {
          if (!cancelled) setLoading(false);
        });
    }, 250);
    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [open, search]);

  useEffect(() => {
    if (!open) return;
    const onDown = (e: MouseEvent) => {
      if (wrapper.current && !wrapper.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', onDown);
    return () => document.removeEventListener('mousedown', onDown);
  }, [open]);

  const visible = options.filter((o) => !excludeIds.includes(o.id));

  return (
    <div className="relative inline-block" ref={wrapper} onClick={(e) => e.stopPropagation()}>
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        disabled={disabled}
        title={disabled ? READ_ONLY_TITLE : undefined}
        className="inline-flex items-center gap-1 rounded-lg border border-dashed border-slate-300 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
      >
        <Plus className="h-3.5 w-3.5" />
        {label}
      </button>
      {open && (
        <div className="absolute left-0 z-40 mt-1 w-64 rounded-lg border border-slate-200 bg-white p-2 shadow-lg" data-testid="crm-tag-picker">
          <input
            autoFocus
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search tags…"
            aria-label="Search tags"
            className="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-indigo-500"
          />
          <div className="mt-2 max-h-56 overflow-y-auto">
            {loading ? (
              <div className="flex justify-center py-3">
                <Loader2 className="h-4 w-4 animate-spin text-slate-400" />
              </div>
            ) : error ? (
              <p className="px-1 py-2 text-xs text-red-600">{error}</p>
            ) : visible.length === 0 ? (
              <p className="px-1 py-2 text-xs text-slate-500">No matching tags. Create tags on the CRM Tags page.</p>
            ) : (
              visible.map((tag) => (
                <button
                  key={tag.id}
                  type="button"
                  onClick={() => {
                    onPick({ id: tag.id, name: tag.name });
                    setOpen(false);
                    setSearch('');
                  }}
                  className="flex w-full items-center justify-between gap-2 rounded-md px-2 py-1.5 text-left text-sm text-slate-700 hover:bg-indigo-50"
                >
                  <span className="flex items-center gap-1.5 truncate">
                    <Tag className="h-3.5 w-3.5 text-slate-400" />
                    {tag.name}
                  </span>
                  <span className="text-xs text-slate-400">{tag.lead_count}</span>
                </button>
              ))
            )}
          </div>
        </div>
      )}
    </div>
  );
}

/**
 * A lead's tags with attach/detach through the dedicated Task 7 endpoints
 * (never through a general lead update). Both calls are idempotent
 * server-side; the lead the server returns replaces the local copy.
 */
export function LeadTagEditor({
  lead,
  disabled,
  onUpdated,
  onError,
}: {
  lead: CrmLead;
  disabled?: boolean;
  onUpdated: (lead: CrmLead, message: string) => void;
  onError: (message: string) => void;
}) {
  const [busyTagId, setBusyTagId] = useState<number | null>(null);

  const run = (tagId: number, call: Promise<{ message: string; data: CrmLead }>, fallback: string) => {
    setBusyTagId(tagId);
    call
      .then((res) => onUpdated(res.data, res.message))
      .catch((err: unknown) => onError(describeApiError(err, fallback).message))
      .finally(() => setBusyTagId(null));
  };

  return (
    <div className="flex flex-wrap items-center gap-2">
      <TagChips
        tags={lead.tags}
        busyTagId={busyTagId}
        disabled={disabled || busyTagId !== null}
        onRemove={(tag) => run(tag.id, crmService.detachTag(lead.id, tag.id), 'Failed to remove the tag.')}
      />
      <TagPicker
        excludeIds={lead.tags.map((t) => t.id)}
        disabled={disabled || busyTagId !== null}
        onPick={(tag) => run(tag.id, crmService.attachTag(lead.id, tag.id), 'Failed to add the tag.')}
      />
    </div>
  );
}
