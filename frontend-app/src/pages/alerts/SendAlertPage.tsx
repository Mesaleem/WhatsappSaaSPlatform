import { useEffect, useMemo, useRef, useState, type ChangeEvent, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { AlertTriangle, CheckCircle2, Code2, Copy, Download, Loader2, Send, ShieldCheck, Sparkles, Upload, Users, XCircle } from 'lucide-react';
import { useAuth } from '../../core/context/AuthContext';
import { useTenant } from '../../core/context/TenantContext';
import MyTemplatesModal from '../../components/templates/MyTemplatesModal';
import RequestTemplateModal from '../../components/templates/RequestTemplateModal';
import contactGroupsService from '../../services/contactGroupsService';
import templateService from '../../services/templateService';
import whatsappService from '../../services/whatsappService';
import type { ContactGroup } from '../../types/contactGroup';
import type { AvailableTemplate } from '../../types/templates';
import { extractCooldownRemainingSeconds, extractErrorMessage } from '../../utils/apiError';

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
 * Bulk Individual Recipients — Send Alert widening. Splits the
 * Recipient Phone field's comma-separated string into individual
 * numbers: trims whitespace, drops empty entries, keeps only
 * plausible-looking phone strings (digits only, 7-15 of them — the
 * server's PhoneNumberNormalizer does the real normalization; this is
 * just enough client-side filtering that an obviously malformed entry
 * never reaches the dispatch loop below), and dedupes while preserving
 * first-seen order.
 */
function parseRecipientPhones(raw: string): string[] {
  const seen = new Set<string>();
  const result: string[] = [];
  for (const part of raw.split(',')) {
    const phone = part.trim();
    if (!phone || !/^\d{7,15}$/.test(phone)) continue;
    if (seen.has(phone)) continue;
    seen.add(phone);
    result.push(phone);
  }
  return result;
}

/**
 * Strict Bulk Messaging Limit — must match the backend's own
 * BulkMessageCooldown::MAX_RECIPIENTS_PER_BATCH exactly; kept as a
 * separate constant here (not fetched) since it's a fixed business
 * rule, not per-account config, and this lets the >150 warning render
 * instantly as the user types/pastes rather than after a round trip.
 */
const MAX_BULK_RECIPIENTS = 150;

/**
 * "3h 45m" / "3h" / "45m" style remaining-time text — mirrors the
 * backend's BulkMessageCooldown::formatRemaining() exactly, so the
 * live client-side countdown (ticked locally, see the cooldown
 * useEffect below) never reads differently from a fresh server value.
 */
function formatCooldownRemaining(seconds: number): string {
  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  if (hours > 0 && minutes > 0) return `${hours}h ${minutes}m`;
  if (hours > 0) return `${hours}h`;
  return `${Math.max(1, minutes)}m`;
}

/**
 * CSV Auto-Fill — reads a single "numbers" column out of an uploaded
 * CSV. If the first row looks like a header (any cell case-insensitively
 * matching phone/phone_number/number/mobile/recipient_phone), that
 * column is used and the header row is skipped; otherwise every row's
 * first column is used, so a plain single-column export with no header
 * still works. Runs the result through parseRecipientPhones() for the
 * same filtering/dedup rules a typed list gets, so both entry paths feed
 * the form identically.
 */
function parsePhoneColumnFromCsv(text: string): string[] {
  const lines = text
    .split(/\r\n|\n|\r/)
    .map((l) => l.trim())
    .filter((l) => l.length > 0);
  if (lines.length === 0) return [];

  const rows = lines.map((line) => line.split(',').map((cell) => cell.trim()));
  const headerNames = ['phone', 'phone_number', 'number', 'mobile', 'recipient_phone'];
  const headerIndex = rows[0].findIndex((cell) => headerNames.includes(cell.toLowerCase()));

  const dataRows = headerIndex !== -1 ? rows.slice(1) : rows;
  const columnIndex = headerIndex !== -1 ? headerIndex : 0;

  return parseRecipientPhones(dataRows.map((row) => row[columnIndex] ?? '').join(','));
}

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
 *
 * Bulk Individual Recipients — this is NOT a revival of the old, removed
 * CSV Bulk Upload flow above (that was a raw/manual alert path with no
 * template or dispatch loop). The Individual tab's Recipient Phone field
 * now accepts a comma-separated list (typed, or auto-filled from an
 * uploaded CSV's phone column) and the SAME Approved Template Form
 * dispatches one internal /alerts/send-template call per number,
 * client-side (see parseRecipientPhones()/handleSubmit() below) — the
 * form, the endpoint, and its validation are all unchanged.
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
  // Strict Bulk Messaging Limit & Tier-Based Cooldown -- "group_messaging_permission"
  // maps to this app's existing contact_groups module flag (see the
  // backend BulkMessageCooldown class's own docblock for why there's
  // no separate permission by that literal name).
  const { hasModule } = useAuth();
  const hasGroupMessagingPermission = hasModule('contact_groups');

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
  const [csvError, setCsvError] = useState<string | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [mediaUrl, setMediaUrl] = useState('');
  const [variables, setVariables] = useState<Record<string, string>>({});
  const [copied, setCopied] = useState(false);

  const [isSending, setIsSending] = useState(false);
  const [sendError, setSendError] = useState<string | null>(null);
  const [sendSuccess, setSendSuccess] = useState<string | null>(null);
  // Anti-Spam Bulk Dispatch -- true only right after a >1-recipient
  // send successfully enqueues (see handleSubmit's bulk branch below);
  // drives the small persistent badge next to the ordinary sendSuccess
  // banner, distinct from it because "queued" (this) and "sent" (the
  // single-recipient/group paths) are different claims.
  const [bulkQueued, setBulkQueued] = useState(false);

  // Strict Bulk Messaging Limit & Tier-Based Cooldown -- null while
  // still loading (never blocks the button on its own; the backend's
  // own 429 guard in sendBulk() is the real safety net, same UX-only
  // philosophy as this page's WhatsApp-disconnect check).
  const [cooldownRemainingSeconds, setCooldownRemainingSeconds] = useState<number | null>(null);
  const isOnCooldown = (cooldownRemainingSeconds ?? 0) > 0;

  useEffect(() => {
    let cancelled = false;
    templateService
      .getBulkCooldownStatus()
      .then((res) => {
        if (!cancelled) setCooldownRemainingSeconds(res.on_cooldown ? res.cooldown_remaining_seconds : 0);
      })
      .catch(() => {
        if (!cancelled) setCooldownRemainingSeconds(0);
      });
    return () => {
      cancelled = true;
    };
  }, []);

  // Live countdown -- ticks the locally-held value down once a second
  // so the banner's "Xh Ym" text updates without re-polling the
  // backend every second. Depends only on the boolean (not the exact
  // number) so this effect starts exactly one interval per cooldown
  // period instead of restarting on every tick.
  useEffect(() => {
    if (!isOnCooldown) return;
    const interval = setInterval(() => {
      setCooldownRemainingSeconds((prev) => (prev && prev > 1 ? prev - 1 : 0));
    }, 1000);
    return () => clearInterval(interval);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOnCooldown]);

  // Tiered Template Approval Workflow — "Request a Template" self-service
  // path (see RequestTemplateModal's own docblock for why this page is
  // the right home: every caller who reaches Send Alert at all lacks
  // manage-templates, so there is no separate gate needed here).
  const [showRequestTemplate, setShowRequestTemplate] = useState(false);
  const [showMyTemplates, setShowMyTemplates] = useState(false);
  const [requestSuccess, setRequestSuccess] = useState<string | null>(null);

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

  // A newly-submitted request starts pending, not approved, so it never
  // changes what available() returns — this just re-confirms nothing
  // approved slipped in already and clears any stale load error, rather
  // than silently leaving a request-submitted screen that still shows
  // the pre-request empty state or a now-outdated error.
  const handleTemplateRequested = (message: string) => {
    setShowRequestTemplate(false);
    setRequestSuccess(message);
    setIsLoadingTemplates(true);
    setLoadError(null);
    templateService
      .available()
      .then((list) => setTemplates(list))
      .catch((err) => setLoadError(extractErrorMessage(err, 'Failed to load templates.')))
      .finally(() => setIsLoadingTemplates(false));
  };

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

  const recipientPhones = useMemo(() => parseRecipientPhones(recipientPhone), [recipientPhone]);
  const exceedsMaxRecipients = recipientPhones.length > MAX_BULK_RECIPIENTS;

  const handleCsvUpload = (e: ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    e.target.value = ''; // allow re-uploading the same file name back-to-back
    if (!file) return;
    setCsvError(null);
    const reader = new FileReader();
    reader.onload = () => {
      const numbers = parsePhoneColumnFromCsv(String(reader.result ?? ''));
      if (numbers.length === 0) {
        setCsvError('No valid phone numbers found in this CSV.');
        return;
      }
      setRecipientPhone(numbers.join(', '));
    };
    reader.onerror = () => setCsvError('Could not read this file.');
    reader.readAsText(file);
  };

  /**
   * Sample CSV Download — a minimal, one-column template
   * (`phone` header + three example rows) matching exactly the column
   * name parsePhoneColumnFromCsv() looks for, so a file downloaded here
   * and immediately re-uploaded via "Upload CSV" round-trips cleanly.
   * Built client-side (Blob + a throwaway <a download>) -- no network
   * call, no new dependency.
   */
  const handleDownloadSampleCsv = () => {
    const csvContent = 'phone\n919876543210\n919876543211\n919876543212\n';
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'sample_contacts.csv';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
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
      // payload below. Shows one representative group_code (the first
      // selected group's code, or a placeholder when none is selected
      // yet) — sending to several groups from outside the platform means
      // calling this endpoint once per group_code. Docs-only: this
      // screen's own internal send (handleSubmit's group branch, below)
      // still calls the separate internal /api/groups/{id}/send-template
      // endpoint by numeric id, unchanged and unaffected by this
      // external contract.
      const selectedGroup = groups.find((g) => g.id === selectedGroupIds[0]);
      return {
        template_code: selectedTemplate.template_code ?? '<template_code>',
        recipient_type: 'group',
        group_code: selectedGroup?.group_code ?? '<group_code>',
        variables: sampleVariables,
      };
    }
    return {
      template_code: selectedTemplate.template_code ?? '<template_code>',
      // Bulk/Comma-Separated Numbers: mirrors exactly what's actually
      // typed/loaded above -- one number shows as a plain string
      // (unchanged), several show as the same comma-separated format the
      // Recipient Phone field itself accepts, so the sample always
      // matches this screen's own input verbatim. The subtitle just
      // below (recipientPhones.length > 1 case) states the real
      // mechanism -- this screen still calls the endpoint once per
      // number -- so the aggregate preview here is never presented
      // without that clarification alongside it.
      recipient_phone:
        recipientPhones.length > 1 ? recipientPhones.join(', ') : recipientPhones[0] || '919876543210',
      variables: sampleVariables,
      // Optional — omit this key entirely to send text-only. If present
      // but unreachable/invalid, the server falls back to text automatically
      // rather than failing the send (see MediaUrl handling below).
      media_url: mediaUrl.trim() || 'https://example.com/invoice.pdf',
    };
  }, [selectedTemplate, recipientType, recipientPhones, selectedGroupIds, groups, sampleVariables, mediaUrl]);

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
    setBulkQueued(false);

    if (!selectedTemplate) {
      setSendError('Select a template first.');
      return;
    }
    if (recipientType === 'individual' && recipientPhones.length === 0) {
      setSendError('Enter at least one valid recipient phone number.');
      return;
    }
    // Strict Bulk Messaging Limit -- blocked client-side before a round
    // trip; the backend's own max:150 rule (SendBulkTemplateMessageRequest)
    // is the real enforcement, this is purely so the user sees the exact
    // warning immediately, not after a 422.
    if (recipientType === 'individual' && exceedsMaxRecipients) {
      setSendError(
        `Maximum ${MAX_BULK_RECIPIENTS} contacts allowed per batch. For larger lists, create a Group or upgrade your plan.`,
      );
      return;
    }
    // Tier-Based Cooldown -- same client-side-first philosophy; the
    // backend's own 429 in sendBulk() is the real enforcement.
    if (recipientType === 'individual' && recipientPhones.length > 1 && isOnCooldown) {
      setSendError(
        `Bulk dispatch is on cooldown. Next bulk dispatch available in ${formatCooldownRemaining(cooldownRemainingSeconds ?? 0)}.`,
      );
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
        // accepts one group per request (group_code in the external
        // payload; GroupMessageDispatcher's own internal parameter is
        // still the resolved integer group id) — there is no batch-send
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
      } else if (recipientPhones.length > 1) {
        // Anti-Spam Bulk Dispatch — ONE call enqueues all N recipients as
        // individually rate-limited, randomly-delayed background jobs
        // (see MessageTemplateController::sendBulk()'s docblock) instead
        // of firing N /alerts/send-template calls from here in parallel
        // with no pacing at all — exactly the mechanical, fixed-cadence
        // burst anti-ban jitter exists to avoid. There is nothing to
        // await per-recipient any more: the backend returns as soon as
        // the jobs are written, well before any of them actually send, so
        // success here means "queued", not "delivered" — per-recipient
        // delivery still lands on the existing Message Logs page as each
        // job eventually runs.
        const response = await templateService.sendBulkTemplateMessage({
          template_id: selectedTemplate.id,
          recipient_phones: recipientPhones,
          variables,
          // Omit the key entirely when blank rather than sending an empty
          // string — the backend only overrides the template's own media
          // when this key is present at all.
          ...(mediaUrl.trim() ? { media_url: mediaUrl.trim() } : {}),
        });
        setBulkQueued(true);
        setSendSuccess(response.message);
        // Strict Bulk Messaging Limit & Tier-Based Cooldown -- a dispatch
        // that used the full 150-recipient cap starts a cooldown on the
        // backend (BulkMessageCooldown::lock(), inside sendBulk()); this
        // mirrors that same 4h/6h decision client-side so the countdown
        // banner appears immediately, without waiting for the next
        // getBulkCooldownStatus() poll.
        if (recipientPhones.length >= MAX_BULK_RECIPIENTS) {
          const cooldownHours = hasGroupMessagingPermission ? 4 : 6;
          setCooldownRemainingSeconds(cooldownHours * 3600);
        }
        setRecipientPhone('');
        setMediaUrl('');
        setVariables(Object.fromEntries(selectedTemplate.variables_schema.map((f) => [f.key, ''])));
      } else {
        // Exactly one recipient — unchanged from before this feature: a
        // single synchronous call with immediate send-or-fail feedback.
        // Anti-spam pacing only matters once there's more than one
        // message to space out.
        await templateService.sendTemplateMessage({
          template_id: selectedTemplate.id,
          recipient_phone: recipientPhones[0],
          variables,
          ...(mediaUrl.trim() ? { media_url: mediaUrl.trim() } : {}),
        });
        setSendSuccess('Sent to 1 recipient.');
        setRecipientPhone('');
        setMediaUrl('');
        setVariables(Object.fromEntries(selectedTemplate.variables_schema.map((f) => [f.key, ''])));
      }
    } catch (err) {
      // Strict Bulk Messaging Limit & Tier-Based Cooldown -- a 429 from
      // sendBulk() (cooldown started/extended by ANOTHER tab, or the
      // client-side guard above having a stale value) carries the real
      // remaining time; use it to correct this page's own countdown
      // rather than leaving it out of sync until the next poll.
      const cooldownSeconds = extractCooldownRemainingSeconds(err);
      if (typeof cooldownSeconds === 'number') {
        setCooldownRemainingSeconds(cooldownSeconds);
      }
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
      <div className="flex flex-col items-center gap-3 rounded-lg border border-dashed border-slate-300 py-10 text-center text-sm text-slate-500">
        <Sparkles className="h-6 w-6 text-slate-300" />
        {requestSuccess ? (
          <p className="max-w-sm text-emerald-600">{requestSuccess}</p>
        ) : (
          <p className="max-w-sm">No approved templates yet. Request one and it'll show up here once approved.</p>
        )}
        <div className="flex items-center gap-3">
          <button
            type="button"
            onClick={() => setShowRequestTemplate(true)}
            className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700"
          >
            Request a Template
          </button>
          <button
            type="button"
            onClick={() => setShowMyTemplates(true)}
            className="text-sm font-medium text-indigo-600 hover:text-indigo-700"
          >
            View My Templates
          </button>
        </div>
        {showRequestTemplate && (
          <RequestTemplateModal onClose={() => setShowRequestTemplate(false)} onSubmitted={handleTemplateRequested} />
        )}
        {showMyTemplates && <MyTemplatesModal onClose={() => setShowMyTemplates(false)} />}
      </div>
    );
  }

  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
      <form onSubmit={(e) => void handleSubmit(e)} className="space-y-4">
        <div>
          <div className="flex items-center justify-between">
            <label className="text-sm font-medium text-slate-700">Template <span className="text-red-500">*</span></label>
            {/* Persistent entry point — the empty-state CTA above only
                ever renders with zero approved templates, so an Admin/User
                who already has one (the common case, thanks to Strict
                1-Template-Per-Client) had no way to request a different
                or additional one. This link stays available regardless. */}
            <span className="flex items-center gap-3">
              <button
                type="button"
                onClick={() => setShowMyTemplates(true)}
                className="text-xs font-medium text-indigo-600 hover:text-indigo-700"
              >
                My Templates
              </button>
              <button
                type="button"
                onClick={() => setShowRequestTemplate(true)}
                className="text-xs font-medium text-indigo-600 hover:text-indigo-700"
              >
                + Request a Template
              </button>
            </span>
          </div>
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
          {requestSuccess && <p className="mt-1.5 text-xs text-emerald-600">{requestSuccess}</p>}
          {showRequestTemplate && (
            <RequestTemplateModal onClose={() => setShowRequestTemplate(false)} onSubmitted={handleTemplateRequested} />
          )}
          {showMyTemplates && <MyTemplatesModal onClose={() => setShowMyTemplates(false)} />}
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
          <div className="space-y-4">
            {(isOnCooldown || !hasGroupMessagingPermission) && (
              <div className="space-y-2">
                {isOnCooldown && (
                  <div className="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    <AlertTriangle className="h-4 w-4 flex-shrink-0" />
                    Next bulk dispatch available in {formatCooldownRemaining(cooldownRemainingSeconds ?? 0)}.
                  </div>
                )}
                {!hasGroupMessagingPermission && (
                  <div className="flex items-center gap-2 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-xs font-medium text-indigo-700">
                    <Sparkles className="h-3.5 w-3.5 flex-shrink-0" />
                    Upgrade to reduce cooldown to 4 hours &amp; unlock Native WhatsApp Group Messaging.
                  </div>
                )}
              </div>
            )}
            <div>
              <div className="flex items-center justify-between gap-2">
                <label className="text-sm font-medium text-slate-700">
                  Recipient Phone(s) <span className="text-red-500">*</span>
                </label>
                <div className="flex items-center gap-2">
                  <button
                    type="button"
                    onClick={handleDownloadSampleCsv}
                    className="flex items-center gap-1 rounded-lg border border-slate-300 bg-white px-2 py-1 text-xs font-medium text-slate-600 hover:bg-slate-50"
                    title="Download a sample CSV with the expected 'phone' column"
                  >
                    <Download className="h-3 w-3" />
                    Download Sample CSV
                  </button>
                  <button
                    type="button"
                    onClick={() => fileInputRef.current?.click()}
                    className="flex items-center gap-1 rounded-lg border border-slate-300 bg-white px-2 py-1 text-xs font-medium text-slate-600 hover:bg-slate-50"
                  >
                    <Upload className="h-3 w-3" />
                    Upload CSV
                  </button>
                  <input
                    ref={fileInputRef}
                    type="file"
                    accept=".csv,text/csv"
                    onChange={handleCsvUpload}
                    className="hidden"
                  />
                </div>
              </div>
              <textarea
                rows={2}
                value={recipientPhone}
                onChange={(e) => setRecipientPhone(e.target.value)}
                placeholder="919876543210, 919876543211, 919876543212"
                className={inputClass}
              />
              <div className="mt-1 flex flex-wrap items-center gap-2">
                {recipientPhones.length > 0 && (
                  <span
                    className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset ${
                      exceedsMaxRecipients
                        ? 'bg-red-50 text-red-700 ring-red-600/20'
                        : 'bg-emerald-50 text-emerald-700 ring-emerald-600/20'
                    }`}
                  >
                    {recipientPhones.length} Recipient Number{recipientPhones.length === 1 ? '' : 's'} Loaded
                  </span>
                )}
                {csvError && <span className="text-xs text-red-600">{csvError}</span>}
              </div>
              {exceedsMaxRecipients && (
                <div className="mt-2 flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-medium text-red-700">
                  <AlertTriangle className="h-3.5 w-3.5 flex-shrink-0" />
                  Maximum {MAX_BULK_RECIPIENTS} contacts allowed per batch. For larger lists, create a Group or
                  upgrade your plan.
                </div>
              )}
            </div>
            <div>
              <label className="text-sm font-medium text-slate-700">
                Media URL
                <span className="ml-1 text-xs font-normal text-slate-400">
                  (optional — sends the template as an image/document; falls back to text if left blank or unreachable)
                </span>
              </label>
              <input
                type="text"
                value={mediaUrl}
                onChange={(e) => setMediaUrl(e.target.value)}
                placeholder="https://example.com/invoice.pdf"
                className={inputClass}
              />
            </div>
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
        {bulkQueued && (
          <div className="flex items-center gap-1.5 rounded-lg bg-indigo-50 px-3 py-1.5 text-xs font-medium text-indigo-700 ring-1 ring-inset ring-indigo-600/20">
            <ShieldCheck className="h-3.5 w-3.5 flex-shrink-0" />
            Bulk messages queued with anti-spam delay protection.
          </div>
        )}

        <button
          type="submit"
          disabled={
            !selectedTemplate ||
            isSending ||
            disabled ||
            readOnly ||
            (recipientType === 'group' && groups.length === 0) ||
            (recipientType === 'individual' && exceedsMaxRecipients) ||
            (recipientType === 'individual' && recipientPhones.length > 1 && isOnCooldown)
          }
          title={
            readOnly
              ? 'Action disabled: Subscription expired.'
              : disabled
                ? 'WhatsApp is disconnected — reconnect it in WhatsApp Setup to send alerts.'
                : recipientType === 'individual' && exceedsMaxRecipients
                  ? `Maximum ${MAX_BULK_RECIPIENTS} contacts allowed per batch.`
                  : recipientType === 'individual' && recipientPhones.length > 1 && isOnCooldown
                    ? `Bulk dispatch is on cooldown. Try again in ${formatCooldownRemaining(cooldownRemainingSeconds ?? 0)}.`
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
                <p className="mt-1 text-xs text-slate-500">Sending to multiple groups? Call this endpoint once per group_code.</p>
              )}
              {recipientType === 'individual' && recipientPhones.length > 1 && (
                <>
                  <p className="mt-1 text-xs text-slate-500">
                    Sending to multiple recipients? Call this endpoint once per recipient_phone -- this screen just
                    queued {recipientPhones.length} calls for you above.
                  </p>
                  <p className="mt-1 text-xs text-slate-500">
                    Messages are sent in batches of 10 with a 1-2 minute anti-spam pause between batches.
                  </p>
                </>
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
                {recipientType === 'individual' && (
                  <span
                    title="string"
                    className="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500"
                  >
                    media_url — optional (string)
                  </span>
                )}
              </div>
            </div>
          </>
        )}
      </div>
    </div>
  );
}
