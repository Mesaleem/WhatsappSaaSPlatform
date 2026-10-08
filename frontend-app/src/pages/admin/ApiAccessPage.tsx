import { useCallback, useEffect, useState } from 'react';
import { Loader2 } from 'lucide-react';
import apiAccessAdminService from '../../services/apiAccessAdminService';
import { describeApiError } from '../../utils/apiError';
import type { AdminApiAccessRow, AdminChangeRequestRow, ApiSecurityEvent } from '../../types/developer';

const fmt = (v: string | null | undefined) => (v ? new Date(v).toLocaleString() : '—');

/** Super Admin — decide server-change requests, revoke/re-bind servers, disable API access. Never shows a key. */
export default function ApiAccessPage() {
  const [rows, setRows] = useState<AdminApiAccessRow[]>([]);
  const [requests, setRequests] = useState<AdminChangeRequestRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [events, setEvents] = useState<{ keyId: number; items: ApiSecurityEvent[] } | null>(null);

  const load = useCallback(async () => {
    try {
      const [r, q] = await Promise.all([apiAccessAdminService.list(), apiAccessAdminService.changeRequests()]);
      setRows(r);
      setRequests(q.filter((x) => x.status === 'pending'));
      setError(null);
    } catch (err) {
      setError(describeApiError(err, 'Could not load API access.').message);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const act = async (id: string, fn: () => Promise<{ message?: string }>) => {
    setBusy(id);
    setError(null);
    try {
      const res = await fn();
      setNotice(res.message ?? 'Done.');
      await load();
    } catch (err) {
      setError(describeApiError(err, 'The action failed.').message);
    } finally {
      setBusy(null);
    }
  };

  const showEvents = async (keyId: number) => {
    try {
      setEvents({ keyId, items: await apiAccessAdminService.events(keyId) });
    } catch (err) {
      setError(describeApiError(err, 'Could not load events.').message);
    }
  };

  if (loading) return <div className="flex justify-center p-12"><Loader2 className="h-6 w-6 animate-spin text-slate-400" /></div>;

  const btn = 'rounded-md border border-slate-300 px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50';

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-semibold text-slate-900">API Access</h1>
        <p className="text-sm text-slate-500">Authorized-server bindings for Public API keys. Keys and credentials are never shown here.</p>
      </div>
      {error && <p role="alert" className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{error}</p>}
      {notice && <p role="status" className="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{notice}</p>}

      <section aria-label="Pending server change requests" className="space-y-2">
        <h2 className="text-sm font-semibold text-slate-800">Pending server change requests ({requests.length})</h2>
        {requests.length === 0 && <p className="text-sm text-slate-500">No pending requests.</p>}
        {requests.map((r) => (
          <div key={r.id} className="rounded-lg border border-slate-200 bg-white p-3 text-sm">
            <p className="font-medium text-slate-900">{r.account_name ?? `Account ${r.account_id}`} — {r.key_name} <span className="font-mono text-xs text-slate-500">{r.key_prefix}</span></p>
            <p className="text-xs text-slate-600">Current: {r.current_label ?? '—'} ({r.current_ip ?? '—'}) → Requested: {r.requested_label ?? '—'} ({r.requested_ips.join(', ')}, {r.requested_ip_policy})</p>
            <p className="text-xs text-slate-600">Reason: {r.reason}</p>
            <div className="mt-2 flex gap-2">
              <button className={btn} disabled={busy !== null} onClick={() => void act(`a${r.id}`, () => apiAccessAdminService.approve(r.id))}>Approve</button>
              <button className={btn} disabled={busy !== null} onClick={() => void act(`r${r.id}`, () => apiAccessAdminService.reject(r.id))}>Reject</button>
            </div>
          </div>
        ))}
      </section>

      <section aria-label="API keys" className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
        <table className="min-w-full text-left text-sm">
          <thead className="bg-slate-50 text-xs uppercase text-slate-500">
            <tr>
              <th className="p-2">Account</th><th className="p-2">Key</th><th className="p-2">Status</th><th className="p-2">Server</th>
              <th className="p-2">Registered IP</th><th className="p-2">Last IP</th><th className="p-2">Last used</th>
              <th className="p-2">Credential</th><th className="p-2">Cooldown</th><th className="p-2">Legacy</th><th className="p-2">Installations</th>
              <th className="p-2">Actions</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((k) => {
              const b = k.server_binding.binding;
              const usage = k.installation_usage;
              return (
                <tr key={k.id} className="border-t border-slate-100">
                  <td className="p-2">{k.account_name ?? k.account_id}</td>
                  <td className="p-2">{k.name} <span className="font-mono text-xs text-slate-500">{k.key_prefix}</span></td>
                  <td className="p-2">{k.server_binding.status}</td>
                  <td className="p-2">{b?.label ?? '—'}</td>
                  <td className="p-2 font-mono text-xs">{b?.registered_ip ?? '—'}</td>
                  <td className="p-2 font-mono text-xs">{b?.last_success_ip ?? '—'}</td>
                  <td className="p-2">{fmt(b?.last_success_at ?? k.last_used_at)}</td>
                  <td className="p-2">{b === null ? '—' : k.server_binding.credential_pending ? 'Pending' : 'Configured'}</td>
                  <td className="p-2">{k.server_binding.in_cooldown ? `Until ${fmt(k.server_binding.cooldown_until)}` : '—'}</td>
                  <td className="p-2">
                    {k.server_binding.legacy_ip_dependent
                      ? <span className="text-amber-700">IP-dependent{k.server_binding.legacy_deadline ? ` (until ${fmt(k.server_binding.legacy_deadline)})` : ' (no deadline)'}</span>
                      : '—'}
                  </td>
                  <td className="p-2">
                    {usage ? <span className={usage.at_or_over_allowance ? 'font-medium text-amber-700' : ''}>{usage.live} / {usage.allowance}</span> : '—'}
                  </td>
                  <td className="flex flex-wrap gap-1 p-2">
                    {b && b.status !== 'revoked' && <button className={btn} disabled={busy !== null} onClick={() => void act(`v${k.id}`, () => apiAccessAdminService.revoke(k.id))}>Revoke server</button>}
                    {k.server_binding.access_disabled
                      ? <button className={btn} disabled={busy !== null} onClick={() => void act(`e${k.id}`, () => apiAccessAdminService.enable(k.id))}>Enable access</button>
                      : <button className={btn} disabled={busy !== null} onClick={() => void act(`d${k.id}`, () => apiAccessAdminService.disable(k.id))}>Disable access</button>}
                    {k.server_binding.in_cooldown && (
                      <button
                        className={btn}
                        disabled={busy !== null}
                        onClick={() => {
                          const reason = window.prompt('Reason for overriding this cooldown (required):');
                          if (reason && reason.trim()) void act(`c${k.id}`, () => apiAccessAdminService.overrideCooldown(k.id, reason.trim()));
                        }}
                      >
                        Override cooldown
                      </button>
                    )}
                    <button className={btn} onClick={() => void showEvents(k.id)}>Events</button>
                    {k.revoked_at === null && (
                      <button
                        className={btn}
                        disabled={busy !== null}
                        onClick={() => {
                          if (window.confirm(`Destroy API key "${k.name}"? This cannot be undone.`)) void act(`x${k.id}`, () => apiAccessAdminService.destroy(k.id));
                        }}
                      >
                        Destroy key
                      </button>
                    )}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </section>

      {events && (
        <section aria-label="Security events" className="rounded-lg border border-slate-200 bg-white p-3">
          <div className="flex justify-between"><h2 className="text-sm font-semibold">Security events</h2><button className={btn} onClick={() => setEvents(null)}>Close</button></div>
          <ul className="mt-2 space-y-1 text-xs text-slate-700">
            {events.items.length === 0 && <li>No events.</li>}
            {events.items.map((e) => <li key={e.id}><span className="font-mono">{fmt(e.created_at)}</span> — {e.event} {e.ip ? `(${e.ip})` : ''}</li>)}
          </ul>
        </section>
      )}
    </div>
  );
}
