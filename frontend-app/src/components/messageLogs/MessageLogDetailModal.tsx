import { useEffect, useState } from 'react';
import { AxiosError } from 'axios';
import { ExternalLink, Loader2, X } from 'lucide-react';
import messageLogsService from '../../services/messageLogsService';
import type { MessageDispatchLogDetail } from '../../types/messageLog';
import type { ApiErrorResponse } from '../../types/auth';

const SOURCE_LABEL: Record<string, string> = {
  web_ui: 'Web (Send Notification)',
  web_template: 'Web (Template)',
  api: 'API',
  chatbot: 'Chatbot',
  journey: 'Journey Builder',
  social_inbox: 'Social Inbox',
  meta_lead_ads: 'Meta Lead Ads',
};

const ENGINE_LABEL: Record<string, string> = { qr: 'QR (Baileys)', meta: 'Meta Cloud API' };

function when(v: string | null): string {
  return v ? new Date(v).toLocaleString() : '—';
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="min-w-0">
      <dt className="text-xs text-slate-500">{label}</dt>
      <dd className="mt-0.5 break-words text-sm text-slate-900">{children}</dd>
    </div>
  );
}

/** Message Logs "View" — shows the complete record of one send, including the full text exactly as it was sent. */
export default function MessageLogDetailModal({ logId, onClose }: { logId: number; onClose: () => void }) {
  const [log, setLog] = useState<MessageDispatchLogDetail | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    setLog(null);
    setError(null);
    messageLogsService
      .show(logId)
      .then((d) => {
        if (!cancelled) setLog(d);
      })
      .catch((err) => {
        if (cancelled) return;
        const e = err as AxiosError<ApiErrorResponse>;
        setError(e.response?.status === 404 ? 'This message could not be found.' : (e.response?.data?.message ?? 'Failed to load the message.'));
      });
    return () => {
      cancelled = true;
    };
  }, [logId]);

  const isGroup = log?.recipient_type === 'group';
  const engine = log?.engine_type ? (ENGINE_LABEL[log.engine_type] ?? log.engine_type) : null;

  return (
    <div role="dialog" aria-modal="true" aria-label="Message Details" className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div className="flex max-h-[90vh] w-full max-w-2xl flex-col rounded-xl bg-white shadow-xl">
        <div className="flex items-center justify-between border-b border-slate-200 px-6 py-4">
          <h3 className="text-base font-semibold text-slate-900">Message Details</h3>
          <button type="button" onClick={onClose} aria-label="Close dialog" className="text-slate-400 hover:text-slate-600">
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className="space-y-5 overflow-y-auto px-6 py-4">
          {!log && !error && (
            <div className="flex justify-center py-10" role="status" aria-label="Loading message">
              <Loader2 className="h-5 w-5 animate-spin text-slate-400" />
            </div>
          )}
          {error && <p role="alert" className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{error}</p>}

          {log && (
            <>
              <dl className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                {log.account && <Field label="Client">{log.account.company_name}</Field>}
                <Field label={isGroup ? 'Group' : 'Recipient'}>{isGroup ? `${log.group_name ?? '—'} (${log.recipient_count})` : log.recipient_phone}</Field>
                <Field label="Source">{SOURCE_LABEL[log.source] ?? log.source}</Field>
                <Field label="Status">
                  <span className="capitalize">{log.status}</span>
                </Field>
                <Field label="Timestamp">{when(log.sent_at ?? log.created_at)}</Field>
                {engine && <Field label="Engine">{engine}</Field>}
                <Field label="Message type">{log.reference_type ?? 'text'}</Field>
                {log.template_name && <Field label="Template">{log.template_name}</Field>}
                {log.template_code && <Field label="Template code"><span className="font-mono text-xs">{log.template_code}</span></Field>}
                {log.gateway_message_id && <Field label="Provider message ID"><span className="font-mono text-xs">{log.gateway_message_id}</span></Field>}
                <Field label="Log ID">{log.id}</Field>
                {log.api_key && <Field label="API key">{log.api_key.name} <span className="font-mono text-xs text-slate-500">({log.api_key.key_prefix})</span></Field>}
              </dl>

              {log.status === 'failed' && (
                <div role="alert" className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                  <span className="font-medium">Failure reason: </span>
                  {log.error_reason ?? 'No reason was recorded.'}
                </div>
              )}

              <div>
                <h4 className="text-xs font-semibold uppercase tracking-wide text-slate-500">Message (as sent)</h4>
                {log.message_body ? (
                  <pre data-testid="message-body" className="mt-2 max-h-72 overflow-y-auto whitespace-pre-wrap break-words rounded-lg border border-slate-200 bg-slate-50 p-3 font-sans text-sm text-slate-800">
                    {log.message_body}
                  </pre>
                ) : (
                  <p className="mt-2 text-sm text-slate-500">No message text was recorded for this send.</p>
                )}
                {log.message_body && !log.message_body_is_complete && (
                  <p className="mt-1 text-xs text-amber-700">Showing a shortened preview — the full text was not stored for messages sent before this view existed.</p>
                )}
              </div>

              <div>
                <h4 className="text-xs font-semibold uppercase tracking-wide text-slate-500">Media attachment</h4>
                {log.media.has_media ? (
                  <div className="mt-2 space-y-2 text-sm text-slate-800">
                    <p>
                      Yes{log.media.name ? ` — ${log.media.name}` : ''}
                      {log.media.type ? ` (${log.media.type})` : ''}
                    </p>
                    {log.media.url && log.media.type === 'image' && (
                      <img src={log.media.url} alt={log.media.name ?? 'Attachment preview'} className="max-h-48 rounded-lg border border-slate-200" />
                    )}
                    {log.media.url && (
                      <a href={log.media.url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-xs font-medium text-indigo-600 hover:underline">
                        <ExternalLink className="h-3 w-3" /> Open attachment
                      </a>
                    )}
                  </div>
                ) : (
                  <p className="mt-2 text-sm text-slate-500">No</p>
                )}
              </div>
            </>
          )}
        </div>

        <div className="flex justify-end border-t border-slate-200 px-6 py-3">
          <button type="button" onClick={onClose} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
            Close
          </button>
        </div>
      </div>
    </div>
  );
}
