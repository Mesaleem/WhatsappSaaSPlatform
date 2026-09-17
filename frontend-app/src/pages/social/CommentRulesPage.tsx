import { useCallback, useEffect, useState } from 'react';
import { AlertCircle, Building2, Loader2, Pencil, Plus, Sparkle, Trash2, ToggleLeft, ToggleRight, X } from 'lucide-react';
import { useAuth } from '../../core/context/AuthContext';
import { useTenant } from '../../core/context/TenantContext';
import commentRulesService from '../../services/commentRulesService';
import { Card, TableCard, inputClass } from '../../components/common/Card';
import ConfirmModal from '../../components/common/ConfirmModal';
import { extractErrorMessage } from '../../utils/apiError';
import { indigo, activeGradient } from '../../theme/signalIndigo';
import type { CommentAutomationRule, CommentAutomationRulePayload } from '../../types/commentRules';

const EMPTY_FORM: CommentAutomationRulePayload = {
  keyword: '',
  public_reply_template: '',
  private_dm_template: '',
  is_active: true,
};

/** Truncates a template preview so the table row stays a fixed, scannable height. */
function preview(text: string, max = 60): string {
  const trimmed = text.trim();
  if (trimmed.length <= max) return trimmed;
  return `${trimmed.slice(0, max)}…`;
}

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 4.
 * Create/edit modal for a single comment-automation rule. The keyword
 * field accepts the literal wildcard `*` (CommentAutomationRule::
 * WILDCARD_KEYWORD on the backend) as a documented, lowest-priority
 * catch-all — this is surfaced with inline help text rather than a
 * separate "match everything" checkbox, since the backend model itself
 * treats it as an ordinary keyword value.
 */
function RuleFormModal({
  initial,
  onClose,
  onSaved,
}: {
  initial: CommentAutomationRule | null;
  onClose: () => void;
  onSaved: () => void;
}) {
  const isEdit = initial !== null;
  const [form, setForm] = useState<CommentAutomationRulePayload>(
    initial
      ? {
          keyword: initial.keyword,
          public_reply_template: initial.public_reply_template,
          private_dm_template: initial.private_dm_template,
          is_active: initial.is_active,
        }
      : EMPTY_FORM,
  );
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const update = (patch: Partial<CommentAutomationRulePayload>) => setForm((prev) => ({ ...prev, ...patch }));

  const validate = (): string | null => {
    if (!form.keyword.trim()) return 'Keyword is required (use * to match every comment).';
    if (!form.public_reply_template.trim()) return 'Public reply template is required.';
    if (!form.private_dm_template.trim()) return 'Private message template is required.';
    return null;
  };

  const handleSave = () => {
    const validationError = validate();
    if (validationError) {
      setError(validationError);
      return;
    }

    const payload: CommentAutomationRulePayload = {
      keyword: form.keyword.trim(),
      public_reply_template: form.public_reply_template.trim(),
      private_dm_template: form.private_dm_template.trim(),
      is_active: form.is_active,
    };

    setError(null);
    setIsSubmitting(true);
    const request = isEdit ? commentRulesService.update(initial.id, payload) : commentRulesService.create(payload);
    request
      .then(() => onSaved())
      .catch((err: unknown) => setError(extractErrorMessage(err, 'Failed to save the rule.')))
      .finally(() => setIsSubmitting(false));
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl">
        <div className="flex items-center justify-between">
          <h2 className="font-display text-base font-bold" style={{ color: indigo.ink }}>
            {isEdit ? 'Edit Comment Rule' : 'New Comment Rule'}
          </h2>
          <button onClick={onClose} className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Close">
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className="mt-4 space-y-4">
          {error && (
            <div className="flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
              <AlertCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
              {error}
            </div>
          )}

          <label className="block text-sm font-medium text-slate-700">
            Keyword <span className="text-red-500">*</span>
            <input
              type="text"
              className={inputClass}
              value={form.keyword}
              onChange={(e) => update({ keyword: e.target.value })}
              placeholder="price"
            />
            <span className="mt-1 block text-xs" style={{ color: indigo.muted }}>
              Matches when a comment contains this word. Use <code className="rounded bg-slate-100 px-1">*</code> as a
              catch-all for any comment that no other rule matched.
            </span>
          </label>

          <label className="block text-sm font-medium text-slate-700">
            Public Reply Template <span className="text-red-500">*</span>
            <textarea
              className={inputClass}
              rows={2}
              value={form.public_reply_template}
              onChange={(e) => update({ public_reply_template: e.target.value })}
              placeholder="Thanks for asking! We've sent you the details in a private message. 🙌"
            />
          </label>

          <label className="block text-sm font-medium text-slate-700">
            Private Message Template <span className="text-red-500">*</span>
            <textarea
              className={inputClass}
              rows={3}
              value={form.private_dm_template}
              onChange={(e) => update({ private_dm_template: e.target.value })}
              placeholder="Hi! Here's our current pricing: …"
            />
          </label>

          <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
            <input
              type="checkbox"
              checked={form.is_active}
              onChange={(e) => update({ is_active: e.target.checked })}
              className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
            />
            Active
          </label>
        </div>

        <div className="mt-6 flex justify-end gap-2">
          <button
            type="button"
            onClick={onClose}
            disabled={isSubmitting}
            className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
          >
            Cancel
          </button>
          <button
            type="button"
            onClick={handleSave}
            disabled={isSubmitting}
            className="flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
            style={{ background: activeGradient }}
          >
            {isSubmitting && <Loader2 className="h-4 w-4 animate-spin" />}
            {isEdit ? 'Save Changes' : 'Create Rule'}
          </button>
        </div>
      </div>
    </div>
  );
}

export default function CommentRulesPage() {
  const { isSuperAdmin } = useAuth();
  const { selectedAccountId } = useTenant();
  const superAdmin = isSuperAdmin();
  const noTenantSelected = superAdmin && selectedAccountId === null;

  const [rules, setRules] = useState<CommentAutomationRule[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [pageError, setPageError] = useState<string | null>(null);
  const [modalRule, setModalRule] = useState<CommentAutomationRule | null | 'new'>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [toast, setToast] = useState<string | null>(null);

  const showToast = useCallback((message: string) => {
    setToast(message);
    setTimeout(() => setToast(null), 4000);
  }, []);

  const loadRules = useCallback(() => {
    if (noTenantSelected) {
      setRules([]);
      setIsLoading(false);
      return;
    }

    setIsLoading(true);
    setPageError(null);
    commentRulesService
      .list()
      .then(setRules)
      .catch((err: unknown) => setPageError(extractErrorMessage(err, 'Failed to load comment rules.')))
      .finally(() => setIsLoading(false));
  }, [noTenantSelected]);

  useEffect(() => {
    loadRules();
  }, [loadRules, selectedAccountId]);

  const handleSaved = () => {
    setModalRule(null);
    showToast(modalRule === 'new' ? 'Rule created.' : 'Rule updated.');
    loadRules();
  };

  const toggleActive = (rule: CommentAutomationRule) => {
    setBusyId(rule.id);
    commentRulesService
      .update(rule.id, { is_active: !rule.is_active })
      .then(() => {
        setRules((prev) => prev.map((r) => (r.id === rule.id ? { ...r, is_active: !r.is_active } : r)));
      })
      .catch((err: unknown) => showToast(extractErrorMessage(err, 'Failed to update the rule.')))
      .finally(() => setBusyId(null));
  };

  const [pendingDelete, setPendingDelete] = useState<CommentAutomationRule | null>(null);

  const handleDelete = (rule: CommentAutomationRule) => {
    setPendingDelete(rule);
  };

  const confirmDelete = () => {
    if (!pendingDelete) return;
    const rule = pendingDelete;
    setBusyId(rule.id);
    commentRulesService
      .remove(rule.id)
      .then(() => {
        setRules((prev) => prev.filter((r) => r.id !== rule.id));
        setPendingDelete(null);
        showToast('Rule deleted.');
      })
      .catch((err: unknown) => showToast(extractErrorMessage(err, 'Failed to delete the rule.')))
      .finally(() => setBusyId(null));
  };

  return (
    <div className="p-6">
      <div className="w-full">
        <div className="mb-6 flex items-center justify-between">
          <div>
            <h1 className="font-display text-lg font-bold" style={{ color: indigo.ink }}>
              Comment Auto-Responder Rules
            </h1>
            <p className="mt-1 text-sm" style={{ color: indigo.muted }}>
              Automatically reply to ad and post comments by keyword, and follow up with a private message.
            </p>
          </div>
          <button
            onClick={() => setModalRule('new')}
            disabled={noTenantSelected}
            title={noTenantSelected ? 'Select a client above first' : undefined}
            className="flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
            style={{ background: activeGradient }}
          >
            <Plus className="h-4 w-4" />
            Add Rule
          </button>
        </div>

        {noTenantSelected ? (
          <div className="flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm" style={{ color: indigo.muted }}>
            <Building2 className="mt-0.5 h-4 w-4 flex-shrink-0" />
            Select a client from the switcher at the top of the page to manage their comment rules.
          </div>
        ) : (
          <>
            {pageError && (
              <div className="mb-4 flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                <AlertCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
                {pageError}
              </div>
            )}

            {!isLoading && rules.length === 0 ? (
              <Card>
                <div className="flex flex-col items-center gap-2 py-6 text-center">
                  <Sparkle className="h-6 w-6" style={{ color: indigo.muted }} />
                  <p className="text-sm" style={{ color: indigo.muted }}>
                    No comment rules yet. Add one to start auto-replying to ad comments.
                  </p>
                </div>
              </Card>
            ) : (
              <TableCard>
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                  <thead className="bg-slate-50">
                    <tr>
                      <th className="px-4 py-3 text-left font-medium text-slate-600">Keyword</th>
                      <th className="px-4 py-3 text-left font-medium text-slate-600">Public Reply</th>
                      <th className="px-4 py-3 text-left font-medium text-slate-600">Private Message</th>
                      <th className="px-4 py-3 text-left font-medium text-slate-600">Status</th>
                      <th className="px-4 py-3 text-right font-medium text-slate-600">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {isLoading ? (
                      <tr>
                        <td colSpan={5} className="px-4 py-10 text-center">
                          <Loader2 className="mx-auto h-5 w-5 animate-spin" style={{ color: indigo.muted }} />
                        </td>
                      </tr>
                    ) : (
                      rules.map((rule) => (
                        <tr key={rule.id}>
                          <td className="px-4 py-3">
                            <code className="rounded bg-slate-100 px-1.5 py-0.5 text-xs font-medium text-slate-800">
                              {rule.keyword}
                            </code>
                          </td>
                          <td className="max-w-[240px] px-4 py-3 text-slate-700">{preview(rule.public_reply_template)}</td>
                          <td className="max-w-[240px] px-4 py-3 text-slate-700">{preview(rule.private_dm_template)}</td>
                          <td className="px-4 py-3">
                            <button
                              type="button"
                              onClick={() => toggleActive(rule)}
                              disabled={busyId === rule.id}
                              className={`inline-flex items-center gap-1.5 text-xs font-semibold disabled:opacity-60 ${
                                rule.is_active ? 'text-emerald-600' : 'text-slate-400'
                              }`}
                            >
                              {rule.is_active ? <ToggleRight className="h-4 w-4" /> : <ToggleLeft className="h-4 w-4" />}
                              {rule.is_active ? 'Active' : 'Inactive'}
                            </button>
                          </td>
                          <td className="px-4 py-3">
                            <div className="flex items-center justify-end gap-2">
                              <button
                                type="button"
                                onClick={() => setModalRule(rule)}
                                disabled={busyId === rule.id}
                                className="rounded-lg border border-slate-300 p-1.5 text-slate-600 hover:bg-slate-50 disabled:opacity-60"
                                aria-label="Edit rule"
                              >
                                <Pencil className="h-3.5 w-3.5" />
                              </button>
                              <button
                                type="button"
                                onClick={() => handleDelete(rule)}
                                disabled={busyId === rule.id}
                                className="rounded-lg border border-red-200 p-1.5 text-red-600 hover:bg-red-50 disabled:opacity-60"
                                aria-label="Delete rule"
                              >
                                <Trash2 className="h-3.5 w-3.5" />
                              </button>
                            </div>
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </TableCard>
            )}
          </>
        )}
      </div>

      {modalRule !== null && (
        <RuleFormModal
          initial={modalRule === 'new' ? null : modalRule}
          onClose={() => setModalRule(null)}
          onSaved={handleSaved}
        />
      )}

      {pendingDelete && (
        <ConfirmModal
          title="Delete rule"
          message={`Delete the "${pendingDelete.keyword}" rule? This cannot be undone.`}
          confirmLabel="Delete"
          variant="danger"
          isLoading={busyId === pendingDelete.id}
          onConfirm={confirmDelete}
          onCancel={() => setPendingDelete(null)}
        />
      )}

      {toast && (
        <div className="fixed bottom-6 right-6 z-50 rounded-lg bg-slate-900 px-4 py-3 text-sm font-medium text-white shadow-lg">
          {toast}
        </div>
      )}
    </div>
  );
}
