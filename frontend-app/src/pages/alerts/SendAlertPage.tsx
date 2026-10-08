import { useEffect, useMemo, useRef, useState, type ChangeEvent, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { AlertTriangle, CalendarClock, CheckCircle2, Code2, Copy, Download, Loader2, Send, ShieldCheck, Sparkles, Upload, Users, XCircle } from 'lucide-react';
import { useAuth } from '../../core/context/AuthContext';
import BatchSendPanel from '../../components/alerts/BatchSendPanel';
import ScheduledMessagesModal from '../../components/alerts/ScheduledMessagesModal';
import senderNumberService from '../../services/senderNumberService';
import messageBatchService from '../../services/messageBatchService';
import type { SenderNumber } from '../../types/senderNumber';
import ScheduleField, { EMPTY_SCHEDULE, formatSchedule, scheduleError, scheduleToIso, type ScheduleValue } from '../../components/common/ScheduleField';
import { useTenant } from '../../core/context/TenantContext';
import MyTemplatesModal from '../../components/templates/MyTemplatesModal';
import RequestTemplateModal from '../../components/templates/RequestTemplateModal';
import contactGroupsService from '../../services/contactGroupsService';
import templateService from '../../services/templateService';
import alertService from '../../services/alertService';
import whatsappService from '../../services/whatsappService';
import type { ContactGroup } from '../../types/contactGroup';
import type { AvailableTemplate } from '../../types/templates';
import { extractCooldownRemainingSeconds, extractErrorMessage } from '../../utils/apiError';
import DismissibleAlert from '../../components/common/DismissibleAlert';
import TemplateSearchSelect from '../../components/common/TemplateSearchSelect';
import ApiKeySetupBanner from '../../components/profile/ApiKeySetupBanner';

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
const MAX_BULK_RECIPIENTS = 30;

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
  const { isReadOnly, isSuperAdmin } = useAuth();
  const superAdmin = isSuperAdmin();

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
    // [Bugfix, disclosed]: Super Admin sends through their own reserved
    // platform-device WhatsApp account, never a client's — the exact same
    // account Device Settings' "Your WhatsApp test device" card already
    // tracks via selfDeviceStatus() (GET /admin/whatsapp/self-device).
    // This page used to call the generic, client-scoped status() here
    // regardless of caller, which checked whatever tenant the header
    // selector happened to resolve to (or nothing, for Global View) —
    // not the account Super Admin actually sends from — so the banner
    // could read "disconnected" even when Device Settings had already
    // been scanned, and "Go to WhatsApp Setup to Connect" pointed at
    // /settings/whatsapp, a page built for a single client tenant, not
    // the platform device. Super Admin now reads the same source of
    // truth Device Settings uses, full stop — no re-check needed on
    // selectedAccountId, since the platform device isn't the selected
    // tenant at all.
    const request = superAdmin ? whatsappService.selfDeviceStatus() : whatsappService.status();
    request
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
  }, [selectedAccountId, superAdmin]);

  const isDisconnected = waStatus === 'disconnected';
  const readOnly = isReadOnly();

  // Scheduled Messages -- every send-later, whichever mode/screen scheduled it (including the
  // Developer API), lives in one list reachable from this top-corner link. See
  // ScheduledMessagesModal's own docblock for why this is a modal rather than a separate page.
  const [showScheduled, setShowScheduled] = useState(false);

  return (
    <div className="p-6">
      <div className="w-full">
        <div className="flex items-start justify-between gap-4">
          <div>
            <h1 className="text-xl font-semibold text-slate-900">Send Notification</h1>
            <p className="mt-1 text-sm text-slate-500">
              Send an approved WhatsApp template message to a customer or contact group.
            </p>
          </div>
          <button
            type="button"
            onClick={() => setShowScheduled(true)}
            className="flex flex-shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-600 hover:bg-slate-50"
          >
            <CalendarClock className="h-3.5 w-3.5" />
            Scheduled Messages
          </button>
        </div>
        {showScheduled && <ScheduledMessagesModal onClose={() => setShowScheduled(false)} />}

        <div className="mt-6">
          <ApiKeySetupBanner />
        </div>

        {isDisconnected && (
          <div className="mt-6 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm">
            <div className="flex items-center gap-2 text-amber-800">
              <AlertTriangle className="h-4 w-4 flex-shrink-0" />
              <span className="font-medium">WhatsApp Disconnected</span>
            </div>
            <Link
              to={superAdmin ? '/admin/device-settings' : '/settings/whatsapp'}
              className="rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-sm font-semibold text-amber-800 hover:bg-amber-100"
            >
              {superAdmin ? 'Go to Device Settings to Connect' : 'Go to WhatsApp Setup to Connect'}
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
  // "No template" option (owner request 2026-10-05): a free-text send that bypasses templates entirely.
  const [noTemplate, setNoTemplate] = useState(false);
  const [directMessage, setDirectMessage] = useState('');

  // Group Messaging — Send Alert screen widening: recipientType decides
  // whether the form sends to one phone number (existing behavior,
  // unchanged) or to one-or-more Contact Groups (new, via the internal
  // POST /groups/{id}/send-template action). Groups are fetched once on
  // mount alongside templates — the list is short enough per tenant that
  // lazy-loading only after the user picks "Group" would just add an
  // extra loading flicker for no real benefit.
  const [recipientType, setRecipientType] = useState<'individual' | 'group'>('individual');
  // Bulk: several numbers typed at once, sent from the ticked numbers in turn (recipientType stays 'individual').
  const [isBulk, setIsBulk] = useState(false);
  const [groups, setGroups] = useState<ContactGroup[]>([]);
  const [isLoadingGroups, setIsLoadingGroups] = useState(true);
  const [groupsLoadError, setGroupsLoadError] = useState<string | null>(null);
  const [selectedGroupIds, setSelectedGroupIds] = useState<number[]>([]);
  // The WhatsApp number this send goes out from: the default, unless the user picks another connected one.
  const [senderNumbers, setSenderNumbers] = useState<SenderNumber[]>([]);
  const [senderNumberId, setSenderNumberId] = useState<number | null>(null);
  const defaultSenderId = senderNumbers.find((n) => n.is_default)?.id ?? null;
  // A group belongs to the number it was created on (a group with no number is on the default one).
  const groupsOnSender = groups.filter((g) => (g.whatsapp_number_id ?? defaultSenderId) === senderNumberId);

  useEffect(() => {
    senderNumberService
      .list()
      .then((list) => {
        setSenderNumbers(list);
        setSenderNumberId((prev) => prev ?? (list.find((n) => n.is_default) ?? list[0])?.id ?? null);
      })
      .catch(() => undefined);
  }, []);

  // When the number changes, drop any selected group that is not on it: the user must choose again.
  useEffect(() => {
    const allowed = new Set(groups.filter((g) => (g.whatsapp_number_id ?? defaultSenderId) === senderNumberId).map((g) => g.id));
    setSelectedGroupIds((prev) => prev.filter((id) => allowed.has(id)));
  }, [senderNumberId, groups, defaultSenderId]);

  const [recipientPhone, setRecipientPhone] = useState('');
  const [csvError, setCsvError] = useState<string | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [mediaUrl, setMediaUrl] = useState('');
  // Optional send time for template sends (empty = send now).
  const [schedule, setSchedule] = useState<ScheduleValue>(EMPTY_SCHEDULE);
  const scheduledIso = scheduleToIso(schedule);
  const scheduleProblem = scheduleError(schedule);
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
    setNoTemplate(false);
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

  const handleSelectNoTemplate = () => {
    setTemplateId('');
    setNoTemplate(true);
    setVariables({});
    setSendSuccess(null);
    setSendError(null);
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
      if (numbers.length > MAX_BULK_RECIPIENTS) {
        setCsvError(`This file has ${numbers.length} numbers. Individual allows up to ${MAX_BULK_RECIPIENTS}. For more, choose Bulk and upload the file there.`);
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
    if (noTemplate) {
      // Developer API "No template" sentinel (MessageTemplate::NO_TEMPLATE_CODE server-side) -- same two
      // endpoints as a real template, just with template_code fixed to "no_template" and a `text` key instead
      // of `variables`. Mirrors the two recipient-type shapes below exactly.
      const text = directMessage.trim() || 'Type the message to send…';
      // media_url is always shown in the sample (falls back to a placeholder, same as the
      // template-based payload below) so it doesn't look "missing" when the field is empty --
      // it's still an optional key server-side (text.required_if:template_code,no_template means
      // only one of text/media_url is actually required).
      const sampleMediaUrl = mediaUrl.trim() || 'https://example.com/invoice.pdf';
      if (recipientType === 'group') {
        const selectedGroup = groups.find((g) => g.id === selectedGroupIds[0]);
        return {
          template_code: 'no_template',
          recipient_type: 'group',
          group_code: selectedGroup?.group_code ?? '<group_code>',
          text,
          media_url: sampleMediaUrl,
        };
      }
      return {
        template_code: 'no_template',
        recipient_phone: recipientPhones[0] || '919876543210',
        text,
        media_url: sampleMediaUrl,
      };
    }
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
        media_url: mediaUrl.trim() || 'https://example.com/invoice.pdf',
        scheduled_at: scheduledIso ?? '',
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
      // Optional send time: null sends now (the server treats null as "no time").
      // Shows the chosen time from the Send later field once one is set.
      scheduled_at: scheduledIso ?? '',
    };
  }, [noTemplate, directMessage, selectedTemplate, recipientType, recipientPhones, selectedGroupIds, groups, sampleVariables, mediaUrl, scheduledIso]);

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

    if (!noTemplate && !selectedTemplate) {
      setSendError('Select a template first.');
      return;
    }
    if (noTemplate && directMessage.trim() === '' && !mediaUrl.trim()) {
      setSendError('Enter a message, attach a media URL, or both.');
      return;
    }
    if (recipientType === 'individual' && recipientPhones.length === 0) {
      setSendError('Enter at least one valid recipient phone number.');
      return;
    }
    // Individual numbers typed here are capped at MAX_BULK_RECIPIENTS -- blocked client-side before a
    // round trip; the backend's own max:30 rule (BulkSendController) is the real enforcement, this is
    // purely so the user sees the exact warning immediately, not after a 422.
    if (recipientType === 'individual' && exceedsMaxRecipients) {
      setSendError(
        `Up to ${MAX_BULK_RECIPIENTS} numbers here. For more, choose Bulk and upload an Excel or CSV file.`,
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
    // the same regardless of who it's being sent to. None of this applies
    // to the "No template" free-text path -- there is no variables_schema.
    const missingRequired = noTemplate ? [] : selectedTemplate!.variables_schema.filter(
      (f) => f.required && !variables[f.key]?.trim(),
    );
    if (missingRequired.length > 0) {
      setSendError(`Fill in: ${missingRequired.map((f) => f.label || f.key).join(', ')}`);
      return;
    }
    const badType = noTemplate ? undefined : selectedTemplate!.variables_schema.find((f) => {
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

    if (scheduleProblem) {
      setSendError(scheduleProblem);
      return;
    }

    setIsSending(true);
    try {
      if (noTemplate) {
        // "No template" option (owner request 2026-10-05) -- same recipient-type branching as the
        // template path above, but through alertService.sendDirect() (DirectMessageDispatcher /
        // GroupDirectMessageDispatcher server-side) instead of TemplateMessageDispatcher. Follows the
        // same sender-number and schedule rules as the template path. Several individual numbers go
        // through the same paced bulk engine the template path uses, not one unpaced request per number.
        const message = directMessage.trim() || undefined;
        const media = mediaUrl.trim() || undefined;
        const sender_number_id = senderNumberId ?? undefined;
        const scheduled_at = scheduledIso ?? undefined;

        if (recipientType === 'group') {
            const results = await Promise.allSettled(
              selectedGroupIds.map((groupId) =>
                alertService.sendDirect({ recipient_type: 'group', group_ids: [groupId], message, media_url: media, sender_number_id, scheduled_at }),
              ),
            );
            const groupName = (id: number) => groups.find((g) => g.id === id)?.name ?? `#${id}`;
            const failures = results
              .map((r, i) => ({ r, groupId: selectedGroupIds[i] }))
              .filter((x): x is { r: PromiseRejectedResult; groupId: number } => x.r.status === 'rejected');
            const verb = scheduled_at ? 'Scheduled' : 'Queued';

            if (failures.length === 0) {
              setSendSuccess(`${verb} for ${results.length} group${results.length === 1 ? '' : 's'}.`);
              setSelectedGroupIds([]);
              setDirectMessage('');
              setMediaUrl('');
              setSchedule(EMPTY_SCHEDULE);
            } else if (failures.length === results.length) {
              setSendError(extractErrorMessage(failures[0].r.reason, 'Could not queue this group dispatch.'));
            } else {
              const succeeded = results.length - failures.length;
              const failedNames = failures.map((f) => groupName(f.groupId)).join(', ');
              setSendSuccess(`${verb} for ${succeeded} of ${results.length} group(s). Failed: ${failedNames}.`);
              setSelectedGroupIds(failures.map((f) => f.groupId));
            }
        } else if (recipientPhones.length > 1) {
          const response = await messageBatchService.createBulk({
            recipient_phones: recipientPhones,
            message_text: message,
            variables: {},
            media_url: media,
            sender_number_ids: senderNumberId ? [senderNumberId] : [],
            scheduled_at,
          });
          setSendSuccess(response.message);
          setRecipientPhone('');
          setDirectMessage('');
          setMediaUrl('');
          setSchedule(EMPTY_SCHEDULE);
        } else {
          const response = await alertService.sendDirect({
            recipient_type: 'individual',
            recipient_phone: recipientPhones[0],
            message,
            media_url: media,
            sender_number_id,
            scheduled_at,
          });
          setSendSuccess(response.scheduled ? 'Scheduled for later.' : 'Sent to 1 recipient.');
          setRecipientPhone('');
          setDirectMessage('');
          setMediaUrl('');
          setSchedule(EMPTY_SCHEDULE);
        }
      } else {
        // noTemplate is false here, so selectedTemplate is non-null by construction (guarded above);
        // this local const just gives TypeScript that same narrowing for the rest of this branch.
        const template = selectedTemplate!;
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
              contactGroupsService.sendTemplate(groupId, {
                template_id: template.id,
                variables,
                ...(senderNumberId ? { sender_number_id: senderNumberId } : {}),
                ...(scheduledIso ? { scheduled_at: scheduledIso } : {}),
              }),
            ),
          );
          const groupName = (id: number) => groups.find((g) => g.id === id)?.name ?? `#${id}`;
          const failures = results
            .map((r, i) => ({ r, groupId: selectedGroupIds[i] }))
            .filter((x): x is { r: PromiseRejectedResult; groupId: number } => x.r.status === 'rejected');

          if (failures.length === 0) {
            setSendSuccess(
              scheduledIso
                ? `Scheduled for ${results.length} group${results.length === 1 ? '' : 's'} on ${formatSchedule(schedule)}.`
                : `Queued for ${results.length} group${results.length === 1 ? '' : 's'}.`,
            );
            setSchedule(EMPTY_SCHEDULE);
            setSelectedGroupIds([]);
            setVariables(Object.fromEntries(template.variables_schema.map((f) => [f.key, ''])));
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
          const response = await messageBatchService.createBulk({
            template_id: template.id,
            recipient_phones: recipientPhones,
            variables,
            sender_number_ids: senderNumberId ? [senderNumberId] : [],
            ...(scheduledIso ? { scheduled_at: scheduledIso } : {}),
            // Omit the key entirely when blank rather than sending an empty
            // string — the backend only overrides the template's own media
            // when this key is present at all.
            ...(mediaUrl.trim() ? { media_url: mediaUrl.trim() } : {}),
          });
          setBulkQueued(true);
          setSendSuccess(scheduledIso ? `Scheduled ${recipientPhones.length} message(s) for ${formatSchedule(schedule)}.` : response.message);
          setSchedule(EMPTY_SCHEDULE);
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
          setVariables(Object.fromEntries(template.variables_schema.map((f) => [f.key, ''])));
        } else {
          // Exactly one recipient — unchanged from before this feature: a
          // single synchronous call with immediate send-or-fail feedback.
          // Anti-spam pacing only matters once there's more than one
          // message to space out.
          await templateService.sendTemplateMessage({
            template_id: template.id,
            recipient_phone: recipientPhones[0],
            variables,
            ...(senderNumberId ? { sender_number_id: senderNumberId } : {}),
            ...(scheduledIso ? { scheduled_at: scheduledIso } : {}),
            ...(mediaUrl.trim() ? { media_url: mediaUrl.trim() } : {}),
          });
          setSendSuccess(scheduledIso ? `Scheduled for ${formatSchedule(schedule)}.` : 'Sent to 1 recipient.');
          setSchedule(EMPTY_SCHEDULE);
          setRecipientPhone('');
          setMediaUrl('');
          setVariables(Object.fromEntries(template.variables_schema.map((f) => [f.key, ''])));
        }
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
      <DismissibleAlert className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        <XCircle className="h-4 w-4 flex-shrink-0" />
        {loadError}
      </DismissibleAlert>
    );
  }

  if (templates.length === 0 && !noTemplate) {
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
          <button
            type="button"
            onClick={handleSelectNoTemplate}
            className="text-sm font-medium text-slate-600 hover:text-slate-800"
          >
            Send without a template
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
    <div className="space-y-6">
      <div>
        <label className="text-sm font-medium text-slate-700">Send To</label>
        <div className="mt-1.5 flex gap-2">
          <button
            type="button"
            onClick={() => {
              setRecipientType('individual');
              setIsBulk(false);
            }}
            className={`flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition ${
              recipientType === 'individual' && !isBulk
                ? 'border-indigo-600 bg-indigo-50 text-indigo-700'
                : 'border-slate-300 text-slate-600 hover:bg-slate-50'
            }`}
          >
            <Send className="h-3.5 w-3.5" />
            Individual
          </button>
          <button
            type="button"
            onClick={() => {
              setRecipientType('group');
              setIsBulk(false);
            }}
            className={`flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition ${
              recipientType === 'group'
                ? 'border-indigo-600 bg-indigo-50 text-indigo-700'
                : 'border-slate-300 text-slate-600 hover:bg-slate-50'
            }`}
          >
            <Users className="h-3.5 w-3.5" />
            Group
          </button>
          <button
            type="button"
            onClick={() => {
              setRecipientType('individual');
              setIsBulk(true);
            }}
            className={`flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition ${
              isBulk ? 'border-indigo-600 bg-indigo-50 text-indigo-700' : 'border-slate-300 text-slate-600 hover:bg-slate-50'
            }`}
          >
            <Upload className="h-3.5 w-3.5" />
            Bulk
          </button>
        </div>
      </div>

      {!isBulk && (
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
      <form onSubmit={(e) => void handleSubmit(e)} className="space-y-4">
        {!isBulk && senderNumbers.length === 0 && (
          <p className="text-xs text-amber-700">No connected WhatsApp number. Connect a number in WhatsApp Setup to choose the number to send from.</p>
        )}

        {!isBulk && senderNumbers.length > 0 && (
          <div>
            <label htmlFor="send-from-number" className="text-sm font-medium text-slate-700">Send from</label>
            <select
              id="send-from-number"
              value={senderNumberId ?? ''}
              onChange={(e) => setSenderNumberId(e.target.value ? Number(e.target.value) : null)}
              className="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
            >
              {senderNumbers.map((n) => (
                <option key={n.id} value={n.id}>
                  {n.phone_number}{n.is_default ? ' (default)' : ''}
                </option>
              ))}
            </select>
            <p className="mt-1 text-xs text-slate-500">
              Only connected numbers are listed. A group is sent only from the number it was created on.
            </p>
          </div>
        )}

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
          <TemplateSearchSelect
            templates={templates}
            value={templateId}
            noTemplate={noTemplate}
            onSelect={(id) => handleSelectTemplate(id)}
            onSelectNoTemplate={handleSelectNoTemplate}
          />
          {requestSuccess && <p className="mt-1.5 text-xs text-emerald-600">{requestSuccess}</p>}
          {showRequestTemplate && (
            <RequestTemplateModal onClose={() => setShowRequestTemplate(false)} onSubmitted={handleTemplateRequested} />
          )}
          {showMyTemplates && <MyTemplatesModal onClose={() => setShowMyTemplates(false)} />}
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
                <span className={`text-[11px] ${exceedsMaxRecipients ? 'text-red-600' : 'text-slate-500'}`}>
                  Up to {MAX_BULK_RECIPIENTS} numbers here: {recipientPhones.length} / {MAX_BULK_RECIPIENTS}
                </span>
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
                <DismissibleAlert className="mt-2 flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-medium text-red-700">
                  <AlertTriangle className="h-3.5 w-3.5 flex-shrink-0" />
                  Up to {MAX_BULK_RECIPIENTS} numbers here. For more, choose Bulk and upload an Excel or CSV file.
                </DismissibleAlert>
              )}
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
            ) : groupsOnSender.length === 0 ? (
              <p className="mt-1.5 text-sm text-slate-500">
                No contact groups are on {senderNumbers.find((n) => n.id === senderNumberId)?.phone_number ?? 'this number'} yet.{' '}
                <Link to="/contact-groups" className="font-medium text-indigo-600 hover:text-indigo-700">
                  Create a group for this number
                </Link>
                .
              </p>
            ) : (
              <div className="mt-1.5 max-h-40 space-y-1 overflow-y-auto rounded-lg border border-slate-300 p-2">
                {groupsOnSender.map((group) => (
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
            {selectedGroupIds.some((id) => groups.find((g) => g.id === id)?.group_type === 'internal_segment') && (
              <p className="mt-1.5 text-xs text-slate-500">
                A custom group (Internal Segment) sends this as a separate message to each member, one by one — not
                as a single group chat message.
              </p>
            )}
          </div>
        )}
        <div className="space-y-4">
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
            <ScheduleField value={schedule} onChange={setSchedule} disabled={isSending} />
        </div>

        {noTemplate && (
          <div className="rounded-lg border border-slate-200 bg-slate-50/60 p-4">
            <div className="flex items-center justify-between">
              <label htmlFor="direct-message" className="text-sm font-medium text-slate-700">Your message</label>
              <span className="text-xs text-slate-400">{directMessage.length} / 4096</span>
            </div>
            <p className="mt-0.5 text-xs text-slate-500">
              Sent as plain text, exactly as you type it. Required unless a Media URL is attached below.
            </p>
            <textarea
              id="direct-message"
              rows={6}
              maxLength={4096}
              value={directMessage}
              onChange={(e) => setDirectMessage(e.target.value)}
              placeholder="Type the message to send…"
              className={`${inputClass} mt-2 w-full`}
            />
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
          <DismissibleAlert className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <XCircle className="h-4 w-4 flex-shrink-0" />
            {sendError}
          </DismissibleAlert>
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
            (!noTemplate && !selectedTemplate) ||
            (noTemplate && directMessage.trim() === '' && !mediaUrl.trim()) ||
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
                : recipientType === 'group' && groups.length === 0
                  ? 'No contact groups exist yet — create one under Contact Groups first.'
                : recipientType === 'individual' && exceedsMaxRecipients
                  ? `Up to ${MAX_BULK_RECIPIENTS} numbers here. For more, choose Bulk and upload an Excel or CSV file.`
                  : recipientType === 'individual' && recipientPhones.length > 1 && isOnCooldown
                    ? `Bulk dispatch is on cooldown. Try again in ${formatCooldownRemaining(cooldownRemainingSeconds ?? 0)}.`
                    : undefined
          }
          className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60"
        >
          {isSending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
          {isSending
            ? 'Sending…'
            : scheduledIso
              ? 'Schedule message'
              : noTemplate
                ? (recipientType === 'group' ? 'Send to Group(s)' : 'Send Message')
                : (recipientType === 'group' ? 'Send to Group(s)' : 'Send Template')}
        </button>
      </form>

      <div className="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
        <div className="flex items-center gap-2 text-sm font-semibold text-slate-900">
          <Code2 className="h-4 w-4 text-indigo-600" />
          Developer API Documentation
        </div>
        {!noTemplate && !selectedTemplate ? (
          <p className="text-sm text-slate-500">Select a template to see its API integration details.</p>
        ) : (
          <>
            <div>
              <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Endpoint</p>
              <p className="mt-1 rounded-lg bg-slate-900 px-3 py-2 font-mono text-xs text-emerald-300">
                {recipientType === 'group' ? 'POST /api/v1/send-message' : 'POST /api/v1/messages/send-template'}
              </p>
              {noTemplate && (
                <p className="mt-1 text-xs text-slate-500">
                  Pass template_code as the literal string "no_template" to send free text with no template at all
                  — the "text" key below replaces "variables".
                </p>
              )}
              {recipientType === 'group' && (
                <>
                  <p className="mt-1 text-xs text-slate-500">Sending to multiple groups? Call this endpoint once per group_code.</p>
                  <p className="mt-1 text-xs text-slate-500">
                    group_code can be a Native WhatsApp Group (one message, one group chat) or a custom group —
                    "Internal Segment" on the Contact Groups page (free, no limit). A custom group's group_code
                    sends this as a separate message to every member, one by one, not as a single group message.
                  </p>
                </>
              )}
              {recipientType === 'individual' && recipientPhones.length > 1 && (
                <>
                  <p className="mt-1 text-xs text-slate-500">
                    Sending to multiple recipients? Call this endpoint once per recipient_phone -- this screen just
                    queued {recipientPhones.length} calls for you above.
                  </p>
                  <p className="mt-1 text-xs text-slate-500">
                    Calling the API itself? Leave a few seconds between calls so the sending number isn't flagged as
                    spam -- this screen's own Bulk option (Excel/CSV upload, dashboard-only) already paces that for
                    you automatically.
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
                Find your key under Profile → API key. Calls are accepted only from your registered server IP.
              </p>
              <p className="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                ⚠️ Security Notice: Requests are strictly authorized only from your registered Server IP. Calls from unauthorized domains/servers will be rejected automatically.
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
                {noTemplate ? (
                  <span
                    title="string"
                    className="inline-flex items-center rounded-full bg-red-50 px-2 py-0.5 text-[11px] font-medium text-red-600"
                  >
                    text — required unless media_url is present (string)
                  </span>
                ) : (
                  selectedTemplate!.variables_schema.map((field) => (
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
                  ))
                )}
                {(recipientType === 'individual' || recipientType === 'group' || noTemplate) && (
                  <span
                    title="string"
                    className="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500"
                  >
                    media_url — optional (string)
                  </span>
                )}
                {!noTemplate && (
                  <span
                    title="Format: yyyy-mm-dd hh:mm:ss in Indian Standard Time (IST), for example 2026-10-08 09:35:00. Leave empty to send now."
                    className="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500"
                  >
                    scheduled_at — optional (yyyy-mm-dd hh:mm:ss, IST)
                  </span>
                )}
              </div>
            </div>
          </>
        )}
      </div>
      </div>
      )}

      {isBulk && <BatchSendPanel senders={senderNumbers} disabled={isSending} />}
    </div>
  );
}