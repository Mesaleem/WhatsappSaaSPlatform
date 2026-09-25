import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { AxiosError } from 'axios';
import { FileText, Loader2, RefreshCw, XCircle } from 'lucide-react';
import messageLogsService from '../../services/messageLogsService';
import type { TemplateMessageDetail } from '../../types/messageLog';
import type { ApiErrorResponse } from '../../types/auth';
import RouteErrorBoundary from '../common/RouteErrorBoundary';

function extractMessage(err: unknown, fallback: string): string {
  const axiosErr = err as AxiosError<ApiErrorResponse>;
  return axiosErr.response?.data?.message ?? fallback;
}

function formatDateTime(value: string | null): string {
  if (!value) return '—';
  return new Date(value).toLocaleString();
}

const STATUS_BADGE: Record<string, string> = {
  sent: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  failed: 'bg-red-50 text-red-700 ring-red-600/20',
  queued: 'bg-amber-50 text-amber-700 ring-amber-600/20',
};

const PROVIDER_LABEL: Record<string, string> = {
  qr: 'QR (Baileys)',
  meta: 'Meta Cloud API',
};

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="space-y-1.5">
      <h4 className="text-xs font-semibold uppercase tracking-wide text-slate-500">{title}</h4>
      {children}
    </section>
  );
}

function MessageBlock({ text, testId }: { text: string; testId: string }) {
  return (
    <pre
      data-testid={testId}
      className="whitespace-pre-wrap break-words rounded-lg border border-slate-200 bg-slate-50 p-3 font-sans text-sm text-slate-800"
    >
      {text}
    </pre>
  );
}

function Meta({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div>
      <dt className="text-xs text-slate-500">{label}</dt>
      <dd className="mt-0.5 break-words text-sm text-slate-800">{children}</dd>
    </div>
  );
}

function DetailBody({ detail }: { detail: TemplateMessageDetail }) {
  const isGroup = detail.recipient_type === 'group';
  const statusClass = STATUS_BADGE[detail.status] ?? 'bg-slate-50 text-slate-700 ring-slate-600/20';

  return (
    <div className="space-y-5">
      <dl className="grid grid-cols-2 gap-3 sm:grid-cols-3">
        <Meta label="Template">{detail.template.name ?? '—'}</Meta>
        <Meta label="Status">
          <span
            className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize ring-1 ring-inset ${statusClass}`}
          >
            {detail.status}
          </span>
        </Meta>
        <Meta label={isGroup ? 'Group' : 'Recipient'}>
          {isGroup ? `${detail.group_name ?? '—'} (${detail.recipient_count ?? 0})` : detail.recipient}
        </Meta>
        <Meta label="Sent">{formatDateTime(detail.sent_at ?? detail.created_at)}</Meta>
        <Meta label="Engine">{detail.provider ? (PROVIDER_LABEL[detail.provider] ?? detail.provider) : '—'}</Meta>
        {detail.template.code && <Meta label="Template code">{detail.template.code}</Meta>}
        {detail.provider_message_id && <Meta label="Provider message ID">{detail.provider_message_id}</Meta>}
        {detail.account && <Meta label="Client">{detail.account.company_name}</Meta>}
      </dl>

      {detail.error_reason && (
        <div className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
          {detail.error_reason}
        </div>
      )}

      {!detail.snapshot_available && (
        <div
          data-testid="no-snapshot-notice"
          className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800"
        >
          This message was sent before full message history was recorded, so only the stored preview is available. The
          current template is not shown here because it may have been edited since.
        </div>
      )}

      {detail.rendered_content !== null && (
        <Section title="Rendered message (as sent)">
          <MessageBlock text={detail.rendered_content} testId="rendered-message" />
        </Section>
      )}

      {detail.rendered_content === null && isGroup && detail.group_preview !== null && (
        <Section title="Group message preview">
          <MessageBlock text={detail.group_preview} testId="group-preview" />
          <p className="text-xs text-slate-500">
            Per-member values (such as each contact&apos;s name) were filled in for each recipient at send time.
          </p>
        </Section>
      )}

      {detail.rendered_content === null && !isGroup && detail.message_preview && (
        <Section title="Stored preview">
          <MessageBlock text={detail.message_preview} testId="stored-preview" />
          {detail.message_preview_possibly_truncated && (
            <p className="text-xs text-slate-500">This preview may be cut short (stored previews are limited to 160 characters).</p>
          )}
        </Section>
      )}

      {detail.template.body !== null && (
        <Section title="Template (at time of sending)">
          <MessageBlock text={detail.template.body} testId="template-body" />
        </Section>
      )}

      {detail.parameters.length > 0 && (
        <Section title="Parameters">
          <ol data-testid="parameters" className="divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
            {detail.parameters.map((p, i) => (
              <li key={`${p.key}-${i}`} className="flex gap-3 px-3 py-2">
                <span className="w-40 flex-shrink-0 font-mono text-xs text-slate-500">{`{{${p.key}}}`}</span>
                <span className="break-words text-slate-800">
                  {p.value === null ? <span className="italic text-slate-400">not supplied</span> : p.value === '' ? <span className="italic text-slate-400">(empty)</span> : p.value}
                </span>
              </li>
            ))}
          </ol>
        </Section>
      )}

      {(detail.media.has_media || detail.media.url) && (
        <Section title="Header media">
          <div className="flex flex-wrap items-center gap-2 text-sm text-slate-700">
            <FileText className="h-4 w-4 text-slate-400" />
            <span className="capitalize">{detail.media.type ?? detail.template.header_type ?? 'attachment'}</span>
            {detail.media.filename && <span className="text-slate-500">· {detail.media.filename}</span>}
            {detail.media.url && (
              <a
                href={detail.media.url}
                target="_blank"
                rel="noopener noreferrer"
                className="break-all text-indigo-600 hover:underline"
              >
                Open file
              </a>
            )}
          </div>
        </Section>
      )}

      <p className="text-xs text-slate-400">
        Language, category, footer and buttons are not part of this app&apos;s templates, so there is nothing to show for them.
      </p>
    </div>
  );
}

/**
 * Message Log "View Message": full, historical content of one template
 * send. Owns its own loading and error states, and wraps its content in an
 * error boundary, so a failed request or a bad payload never blanks the
 * Message Logs page — the user can always close this and keep working.
 */
export default function TemplateMessageDetailModal({ logId, onClose }: { logId: number; onClose: () => void }) {
  const [detail, setDetail] = useState<TemplateMessageDetail | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      setDetail(await messageLogsService.getTemplateDetail(logId));
    } catch (err) {
      setDetail(null);
      setError(extractMessage(err, 'Could not load this message. Please try again.'));
    } finally {
      setIsLoading(false);
    }
  }, [logId]);

  useEffect(() => {
    void load();
  }, [load]);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [onClose]);

  const errorBox = (message: string, onRetry: () => void) => (
    <div role="alert" className="rounded-lg border border-red-200 bg-red-50 px-3 py-3 text-sm text-red-700">
      <p>{message}</p>
      <button
        type="button"
        onClick={onRetry}
        className="mt-2 inline-flex items-center gap-1.5 rounded-lg border border-red-300 bg-white px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-100"
      >
        <RefreshCw className="h-3.5 w-3.5" />
        Try again
      </button>
    </div>
  );

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" onClick={onClose}>
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="template-message-detail-title"
        className="flex max-h-[90vh] w-full max-w-2xl flex-col rounded-xl bg-white shadow-xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-center justify-between border-b border-slate-200 px-6 py-4">
          <h3 id="template-message-detail-title" className="text-base font-semibold text-slate-900">
            Template Message
          </h3>
          <button onClick={onClose} aria-label="Close message details" className="text-slate-400 hover:text-slate-600">
            <XCircle className="h-5 w-5" />
          </button>
        </div>

        <div className="overflow-y-auto px-6 py-5">
          {isLoading ? (
            <div data-testid="detail-loading" className="flex items-center gap-2 py-8 text-sm text-slate-500">
              <Loader2 className="h-4 w-4 animate-spin" />
              Loading message…
            </div>
          ) : error ? (
            errorBox(error, () => void load())
          ) : detail ? (
            <RouteErrorBoundary
              renderFallback={(err, reset) =>
                errorBox(`This message could not be displayed: ${err.message}`, () => {
                  reset();
                  void load();
                })
              }
            >
              <DetailBody detail={detail} />
            </RouteErrorBoundary>
          ) : null}
        </div>

        <div className="flex justify-end border-t border-slate-200 px-6 py-3">
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
