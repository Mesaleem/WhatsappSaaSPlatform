import { useCallback, useEffect, useRef, useState } from 'react';
import { CalendarClock, Pause, Play, Square, Upload, Loader2, X } from 'lucide-react';
import messageBatchService from '../../services/messageBatchService';
import type { MessageBatch, MessageBatchStatus } from '../../types/messageBatch';
import type { SenderNumber } from '../../types/senderNumber';
import templateService from '../../services/templateService';
import ScheduleField, { EMPTY_SCHEDULE, scheduleError, scheduleToIso, type ScheduleValue } from '../common/ScheduleField';
import ConfirmModal from '../common/ConfirmModal';
import DismissibleAlert from '../common/DismissibleAlert';
import { extractErrorMessage } from '../../utils/apiError';

/** The template the page is sending, reduced to what a batch needs. */
interface BatchTemplate {
  id: number;
  title: string;
  template_body: string;
  variables_schema: ReadonlyArray<{ key: string; label?: string; required: boolean }>;
}

interface BatchSendPanelProps {
  /** The linked, active numbers the batch can send from (default first). */
  senders: SenderNumber[];
  disabled?: boolean;
}

const BATCH_SIZES = [10, 25, 50, 100];
const POLL_MS = 15_000;
const HISTORY_PAGE_SIZE = 10;
/** A batch still shown in the "Running now" list; a stopped or completed one moves to History only. */
const ACTIVE: MessageBatchStatus[] = ['scheduled', 'running', 'paused'];

const STATUS_LABEL: Record<MessageBatchStatus, string> = {
  scheduled: 'Scheduled',
  running: 'Sending',
  paused: 'Paused',
  stopped: 'Stopped',
  completed: 'Completed',
};

const STATUS_CLASS: Record<MessageBatchStatus, string> = {
  scheduled: 'bg-sky-50 text-sky-700 ring-sky-200',
  running: 'bg-indigo-50 text-indigo-700 ring-indigo-200',
  paused: 'bg-amber-50 text-amber-800 ring-amber-200',
  stopped: 'bg-slate-100 text-slate-600 ring-slate-300',
  completed: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
};

function formatWhen(iso: string | null): string {
  if (!iso) return '';
  return new Date(iso).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Kolkata' }) + ' IST';
}

/** A sample list the user can download, fill in and upload. */
function downloadSample() {
  const csv = 'phone,name\n919876543210,Sample One\n919876543211,Sample Two\n';
  const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
  const link = document.createElement('a');
  link.href = url;
  link.download = 'batch-sample.csv';
  link.click();
  URL.revokeObjectURL(url);
}

/** The same substitution the Send page uses for its own preview. */
function renderPreview(body: string, values: Record<string, string>): string {
  return body.replace(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g, (match, name: string) =>
    Object.prototype.hasOwnProperty.call(values, name) ? values[name] : match,
  );
}

/**
 * Send a long list from an Excel or CSV file in chunks of `batch_size` numbers, back to back with no rest between
 * them — the spacing that keeps WhatsApp from seeing a burst comes entirely from the per-number gap (see
 * MessageBatchService::stepSeconds()), based on how many numbers are chosen below. A batch can start at a scheduled
 * time, be paused and resumed, or be stopped for good; once started it keeps sending on the server even if the
 * browser is closed. Lives on the Send Notification page only; the Developer API has no batch endpoint.
 */
export default function BatchSendPanel({ senders, disabled = false }: BatchSendPanelProps) {
  const [file, setFile] = useState<File | null>(null);
  const [batchSize, setBatchSize] = useState(50);
  const [title, setTitle] = useState('');
  // The numbers the batch sends from, in turn. Starts with the default number; the user can add more.
  const [selectedSenders, setSelectedSenders] = useState<number[]>([]);
  // The template (its variables and preview), or "No template" to send typed text instead; chosen here.
  const [templates, setTemplates] = useState<BatchTemplate[]>([]);
  const [selection, setSelection] = useState<number | 'none' | ''>('');
  const [variables, setVariables] = useState<Record<string, string>>({});
  const [messageText, setMessageText] = useState('');
  const [mediaUrl, setMediaUrl] = useState('');
  // Optional: start the batch at a chosen time (IST) instead of now.
  const [schedule, setSchedule] = useState<ScheduleValue>(EMPTY_SCHEDULE);
  const scheduledIso = scheduleToIso(schedule);
  const scheduleProblem = scheduleError(schedule);
  const noTemplate = selection === 'none';
  const selectedTemplate = typeof selection === 'number' ? templates.find((t) => t.id === selection) ?? null : null;

  useEffect(() => {
    templateService.available().then(setTemplates).catch(() => undefined);
  }, []);

  useEffect(() => {
    setSelectedSenders((prev) => {
      const stillLinked = prev.filter((id) => senders.some((s) => s.id === id));
      if (stillLinked.length > 0) return stillLinked;
      const first = senders.find((s) => s.is_default) ?? senders[0];
      return first ? [first.id] : [];
    });
  }, [senders]);
  const fileInput = useRef<HTMLInputElement>(null);

  const [batches, setBatches] = useState<MessageBatch[]>([]);
  const [creating, setCreating] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [stopTarget, setStopTarget] = useState<MessageBatch | null>(null);

  // The batch list is shown here only for what is running now; everything (including stopped) is in History.
  const [historyOpen, setHistoryOpen] = useState(false);
  const [historyPage, setHistoryPage] = useState(1);
  const activeBatches = batches.filter((b) => ACTIVE.includes(b.status));
  const historyPageCount = Math.max(1, Math.ceil(batches.length / HISTORY_PAGE_SIZE));
  const historyPageItems = batches.slice((historyPage - 1) * HISTORY_PAGE_SIZE, historyPage * HISTORY_PAGE_SIZE);

  const load = useCallback(() => {
    messageBatchService
      .list()
      .then(setBatches)
      .catch(() => undefined);
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  // While a batch can still send, refresh the list so progress moves on its own.
  const anyActive = activeBatches.length > 0;
  useEffect(() => {
    if (!anyActive) return;
    const timer = window.setInterval(load, POLL_MS);
    return () => window.clearInterval(timer);
  }, [anyActive, load]);

  const openHistory = () => {
    setHistoryOpen(true);
    setHistoryPage(1);
    load();
  };

  const missingRequired = selectedTemplate
    ? selectedTemplate.variables_schema.filter((f) => f.required && !(variables[f.key] ?? '').trim())
    : [];

  const create = async () => {
    setError(null);
    setNotice(null);

    if (scheduleProblem) {
      setError(scheduleProblem);
      return;
    }
    if (selection === '') {
      setError('Choose a template, or choose "No template" to write your own message.');
      return;
    }
    if (noTemplate && !messageText.trim() && !mediaUrl.trim()) {
      setError('Write a message or attach a media URL.');
      return;
    }
    if (!file) {
      setError('Choose an Excel (.xlsx) or CSV file with the phone numbers.');
      return;
    }
    if (selectedSenders.length === 0) {
      setError('Choose at least one WhatsApp number to send from.');
      return;
    }
    if (!noTemplate && missingRequired.length > 0) {
      setError(`Fill in the required variables first: ${missingRequired.map((f) => f.key).join(', ')}.`);
      return;
    }

    setCreating(true);
    try {
      const result = await messageBatchService.create({
        file,
        ...(noTemplate ? { message_text: messageText.trim() || undefined } : { template_id: selectedTemplate!.id }),
        // In the order of the number list (default first): message 1 from the first number, message 2 from the second, and so on.
        sender_number_ids: senders.filter((s) => selectedSenders.includes(s.id)).map((s) => s.id),
        variables,
        media_url: mediaUrl.trim() || undefined,
        title: title.trim() || undefined,
        batch_size: batchSize,
        scheduled_at: scheduledIso ?? undefined,
      });
      setNotice(result.message);
      setFile(null);
      setTitle('');
      if (fileInput.current) fileInput.current.value = '';
      load();
    } catch (err) {
      setError(extractErrorMessage(err, 'We could not create the batch. Please check the file and try again.'));
    } finally {
      setCreating(false);
    }
  };

  const act = async (batch: MessageBatch, action: 'pause' | 'resume' | 'stop') => {
    setBusyId(batch.id);
    setError(null);
    try {
      const updated =
        action === 'pause'
          ? await messageBatchService.pause(batch.id)
          : action === 'resume'
            ? await messageBatchService.resume(batch.id)
            : await messageBatchService.stop(batch.id);
      setBatches((list) => list.map((b) => (b.id === updated.id ? updated : b)));
    } catch (err) {
      setError(extractErrorMessage(err, 'This action could not be completed. Please try again.'));
    } finally {
      setBusyId(null);
      setStopTarget(null);
    }
  };

  const controlsDisabled = disabled || creating;

  /** One batch row: progress, the total/sent/left counts, and its Pause/Resume/Stop buttons. Used in the side list and History. */
  const renderRow = (batch: MessageBatch) => {
    const done = batch.sent_count + batch.failed_count;
    const percent = batch.total > 0 ? Math.round((done / batch.total) * 100) : 0;
    const busy = busyId === batch.id;

    return (
      <div key={batch.id} className="rounded-lg border border-slate-200 p-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <p className="min-w-0 truncate text-sm font-semibold text-slate-900">{batch.title}</p>
          <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset ${STATUS_CLASS[batch.status]}`}>
            {batch.status === 'scheduled' && <CalendarClock className="h-3 w-3" />}
            {STATUS_LABEL[batch.status]}
          </span>
        </div>
        {batch.scheduled_at && batch.status === 'scheduled' && (
          <p className="text-[11px] text-slate-500">Starts {formatWhen(batch.scheduled_at)}</p>
        )}

        <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100">
          <div className="h-full rounded-full bg-indigo-500" style={{ width: `${percent}%` }} />
        </div>
        <p className="mt-1 text-[11px] text-slate-600">
          {batch.total} numbers · {batch.sent_count} sent · {batch.pending_count} left
          {batch.failed_count > 0 ? ` · ${batch.failed_count} failed` : ''}
        </p>
        {(batch.stop_reason || (batch.finishes_at && batch.pending_count > 0)) && (
          <p className="text-[11px] text-slate-500">
            {batch.finishes_at && batch.pending_count > 0 ? `Finishes about ${formatWhen(batch.finishes_at)}` : ''}
            {batch.stop_reason ? ` ${batch.stop_reason}` : ''}
          </p>
        )}

        {/* Stop is final: no restart. Paused shows Resume and Stop together, so the user can continue or end it there. */}
        <div className="mt-2 flex flex-wrap gap-2">
          {(batch.status === 'scheduled' || batch.status === 'running') && (
            <button
              type="button"
              onClick={() => void act(batch, 'pause')}
              disabled={busy}
              className="flex items-center gap-1 rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60"
            >
              <Pause className="h-3.5 w-3.5" /> Pause
            </button>
          )}
          {batch.status === 'paused' && (
            <button
              type="button"
              onClick={() => void act(batch, 'resume')}
              disabled={busy}
              className="flex items-center gap-1 rounded-md border border-indigo-300 px-2.5 py-1 text-xs font-medium text-indigo-700 hover:bg-indigo-50 disabled:opacity-60"
            >
              <Play className="h-3.5 w-3.5" /> Resume
            </button>
          )}
          {(batch.status === 'scheduled' || batch.status === 'running' || batch.status === 'paused') && (
            <button
              type="button"
              onClick={() => setStopTarget(batch)}
              disabled={busy}
              className="flex items-center gap-1 rounded-md border border-red-300 px-2.5 py-1 text-xs font-medium text-red-700 hover:bg-red-50 disabled:opacity-60"
            >
              <Square className="h-3.5 w-3.5" /> Stop
            </button>
          )}
        </div>
      </div>
    );
  };

  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_300px]">
      <section className="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm" aria-label="Batch send from a file">
        <div className="flex items-start justify-between gap-3">
          <div>
            <h2 className="text-sm font-semibold text-slate-900">Send a large list in batches</h2>
            <p className="mt-1 text-xs text-slate-500">
              Upload an Excel (.xlsx) or CSV file with a <span className="font-medium">phone</span> column. Messages are spaced out
              automatically so WhatsApp does not see a burst.
            </p>
          </div>
          <button type="button" onClick={openHistory} className="flex-shrink-0 text-xs font-medium text-indigo-600 hover:text-indigo-700">
            History
          </button>
        </div>

        <div className="grid gap-3 md:grid-cols-2">
          <label className="flex flex-col gap-1 text-xs font-medium text-slate-700">
            <span>Template <span className="text-red-600">*</span></span>
            <select
              value={selection}
              disabled={controlsDisabled}
              onChange={(e) => {
                const v = e.target.value;
                setSelection(v === '' ? '' : v === 'none' ? 'none' : Number(v));
                setVariables({});
                setMessageText('');
              }}
              className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900"
            >
              <option value="">Choose a template…</option>
              <option value="none">No template (write your own message)</option>
              {templates.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.title}
                </option>
              ))}
            </select>
          </label>
          <label className="flex flex-col gap-1 text-xs font-medium text-slate-700">
            Media URL (optional)
            <input
              type="url"
              value={mediaUrl}
              disabled={controlsDisabled}
              onChange={(e) => setMediaUrl(e.target.value)}
              placeholder="https://example.com/invoice.pdf"
              className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
            />
          </label>
        </div>

        {noTemplate && (
          <label className="flex flex-col gap-1 text-xs font-medium text-slate-700">
            Your message
            <textarea
              rows={4}
              maxLength={4096}
              value={messageText}
              disabled={controlsDisabled}
              onChange={(e) => setMessageText(e.target.value)}
              placeholder="Type the message to send…"
              className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
            />
          </label>
        )}

        {selectedTemplate && selectedTemplate.variables_schema.length > 0 && (
          <div className="grid gap-3 md:grid-cols-2">
            {selectedTemplate.variables_schema.map((field) => (
              <label key={field.key} className="flex flex-col gap-1 text-xs font-medium text-slate-700">
                <span>{field.label || field.key}{" "}{field.required && <span className="text-red-600">*</span>}</span>
                <input
                  type="text"
                  value={variables[field.key] ?? ''}
                  disabled={controlsDisabled}
                  onChange={(e) => setVariables((v) => ({ ...v, [field.key]: e.target.value }))}
                  className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
                />
              </label>
            ))}
          </div>
        )}

        {selectedTemplate && (
          <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
            <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Preview</p>
            <p className="mt-1 whitespace-pre-wrap text-sm text-slate-800">{renderPreview(selectedTemplate.template_body, variables)}</p>
          </div>
        )}

        <fieldset className="space-y-2">
          <legend className="text-xs font-medium text-slate-700">
            Send from these numbers <span className="text-red-600">*</span>
          </legend>
          {senders.length === 0 && <p className="text-xs text-amber-700">No connected WhatsApp number. Connect a number first.</p>}
          <div className="flex flex-wrap gap-2">
            {senders.map((sender) => (
              <label key={sender.id} className="flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
                <input
                  type="checkbox"
                  checked={selectedSenders.includes(sender.id)}
                  disabled={controlsDisabled}
                  onChange={(e) =>
                    setSelectedSenders((prev) => (e.target.checked ? [...prev, sender.id] : prev.filter((id) => id !== sender.id)))
                  }
                />
                <span className="font-mono text-xs">{sender.phone_number}</span>
                {sender.is_default && <span className="text-[10px] font-semibold text-indigo-700">default</span>}
              </label>
            ))}
          </div>
          <p className="text-[11px] text-slate-500">
            Messages rotate through the ticked numbers in turn; each number waits about 25 seconds between its own messages.
          </p>
        </fieldset>

        <div className="flex flex-wrap items-center justify-between gap-2">
          <p className="text-xs text-slate-500">
            One phone number per row, under a column named <span className="font-mono">phone</span>.
          </p>
          <button
            type="button"
            onClick={downloadSample}
            className="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
          >
            Download sample file (.csv)
          </button>
        </div>

        <div className="grid gap-3 md:grid-cols-2">
          <label className="flex flex-col gap-1 text-xs font-medium text-slate-700">
            <span>Excel or CSV file <span className="text-red-600">*</span></span>
            <input
              ref={fileInput}
              type="file"
              accept=".xlsx,.csv,.txt"
              disabled={controlsDisabled}
              onChange={(e) => setFile(e.target.files?.[0] ?? null)}
              className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 file:mr-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-1 file:text-indigo-700"
            />
          </label>

          <label className="flex flex-col gap-1 text-xs font-medium text-slate-700">
            Batch title (optional)
            <input
              type="text"
              maxLength={120}
              value={title}
              disabled={controlsDisabled}
              onChange={(e) => setTitle(e.target.value)}
              placeholder="Defaults to the file name"
              className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
            />
          </label>
        </div>

        <label className="flex max-w-[12rem] flex-col gap-1 text-xs font-medium text-slate-700">
          <span>Messages per batch <span className="text-red-600">*</span></span>
          <select
            value={batchSize}
            disabled={controlsDisabled}
            onChange={(e) => setBatchSize(Number(e.target.value))}
            className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900"
          >
            {BATCH_SIZES.map((size) => (
              <option key={size} value={size}>
                {size}
              </option>
            ))}
          </select>
          <span className="text-[11px] font-normal text-slate-500">
            Sent in chunks of this size, one after another, with no rest between them.
          </span>
        </label>

        <ScheduleField value={schedule} onChange={setSchedule} disabled={controlsDisabled} />

        <div className="flex flex-wrap items-center gap-3">
          <button
            type="button"
            onClick={() => void create()}
            disabled={controlsDisabled}
            className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60"
          >
            {creating ? <Loader2 className="h-4 w-4 animate-spin" /> : <Upload className="h-4 w-4" />}
            {creating ? 'Reading file…' : scheduledIso ? 'Schedule batch' : 'Start batch now'}
          </button>
        </div>

        {notice && <p className="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-800">{notice}</p>}
        {error && (
          <DismissibleAlert className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">{error}</DismissibleAlert>
        )}
      </section>

      <aside className="h-fit space-y-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500">Running now</h3>
        {activeBatches.length === 0 ? (
          <p className="text-xs text-slate-500">Nothing running. Start a batch, or check History for past ones.</p>
        ) : (
          <div className="space-y-3">{activeBatches.map(renderRow)}</div>
        )}
      </aside>

      {historyOpen && (
        <div className="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/50 p-4" role="dialog" aria-modal="true" aria-labelledby="batch-history-title">
          <div className="max-h-[85vh] w-full max-w-lg overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
            <div className="flex items-center justify-between">
              <h3 id="batch-history-title" className="text-sm font-semibold text-slate-900">
                Batch history
              </h3>
              <button type="button" onClick={() => setHistoryOpen(false)} className="text-slate-400 hover:text-slate-600" aria-label="Close">
                <X className="h-5 w-5" />
              </button>
            </div>

            <div className="mt-4 space-y-3">
              {batches.length === 0 && <p className="text-xs text-slate-500">No batches yet.</p>}
              {historyPageItems.map(renderRow)}
            </div>

            {batches.length > HISTORY_PAGE_SIZE && (
              <div className="mt-4 flex items-center justify-between text-xs">
                <button
                  type="button"
                  onClick={() => setHistoryPage((p) => Math.max(1, p - 1))}
                  disabled={historyPage === 1}
                  className="rounded-lg border border-slate-300 px-2.5 py-1 font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-40"
                >
                  Previous
                </button>
                <span className="text-slate-500">
                  Page {historyPage} of {historyPageCount}
                </span>
                <button
                  type="button"
                  onClick={() => setHistoryPage((p) => Math.min(historyPageCount, p + 1))}
                  disabled={historyPage === historyPageCount}
                  className="rounded-lg border border-slate-300 px-2.5 py-1 font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-40"
                >
                  Next
                </button>
              </div>
            )}
          </div>
        </div>
      )}

      {stopTarget && (
        <ConfirmModal
          title="Stop this batch?"
          message={`Stop "${stopTarget.title}"? The numbers not sent yet will be cancelled. Messages already sent stay in the logs, and this cannot be undone.`}
          confirmLabel="Stop batch"
          variant="danger"
          isLoading={busyId === stopTarget.id}
          onConfirm={() => void act(stopTarget, 'stop')}
          onCancel={() => setStopTarget(null)}
        />
      )}
    </div>
  );
}
