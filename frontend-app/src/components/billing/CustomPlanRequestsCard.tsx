import { useEffect, useState } from 'react';
import { Headset } from 'lucide-react';
import billingService from '../../services/billingService';
import { describeApiError } from '../../utils/apiError';
import type { CustomPlanRequest } from '../../types/billing';

/**
 * Super Admin — custom plan requests from "Contact sales": new ones first, with the
 * requirement and the contact details; mark them contacted or closed.
 */
export default function CustomPlanRequestsCard() {
  const [rows, setRows] = useState<CustomPlanRequest[] | null>(null);
  const [newCount, setNewCount] = useState(0);
  const [error, setError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);

  const load = () =>
    billingService
      .customPlanRequests()
      .then((res) => {
        setRows(res.data);
        setNewCount(res.new_count);
      })
      .catch((e: unknown) => setError(describeApiError(e, 'Failed to load custom plan requests.').message));

  useEffect(() => {
    void load();
  }, []);

  const mark = (r: CustomPlanRequest, status: 'contacted' | 'closed') => {
    setBusyId(r.id);
    billingService
      .updateCustomPlanRequest(r.id, { status })
      .then(() => load())
      .catch((e: unknown) => setError(describeApiError(e, 'Could not update the request.').message))
      .finally(() => setBusyId(null));
  };

  if (rows === null && !error) return null;

  return (
    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" data-testid="custom-plan-requests">
      <div className="flex items-center justify-between">
        <h3 className="flex items-center gap-2 text-sm font-semibold text-slate-900">
          <Headset className="h-4 w-4 text-emerald-700" />
          Custom plan requests
        </h3>
        {newCount > 0 && <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">{newCount} new</span>}
      </div>
      {error && <p className="mt-2 text-sm text-red-600">{error}</p>}
      {rows && rows.length === 0 ? (
        <p className="mt-2 text-sm text-slate-500">No requests yet.</p>
      ) : (
        <ul className="mt-3 divide-y divide-slate-100">
          {(rows ?? []).slice(0, 10).map((r) => (
            <li key={r.id} className="py-3 text-sm">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                  <p className="font-medium text-slate-900">
                    {r.company || r.name}
                    {r.status === 'new' && <span className="ml-2 rounded bg-amber-50 px-1.5 py-0.5 text-xs font-semibold text-amber-700">New</span>}
                    {r.status !== 'new' && <span className="ml-2 text-xs text-slate-500">{r.status}</span>}
                  </p>
                  <p className="text-xs text-slate-500">
                    {r.name} · {r.email}
                    {r.phone ? ` · ${r.phone}` : ''}
                    {r.account ? ` · ${r.account.company_name}` : ''} · {new Date(r.created_at).toLocaleDateString()}
                  </p>
                  <p className="mt-1 whitespace-pre-line text-slate-700">{r.requirement}</p>
                  <p className="mt-1 text-xs text-slate-500">
                    {[r.expected_messages ? `${r.expected_messages.toLocaleString()} messages/month` : null, r.whatsapp_numbers ? `${r.whatsapp_numbers} numbers` : null, r.team_users ? `${r.team_users} users` : null]
                      .filter(Boolean)
                      .join(' · ')}
                  </p>
                </div>
                {r.status !== 'closed' && (
                  <div className="flex gap-2">
                    {r.status === 'new' && (
                      <button type="button" disabled={busyId === r.id} onClick={() => mark(r, 'contacted')} className="rounded-lg bg-emerald-600 px-2.5 py-1 text-xs font-semibold text-white disabled:opacity-50">
                        Mark contacted
                      </button>
                    )}
                    <button type="button" disabled={busyId === r.id} onClick={() => mark(r, 'closed')} className="rounded-lg border border-slate-300 px-2.5 py-1 text-xs text-slate-700 disabled:opacity-50">
                      Close
                    </button>
                  </div>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
