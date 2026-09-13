import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { AlertTriangle, CheckCircle2, Code2, Copy, Loader2, Send, Sparkles, Users, XCircle } from 'lucide-react';
import { useAuth } from '../../core/context/AuthContext';
import { useTenant } from '../../core/context/TenantContext';
import contactGroupsService from '../../services/contactGroupsService';
import templateService from '../../services/templateService';
import whatsappService from '../../services/whatsappService';
import type { ContactGroup } from '../../types/contactGroup';
import type { AvailableTemplate } from '../../types/templates';
import { extractErrorMessage } from '../../utils/apiError';

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

const inputClass =
  'mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100';

/**
 * Client Admin UI & Single Alert Removal: the legacy manual/static Single
 * Alert form AND the CSV Bulk Upload option are both gone — the Approved
 * Template Form (TemplateMessageTab below) is now the ONLY alert
 * interface, with one Send button. Template testing for the Super Admin
 * now lives entirely in Template Manager's "Send Template" action (fired
 * through the Super Admin's own scanned device, see AdminDeviceSettingsPage) —
 * "Send Alert" itself is also hidden from Super Admin's sidebar
 * (AppLayout's hiddenForSuperAdmin), so this page is effectively
 * Client-Admin-only now.
 */
export default function SendAlertPage() {
  const { isReadOnly } = useAuth();

  // Super Admin Multi-Tenant Scoping: re-check WhatsApp status whenever the
  // Header's client selector changes — a status fetched for one tenant (or
  // for no tenant at all) must not linger once Super Admin switches to
  // another.
  const { selectedAccountId } = useTenant();

  // Send Alert Disconnect Guard: fetched on mount AND on tenant switch.
  // `null` = still loading / no tenant selected (never blocks the button —
  // the backend's own 422 guard is the real safety net; this is UX only).
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
          Notify a customer over WhatsApp that their payment was received, using an approved template.
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

        <div className="mt-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
          <TemplateMessageTab disabled={isDisconnected} readOnly={readOnly} />
        </div>
      </div>
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
function TemplateMessageTab({ disabled, readOnly }: { disabled: boolean; readOnly: boolean }) {
  const [templates, setTemplates] = useState<AvailableTemplate[]>([]);
  const [isLoadingTemplates, setIsLoadingTemplates] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);

  const [templateId, setTemplateId] = useState<number | ''>('');

  // Group Messaging — Send Alert screen widening: recipientType decides
  // whether the form sends to one phone number (existing behavior,
  // unchanged) or to one-or-more Contact Groups (new, via the internal
  // POST /groups/{id}/send-template action). Groups are fetched once on
  // mount alongside templates — the list is short enough per tenant that
  // lazy-loading only after the user picks "Group" would just add an
  // extra loading flicker for no real benefit.
  const [recipientType, setRecipientType] = useState<'individual' | 'group'>('individual');
  const [groups, setGroups] = useState<ContactGroup[]>([]);
  const [isLoadingGroups, setIsLoadingGroups] = useState(true);
  const [groupsLoadError, setGroupsLoadError] = useState<string | null>(null);
  const [selectedGroupIds, setSelectedGroupIds] = useState<number[]>([]);

  const [recipientPhone, setRecipientPhone] = useState('');
  const [variables, setVariables] = useState<Record<string, string>>({});
  const [copied, setCopied] = useState(false);

  const [isSending, setIsSending] = useState(false);
  const [sendError, setSendError] = useState<string | null>(null);
  const [sendSuccess, setSendSuccess] = useState<string | null>(null);

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

  useEffect(() => {
    let cancelled = false;
    contactGroupsService
      .list()
      .then((list) => {
        if (!cancelled) setGroups(list);
      })
      .catch((err) => {
        // Non-fatal here — a tenant without the contact_groups module
        // enabled (or with none created yet) must still be able to send
        // individually; this only actually blocks anything once the user
        // picks "Group" below (see the submit button's disabled check).
        if (!cancelled) setGroupsLoadError(extractErrorMessage(err, 'Failed to load contact groups.'));
      })
      .finally(() => {
        if (!cancelled) setIsLoadingGroups(false);
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

  const sampleVariables = useMemo(() => {
    if (!selectedTemplate) return {};
    return Object.fromEntries(
      selectedTemplate.variables_schema.map((f) => {
        if (variables[f.key]) return [f.key, variables[f.key]];
        // Sample payload placeholder: `<key>` for a required field (the
        // caller must supply a real value), or '' for optional — makes
        // required-vs-optional visible in the Developer API doc box's
        // JSON, per the spec's "showing which fields are required vs
        // optional" ask, without needing separate prose next to the code.
        return [f.key, f.required ? `<${f.key}>` : ''];
      }),
    );
  }, [selectedTemplate, variables]);

  const samplePayload = useMemo(() => {
    if (!selectedTemplate) return null;
    if (recipientType === 'group') {
      // Group Messaging widening: mirrors POST /api/v1/send-message
      // (recipient_type: "group") — the only external endpoint that can
      // trigger a group send; distinct from the individual endpoint's
      // payload below. Shows one representative group_id (the first
      // selected, or a placeholder when none is selected yet) — sending
      // to several groups from outside the platform means calling this
      // endpoint once per group_id, exactly as this screen itself does
      // internally (see handleSubmit's group branch).
      return {
        template_id: selectedTemplate.id,
        recipient_type: 'group',
        group_id: selectedGroupIds[0] ?? '<group_id>',
        variables: sampleVariables,
      };
    }
    return {
      template_id: selectedTemplate.id,
      recipient_phone: recipientPhone || '919876543210',
      variables: sampleVariables,
    };
  }, [selectedTemplate, recipientType, recipientPhone, selectedGroupIds, sampleVariables]);

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

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    setSendError(null);
    setSendSuccess(null);

    if (!selectedTemplate) {
      setSendError('Select a template first.');
      return;
    }
    if (recipientType === 'individual' && !recipientPhone.trim()) {
      setSendError('Recipient phone is required.');
      return;
    }
    if (recipientType === 'group' && selectedGroupIds.length === 0) {
      setSendError('Select at least one group.');
      return;
    }

    // Client-side mirror of SendTemplateMessageRequest's dynamic rules —
    // required-ness and basic type shape per field, so an invalid
    // submission is caught before a round trip, not just after a 422.
    // Shared by both recipient types — a template's variables_schema is
    // the same regardless of who it's being sent to.
    const missingRequired = selectedTemplate.variables_schema.filter(
      (f) => f.required && !variables[f.key]?.trim(),
    );
    if (missingRequired.length > 0) {
      setSendError(`Fill in: ${missingRequired.map((f) => f.label || f.key).join(', ')}`);
      return;
    }
    const badType = selectedTemplate.variables_schema.find((f) => {
      const value = variables[f.key]?.trim();
      if (!value) return false; // optional & blank — nothing to type-check
      if (f.type === 'number') return Number.isNaN(Number(value));
      if (f.type === 'select') return !(f.options ?? []).includes(value);
      return false;
    });
    if (badType) {
      setSendError(
        badType.type === 'select'
          ? `"${badType.label || badType.key}" must be one of the allowed options.`
          : `"${badType.label || badType.key}" must be a number.`,
      );
      return;
    }

    setIsSending(true);
    try {
      if (recipientType === 'group') {
        // One call per selected group — GroupMessageDispatcher (and the
        // external /v1/send-message endpoint it also backs) only ever
        // accepts one group_id per request; there is no batch-send
        // endpoint. Promise.allSettled so one group failing (e.g. a
        // native group still mid-sync) doesn't stop the others sending.
        const results = await Promise.allSettled(
          selectedGroupIds.map((groupId) =>
            contactGroupsService.sendTemplate(groupId, { template_id: selectedTemplate.id, variables }),
          ),
        );
        const groupName = (id: number) => groups.find((g) => g.id === id)?.name ?? `#${id}`;
        const failures = results
          .map((r, i) => ({ r, groupId: selectedGroupIds[i] }))
          .filter((x): x is { r: PromiseRejectedResult; groupId: number } => x.r.status === 'rejected');

        if (failures.length === 0) {
          setSendSuccess(`Queued for ${results.length} group${results.length === 1 ? '' : 's'}.`);
          setSelectedGroupIds([]);
          setVariables(Object.fromEntries(selectedTemplate.variables_schema.map((f) => [f.key, ''])));
        } else if (failures.length === results.length) {
          setSendError(extractErrorMessage(failures[0].r.reason, 'Could not queue this group dispatch.'));
        } else {
          // Partial failure: leave the failed groups selected so the
          // user can just hit Send again for those, and name them
          // rather than only counting them.
          const succeeded = results.length - failures.length;
          const failedNames = failures.map((f) => groupName(f.groupId)).join(', ');
          setSendSuccess(`Queued for ${succeeded} of ${results.length} group(s). Failed: ${failedNames}.`);
          setSelectedGroupIds(failures.map((f) => f.groupId));
        }
      } else {
        await templateService.sendTemplateMessage({
          template_id: selectedTemplate.id,
          recipient_phone: recipientPhone.trim(),
          variables,
        });
        setSendSuccess('Message sent.');
        setRecipientPhone('');
        setVariables(Object.fromEntries(selectedTemplate.variables_schema.map((f) => [f.key, ''])));
      }
    } catch (err) {
      setSendError(extractErrorMessage(err, 'Could not send this message.'));
    } finally {
      setIsSending(false);
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
          <label className="text-sm font-medium text-slate-700">Template <span className="text-red-500">*</span></label>
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
          <label className="text-sm font-medium text-slate-700">Send To</label>
          <div className="mt-1.5 flex gap-2">
            <button
              type="button"
              onClick={() => setRecipientType('individual')}
              className={`flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition ${
                recipientType === 'individual'
                  ? 'border-indigo-600 bg-indigo-50 text-indigo-700'
                  : 'border-slate-300 text-slate-600 hover:bg-slate-50'
              }`}
            >
              <Send className="h-3.5 w-3.5" />
              Individual
            </button>
            <button
              type="button"
              onClick={() => setRecipientType('group')}
              className={`flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition ${
                recipientType === 'group'
                  ? 'border-indigo-600 bg-indigo-50 text-indigo-700'
                  : 'border-slate-300 text-slate-600 hover:bg-slate-50'
              }`}
            >
              <Users className="h-3.5 w-3.5" />
              Group
            </button>
          </div>
        </div>

        {recipientType === 'individual' ? (
          <div>
            <label className="text-sm font-medium text-slate-700">Recipient Phone <span className="text-red-500">*</span></label>
            <input
              type="text"
              value={recipientPhone}
              onChange={(e) => setRecipientPhone(e.target.value)}
              placeholder="919876543210"
              className={inputClass}
            />
          </div>
        ) : (
          <div>
            <label className="text-sm font-medium text-slate-700">
              Contact Group(s) <span className="text-red-500">*</span>
              <span className="ml-1 text-xs font-normal text-slate-400">(select one or more)</span>
            </label>
            {isLoadingGroups ? (
              <div className="mt-1.5 flex items-center gap-2 text-sm text-slate-500">
                <Loader2 className="h-4 w-4 animate-spin" />
                Loading groups…
              </div>
            ) : groupsLoadError ? (
              <p className="mt-1.5 text-sm text-red-600">{groupsLoadError}</p>
            ) : groups.length === 0 ? (
              <p className="mt-1.5 text-sm text-slate-500">
                No contact groups yet. Create one in{' '}
                <Link to="/contact-groups" className="font-medium text-indigo-600 hover:text-indigo-700">
                  Contact Groups
                </Link>
                .
              </p>
            ) : (
              <div className="mt-1.5 max-h-40 space-y-1 overflow-y-auto rounded-lg border border-slate-300 p-2">
                {groups.map((group) => (
                  <label key={group.id} className="flex items-center gap-2 rounded px-1.5 py-1 text-sm hover:bg-slate-50">
                    <input
                      type="checkbox"
                      checked={selectedGroupIds.includes(group.id)}
                      onChange={(e) =>
                        setSelectedGroupIds((prev) =>
                          e.target.checked ? [...prev, group.id] : prev.filter((gid) => gid !== group.id),
                        )
                      }
                      className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                    />
                    <span className="text-slate-700">{group.name}</span>
                    <span className="text-xs text-slate-400">
                      ({group.members_count} member{group.members_count === 1 ? '' : 's'}
                      {group.group_type === 'native_wa_group' ? ' · Native WhatsApp Group' : ''})
                    </span>
                  </label>
                ))}
              </div>
            )}
          </div>
        )}

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

        <button
          type="submit"
          disabled={
            !selectedTemplate ||
            isSending ||
            disabled ||
            readOnly ||
            (recipientType === 'group' && groups.length === 0)
          }
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
          {isSending ? 'Sending…' : recipientType === 'group' ? 'Send to Group(s)' : 'Send Template'}
        </button>
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
                {recipientType === 'group' ? 'POST /api/v1/send-message' : 'POST /api/v1/messages/send-template'}
              </p>
              {recipientType === 'group' && (
                <p className="mt-1 text-xs text-slate-500">Sending to multiple groups? Call this endpoint once per group_id.</p>
              )}
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
