import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Copy, Loader2 } from 'lucide-react';
import crmDocumentsService from '../../../services/crmDocumentsService';
import crmLeadSettingsService, { type CrmLeadSettings } from '../../../services/crmLeadSettingsService';
import { Card } from '../../../components/common/Card';
import { ErrorBanner, NoClientSelected, Toast } from '../../../components/crm/CrmUi';
import { formatDate } from '../../../components/crm/crmFilters';
import { useCrmContext, useCrmQuery, useCrmToast } from '../../../components/crm/crmHooks';
import { fieldClass } from '../../../components/crm/sales/SalesUi';
import { useAuth } from '../../../core/context/AuthContext';
import { describeApiError } from '../../../utils/apiError';
import { formatMoney } from '../../../types/crm';
import type { CrmBusinessProfile, CrmGatewaySettings, CrmReminderSettings } from '../../../types/crmSales';

/**
 * CRM Sales Phases 2, 3, 5 — business details for documents (GSTIN, address, bank / UPI,
 * terms), the business's OWN online payment gateway, and payment-reminder rules with the
 * approval queue. Secrets are write-only: never shown again, blank keeps the saved one.
 */
export default function CrmSalesSettingsPage() {
  const { hasPermission, user } = useAuth();
  const { needsClient, readOnly } = useCrmContext();
  const { toast, show } = useCrmToast();
  const canPay = hasPermission('manage-crm-payments');
  // Lead routing is an account-wide decision: for members who see every lead (QA fixes, 2026-10-14).
  const canRouteLeads = hasPermission('view-all-crm');
  // The CRM-only plan has no WhatsApp sending: reminders and documents go by email there.
  const hasWhatsApp = user?.capabilities?.whatsapp_send !== false;

  return (
    <div className="p-6">
      <div className="w-full space-y-6">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">Sales settings</h1>
          <p className="mt-1 text-sm text-slate-500">Your business details on documents, online payments and payment reminders.</p>
        </div>
        {needsClient ? (
          <NoClientSelected what="sales settings" />
        ) : (
          <>
            {canRouteLeads && <LeadRoutingCard readOnly={readOnly} hasWhatsApp={hasWhatsApp} onMessage={show} />}
            <ProfileCard readOnly={readOnly} onMessage={show} />
            {canPay && <GatewayCard readOnly={readOnly} onMessage={show} />}
            {canPay && <RemindersCard readOnly={readOnly} hasWhatsApp={hasWhatsApp} onMessage={show} />}
          </>
        )}
      </div>
      <Toast message={toast} />
    </div>
  );
}

function SaveButton({ saving, disabled, onClick }: { saving: boolean; disabled?: boolean; onClick: () => void }) {
  return (
    <button type="button" onClick={onClick} disabled={saving || disabled} className="flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:bg-slate-300">
      {saving && <Loader2 className="h-4 w-4 animate-spin" />} Save
    </button>
  );
}

/** QA fixes (2026-10-14) — who a new lead goes to, and who is told. */
function LeadRoutingCard({ readOnly, hasWhatsApp, onMessage }: { readOnly: boolean; hasWhatsApp: boolean; onMessage: (m: string) => void }) {
  const [s, setS] = useState<CrmLeadSettings | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    crmLeadSettingsService.get().then(setS).catch((e: unknown) => setError(describeApiError(e, 'Failed to load.').message));
  }, []);
  if (!s) return error ? <ErrorBanner message={error} /> : null;

  const save = () => {
    setSaving(true);
    crmLeadSettingsService
      .save(s)
      .then((r) => {
        setS(r.data);
        onMessage(r.message);
      })
      .catch((e: unknown) => onMessage(describeApiError(e, 'Could not save.').message))
      .finally(() => setSaving(false));
  };

  return (
    <Card className="space-y-4" data-testid="crm-lead-routing">
      <div>
        <p className="text-base font-semibold text-slate-900">New leads</p>
        <p className="text-sm text-slate-500">What happens when a lead arrives from an ad, WhatsApp, a journey, your website or the API.</p>
      </div>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <label className="text-sm font-medium text-slate-700">
          Assign new leads to
          <select value={s.assign_mode} onChange={(e) => setS({ ...s, assign_mode: e.target.value as CrmLeadSettings['assign_mode'] })} disabled={readOnly} className={fieldClass} aria-label="Assign new leads to">
            <option value="none">Nobody (assign by hand)</option>
            <option value="owner">The account owner</option>
            <option value="round_robin">Team members with CRM access, in turn</option>
          </select>
        </label>
        <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
          <input type="checkbox" checked={s.notify_assignee} onChange={(e) => setS({ ...s, notify_assignee: e.target.checked })} disabled={readOnly} className="h-4 w-4 rounded border-slate-300 text-emerald-600" />
          Notify the person a lead is assigned to (app + email)
        </label>
        {hasWhatsApp && (
          <label className="flex items-center gap-2 text-sm font-medium text-slate-700 sm:col-span-2">
            <input type="checkbox" checked={s.whatsapp_inbound_leads} onChange={(e) => setS({ ...s, whatsapp_inbound_leads: e.target.checked })} disabled={readOnly} className="h-4 w-4 rounded border-slate-300 text-emerald-600" />
            Create a lead when a new number messages us on WhatsApp
          </label>
        )}
      </div>
      <div className="flex justify-end">
        <SaveButton saving={saving} disabled={readOnly} onClick={save} />
      </div>
    </Card>
  );
}

function ProfileCard({ readOnly, onMessage }: { readOnly: boolean; onMessage: (m: string) => void }) {
  const [p, setP] = useState<CrmBusinessProfile | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    crmDocumentsService.getProfile().then(setP).catch((e: unknown) => setError(describeApiError(e, 'Failed to load.').message));
  }, []);
  if (!p) return error ? <ErrorBanner message={error} /> : null;
  const gstinOk = !p.gstin || /^[0-9]{2}[A-Za-z0-9]{13}$/.test(p.gstin);
  const text = (k: keyof CrmBusinessProfile, label: string, max = 191, span = false) => (
    <label className={`text-sm font-medium text-slate-700 ${span ? 'sm:col-span-2' : ''}`}>
      {label}
      <input value={(p[k] as string | null) ?? ''} onChange={(e) => setP({ ...p, [k]: e.target.value })} maxLength={max} disabled={readOnly} className={fieldClass} />
    </label>
  );

  const save = () => {
    setSaving(true);
    const { default_legal_name: _ignored, ...payload } = p;
    void _ignored;
    crmDocumentsService
      .saveProfile({ ...payload, gstin: p.gstin?.trim().toUpperCase() || null, state_code: p.state_code?.trim() || null })
      .then((r) => {
        setP(r.data);
        onMessage(r.message);
      })
      .catch((e: unknown) => onMessage(describeApiError(e, 'Could not save.').message))
      .finally(() => setSaving(false));
  };

  return (
    <Card className="space-y-4">
      <div>
        <p className="text-base font-semibold text-slate-900">Business details on documents</p>
        <p className="text-sm text-slate-500">With a GSTIN, invoices charge CGST + SGST inside your state and IGST outside it. Without one, no GST is charged.</p>
      </div>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <label className="text-sm font-medium text-slate-700 sm:col-span-2">
          Legal name
          <input value={p.legal_name ?? ''} placeholder={p.default_legal_name ?? ''} onChange={(e) => setP({ ...p, legal_name: e.target.value })} maxLength={191} disabled={readOnly} className={fieldClass} />
        </label>
        <label className="text-sm font-medium text-slate-700">
          GSTIN
          <input value={p.gstin ?? ''} onChange={(e) => setP({ ...p, gstin: e.target.value.toUpperCase() })} maxLength={15} disabled={readOnly} className={fieldClass} />
          {!gstinOk && <span className="mt-1 block text-xs text-red-600">15 characters, starting with the 2-digit state code.</span>}
        </label>
        <label className="text-sm font-medium text-slate-700">
          State code
          <input value={p.state_code ?? ''} onChange={(e) => setP({ ...p, state_code: e.target.value.replace(/\D/g, '').slice(0, 2) })} placeholder="From the GSTIN" disabled={readOnly} className={fieldClass} />
        </label>
        {text('address_line1', 'Address', 191, true)}
        {text('city', 'City', 100)}
        {text('pincode', 'PIN code', 12)}
        {text('phone', 'Phone', 32)}
        {text('email', 'Email (customers reply here)')}
        {text('upi_id', 'UPI ID', 100)}
        <label className="text-sm font-medium text-slate-700">
          Default payment days
          <input type="number" min={0} max={365} value={p.default_due_days} onChange={(e) => setP({ ...p, default_due_days: Number(e.target.value) })} disabled={readOnly} className={fieldClass} />
        </label>
        <label className="text-sm font-medium text-slate-700 sm:col-span-2">
          Bank details
          <textarea rows={2} value={p.bank_details ?? ''} onChange={(e) => setP({ ...p, bank_details: e.target.value })} maxLength={1000} disabled={readOnly} className={fieldClass} />
        </label>
        <label className="text-sm font-medium text-slate-700 sm:col-span-2">
          Default terms
          <textarea rows={2} value={p.default_terms ?? ''} onChange={(e) => setP({ ...p, default_terms: e.target.value })} maxLength={2000} disabled={readOnly} className={fieldClass} />
        </label>
        <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
          <input type="checkbox" checked={p.round_off} onChange={(e) => setP({ ...p, round_off: e.target.checked })} disabled={readOnly} className="h-4 w-4 rounded border-slate-300 text-emerald-600" />
          Round totals to the nearest rupee
        </label>
      </div>
      <div className="flex justify-end">
        <SaveButton saving={saving} disabled={readOnly || !gstinOk} onClick={save} />
      </div>
    </Card>
  );
}

function GatewayCard({ readOnly, onMessage }: { readOnly: boolean; onMessage: (m: string) => void }) {
  const [g, setG] = useState<CrmGatewaySettings | null>(null);
  const [keySecret, setKeySecret] = useState('');
  const [webhookSecret, setWebhookSecret] = useState('');
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    crmDocumentsService.getGateway().then(setG).catch(() => undefined);
  }, []);
  if (!g) return null;

  const save = () => {
    setSaving(true);
    crmDocumentsService
      .saveGateway({ gateway: g.gateway, mode: g.mode, key_id: g.key_id, key_secret: keySecret || null, webhook_secret: webhookSecret || null, is_enabled: g.is_enabled })
      .then((r) => {
        setG(r.data);
        setKeySecret('');
        setWebhookSecret('');
        onMessage(r.message);
      })
      .catch((e: unknown) => onMessage(describeApiError(e, 'Could not save.').message))
      .finally(() => setSaving(false));
  };

  return (
    <Card className="space-y-4">
      <div>
        <p className="text-base font-semibold text-slate-900">Online payments (your own gateway)</p>
        <p className="text-sm text-slate-500">Customers pay invoices from the link you send, straight into YOUR Razorpay account. Payments are recorded only after the gateway confirms them.</p>
      </div>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <label className="text-sm font-medium text-slate-700">
          Gateway
          <select value={g.gateway} onChange={(e) => setG({ ...g, gateway: e.target.value as CrmGatewaySettings['gateway'] })} disabled={readOnly} className={fieldClass}>
            <option value="razorpay">Razorpay</option>
            <option value="stripe">Stripe</option>
          </select>
        </label>
        <label className="text-sm font-medium text-slate-700">
          Mode
          <select value={g.mode} onChange={(e) => setG({ ...g, mode: e.target.value as CrmGatewaySettings['mode'] })} disabled={readOnly} className={fieldClass}>
            <option value="test">Test</option>
            <option value="live">Live</option>
          </select>
        </label>
        <label className="text-sm font-medium text-slate-700">
          Key ID
          <input value={g.key_id ?? ''} onChange={(e) => setG({ ...g, key_id: e.target.value })} maxLength={191} disabled={readOnly} className={fieldClass} />
        </label>
        <label className="text-sm font-medium text-slate-700">
          Key secret {g.has_key_secret && <span className="text-xs font-normal text-emerald-700">(saved — leave empty to keep)</span>}
          <input type="password" autoComplete="new-password" value={keySecret} onChange={(e) => setKeySecret(e.target.value)} disabled={readOnly} className={fieldClass} />
        </label>
        <label className="text-sm font-medium text-slate-700">
          Webhook secret {g.has_webhook_secret && <span className="text-xs font-normal text-emerald-700">(saved)</span>}
          <input type="password" autoComplete="new-password" value={webhookSecret} onChange={(e) => setWebhookSecret(e.target.value)} disabled={readOnly} className={fieldClass} />
        </label>
        <label className="flex items-center gap-2 self-end text-sm font-medium text-slate-700">
          <input type="checkbox" checked={g.is_enabled} onChange={(e) => setG({ ...g, is_enabled: e.target.checked })} disabled={readOnly} className="h-4 w-4 rounded border-slate-300 text-emerald-600" />
          Accept online payments
        </label>
      </div>
      {g.webhook_url && (
        <div className="rounded-lg bg-slate-50 p-3 text-sm">
          <p className="font-medium text-slate-800">Webhook URL (add it in your {g.gateway === 'razorpay' ? 'Razorpay' : 'Stripe'} dashboard with the webhook secret)</p>
          <div className="mt-1 flex items-center gap-2">
            <code className="min-w-0 flex-1 truncate text-xs text-slate-600">{g.webhook_url}</code>
            <button type="button" onClick={() => navigator.clipboard?.writeText(g.webhook_url!).then(() => onMessage('Webhook URL copied.'))} aria-label="Copy webhook URL" className="rounded p-1 text-slate-500 hover:bg-slate-200">
              <Copy className="h-4 w-4" />
            </button>
          </div>
        </div>
      )}
      {g.gateway === 'stripe' && <p className="text-xs text-amber-700">The customer payment page supports Razorpay checkout today. With Stripe, payments arrive through the webhook only.</p>}
      <div className="flex justify-end">
        <SaveButton saving={saving} disabled={readOnly} onClick={save} />
      </div>
    </Card>
  );
}

function RemindersCard({ readOnly, hasWhatsApp, onMessage }: { readOnly: boolean; hasWhatsApp: boolean; onMessage: (m: string) => void }) {
  const [s, setS] = useState<CrmReminderSettings | null>(null);
  const [saving, setSaving] = useState(false);
  const [version, setVersion] = useState(0);
  const queue = useCrmQuery(`reminder-queue:${version}`, () => crmDocumentsService.listReminders('awaiting_approval'), 'Failed to load reminders.');
  useEffect(() => {
    crmDocumentsService.getReminderSettings().then(setS).catch(() => undefined);
  }, []);
  if (!s) return null;

  const days = (v: string) => Array.from(new Set(v.split(/[ ,]+/).map(Number).filter((n) => Number.isInteger(n) && n > 0 && n <= 120))).slice(0, 6);
  const save = () => {
    setSaving(true);
    crmDocumentsService
      .saveReminderSettings(s)
      .then((r) => {
        setS(r.data);
        onMessage(r.message);
      })
      .catch((e: unknown) => onMessage(describeApiError(e, 'Could not save.').message))
      .finally(() => setSaving(false));
  };
  const act = (p: Promise<{ message: string }>) =>
    p.then((r) => {
      onMessage(r.message);
      setVersion((v) => v + 1);
    }).catch((e: unknown) => onMessage(describeApiError(e, 'That did not work.').message));

  return (
    <Card className="space-y-4">
      <div>
        <p className="text-base font-semibold text-slate-900">Payment reminders</p>
        <p className="text-sm text-slate-500">Checked every hour. A reminder is never sent twice, always shows the current balance, and stops once the invoice is paid.</p>
      </div>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <label className="flex items-center gap-2 text-sm font-medium text-slate-700 sm:col-span-2">
          <input type="checkbox" checked={s.enabled} onChange={(e) => setS({ ...s, enabled: e.target.checked })} disabled={readOnly} className="h-4 w-4 rounded border-slate-300 text-emerald-600" />
          Send payment reminders automatically
        </label>
        <label className="text-sm font-medium text-slate-700">
          Days before the due date
          <input defaultValue={s.days_before.join(', ')} onBlur={(e) => setS({ ...s, days_before: days(e.target.value).slice(0, 5) })} placeholder="e.g. 3" disabled={readOnly} className={fieldClass} />
        </label>
        <label className="text-sm font-medium text-slate-700">
          Days after the due date
          <input defaultValue={s.days_after.join(', ')} onBlur={(e) => setS({ ...s, days_after: days(e.target.value) })} placeholder="e.g. 3, 7, 15" disabled={readOnly} className={fieldClass} />
        </label>
        <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
          <input type="checkbox" checked={s.on_due_date} onChange={(e) => setS({ ...s, on_due_date: e.target.checked })} disabled={readOnly} className="h-4 w-4 rounded border-slate-300 text-emerald-600" />
          On the due date
        </label>
        <label className="text-sm font-medium text-slate-700">
          Channel
          {/* QA fixes (2026-10-14): without WhatsApp in the plan (CRM plan) only email can work. */}
          <select value={hasWhatsApp ? s.channel : 'email'} onChange={(e) => setS({ ...s, channel: e.target.value as CrmReminderSettings['channel'] })} disabled={readOnly || !hasWhatsApp} className={fieldClass}>
            {hasWhatsApp && <option value="whatsapp">WhatsApp</option>}
            <option value="email">Email</option>
            {hasWhatsApp && <option value="both">Both</option>}
          </select>
          {!hasWhatsApp && <span className="mt-1 block text-xs font-normal text-slate-500">Your plan has no WhatsApp sending, so reminders go by email.</span>}
        </label>
        <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
          <input type="checkbox" checked={!s.auto_send} onChange={(e) => setS({ ...s, auto_send: !e.target.checked })} disabled={readOnly} className="h-4 w-4 rounded border-slate-300 text-emerald-600" />
          I approve each reminder before it is sent
        </label>
        {hasWhatsApp && s.channel === 'whatsapp' && (
          <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
            <input type="checkbox" checked={s.email_fallback} onChange={(e) => setS({ ...s, email_fallback: e.target.checked })} disabled={readOnly} className="h-4 w-4 rounded border-slate-300 text-emerald-600" />
            If WhatsApp fails, send an email
          </label>
        )}
        <label className="text-sm font-medium text-slate-700">
          Quiet hours (no sending) from
          <input type="number" min={0} max={23} value={s.quiet_start_hour ?? ''} onChange={(e) => setS({ ...s, quiet_start_hour: e.target.value === '' ? null : Number(e.target.value) })} placeholder="21" disabled={readOnly} className={fieldClass} />
        </label>
        <label className="text-sm font-medium text-slate-700">
          to
          <input type="number" min={0} max={23} value={s.quiet_end_hour ?? ''} onChange={(e) => setS({ ...s, quiet_end_hour: e.target.value === '' ? null : Number(e.target.value) })} placeholder="9" disabled={readOnly} className={fieldClass} />
        </label>
      </div>
      <div className="flex justify-end">
        <SaveButton saving={saving} disabled={readOnly} onClick={save} />
      </div>

      <div>
        <p className="text-sm font-semibold text-slate-900">Waiting for your approval</p>
        {(queue.data?.data ?? []).length === 0 ? (
          <p className="mt-1 text-sm text-slate-500">Nothing waiting.</p>
        ) : (
          <ul className="mt-2 divide-y divide-slate-100 text-sm" data-testid="crm-reminder-queue">
            {(queue.data?.data ?? []).map((r) => (
              <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                <span>
                  {r.document ? <Link to={`/crm/documents/${r.document.id}`} className="font-medium text-emerald-700 hover:underline">{r.document.number}</Link> : 'Invoice'} ·{' '}
                  {r.document?.customer_name ?? ''} · balance {formatMoney(r.document?.balance_due)} · {r.rule_key} ({formatDate(r.scheduled_for)})
                </span>
                <span className="flex gap-2">
                  <button type="button" disabled={readOnly} onClick={() => act(crmDocumentsService.approveReminder(r.id))} className="rounded-lg bg-emerald-600 px-3 py-1 text-xs font-semibold text-white disabled:opacity-50">
                    Send
                  </button>
                  <button type="button" disabled={readOnly} onClick={() => act(crmDocumentsService.skipReminder(r.id))} className="rounded-lg border border-slate-300 px-3 py-1 text-xs text-slate-700 disabled:opacity-50">
                    Skip
                  </button>
                </span>
              </li>
            ))}
          </ul>
        )}
      </div>
    </Card>
  );
}
