import { useCallback, useEffect, useMemo, useRef, useState, type ChangeEvent, type DragEvent, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import {
  AlertCircle,
  AlertTriangle,
  CheckCircle2,
  Code2,
  Copy,
  Download,
  FileText,
  FlaskConical,
  Loader2,
  Send,
  Sparkles,
  UploadCloud,
  X,
  XCircle,
} from 'lucide-react';
import alertService from '../../services/alertService';
import { useAuth } from '../../core/context/AuthContext';
import { useTenant } from '../../core/context/TenantContext';
import templateService from '../../services/templateService';
import whatsappService from '../../services/whatsappService';
import type { BulkUploadResponse, ParsedCsvRow } from '../../types/alert';
import type { AvailableTemplate } from '../../types/templates';
import { extractErrorMessage } from '../../utils/apiError';

type Tab = 'bulk' | 'template';

/**
 * Client-side mirror of the backend's TemplateRenderer::render(), as
 * actually invoked by TemplateMessageDispatcher (not the raw renderer in
 * isolation): a schema-known variable substitutes its current value even
 * when that value is empty (an optional field left blank sends as '', not
 * as the literal "{{token}}" — see TemplateMessageDispatcher's
 * $renderVariables fill step), matching exactly what the server will
 * send. A token with no corresponding entry in `variables` at all (should
 * not happen once a template is selected — every {{token}} gets a
 * `variables` key, required or not) is left as-is rather than silently
 * disappearing, so a schema/body mismatch stays visible instead of hidden.
 */
function renderTemplatePreview(body: string, variables: Record<string, string>): string {
  return body.replace(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g, (match, name: string) =>
    Object.prototype.hasOwnProperty.call(variables, name) ? variables[name] : match,
  );
}

const REQUIRED_CSV_COLUMNS = ['phone', 'customer_name', 'amount', 'payment_ref'] as const;

const inputClass =
  'mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100';

/**
 * Minimal RFC4180-ish CSV parser (quoted fields, embedded commas, escaped
 * "" quotes, \r\n or \n line endings). This is a client-side PREVIEW only —
 * the backend's fgetcsv() parse on the actual upload is authoritative; this
 * just needs to be good enough to show the user what they're about to send.
 */
function parseCsv(text: string): string[][] {
  const rows: string[][] = [];
  let row: string[] = [];
  let field = '';
  let inQuotes = false;

  for (let i = 0; i < text.length; i++) {
    const char = text[i];
    const next = text[i + 1];

    if (inQuotes) {
      if (char === '"' && next === '"') {
        field += '"';
        i++;
      } else if (char === '"') {
        inQuotes = false;
      } else {
        field += char;
      }
      continue;
    }

    if (char === '"') {
      inQuotes = true;
    } else if (char === ',') {
      row.push(field);
      field = '';
    } else if (char === '\n' || char === '\r') {
      if (char === '\r' && next === '\n') i++;
      row.push(field);
      field = '';
      rows.push(row);
      row = [];
    } else {
      field += char;
    }
  }

  if (field !== '' || row.length > 0) {
    row.push(field);
    rows.push(row);
  }

  return rows.filter((r) => r.some((v) => v.trim() !== ''));
}

function downloadSampleCsv() {
  const csv = [
    'phone,customer_name,amount,payment_ref',
    '919876543210,Ravi Kumar,1500.00,PAY-2026-0001',
    '919812345678,Anita Sharma,750.50,PAY-2026-0002',
  ].join('\n');

  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = 'payment_alerts_sample.csv';
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
}

export default function SendAlertPage() {
  // Client Admin UI & Single Alert Removal: the legacy manual/static
  // Single Alert tab is gone (see the removed SingleAlertTab function
  // below this component) and the Approved Template Form is now the
  // PRIMARY tab (opens first). Bulk Upload is kept — the spec's heading
  // and body both name the "Single Alert form" specifically as what to
  // strip, not CSV bulk import, which is a distinct workflow this spec
  // never mentions removing.
  const [tab, setTab] = useState<Tab>('template');

  const { user, isReadOnly } = useAuth();
  // Client Admin Test Button: "their own registered number" is this
  // account's primary_phone (Account::$fillable, already returned on
  // every /auth/me response via AuthController::formatUser()'s
  // 'account' => $user->account — no new endpoint needed). Null for
  // Super Admin (who has no account of their own) and for any client
  // account that hasn't set one yet; TemplateMessageTab's Test button
  // surfaces that as an actionable error rather than silently no-op-ing.
  const registeredPhone = user?.account?.primary_phone ?? null;

  // Super Admin Multi-Tenant Scoping: re-check WhatsApp status whenever the
  // Header's client selector changes — a status fetched for one tenant (or
  // for no tenant at all) must not linger once Super Admin switches to
  // another.
  const { selectedAccountId } = useTenant();

  // Send Alert Disconnect Guard: fetched on mount AND on tenant switch so
  // both tabs share one source of truth. `null` = still loading / no
  // tenant selected (never blocks the button — the backend's own 422
  // guard is the real safety net; this is UX only).
  const [waStatus, setWaStatus] = useState<'connected' | 'connecting' | 'disconnected' | null>(null);

  useEffect(() => {
    let cancelled = false;
    whatsappService
      .status()
      .then((res) => {
        if (!cancelled) setWaStatus(res.status);
      })
      .catch(() => {
        // Can't confirm connectivity (including "Super Admin, no tenant
        // selected yet") — don't block sending on this alone; the
        // backend's own guard still protects the send.
        if (!cancelled) setWaStatus(null);
      });
    return () => {
      cancelled = true;
    };
  }, [selectedAccountId]);

  const isDisconnected = waStatus === 'disconnected';
  const readOnly = isReadOnly();

  return (
    <div className="p-6">
      <div className="w-full">
        <h1 className="text-xl font-semibold text-slate-900">Send Payment Alert</h1>
        <p className="mt-1 text-sm text-slate-500">
          Notify a customer over WhatsApp that their payment was received.
        </p>

        {isDisconnected && (
          <div className="mt-6 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm">
            <div className="flex items-center gap-2 text-amber-800">
              <AlertTriangle className="h-4 w-4 flex-shrink-0" />
              <span className="font-medium">WhatsApp Disconnected</span>
            </div>
            <Link
              to="/settings/whatsapp"
              className="rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-sm font-semibold text-amber-800 hover:bg-amber-100"
            >
              Go to WhatsApp Setup to Connect
            </Link>
          </div>
        )}

        <div className="mt-6 flex gap-1 rounded-lg border border-slate-200 bg-white p-1">
          <button
            type="button"
            onClick={() => setTab('template')}
            className={`flex-1 rounded-md px-4 py-2 text-sm font-medium transition ${
              tab === 'template' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50'
            }`}
          >
            Template Message
          </button>
          <button
            type="button"
            onClick={() => setTab('bulk')}
            className={`flex-1 rounded-md px-4 py-2 text-sm font-medium transition ${
              tab === 'bulk' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50'
            }`}
          >
            Bulk Upload
          </button>
        </div>

        <div className="mt-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
          {tab === 'template' && (
            <TemplateMessageTab disabled={isDisconnected} readOnly={readOnly} registeredPhone={registeredPhone} />
          )}
          {tab === 'bulk' && <BulkUploadTab disabled={isDisconnected} readOnly={readOnly} />}
        </div>
      </div>
    </div>
  );
}

function BulkUploadTab({ disabled, readOnly }: { disabled: boolean; readOnly: boolean }) {
  const fileInputRef = useRef<HTMLInputElement>(null);

  const [file, setFile] = useState<File | null>(null);
  const [rows, setRows] = useState<ParsedCsvRow[]>([]);
  const [parseError, setParseError] = useState<string | null>(null);
  const [isDragging, setIsDragging] = useState(false);

  const [isUploading, setIsUploading] = useState(false);
  const [uploadError, setUploadError] = useState<string | null>(null);
  const [result, setResult] = useState<BulkUploadResponse | null>(null);

  const parseFile = useCallback((selected: File) => {
    setParseError(null);
    setResult(null);
    setUploadError(null);

    const reader = new FileReader();
    reader.onload = () => {
      const text = String(reader.result ?? '');
      const table = parseCsv(text);

      if (table.length === 0) {
        setParseError('The CSV file is empty.');
        setRows([]);
        return;
      }

      const header = table[0].map((h) => h.trim().toLowerCase());
      const missing = REQUIRED_CSV_COLUMNS.filter((col) => !header.includes(col));
      if (missing.length > 0) {
        setParseError(`CSV is missing required column(s): ${missing.join(', ')}`);
        setRows([]);
        return;
      }

      const colIndex = {
        phone: header.indexOf('phone'),
        customer_name: header.indexOf('customer_name'),
        amount: header.indexOf('amount'),
        payment_ref: header.indexOf('payment_ref'),
      };

      const parsedRows: ParsedCsvRow[] = table.slice(1).map((line, idx) => ({
        row: idx + 2, // header is row 1
        phone: (line[colIndex.phone] ?? '').trim(),
        customer_name: (line[colIndex.customer_name] ?? '').trim(),
        amount: (line[colIndex.amount] ?? '').trim(),
        payment_ref: (line[colIndex.payment_ref] ?? '').trim(),
      }));

      setRows(parsedRows);
    };
    reader.onerror = () => setParseError('Could not read this file.');
    reader.readAsText(selected);
  }, []);

  const handleFileSelected = (selected: File | null) => {
    if (!selected) return;
    if (!selected.name.toLowerCase().endsWith('.csv') && selected.type !== 'text/csv') {
      setParseError('Please select a .csv file.');
      return;
    }
    setFile(selected);
    parseFile(selected);
  };

  const onInputChange = (e: ChangeEvent<HTMLInputElement>) => {
    handleFileSelected(e.target.files?.[0] ?? null);
  };

  const onDrop = (e: DragEvent<HTMLDivElement>) => {
    e.preventDefault();
    setIsDragging(false);
    handleFileSelected(e.dataTransfer.files?.[0] ?? null);
  };

  const clearFile = () => {
    setFile(null);
    setRows([]);
    setParseError(null);
    setResult(null);
    setUploadError(null);
    if (fileInputRef.current) fileInputRef.current.value = '';
  };

  const handleUpload = async () => {
    if (!file) return;
    setIsUploading(true);
    setUploadError(null);
    setResult(null);
    try {
      const res = await alertService.bulkUpload(file);
      setResult(res);
    } catch (err) {
      setUploadError(extractErrorMessage(err, 'Could not upload this file. Please try again.'));
    } finally {
      setIsUploading(false);
    }
  };

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <p className="text-sm text-slate-500">
          CSV must include the columns: <span className="font-mono">phone, customer_name, amount, payment_ref</span>.
        </p>
        <button
          type="button"
          onClick={downloadSampleCsv}
          className="flex items-center gap-2 whitespace-nowrap rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
        >
          <Download className="h-4 w-4" />
          Sample CSV
        </button>
      </div>

      {!file ? (
        <div
          onDragOver={(e) => {
            e.preventDefault();
            setIsDragging(true);
          }}
          onDragLeave={() => setIsDragging(false)}
          onDrop={onDrop}
          onClick={() => fileInputRef.current?.click()}
          className={`flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed px-6 py-10 text-center transition ${
            isDragging ? 'border-indigo-500 bg-indigo-50' : 'border-slate-300 bg-slate-50 hover:bg-slate-100'
          }`}
        >
          <UploadCloud className="h-8 w-8 text-slate-400" />
          <p className="text-sm font-medium text-slate-700">Drag and drop a CSV file here, or click to browse</p>
          <p className="text-xs text-slate-500">.csv up to 5 MB</p>
          <input ref={fileInputRef} type="file" accept=".csv,text/csv" onChange={onInputChange} className="hidden" />
        </div>
      ) : (
        <div className="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
          <div className="flex items-center gap-2 text-sm text-slate-700">
            <FileText className="h-4 w-4 text-slate-500" />
            <span className="font-medium">{file.name}</span>
            <span className="text-slate-400">({rows.length} row{rows.length === 1 ? '' : 's'} parsed)</span>
          </div>
          <button type="button" onClick={clearFile} className="text-slate-400 hover:text-slate-600">
            <X className="h-4 w-4" />
          </button>
        </div>
      )}

      {parseError && (
        <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          <XCircle className="h-4 w-4 flex-shrink-0" />
          {parseError}
        </div>
      )}

      {rows.length > 0 && !parseError && (
        <div className="overflow-x-auto rounded-lg border border-slate-200">
          <table className="min-w-full divide-y divide-slate-200 text-sm">
            <thead className="bg-slate-50">
              <tr>
                <th className="px-3 py-2 text-left font-medium text-slate-600">Row</th>
                <th className="px-3 py-2 text-left font-medium text-slate-600">Phone</th>
                <th className="px-3 py-2 text-left font-medium text-slate-600">Customer</th>
                <th className="px-3 py-2 text-left font-medium text-slate-600">Amount</th>
                <th className="px-3 py-2 text-left font-medium text-slate-600">Payment Ref</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {rows.slice(0, 20).map((r) => (
                <tr key={r.row}>
                  <td className="px-3 py-2 text-slate-500">{r.row}</td>
                  <td className="px-3 py-2 text-slate-800">{r.phone || '—'}</td>
                  <td className="px-3 py-2 text-slate-800">{r.customer_name || '—'}</td>
                  <td className="px-3 py-2 text-slate-800">{r.amount || '—'}</td>
                  <td className="px-3 py-2 text-slate-800">{r.payment_ref || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {rows.length > 20 && (
            <p className="border-t border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-500">
              Showing the first 20 of {rows.length} rows. All {rows.length} will be submitted.
            </p>
          )}
        </div>
      )}

      {uploadError && (
        <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          <XCircle className="h-4 w-4 flex-shrink-0" />
          {uploadError}
        </div>
      )}

      {result && (
        <div className="space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-4">
          <div className="flex items-center gap-2 text-sm font-medium text-slate-900">
            <CheckCircle2 className="h-4 w-4 text-emerald-500" />
            {result.message}
          </div>

          {result.skipped_duplicates.length > 0 && (
            <div className="text-sm text-amber-700">
              <p className="flex items-center gap-1 font-medium">
                <AlertTriangle className="h-3.5 w-3.5" />
                Skipped duplicates ({result.skipped_duplicates.length})
              </p>
              <ul className="mt-1 space-y-0.5 pl-5 text-xs">
                {result.skipped_duplicates.map((d) => (
                  <li key={d.row}>
                    Row {d.row}: {d.payment_ref}
                  </li>
                ))}
              </ul>
            </div>
          )}

          {result.invalid_rows.length > 0 && (
            <div className="text-sm text-red-700">
              <p className="flex items-center gap-1 font-medium">
                <AlertCircle className="h-3.5 w-3.5" />
                Invalid rows ({result.invalid_rows.length})
              </p>
              <ul className="mt-1 space-y-0.5 pl-5 text-xs">
                {result.invalid_rows.map((r) => (
                  <li key={r.row}>
                    Row {r.row}: {r.errors.join(', ')}
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>
      )}

      <button
        type="button"
        onClick={() => void handleUpload()}
        disabled={!file || !!parseError || rows.length === 0 || isUploading || disabled || readOnly}
        title={
          readOnly
            ? 'Action disabled: Subscription expired.'
            : disabled
              ? 'WhatsApp is disconnected — reconnect it in WhatsApp Setup to send alerts.'
              : undefined
        }
        className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60"
      >
        {isUploading ? <Loader2 className="h-4 w-4 animate-spin" /> : <UploadCloud className="h-4 w-4" />}
        {isUploading ? 'Uploading…' : 'Upload & Queue'}
      </button>
    </div>
  );
}

/**
 * Dynamic Templates & Variables System — Client Admin Dynamic Form
 * Engine. Selecting a template auto-generates one input per
 * {{variable}} the template declares, a Live Message Preview
 * re-renders on every keystroke (client-side mirror of the backend's
 * TemplateRenderer — see renderTemplatePreview() above), and a
 * Developer API Documentation box below shows the exact external-API
 * call this same send would look like from outside the platform.
 */
function TemplateMessageTab({
  disabled,
  readOnly,
  registeredPhone,
}: {
  disabled: boolean;
  readOnly: boolean;
  /** Client Admin Test Button target — this account's own registered WhatsApp number (Account::primary_phone), or null if unset. */
  registeredPhone: string | null;
}) {
  const [templates, setTemplates] = useState<AvailableTemplate[]>([]);
  const [isLoadingTemplates, setIsLoadingTemplates] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);

  const [templateId, setTemplateId] = useState<number | ''>('');
  const [recipientPhone, setRecipientPhone] = useState('');
  const [variables, setVariables] = useState<Record<string, string>>({});
  const [copied, setCopied] = useState(false);

  const [isSending, setIsSending] = useState(false);
  const [sendError, setSendError] = useState<string | null>(null);
  const [sendSuccess, setSendSuccess] = useState<string | null>(null);

  // Client Admin Test Button — same live approved-template send path as
  // the real Send Message button, just targeting registeredPhone instead
  // of whatever's in the Recipient Phone field, and tracked with its own
  // isTesting/testError/testSuccess so a test-send's result never
  // overwrites (or is overwritten by) a real send's result shown above it.
  const [isTesting, setIsTesting] = useState(false);
  const [testError, setTestError] = useState<string | null>(null);
  const [testSuccess, setTestSuccess] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    templateService
      .available()
      .then((list) => {
        if (!cancelled) setTemplates(list);
      })
      .catch((err) => {
        if (!cancelled) {
          setLoadError(extractErrorMessage(err, 'Failed to load templates.'));
        }
      })
      .finally(() => {
        if (!cancelled) setIsLoadingTemplates(false);
      });
    return () => {
      cancelled = true;
    };
  }, []);

  const selectedTemplate = useMemo(
    () => templates.find((t) => t.id === templateId) ?? null,
    [templates, templateId],
  );

  const handleSelectTemplate = (id: number | '') => {
    setTemplateId(id);
    setSendSuccess(null);
    setSendError(null);
    setTestSuccess(null);
    setTestError(null);
    const tpl = templates.find((t) => t.id === id);
    if (!tpl) {
      setVariables({});
      return;
    }
    // Reset to one empty field per variable — never carries over stale
    // values from a previously selected, differently-shaped template.
    setVariables(Object.fromEntries(tpl.variables_schema.map((f) => [f.key, ''])));
  };

  const previewText = selectedTemplate ? renderTemplatePreview(selectedTemplate.template_body, variables) : '';

  const samplePayload = useMemo(() => {
    if (!selectedTemplate) return null;
    return {
      template_id: selectedTemplate.id,
      recipient_phone: recipientPhone || '919876543210',
      variables: Object.fromEntries(
        selectedTemplate.variables_schema.map((f) => {
          if (variables[f.key]) return [f.key, variables[f.key]];
          // Sample payload placeholder: `<key>` for a required field (the
          // caller must supply a real value), or '' for optional — makes
          // required-vs-optional visible in the Developer API doc box's
          // JSON, per the spec's "showing which fields are required vs
          // optional" ask, without needing separate prose next to the code.
          return [f.key, f.required ? `<${f.key}>` : ''];
        }),
      ),
    };
  }, [selectedTemplate, recipientPhone, variables]);

  const handleCopyPayload = async () => {
    if (!samplePayload) return;
    try {
      await navigator.clipboard.writeText(JSON.stringify(samplePayload, null, 2));
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      // Clipboard permission denied — non-fatal, the code block is still selectable/copyable by hand.
    }
  };

  // Client-side mirror of SendTemplateMessageRequest's dynamic rules —
  // required-ness and basic type shape per field, so an invalid
  // submission is caught before a round trip, not just after a 422.
  // Shared by handleSubmit (real send) and handleTestSend (Client Admin
  // Test Button) so the two can never validate a variable differently.
  const validateVariables = (template: AvailableTemplate): string | null => {
    const missingRequired = template.variables_schema.filter((f) => f.required && !variables[f.key]?.trim());
    if (missingRequired.length > 0) {
      return `Fill in: ${missingRequired.map((f) => f.label || f.key).join(', ')}`;
    }
    const badType = template.variables_schema.find((f) => {
      const value = variables[f.key]?.trim();
      if (!value) return false; // optional & blank — nothing to type-check
      if (f.type === 'number') return Number.isNaN(Number(value));
      if (f.type === 'select') return !(f.options ?? []).includes(value);
      return false;
    });
    if (badType) {
      return badType.type === 'select'
        ? `"${badType.label || badType.key}" must be one of the allowed options.`
        : `"${badType.label || badType.key}" must be a number.`;
    }
    return null;
  };

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    setSendError(null);
    setSendSuccess(null);

    if (!selectedTemplate) {
      setSendError('Select a template first.');
      return;
    }
    if (!recipientPhone.trim()) {
      setSendError('Recipient phone is required.');
      return;
    }

    const validationError = validateVariables(selectedTemplate);
    if (validationError) {
      setSendError(validationError);
      return;
    }

    setIsSending(true);
    try {
      await templateService.sendTemplateMessage({
        template_id: selectedTemplate.id,
        recipient_phone: recipientPhone.trim(),
        variables,
      });
      setSendSuccess('Message sent.');
      setRecipientPhone('');
      setVariables(Object.fromEntries(selectedTemplate.variables_schema.map((f) => [f.key, ''])));
    } catch (err) {
      setSendError(extractErrorMessage(err, 'Could not send this message.'));
    } finally {
      setIsSending(false);
    }
  };

  /**
   * Client Admin Test Button — fires the SAME live, approved-template
   * send (templateService.sendTemplateMessage, no separate backend
   * endpoint) but always to registeredPhone instead of whatever's typed
   * into Recipient Phone, using whatever sample data the Client Admin has
   * already filled into the variable fields. Unlike the Super Admin's
   * dedicated POST /admin/templates/{id}/test (MessageTemplateController::test(),
   * which deliberately skips quota and the approval gate for a
   * PRE-approval test-fire), this is an already-approved template being
   * sent through the client's own normal, billable send path — the spec
   * does not ask for a quota exemption here, and this account is the one
   * both sending and receiving the message either way.
   */
  const handleTestSend = async () => {
    setTestError(null);
    setTestSuccess(null);

    if (!selectedTemplate) {
      setTestError('Select a template first.');
      return;
    }
    if (!registeredPhone) {
      setTestError('No registered WhatsApp number on file for your account yet — ask your Super Admin to set one.');
      return;
    }

    const validationError = validateVariables(selectedTemplate);
    if (validationError) {
      setTestError(validationError);
      return;
    }

    setIsTesting(true);
    try {
      await templateService.sendTemplateMessage({
        template_id: selectedTemplate.id,
        recipient_phone: registeredPhone,
        variables,
      });
      setTestSuccess(`Test message sent to your registered number (${registeredPhone}).`);
    } catch (err) {
      setTestError(extractErrorMessage(err, 'Could not send the test message.'));
    } finally {
      setIsTesting(false);
    }
  };

  if (isLoadingTemplates) {
    return (
      <div className="flex items-center gap-2 py-10 text-sm text-slate-500">
        <Loader2 className="h-4 w-4 animate-spin" />
        Loading templates…
      </div>
    );
  }

  if (loadError) {
    return (
      <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        <XCircle className="h-4 w-4 flex-shrink-0" />
        {loadError}
      </div>
    );
  }

  if (templates.length === 0) {
    return (
      <div className="flex flex-col items-center gap-2 rounded-lg border border-dashed border-slate-300 py-10 text-center text-sm text-slate-500">
        <Sparkles className="h-6 w-6 text-slate-300" />
        No approved templates yet. Ask your Super Admin to build and approve one in Template Manager.
      </div>
    );
  }

  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
      <form onSubmit={(e) => void handleSubmit(e)} className="space-y-4">
        <div>
          <label className="text-sm font-medium text-slate-700">Template</label>
          <select
            value={templateId}
            onChange={(e) => handleSelectTemplate(e.target.value === '' ? '' : Number(e.target.value))}
            className={inputClass}
          >
            <option value="">Select a template…</option>
            {templates.map((t) => (
              <option key={t.id} value={t.id}>
                {t.title}
                {t.industry_type ? ` — ${t.industry_type}` : ''}
              </option>
            ))}
          </select>
        </div>

        <div>
          <label className="text-sm font-medium text-slate-700">Recipient Phone</label>
          <input
            type="text"
            value={recipientPhone}
            onChange={(e) => setRecipientPhone(e.target.value)}
            placeholder="919876543210"
            className={inputClass}
          />
        </div>

        {selectedTemplate && selectedTemplate.variables_schema.length > 0 && (
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            {selectedTemplate.variables_schema.map((field) => (
              <div key={field.key}>
                <label className="text-sm font-medium text-slate-700">
                  {field.label || field.key}
                  {field.required ? (
                    <span className="ml-0.5 text-red-500" aria-hidden="true">
                      *
                    </span>
                  ) : (
                    <span className="ml-1 text-xs font-normal text-slate-400">(Optional)</span>
                  )}
                </label>
                {field.type === 'select' ? (
                  <select
                    value={variables[field.key] ?? ''}
                    onChange={(e) => setVariables((prev) => ({ ...prev, [field.key]: e.target.value }))}
                    className={inputClass}
                  >
                    <option value="">Select…</option>
                    {(field.options ?? []).map((opt) => (
                      <option key={opt} value={opt}>
                        {opt}
                      </option>
                    ))}
                  </select>
                ) : (
                  <input
                    type={field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : 'text'}
                    step={field.type === 'number' ? 'any' : undefined}
                    value={variables[field.key] ?? ''}
                    onChange={(e) => setVariables((prev) => ({ ...prev, [field.key]: e.target.value }))}
                    placeholder={`{{${field.key}}}`}
                    className={inputClass}
                  />
                )}
              </div>
            ))}
          </div>
        )}

        {selectedTemplate && (
          <div>
            <p className="text-sm font-medium text-slate-700">Live Message Preview</p>
            <div className="mt-1.5 rounded-xl border border-emerald-200 bg-emerald-50 p-4">
              <div className="max-w-sm whitespace-pre-wrap rounded-lg rounded-tl-none bg-white px-3 py-2 text-sm text-slate-800 shadow-sm">
                {previewText}
              </div>
            </div>
          </div>
        )}

        {sendError && (
          <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <XCircle className="h-4 w-4 flex-shrink-0" />
            {sendError}
          </div>
        )}
        {sendSuccess && (
          <div className="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
            <CheckCircle2 className="h-4 w-4 flex-shrink-0" />
            {sendSuccess}
          </div>
        )}
        {testError && (
          <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <XCircle className="h-4 w-4 flex-shrink-0" />
            {testError}
          </div>
        )}
        {testSuccess && (
          <div className="flex items-center gap-2 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-700">
            <FlaskConical className="h-4 w-4 flex-shrink-0" />
            {testSuccess}
          </div>
        )}

        <div className="flex flex-wrap items-center gap-3">
          <button
            type="submit"
            disabled={!selectedTemplate || isSending || disabled || readOnly}
            title={
              readOnly
                ? 'Action disabled: Subscription expired.'
                : disabled
                  ? 'WhatsApp is disconnected — reconnect it in WhatsApp Setup to send alerts.'
                  : undefined
            }
            className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60"
          >
            {isSending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
            {isSending ? 'Sending…' : 'Send Message'}
          </button>

          <button
            type="button"
            onClick={() => void handleTestSend()}
            disabled={!selectedTemplate || isTesting || disabled || readOnly}
            title={
              readOnly
                ? 'Action disabled: Subscription expired.'
                : disabled
                  ? 'WhatsApp is disconnected — reconnect it in WhatsApp Setup to send alerts.'
                  : registeredPhone
                    ? `Sends a live test to your registered number (${registeredPhone}).`
                    : 'No registered WhatsApp number on file for your account yet.'
            }
            className="flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 disabled:opacity-60"
          >
            {isTesting ? <Loader2 className="h-4 w-4 animate-spin" /> : <FlaskConical className="h-4 w-4" />}
            {isTesting ? 'Sending test…' : 'Test Template Message'}
          </button>
        </div>
      </form>

      <div className="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
        <div className="flex items-center gap-2 text-sm font-semibold text-slate-900">
          <Code2 className="h-4 w-4 text-indigo-600" />
          Developer API Documentation
        </div>
        {!selectedTemplate ? (
          <p className="text-sm text-slate-500">Select a template to see its API integration details.</p>
        ) : (
          <>
            <div>
              <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Endpoint</p>
              <p className="mt-1 rounded-lg bg-slate-900 px-3 py-2 font-mono text-xs text-emerald-300">
                POST /api/v1/messages/send-template
              </p>
            </div>
            <div>
              <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Headers</p>
              <p className="mt-1 rounded-lg bg-slate-900 px-3 py-2 font-mono text-xs text-slate-200">
                X-API-KEY: your_client_api_key_here
                <br />
                Content-Type: application/json
              </p>
              <p className="mt-1 text-xs text-slate-500">
                Find your key under Profile → Client API Key.
              </p>
            </div>
            <div>
              <div className="flex items-center justify-between">
                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Sample JSON Payload</p>
                <button
                  type="button"
                  onClick={() => void handleCopyPayload()}
                  className="flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-700"
                >
                  <Copy className="h-3 w-3" />
                  {copied ? 'Copied!' : 'Copy'}
                </button>
              </div>
              <pre className="mt-1 overflow-x-auto rounded-lg bg-slate-900 px-3 py-2 font-mono text-xs text-slate-200">
                {JSON.stringify(samplePayload, null, 2)}
              </pre>
              <div className="mt-2 flex flex-wrap gap-1.5">
                {selectedTemplate.variables_schema.map((field) => (
                  <span
                    key={field.key}
                    title={field.type}
                    className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ${
                      field.required
                        ? 'bg-red-50 text-red-600'
                        : 'bg-slate-100 text-slate-500'
                    }`}
                  >
                    {field.key} — {field.required ? 'required' : 'optional'} ({field.type})
                  </span>
                ))}
              </div>
            </div>
          </>
        )}
      </div>
    </div>
  );
}
