import { useEffect, useState } from 'react';
import { AlertCircle, FileText, Loader2, XCircle } from 'lucide-react';
import templateService from '../../services/templateService';
import type { MessageTemplateStatus, MyTemplateSummary } from '../../types/templates';
import { extractErrorMessage } from '../../utils/apiError';

/**
 * "My Templates" — a plain Client Admin/User's own view of every
 * template they've requested for their account, any status. Same
 * status label/badge convention as TemplateManagerPage.tsx (kept as its
 * own local copy rather than a shared export, matching this codebase's
 * existing per-page convention for small presentational helpers — see
 * UsersPage.tsx's generatePassword() docblock for the same precedent).
 * Read-only: this audience can request a new one (RequestTemplateModal)
 * but never edit/approve/reject here — that stays in Template Manager.
 */
const STATUS_LABEL: Record<MessageTemplateStatus, string> = {
  pending_agent_review: 'Pending Agent Review',
  pending_admin_review: 'Pending Admin Review',
  pending_meta_approval: 'Pending Meta Approval',
  pending: 'Pending Final Review',
  approved: 'Approved',
  rejected: 'Rejected',
};

const STATUS_BADGE: Record<MessageTemplateStatus, string> = {
  pending_agent_review: 'bg-sky-50 text-sky-700 ring-sky-600/20',
  pending_admin_review: 'bg-violet-50 text-violet-700 ring-violet-600/20',
  pending_meta_approval: 'bg-fuchsia-50 text-fuchsia-700 ring-fuchsia-600/20',
  pending: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  approved: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  rejected: 'bg-red-50 text-red-700 ring-red-600/20',
};

function formatDate(value: string): string {
  return new Date(value).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

export default function MyTemplatesModal({ onClose }: { onClose: () => void }) {
  const [templates, setTemplates] = useState<MyTemplateSummary[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    templateService
      .mine()
      .then((list) => {
        if (!cancelled) setTemplates(list);
      })
      .catch((err: unknown) => {
        if (!cancelled) setError(extractErrorMessage(err, 'Failed to load your templates.'));
      });
    return () => {
      cancelled = true;
    };
  }, []);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div className="flex max-h-[85vh] w-full max-w-2xl flex-col rounded-xl bg-white p-6 shadow-xl">
        <div className="flex items-center justify-between">
          <h3 className="flex items-center gap-2 text-base font-semibold text-slate-900">
            <FileText className="h-4 w-4 text-indigo-600" />
            My Templates
          </h3>
          <button onClick={onClose} className="text-slate-400 hover:text-slate-600">
            <XCircle className="h-5 w-5" />
          </button>
        </div>
        <p className="mt-1 text-sm text-slate-500">Every template you've requested for this account, and its current status.</p>

        <div className="mt-4 flex-1 overflow-y-auto">
          {templates === null && !error && (
            <div className="flex items-center gap-2 py-8 text-sm text-slate-500">
              <Loader2 className="h-4 w-4 animate-spin" />
              Loading…
            </div>
          )}

          {error && (
            <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
              <AlertCircle className="h-4 w-4 flex-shrink-0" />
              {error}
            </div>
          )}

          {templates !== null && templates.length === 0 && (
            <div className="flex flex-col items-center gap-2 rounded-lg border border-dashed border-slate-300 py-8 text-center text-sm text-slate-500">
              <FileText className="h-6 w-6 text-slate-300" />
              You haven't requested a template yet.
            </div>
          )}

          {templates !== null && templates.length > 0 && (
            <ul className="space-y-2">
              {templates.map((t) => (
                <li key={t.id} className="rounded-lg border border-slate-200 p-3">
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <p className="text-sm font-medium text-slate-900">{t.title}</p>
                      {t.industry_type && <p className="mt-0.5 text-xs text-slate-500">{t.industry_type}</p>}
                    </div>
                    <span
                      className={`flex-shrink-0 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${STATUS_BADGE[t.status]}`}
                    >
                      {STATUS_LABEL[t.status]}
                    </span>
                  </div>
                  {t.status === 'rejected' && t.rejection_reason && (
                    <p className="mt-2 text-xs text-red-700">Reason: {t.rejection_reason}</p>
                  )}
                  <p className="mt-2 text-xs text-slate-400">Requested {formatDate(t.created_at)}</p>
                </li>
              ))}
            </ul>
          )}
        </div>

        <div className="mt-4 flex justify-end">
          <button
            onClick={onClose}
            className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
          >
            Close
          </button>
        </div>
      </div>
    </div>
  );
}
