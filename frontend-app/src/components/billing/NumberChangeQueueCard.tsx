import { useCallback, useEffect, useState } from 'react';
import whatsappService, { type NumberChangeRequest } from '../../services/whatsappService';
import ConfirmModal from '../common/ConfirmModal';
import { extractErrorMessage } from '../../utils/apiError';

/**
 * Super Admin and agent queue for WhatsApp numbers entered wrongly. Approving changes the slot's
 * number and disconnects its session; rejecting leaves the number alone. The server decides which
 * clients each person may act on.
 */
export default function NumberChangeQueueCard({ onChanged }: { onChanged?: () => void } = {}) {
  const [rows, setRows] = useState<NumberChangeRequest[] | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [approving, setApproving] = useState<NumberChangeRequest | null>(null);
  const [rejecting, setRejecting] = useState<NumberChangeRequest | null>(null);

  const load = useCallback(async () => {
    try {
      setRows(await whatsappService.pendingNumberChanges());
    } catch (err) {
      setError(extractErrorMessage(err, 'Could not load the number change requests.'));
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const act = async (row: NumberChangeRequest, action: () => Promise<unknown>, fallback: string) => {
    setBusyId(row.id);
    setError(null);
    try {
      await action();
      await load();
      onChanged?.();
    } catch (err) {
      setError(extractErrorMessage(err, fallback));
    } finally {
      setBusyId(null);
    }
  };

  if (rows === null || rows.length === 0) return null;

  return (
    <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
      <p className="text-sm font-semibold text-slate-900">WhatsApp number change requests</p>
      <p className="mt-0.5 text-xs text-slate-500">
        A client entered a wrong number and asks for the correct one. Check the new number before you approve.
      </p>

      {error && <p className="mt-3 text-sm text-red-600">{error}</p>}

      <ul className="mt-4 divide-y divide-slate-200">
        {rows.map((row) => (
          <li key={row.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
            <div className="min-w-0 text-sm">
              <p className="font-medium text-slate-900">{row.account?.name ?? `Account ${row.account?.id ?? ''}`}</p>
              <p className="text-xs text-slate-600">
                +{row.old_phone} → <strong>+{row.new_phone}</strong>
              </p>
              <p className="text-xs text-slate-500">
                &quot;{row.reason}&quot;{row.created_at ? ` · ${new Date(row.created_at).toLocaleDateString('en-IN')}` : ''}
              </p>
            </div>
            <div className="flex items-center gap-2">
              <button
                type="button"
                disabled={busyId === row.id}
                onClick={() => setApproving(row)}
                className="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700 disabled:opacity-60"
              >
                Approve
              </button>
              <button
                type="button"
                disabled={busyId === row.id}
                onClick={() => setRejecting(row)}
                className="rounded-lg border border-red-200 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 disabled:opacity-60"
              >
                Reject
              </button>
            </div>
          </li>
        ))}
      </ul>

      {approving && (
        <ConfirmModal
          title="Change this WhatsApp number?"
          variant="default"
          message={
            <>
              The slot will hold <strong>+{approving.new_phone}</strong> instead of +{approving.old_phone}. Its current connection is
              disconnected, and the new number must be connected again.
            </>
          }
          confirmLabel="Approve change"
          isLoading={busyId === approving.id}
          onCancel={() => setApproving(null)}
          onConfirm={() => {
            const row = approving;
            void act(row, () => whatsappService.approveNumberChange(row.id), 'Could not approve this change.').then(() => setApproving(null));
          }}
        />
      )}

      {rejecting && (
        <ConfirmModal
          title="Reject this change?"
          message={
            <>
              The slot keeps +{rejecting.old_phone}. The client is told that +{rejecting.new_phone} was not approved.
            </>
          }
          confirmLabel="Reject request"
          isLoading={busyId === rejecting.id}
          onCancel={() => setRejecting(null)}
          onConfirm={() => {
            const row = rejecting;
            void act(row, () => whatsappService.rejectNumberChange(row.id), 'Could not reject this change.').then(() => setRejecting(null));
          }}
        />
      )}
    </div>
  );
}
