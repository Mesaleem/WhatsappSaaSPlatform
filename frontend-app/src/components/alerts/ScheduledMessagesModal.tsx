import { useEffect, useState } from 'react';
import { AlertCircle, CalendarClock, Loader2, Users, XCircle } from 'lucide-react';
import scheduledMessageService from '../../services/scheduledMessageService';
import type { ScheduledMessageRow } from '../../types/scheduledMessage';
import { extractErrorMessage } from '../../utils/apiError';
import DismissibleAlert from '../common/DismissibleAlert';
import ConfirmModal from '../common/ConfirmModal';

/**
 * "Scheduled Messages" -- every message this account has scheduled for later from the Send
 * Notification page (template or "No template", individual or group), plus the ones scheduled through
 * the Developer API (source: 'api') so this is the one place to see all of them. Previously the only
 * way to see or cancel a scheduled send was the Developer API's own DELETE endpoint; a user who
 * scheduled something from the dashboard had no page to check it, or to change their mind.
 *
 * Paginated server-side (ScheduledMessageController::index(), 20/page), same top-corner-link-opens-a-
 * modal pattern as BatchSendPanel's own History link, rather than an always-expanded list.
 */
const STATUS_LABEL: Record<ScheduledMessageRow['status'], string> = {
  pending: 'Scheduled',
  sent: 'Sent',
  failed: 'Failed',
  cancelled: 'Cancelled',
};

const STATUS_BADGE: Record<ScheduledMessageRow['status'], string> = {
  pending: 'bg-indigo-50 text-indigo-700 ring-indigo-600/20',
  sent: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  failed: 'bg-red-50 text-red-700 ring-red-600/20',
  cancelled: 'bg-slate-100 text-slate-500 ring-slate-500/20',
};

function formatDateTime(value: string): string {
  return new Date(value).toLocaleString(undefined, {
    year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit',
  });
}

export default function ScheduledMessagesModal({ onClose }: { onClose: () => void }) {
  const [rows, setRows] = useState<ScheduledMessageRow[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [cancelTarget, setCancelTarget] = useState<ScheduledMessageRow | null>(null);
  const [isCancelling, setIsCancelling] = useState(false);
  const [cancelError, setCancelError] = useState<string | null>(null);

  const load = (targetPage: number) => {
    setRows(null);
    setError(null);
    scheduledMessageService
      .list(targetPage)
      .then((res) => {
        setRows(res.data);
        setPage(res.meta.current_page);
        setLastPage(res.meta.last_page);
      })
      .catch((err: unknown) => setError(extractErrorMessage(err, 'Failed to load scheduled messages.')));
  };

  useEffect(() => {
    load(1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const handleCancel = () => {
    if (!cancelTarget) return;
    setIsCancelling(true);
    setCancelError(null);
    scheduledMessageService
      .cancel(cancelTarget.id)
      .then(() => {
        setCancelTarget(null);
        load(page);
      })
      .catch((err: unknown) => setCancelError(extractErrorMessage(err, 'Could not cancel this message.')))
      .finally(() => setIsCancelling(false));
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div className="flex max-h-[85vh] w-full max-w-3xl flex-col rounded-xl bg-white p-6 shadow-xl">
        <div className="flex items-center justify-between">
          <h3 className="flex items-center gap-2 text-base font-semibold text-slate-900">
            <CalendarClock className="h-4 w-4 text-indigo-600" />
            Scheduled Messages
          </h3>
          <button onClick={onClose} className="text-slate-400 hover:text-slate-600">
            <XCircle className="h-5 w-5" />
          </button>
        </div>
        <p className="mt-1 text-sm text-slate-500">
          Every message scheduled for later from this page or the Developer API -- cancel one any time before it sends.
        </p>

        <div className="mt-4 flex-1 overflow-y-auto">
          {rows === null && !error && (
            <div className="flex items-center gap-2 py-8 text-sm text-slate-500">
              <Loader2 className="h-4 w-4 animate-spin" />
              Loading…
            </div>
          )}

          {error && (
            <DismissibleAlert className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
              <AlertCircle className="h-4 w-4 flex-shrink-0" />
              {error}
            </DismissibleAlert>
          )}

          {rows !== null && rows.length === 0 && (
            <div className="flex flex-col items-center gap-2 rounded-lg border border-dashed border-slate-300 py-8 text-center text-sm text-slate-500">
              <CalendarClock className="h-6 w-6 text-slate-300" />
              Nothing scheduled right now.
            </div>
          )}

          {rows !== null && rows.length > 0 && (
            <ul className="space-y-2">
              {rows.map((row) => (
                <li key={row.id} className="rounded-lg border border-slate-200 p-3">
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <p className="flex items-center gap-1.5 text-sm font-medium text-slate-900">
                        {row.recipient_type === 'group' && <Users className="h-3.5 w-3.5 flex-shrink-0 text-slate-400" />}
                        {row.recipient}
                      </p>
                      <p className="mt-0.5 truncate text-xs text-slate-500">
                        {row.is_template ? `Template: ${row.preview}` : row.preview || '(no text)'}
                      </p>
                      <p className="mt-1 text-xs text-slate-400">
                        {row.status === 'pending' ? 'Sends' : row.status === 'sent' ? 'Sent' : 'Was due'} {formatDateTime(row.send_at)}
                        {row.sender_number && <> · from {row.sender_number}</>}
                        {row.source === 'api' && <> · via Developer API</>}
                      </p>
                      {row.status === 'failed' && row.last_error && (
                        <p className="mt-1 text-xs text-red-700">Reason: {row.last_error}</p>
                      )}
                    </div>
                    <div className="flex flex-shrink-0 items-center gap-2">
                      <span className={`whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${STATUS_BADGE[row.status]}`}>
                        {STATUS_LABEL[row.status]}
                      </span>
                      {row.can_cancel && (
                        <button
                          type="button"
                          onClick={() => setCancelTarget(row)}
                          className="whitespace-nowrap text-xs font-medium text-red-600 hover:text-red-700"
                        >
                          Cancel
                        </button>
                      )}
                    </div>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </div>

        {rows !== null && lastPage > 1 && (
          <div className="mt-3 flex items-center justify-between border-t border-slate-100 pt-3">
            <button
              type="button"
              disabled={page <= 1}
              onClick={() => load(page - 1)}
              className="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-50 disabled:opacity-40"
            >
              Previous
            </button>
            <span className="text-xs text-slate-500">Page {page} of {lastPage}</span>
            <button
              type="button"
              disabled={page >= lastPage}
              onClick={() => load(page + 1)}
              className="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-50 disabled:opacity-40"
            >
              Next
            </button>
          </div>
        )}

        <div className="mt-4 flex justify-end">
          <button onClick={onClose} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
            Close
          </button>
        </div>
      </div>

      {cancelTarget && (
        <ConfirmModal
          title="Cancel this scheduled message?"
          message={
            <>
              This message to <strong>{cancelTarget.recipient}</strong>, due {formatDateTime(cancelTarget.send_at)}, will not be sent.
              {cancelError && <p className="mt-2 text-red-700">{cancelError}</p>}
            </>
          }
          confirmLabel="Cancel Message"
          isLoading={isCancelling}
          onConfirm={handleCancel}
          onCancel={() => {
            setCancelTarget(null);
            setCancelError(null);
          }}
        />
      )}
    </div>
  );
}
