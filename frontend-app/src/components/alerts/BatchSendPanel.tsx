import { useCallback, useEffect, useRef, useState } from 'react';
import { CalendarClock, Pause, Play, Square, Upload, Loader2 } from 'lucide-react';
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
const INTERVALS = [1, 2, 5, 10, 15, 30];
const POLL_MS = 15_000;

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

const ACTIVE: MessageBatchStatus[] = ['scheduled', 'running'];

function formatWhen(iso: string | null): string {
  if (!iso) return '';
  return new Date(iso).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Kolkata' }) + ' IST';
}

/**
 * Send a long list from an Excel or CSV file in batches: each batch sends `batch_size` numbers, then waits
 * `interval_minutes`. A batch can start at a scheduled time, be paused and resumed, or be stopped for good.
 * Lives on the Send Notification page only; the Developer API has no batch endpoint.
 */
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

export default function BatchSendPanel({ senders, disabled = false }: BatchSendPanelProps) {
  const [file, setFile] = useState<File | null>(null);
  const [batchSize, setBatchSize] = useState(50);
  const [intervalMinutes, setIntervalMinutes] = useState(5);
  const [title, setTitle] = useState('');
  // The numbers the batch sends from, in turn. Starts with the default number; the user can add more.
  const [selectedSenders, setSelectedSenders] = useState<number[]>([]);
  // The template, its variables and the media URL belong to the batch, chosen here.
  const [templates, setTemplates] = useState<BatchTemplate[]>([]);
  const [templateId, setTemplateId] = useState<number | ''>('');
  const [variables, setVariables] = useState<Record<string, string>>({});
  const [mediaUrl, setMediaUrl] = useState('');
  // Optional: start the batch at a chosen time (IST) instead of now.
  const [schedule, setSchedule] = useState<ScheduleValue>(EMPTY_SCHEDULE);
  const scheduledIso = scheduleToIso(schedule);
  const scheduleProblem = scheduleError(schedule);
  const selectedTemplate = templates.find((t) => t.id === templateId) ?? null;

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
  const anyActive = batches.some((b) => ACTIVE.includes(b.status));
  useEffect(() => {
    if (!anyActive) return;
    const timer = window.setInterval(load, POLL_MS);
    return () => window.clearInterval(timer);
  }, [anyActive, load]);

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
    if (!selectedTemplate) {
      setError('Choose a template first.');
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
    if (missingRequired.length > 0) {
      setError(`Fill in the required variables first: ${missingRequired.map((f) => f.key).join(', ')}.`);
      return;
    }

    setCreating(true);
    try {
      const result = await messageBatchService.create({
        file,
        template_id: selectedTemplate.id,
        // In the order of the number list (default first): message 1 from the first number, message 2 from the second, and so on.
        sender_number_ids: senders.filter((s) => selectedSenders.includes(s.id)).map((s) => s.id),
        variables,
        media_url: mediaUrl.trim() || undefined,
        title: title.trim() || undefined,
        batch_size: batchSize,
        interval_minutes: intervalMinutes,
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

  return (
    <section className="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm" aria-label="Batch send from a file">
      <div>
        <h2 className="text-sm font-semibold text-slate-900">Send a large list in batches</h2>
        <p className="mt-1 text-xs text-slate-500">
          Upload an Excel (.xlsx) or CSV file with a <span className="font-medium">phone</span> column. One message is sent, then the next one
          waits 25 seconds, so WhatsApp does not see a burst. After each batch of messages, the batch waits the chosen interval. The
          template and variables above are used for every number.
        </p>
      </div>

      <div className="grid gap-3 md:grid-cols-2">
        <label className="flex flex-col gap-1 text-xs font-medium text-slate-700">
          <span>Template <span className="text-red-600">*</span></span>
          <select
            value={templateId}
            disabled={controlsDisabled}
            onChange={(e) => {
              setTemplateId(e.target.value ? Number(e.target.value) : '');
              setVariables({});
            }}
            className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900"
          >
            <option value="">Choose a template…</option>
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
          Messages rotate in this order: message 1 from the first number, message 2 from the second, and so on, then back to the
          first. Each number waits 25 seconds between its own messages, so no single number is flooded.
        </p>
      </fieldset>

      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-xs text-slate-500">One phone number per row, in a column named <span className="font-mono">phone</span>. Country code, digits only.</p>
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

        <label className="flex flex-col gap-1 text-xs font-medium text-slate-700">
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
        </label>

        <label className="flex flex-col gap-1 text-xs font-medium text-slate-700">
          <span>Wait between batches <span className="text-red-600">*</span></span>
          <select
            value={intervalMinutes}
            disabled={controlsDisabled}
            onChange={(e) => setIntervalMinutes(Number(e.target.value))}
            className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900"
          >
            {INTERVALS.map((minutes) => (
              <option key={minutes} value={minutes}>
                {minutes} minute{minutes === 1 ? '' : 's'}
              </option>
            ))}
          </select>
        </label>
      </div>

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

      <div className="space-y-3">
        <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500">Your batches</h3>

        {batches.length === 0 && <p className="text-xs text-slate-500">No batches yet.</p>}

        {batches.map((batch) => {
          const done = batch.sent_count + batch.failed_count;
          const percent = batch.total > 0 ? Math.round((done / batch.total) * 100) : 0;
          const busy = busyId === batch.id;

          return (
            <div key={batch.id} className="rounded-lg border border-slate-200 p-3">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="min-w-0">
                  <p className="truncate text-sm font-semibold text-slate-900">{batch.title}</p>
                  <p className="text-[11px] text-slate-500">
                    {batch.total} numbers · {batch.batch_size} per batch · every {batch.interval_minutes} min
                    {batch.scheduled_at && batch.status === 'scheduled' ? ` · starts ${formatWhen(batch.scheduled_at)}` : ''}
                  </p>
                </div>
                <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset ${STATUS_CLASS[batch.status]}`}>
                  {batch.status === 'scheduled' && <CalendarClock className="h-3 w-3" />}
                  {STATUS_LABEL[batch.status]}
                </span>
              </div>

              <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100">
                <div className="h-full rounded-full bg-indigo-500" style={{ width: `${percent}%` }} />
              </div>
              <p className="mt-1 text-[11px] text-slate-600">
                Sent {batch.sent_count} · Failed {batch.failed_count} · Waiting {batch.pending_count}
                {batch.finishes_at && batch.pending_count > 0 ? ` · finishes about ${formatWhen(batch.finishes_at)}` : ''}
                {batch.stop_reason ? ` · ${batch.stop_reason}` : ''}
              </p>

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
        })}
      </div>

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
    </section>
  );
}
