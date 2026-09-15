import { AlertTriangle, Loader2, XCircle } from 'lucide-react';

/**
 * Shared confirmation dialog — replaces the browser-native `confirm()`
 * popup (the plain "localhost:5173 says ..." box) with a modal styled
 * like every other dialog in this app (CreateGroupModal,
 * RequestTemplateModal, etc.): white rounded card, XCircle close button,
 * Cancel + a primary action button. Wired into every page that used to
 * call `confirm()`: ContactGroupsPage (Delete/Recreate), AccountsPage
 * (Deactivate/Reactivate), TemplateManagerPage (Delete), ChatbotPage
 * (Delete rule), DeveloperPage (Revoke key / Regenerate secret / Delete
 * webhook), UsersPage (Remove team member), and ProfileModal
 * (Generate/Regenerate API key) — one component instead of eight
 * one-off native dialogs.
 *
 * `message` accepts a ReactNode (not just a string) so a caller can bold
 * or otherwise emphasize part of the warning the way the two Contact
 * Groups confirmations already do (the group name, the "NOT affected"
 * qualifier) — plain strings work unchanged since ReactNode allows one.
 */
export default function ConfirmModal({
  title,
  message,
  confirmLabel = 'Confirm',
  cancelLabel = 'Cancel',
  variant = 'danger',
  isLoading = false,
  onConfirm,
  onCancel,
}: {
  title: string;
  message: React.ReactNode;
  confirmLabel?: string;
  cancelLabel?: string;
  /** 'danger' (red, default) for a destructive action like delete; 'default' (indigo) for a non-destructive one like recreate. */
  variant?: 'danger' | 'default';
  isLoading?: boolean;
  onConfirm: () => void;
  onCancel: () => void;
}) {
  const confirmButtonClass =
    variant === 'danger'
      ? 'bg-red-600 hover:bg-red-700'
      : 'bg-indigo-600 hover:bg-indigo-700';

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
        <div className="flex items-center justify-between">
          <h3 className="flex items-center gap-2 text-base font-semibold text-slate-900">
            <AlertTriangle className={`h-4 w-4 ${variant === 'danger' ? 'text-red-500' : 'text-amber-500'}`} />
            {title}
          </h3>
          <button onClick={onCancel} disabled={isLoading} className="text-slate-400 hover:text-slate-600 disabled:opacity-50">
            <XCircle className="h-5 w-5" />
          </button>
        </div>

        <p className="mt-3 text-sm text-slate-600">{message}</p>

        <div className="mt-5 flex justify-end gap-2">
          <button
            onClick={onCancel}
            disabled={isLoading}
            className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60"
          >
            {cancelLabel}
          </button>
          <button
            onClick={onConfirm}
            disabled={isLoading}
            className={`flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold text-white shadow-sm disabled:opacity-60 ${confirmButtonClass}`}
          >
            {isLoading && <Loader2 className="h-4 w-4 animate-spin" />}
            {confirmLabel}
          </button>
        </div>
      </div>
    </div>
  );
}
