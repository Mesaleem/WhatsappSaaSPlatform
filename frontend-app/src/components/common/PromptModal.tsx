import { useState, type FormEvent } from 'react';
import { Loader2, MessageSquare, XCircle } from 'lucide-react';

/**
 * Shared single-line text-input dialog -- replaces the browser-native
 * `prompt()` popup (the plain "waphub.sahilmoney.in says ..." box) with a
 * modal styled like every other dialog in this app (ConfirmModal,
 * CreateGroupModal, RequestTemplateModal, etc.). Sibling to ConfirmModal
 * rather than a variant of it -- ConfirmModal has no input field at all,
 * and a caller here always needs to read back a typed value, so folding
 * both behaviors into one component would make its prop contract harder
 * to reason about than just having two small, focused components.
 *
 * Wired into every call site that used to call `prompt()`: RichTextEditor
 * (Insert link URL) and TemplateManagerPage (Reject reason).
 *
 * Submits on Enter (the input is wrapped in a <form>) or the primary
 * button, matching native `prompt()`'s own keyboard behavior. Cancelling
 * (the X button, the Cancel button) calls `onCancel` -- the caller decides
 * what "no value" means for its own action, exactly as it did with
 * `prompt()`'s null return.
 */
export default function PromptModal({
  title,
  message,
  label,
  placeholder,
  defaultValue = '',
  confirmLabel = 'Submit',
  cancelLabel = 'Cancel',
  isLoading = false,
  required = false,
  onSubmit,
  onCancel,
}: {
  title: string;
  message?: React.ReactNode;
  /** Optional label rendered above the input -- omit for a single-purpose prompt where `message` alone already explains what to type. */
  label?: string;
  placeholder?: string;
  defaultValue?: string;
  confirmLabel?: string;
  cancelLabel?: string;
  isLoading?: boolean;
  /** When true, the primary button stays disabled until the input is non-empty -- the one behavioral difference between an optional prompt (reject reason) and a required one (link URL). */
  required?: boolean;
  onSubmit: (value: string) => void;
  onCancel: () => void;
}) {
  const [value, setValue] = useState(defaultValue);

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    if (required && !value.trim()) return;
    onSubmit(value);
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
        <div className="flex items-center justify-between">
          <h3 className="flex items-center gap-2 text-base font-semibold text-slate-900">
            <MessageSquare className="h-4 w-4 text-indigo-500" />
            {title}
          </h3>
          <button onClick={onCancel} disabled={isLoading} className="text-slate-400 hover:text-slate-600 disabled:opacity-50">
            <XCircle className="h-5 w-5" />
          </button>
        </div>

        {message && <p className="mt-3 text-sm text-slate-600">{message}</p>}

        <form onSubmit={handleSubmit}>
          {label && <label className="mt-4 block text-xs font-medium text-slate-500">{label}</label>}
          <input
            type="text"
            autoFocus
            value={value}
            onChange={(e) => setValue(e.target.value)}
            placeholder={placeholder}
            disabled={isLoading}
            className={`w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 disabled:opacity-60 ${label ? 'mt-1.5' : 'mt-4'}`}
          />

          <div className="mt-5 flex justify-end gap-2">
            <button
              type="button"
              onClick={onCancel}
              disabled={isLoading}
              className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60"
            >
              {cancelLabel}
            </button>
            <button
              type="submit"
              disabled={isLoading || (required && !value.trim())}
              className="flex items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60"
            >
              {isLoading && <Loader2 className="h-4 w-4 animate-spin" />}
              {confirmLabel}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
