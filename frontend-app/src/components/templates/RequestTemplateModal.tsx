import { useState } from 'react';
import { AxiosError } from 'axios';
import { FileText, Loader2, XCircle } from 'lucide-react';
import templateService from '../../services/templateService';
import type { ApiErrorResponse } from '../../types/auth';
import type { TemplateHeaderType } from '../../types/templates';

function extractMessage(err: unknown, fallback: string): string {
  const axiosErr = err as AxiosError<ApiErrorResponse>;
  return axiosErr.response?.data?.message ?? fallback;
}

const inputClass =
  'mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100';

/** Media Templates (QR/Baileys-only) -- same options as TemplateManagerPage.tsx's TemplateModal, for consistency. */
const HEADER_TYPE_OPTIONS: { value: TemplateHeaderType; label: string; hint: string }[] = [
  { value: 'text', label: 'Text', hint: 'Plain message, no attachment.' },
  { value: 'image', label: 'Image', hint: 'e.g. a photo.' },
  { value: 'document', label: 'Document', hint: 'e.g. a PDF bill.' },
];

/**
 * Tiered Template Approval Workflow — "Request a Template" modal. A
 * plain Client Admin/User holds `send-messages` but never
 * `manage-templates` (see RolePermissionSeeder), so Template Manager
 * itself is correctly invisible to them — the only self-service path
 * left is MessageTemplateController::submitRequest(), which already
 * existed server-side (routed to pending_agent_review/pending_admin_review
 * purely by the caller's own account.agent_id — see TemplateService::
 * resolveCreationStatus()) but had no frontend form calling it until now.
 * Deliberately does NOT expose the Variable Configurator Panel
 * (variables_schema) here — that stays a reviewer-side step (the
 * Agent/Super Admin fills it in via Template Manager's own edit form
 * before approving), keeping this request form to the two things a
 * Client actually needs to describe: what the message should say, and
 * roughly for what kind of business.
 */
export default function RequestTemplateModal({
  onClose,
  onSubmitted,
}: {
  onClose: () => void;
  onSubmitted: (message: string) => void;
}) {
  const [title, setTitle] = useState('');
  const [industryType, setIndustryType] = useState('');
  const [templateBody, setTemplateBody] = useState('');
  const [headerType, setHeaderType] = useState<TemplateHeaderType>('text');
  const [headerMediaUrl, setHeaderMediaUrl] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const isValid = title.trim() !== '' && templateBody.trim() !== '';

  const handleSubmit = async () => {
    if (!isValid) {
      setError('Give the template a title and write out the message text.');
      return;
    }
    setIsSubmitting(true);
    setError(null);
    try {
      const result = await templateService.submitRequest({
        title: title.trim(),
        industry_type: industryType.trim() || undefined,
        template_body: templateBody.trim(),
        header_type: headerType,
        header_media_url: headerType === 'text' ? undefined : headerMediaUrl.trim() || undefined,
      });
      onSubmitted(result.message);
    } catch (err) {
      setError(extractMessage(err, 'Could not submit this template request. Please try again.'));
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div className="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
        <div className="flex items-center justify-between">
          <h3 className="flex items-center gap-2 text-base font-semibold text-slate-900">
            <FileText className="h-4 w-4 text-indigo-600" />
            Request a Template
          </h3>
          <button onClick={onClose} className="text-slate-400 hover:text-slate-600" disabled={isSubmitting}>
            <XCircle className="h-5 w-5" />
          </button>
        </div>
        <p className="mt-1 text-sm text-slate-500">
          This will be reviewed and approved before you can use it to send alerts.
        </p>

        <div className="mt-4 space-y-4">
          <div>
            <label htmlFor="template-request-title" className="text-sm font-medium text-slate-700">
              Title <span className="text-red-500">*</span>
            </label>
            <input
              id="template-request-title"
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              maxLength={255}
              placeholder="e.g. Payment Received Confirmation"
              className={inputClass}
              autoFocus
            />
          </div>
          <div>
            <label htmlFor="template-request-industry" className="text-sm font-medium text-slate-700">
              Industry / Use Case <span className="font-normal text-slate-400">(optional)</span>
            </label>
            <input
              id="template-request-industry"
              value={industryType}
              onChange={(e) => setIndustryType(e.target.value)}
              maxLength={100}
              placeholder="e.g. E-commerce, Real Estate, Clinic"
              className={inputClass}
            />
          </div>
          <div>
            <label htmlFor="template-request-body" className="text-sm font-medium text-slate-700">
              Message Text <span className="text-red-500">*</span>
            </label>
            <textarea
              id="template-request-body"
              value={templateBody}
              onChange={(e) => setTemplateBody(e.target.value)}
              rows={5}
              maxLength={4000}
              placeholder="Hi {{customer_name}}, we've received your payment of {{amount}}. Thank you!"
              className={inputClass}
            />
            <p className="mt-1 text-xs text-slate-500">
              Use <code className="rounded bg-slate-100 px-1 py-0.5">{'{{variable_name}}'}</code> for anything that
              changes per message, like a customer name or order ID.
            </p>
          </div>

          <div>
            <label className="text-sm font-medium text-slate-700">Attach a file? <span className="font-normal text-slate-400">(optional)</span></label>
            <div className="mt-1 grid grid-cols-1 gap-2 sm:grid-cols-3">
              {HEADER_TYPE_OPTIONS.map((opt) => (
                <button
                  type="button"
                  key={opt.value}
                  onClick={() => setHeaderType(opt.value)}
                  className={`rounded-lg border px-3 py-2 text-left text-sm ${
                    headerType === opt.value
                      ? 'border-indigo-500 bg-indigo-50 text-indigo-700'
                      : 'border-slate-300 text-slate-700 hover:bg-slate-50'
                  }`}
                >
                  <div className="font-medium">{opt.label}</div>
                  <div className="text-xs text-slate-500">{opt.hint}</div>
                </button>
              ))}
            </div>

            {headerType !== 'text' && (
              <div className="mt-3">
                <label htmlFor="template-request-media-url" className="text-sm font-medium text-slate-700">
                  Media URL
                  <span className="ml-1 text-xs font-normal text-slate-400">
                    (optional default — a direct link to the {headerType === 'image' ? 'image' : 'PDF/document'};
                    your message text above becomes the caption. Can be left blank, or overridden per-send via
                    the API's media_url field — the message still sends as plain text if no URL ends up
                    available.)
                  </span>
                </label>
                <input
                  id="template-request-media-url"
                  value={headerMediaUrl}
                  onChange={(e) => setHeaderMediaUrl(e.target.value)}
                  placeholder={
                    headerType === 'image'
                      ? 'https://example.com/files/photo.jpg'
                      : 'https://example.com/files/bill.pdf'
                  }
                  className={inputClass}
                />
              </div>
            )}
          </div>
        </div>

        {error && (
          <div className="mt-4 flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
            <XCircle className="h-4 w-4 flex-shrink-0" />
            {error}
          </div>
        )}

        <div className="mt-5 flex justify-end gap-2">
          <button
            onClick={onClose}
            disabled={isSubmitting}
            className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60"
          >
            Cancel
          </button>
          <button
            onClick={() => void handleSubmit()}
            disabled={isSubmitting || !isValid}
            className="flex items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60"
          >
            {isSubmitting ? <Loader2 className="h-4 w-4 animate-spin" /> : <FileText className="h-4 w-4" />}
            Submit Request
          </button>
        </div>
      </div>
    </div>
  );
}
