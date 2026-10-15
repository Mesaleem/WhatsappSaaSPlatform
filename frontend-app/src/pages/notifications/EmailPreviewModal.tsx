import { useState } from 'react';
import { AlertTriangle, CheckCircle2, Monitor, Smartphone, X, XCircle } from 'lucide-react';
import { findInlineCssIssues, renderPreview } from './emailPreview';

/**
 * Full-size "how will this email look" preview, like pasting HTML into an
 * online HTML viewer: the HTML is drawn in a sandboxed iframe (no scripts,
 * no access to this app) with sample values in the {{variables}}, at
 * desktop width or phone width. Below it, the inline-CSS check lists what an
 * email client would drop.
 */
export default function EmailPreviewModal({
  subject,
  body,
  onClose,
}: {
  subject: string;
  body: string;
  onClose: () => void;
}) {
  const [device, setDevice] = useState<'desktop' | 'mobile'>('desktop');
  const issues = findInlineCssIssues(body);

  return (
    <div
      className="fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/60 p-4"
      role="dialog"
      aria-modal="true"
      aria-label="Email preview"
    >
      <div className="flex max-h-[94vh] w-full max-w-5xl flex-col overflow-hidden rounded-xl bg-white shadow-xl">
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-3">
          <div className="min-w-0">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Email preview — sample values</p>
            <p className="truncate text-sm font-semibold text-slate-900" title={renderPreview(subject)}>
              {renderPreview(subject) || <span className="text-slate-400">(no subject)</span>}
            </p>
          </div>
          <div className="flex items-center gap-2">
            <div className="flex overflow-hidden rounded-lg border border-slate-300 text-xs font-medium">
              <button
                type="button"
                onClick={() => setDevice('desktop')}
                aria-pressed={device === 'desktop'}
                className={`flex items-center gap-1.5 px-3 py-1.5 ${device === 'desktop' ? 'bg-emerald-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50'}`}
              >
                <Monitor className="h-3.5 w-3.5" /> Desktop
              </button>
              <button
                type="button"
                onClick={() => setDevice('mobile')}
                aria-pressed={device === 'mobile'}
                className={`flex items-center gap-1.5 px-3 py-1.5 ${device === 'mobile' ? 'bg-emerald-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50'}`}
              >
                <Smartphone className="h-3.5 w-3.5" /> Mobile
              </button>
            </div>
            <button
              type="button"
              onClick={onClose}
              aria-label="Close preview"
              className="rounded-md p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
            >
              <X className="h-5 w-5" />
            </button>
          </div>
        </div>

        <div className="flex-1 overflow-auto bg-slate-100 p-4">
          <div className={`mx-auto transition-all ${device === 'mobile' ? 'w-[375px] max-w-full' : 'w-full'}`}>
            <iframe
              title="Email preview"
              sandbox=""
              srcDoc={renderPreview(body) || '<p style="color:#94a3b8;font-family:sans-serif;padding:16px">Nothing to preview yet.</p>'}
              className="h-[62vh] w-full rounded-lg border border-slate-200 bg-white"
            />
          </div>
        </div>

        <div className="border-t border-slate-200 px-5 py-3">
          <InlineCssReport issues={issues} />
        </div>
      </div>
    </div>
  );
}

export function InlineCssReport({ issues }: { issues: ReturnType<typeof findInlineCssIssues> }) {
  if (issues.length === 0) {
    return (
      <p className="flex items-center gap-2 text-xs text-emerald-700">
        <CheckCircle2 className="h-4 w-4 flex-shrink-0" />
        Inline CSS only — this HTML is safe for email clients.
      </p>
    );
  }

  return (
    <ul className="space-y-1">
      {issues.map((issue) => (
        <li
          key={issue.message}
          className={`flex items-start gap-2 text-xs ${issue.level === 'error' ? 'text-red-700' : 'text-amber-700'}`}
        >
          {issue.level === 'error' ? (
            <XCircle className="mt-0.5 h-3.5 w-3.5 flex-shrink-0" />
          ) : (
            <AlertTriangle className="mt-0.5 h-3.5 w-3.5 flex-shrink-0" />
          )}
          {issue.message}
        </li>
      ))}
    </ul>
  );
}
