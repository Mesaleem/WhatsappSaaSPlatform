import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { BellRing, Copy, Download, FileMinus2, Paperclip, Pencil, Send, Stamp, Trash2, Wallet } from 'lucide-react';
import crmDocumentsService from '../../../services/crmDocumentsService';
import { Card } from '../../../components/common/Card';
import ConfirmModal from '../../../components/common/ConfirmModal';
import PromptModal from '../../../components/common/PromptModal';
import { ErrorBanner, NoClientSelected, READ_ONLY_TITLE, Spinner, Toast } from '../../../components/crm/CrmUi';
import { formatDate } from '../../../components/crm/crmFilters';
import { useCrmContext, useCrmQuery, useCrmToast } from '../../../components/crm/crmHooks';
import { DocStatusBadge } from '../../../components/crm/sales/SalesUi';
import RecordPaymentModal from '../../../components/crm/sales/RecordPaymentModal';
import SendDocumentModal from '../../../components/crm/sales/SendDocumentModal';
import { useAuth } from '../../../core/context/AuthContext';
import { describeApiError } from '../../../utils/apiError';
import { crmLabel, formatMoney } from '../../../types/crm';
import { CRM_DOC_ACTIONS, CRM_DOC_CONVERSIONS, CRM_DOC_TYPE_LABELS, crmDocStatusLabel, type CrmDocDetail } from '../../../types/crmSales';

const ACTION_LABELS: Record<string, string> = {
  accepted: 'Mark accepted',
  rejected: 'Mark rejected',
  cancelled: 'Cancel',
  approved: 'Approve',
  fulfilled: 'Mark fulfilled',
  void: 'Void',
};

/**
 * CRM Sales Phases 2–5 — one document: lines and totals (as stored by the server), the
 * lifecycle actions the server allows, issue, convert, credit note, PDF, send, record
 * payment, payment reminder, share link and the delivery / payment history.
 */
export default function CrmDocumentDetailPage() {
  const { id } = useParams();
  const docId = Number(id);
  const navigate = useNavigate();
  const { hasPermission } = useAuth();
  const { needsClient, readOnly, selectedAccountId } = useCrmContext();
  const { toast, show } = useCrmToast();
  const [version, setVersion] = useState(0);
  const [busy, setBusy] = useState(false);
  const [askReason, setAskReason] = useState<string | null>(null);
  const [confirmIssue, setConfirmIssue] = useState(false);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [sending, setSending] = useState(false);
  const [paying, setPaying] = useState(false);
  const canPay = hasPermission('manage-crm-payments');

  const valid = Number.isInteger(docId) && docId > 0;
  const query = useCrmQuery(needsClient || !valid ? null : `doc:${selectedAccountId ?? 'own'}:${docId}:${version}`, () => crmDocumentsService.getDocument(docId), 'Failed to load this document.');
  const doc = query.data;

  const run = (p: Promise<{ message: string; data?: unknown }>, after?: (data: unknown) => void) => {
    setBusy(true);
    p.then((res) => {
      show(res.message);
      if (after) after(res.data);
      else setVersion((v) => v + 1);
    })
      .catch((err: unknown) => show(describeApiError(err, 'That did not work.').message))
      .finally(() => {
        setBusy(false);
        setAskReason(null);
        setConfirmIssue(false);
      });
  };

  const actions = doc ? (CRM_DOC_ACTIONS[doc.type][doc.status] ?? []) : [];
  const conversions = doc && ['sent', 'accepted', 'received', 'approved', 'confirmed', 'fulfilled'].includes(doc.status) ? (CRM_DOC_CONVERSIONS[doc.type] ?? []) : [];
  const btn = 'flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50';
  const primary = 'flex items-center gap-2 rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50';
  const off = readOnly || busy;

  return (
    <div className="p-6">
      <div className="w-full space-y-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <Link to={`/crm/documents?type=${doc?.type ?? 'invoice'}`} className="text-xs font-medium text-emerald-700 hover:underline">
              All {doc ? CRM_DOC_TYPE_LABELS[doc.type].toLowerCase() + 's' : 'documents'}
            </Link>
            <h1 className="mt-1 flex flex-wrap items-center gap-3 text-xl font-semibold text-slate-900">
              {doc ? `${CRM_DOC_TYPE_LABELS[doc.type]} ${doc.number ?? (doc.external_reference ?? `draft #${doc.id}`)}` : 'Document'}
              {doc && <DocStatusBadge status={doc.status} overdue={doc.is_overdue} />}
            </h1>
            <p className="mt-1 text-sm text-slate-500">{doc?.customer_name ?? (doc ? 'No customer chosen yet' : '')}</p>
          </div>
          {doc && (
            <div className="flex flex-wrap gap-2">
              {doc.editable && (
                <>
                  <button type="button" className={btn} disabled={off} title={readOnly ? READ_ONLY_TITLE : undefined} onClick={() => navigate(`/crm/documents/${doc.id}/edit`)}>
                    <Pencil className="h-4 w-4" /> Edit
                  </button>
                  {doc.type !== 'customer_po' && (
                    <button type="button" className={primary} disabled={off} onClick={() => setConfirmIssue(true)}>
                      <Stamp className="h-4 w-4" /> Issue
                    </button>
                  )}
                  {doc.number === null && doc.type !== 'customer_po' && (
                    <button type="button" className={btn} disabled={off} onClick={() => setConfirmDelete(true)} aria-label="Delete draft">
                      <Trash2 className="h-4 w-4" />
                    </button>
                  )}
                </>
              )}
              {doc.number && !['void', 'cancelled'].includes(doc.status) && (
                <button type="button" className={btn} disabled={off} onClick={() => setSending(true)}>
                  <Send className="h-4 w-4" /> Send
                </button>
              )}
              <button type="button" className={btn} disabled={busy} onClick={() => crmDocumentsService.downloadPdf(doc.id, `${doc.type}-${doc.number ?? doc.id}.pdf`).catch((e: unknown) => show(describeApiError(e, 'Could not download the PDF.').message))}>
                <Download className="h-4 w-4" /> PDF
              </button>
              {canPay && doc.type === 'invoice' && ['issued', 'partially_paid'].includes(doc.status) && (
                <>
                  <button type="button" className={primary} disabled={off} onClick={() => setPaying(true)}>
                    <Wallet className="h-4 w-4" /> Record payment
                  </button>
                  <button type="button" className={btn} disabled={off} onClick={() => run(crmDocumentsService.remind(doc.id))}>
                    <BellRing className="h-4 w-4" /> Send reminder
                  </button>
                </>
              )}
              {doc.type === 'invoice' && ['issued', 'partially_paid', 'paid'].includes(doc.status) && (
                <button type="button" className={btn} disabled={off} onClick={() => setAskReason('credit_note')}>
                  <FileMinus2 className="h-4 w-4" /> Credit note
                </button>
              )}
              {conversions.map((t) => (
                <button key={t} type="button" className={btn} disabled={off} onClick={() => run(crmDocumentsService.convert(doc.id, t), (d) => navigate(`/crm/documents/${(d as CrmDocDetail).id}`))}>
                  Create {CRM_DOC_TYPE_LABELS[t].toLowerCase()}
                </button>
              ))}
              {actions.map((a) => (
                <button
                  key={a}
                  type="button"
                  className={['void', 'cancelled', 'rejected'].includes(a) ? `${btn} text-red-600` : btn}
                  disabled={off}
                  onClick={() => (['void', 'cancelled', 'rejected'].includes(a) ? setAskReason(a) : run(crmDocumentsService.changeStatus(doc.id, a)))}
                >
                  {ACTION_LABELS[a] ?? crmDocStatusLabel(a)}
                </button>
              ))}
            </div>
          )}
        </div>

        {needsClient ? (
          <NoClientSelected what="sales documents" />
        ) : query.isLoading && !doc ? (
          <Spinner />
        ) : query.error || !valid ? (
          <ErrorBanner message={query.status === 404 || !valid ? 'This document could not be found.' : (query.error ?? '')} />
        ) : doc ? (
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <Card className="space-y-5 lg:col-span-2" padded>
              <dl className="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                <Info label="Date">{formatDate(doc.issue_date)}</Info>
                {doc.due_date && <Info label="Due">{formatDate(doc.due_date)}</Info>}
                {doc.valid_until && <Info label="Valid until">{formatDate(doc.valid_until)}</Info>}
                <Info label="GST">{doc.tax_mode === 'intra' ? 'CGST + SGST' : doc.tax_mode === 'inter' ? 'IGST' : 'Not charged'}</Info>
                {doc.external_reference && <Info label="Customer PO">{doc.external_reference}</Info>}
              </dl>

              {doc.body && <p className="whitespace-pre-line rounded-lg bg-slate-50 p-3 text-sm text-slate-700">{doc.body}</p>}

              {doc.items.length > 0 && (
                <div className="overflow-x-auto">
                  <table className="min-w-full text-sm" data-testid="crm-doc-items">
                    <thead>
                      <tr className="border-b border-slate-200 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <th className="py-2 pr-3">Description</th>
                        <th className="py-2 pr-3 text-right">Qty</th>
                        <th className="py-2 pr-3 text-right">Rate</th>
                        <th className="py-2 pr-3 text-right">GST</th>
                        <th className="py-2 text-right">Amount</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {doc.items.map((it) => (
                        <tr key={it.id}>
                          <td className="py-2 pr-3 text-slate-800">
                            {it.description}
                            {it.hsn_sac && <span className="ml-1 text-xs text-slate-500">HSN/SAC {it.hsn_sac}</span>}
                          </td>
                          <td className="py-2 pr-3 text-right text-slate-700">{Number(it.quantity)} {it.unit}</td>
                          <td className="py-2 pr-3 text-right text-slate-700">{formatMoney(it.unit_price)}</td>
                          <td className="py-2 pr-3 text-right text-slate-700">{Number(it.tax_rate)}%</td>
                          <td className="py-2 text-right font-medium text-slate-900">{formatMoney(it.line_total)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}

              <dl className="ml-auto max-w-xs space-y-1 text-sm" data-testid="crm-doc-totals">
                <Row label="Taxable amount" value={formatMoney(doc.taxable_total)} />
                {doc.tax_mode === 'intra' && <Row label="CGST" value={formatMoney(doc.cgst_total)} />}
                {doc.tax_mode === 'intra' && <Row label="SGST" value={formatMoney(doc.sgst_total)} />}
                {doc.tax_mode === 'inter' && <Row label="IGST" value={formatMoney(doc.igst_total)} />}
                {doc.round_off !== '0.00' && <Row label="Round off" value={doc.round_off} />}
                <Row label="Total" value={formatMoney(doc.grand_total)} strong />
                {doc.type === 'invoice' && doc.status !== 'draft' && (
                  <>
                    <Row label="Paid" value={formatMoney(doc.amount_paid)} />
                    {doc.amount_credited !== '0.00' && <Row label="Credited" value={formatMoney(doc.amount_credited)} />}
                    <Row label="Balance due" value={formatMoney(doc.balance_due)} strong />
                  </>
                )}
              </dl>
              {doc.cancel_reason && <p className="text-sm text-slate-500">Reason: {doc.cancel_reason}</p>}
              <p className="text-xs text-slate-500" data-testid="crm-doc-audit">
                {doc.created_by && <>Created by {doc.created_by.name}. </>}
                {doc.issued_by && <>Issued by {doc.issued_by.name} on {formatDate(doc.issued_at)}. </>}
                {doc.decided_by && <>Marked {crmDocStatusLabel(doc.status).toLowerCase()} by {doc.decided_by.name} on {formatDate(doc.decided_at)}.</>}
              </p>
            </Card>

            <div className="space-y-6">
              {doc.share && (
                <Card>
                  <p className="text-sm font-semibold text-slate-900">Customer link</p>
                  <p className="mt-1 text-xs text-slate-500">Anyone with this link can view {doc.type === 'invoice' ? 'and pay ' : ''}this document only.</p>
                  <button
                    type="button"
                    onClick={() => navigator.clipboard?.writeText(doc.share!.view_url).then(() => show('Link copied.'))}
                    className="mt-2 flex w-full items-center justify-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50"
                  >
                    <Copy className="h-4 w-4" /> Copy link
                  </button>
                </Card>
              )}
              <AttachmentCard doc={doc} readOnly={readOnly} onDone={(m) => { show(m); setVersion((v) => v + 1); }} onError={show} />
              {doc.type === 'invoice' && doc.number && <RemindersCard docId={doc.id} />}
              {(doc.gateway_orders ?? []).length > 0 && (
                <Card>
                  <p className="text-sm font-semibold text-slate-900">Online payment attempts</p>
                  <ul className="mt-2 space-y-1 text-sm" data-testid="crm-gateway-attempts">
                    {(doc.gateway_orders ?? []).map((o) => (
                      <li key={o.id} className="flex justify-between">
                        <span className="text-slate-700">
                          {crmLabel(o.gateway)} · {formatDate(o.created_at)}
                        </span>
                        <span className="flex items-center gap-2">
                          {formatMoney(o.amount)} <DocStatusBadge status={o.status === 'created' ? 'pending' : o.status} />
                        </span>
                      </li>
                    ))}
                  </ul>
                </Card>
              )}
              {(doc.parent_id || doc.children.length > 0) && (
                <Card>
                  <p className="text-sm font-semibold text-slate-900">Linked documents</p>
                  <ul className="mt-2 space-y-1 text-sm">
                    {doc.parent_id && (
                      <li>
                        <Link to={`/crm/documents/${doc.parent_id}`} className="text-emerald-700 hover:underline">Created from document #{doc.parent_id}</Link>
                      </li>
                    )}
                    {doc.children.map((c) => (
                      <li key={c.id}>
                        <Link to={`/crm/documents/${c.id}`} className="text-emerald-700 hover:underline">
                          {CRM_DOC_TYPE_LABELS[c.type]} {c.number ?? `draft #${c.id}`}
                        </Link>{' '}
                        <span className="text-slate-500">· {crmDocStatusLabel(c.status)}</span>
                      </li>
                    ))}
                  </ul>
                </Card>
              )}
              {doc.allocations.length > 0 && (
                <Card>
                  <p className="text-sm font-semibold text-slate-900">Payments</p>
                  <ul className="mt-2 space-y-1 text-sm">
                    {doc.allocations.map((a) => (
                      <li key={a.id} className={`flex justify-between ${a.reversed ? 'text-slate-400 line-through' : 'text-slate-700'}`}>
                        <span>
                          {a.payment.number} · {crmLabel(a.payment.method)} · {formatDate(a.payment.paid_on)}
                        </span>
                        <span>{formatMoney(a.amount)}</span>
                      </li>
                    ))}
                  </ul>
                </Card>
              )}
              <Card>
                <p className="text-sm font-semibold text-slate-900">Sent</p>
                {doc.deliveries.length === 0 ? (
                  <p className="mt-1 text-sm text-slate-500">Not sent yet.</p>
                ) : (
                  <ul className="mt-2 space-y-2 text-sm" data-testid="crm-doc-deliveries">
                    {doc.deliveries.map((d) => (
                      <li key={d.id}>
                        <div className="flex justify-between">
                          <span className="text-slate-800">
                            {d.channel === 'whatsapp' ? 'WhatsApp' : crmLabel(d.channel)}
                            {d.purpose === 'reminder' && ' reminder'} · {d.recipient}
                          </span>
                          <DocStatusBadge status={d.status} />
                        </div>
                        <p className="text-xs text-slate-500">
                          {formatDate(d.sent_at ?? d.created_at)}
                          {d.sent_by ? ` · ${d.sent_by.name}` : ''}
                          {d.error ? ` · ${d.error}` : ''}
                        </p>
                      </li>
                    ))}
                  </ul>
                )}
              </Card>
            </div>
          </div>
        ) : null}
      </div>

      {confirmIssue && doc && (
        <ConfirmModal
          title={`Issue this ${CRM_DOC_TYPE_LABELS[doc.type].toLowerCase()}`}
          message="It gets its number and becomes final: its lines, prices, tax and customer details can no longer be changed."
          confirmLabel="Issue"
          isLoading={busy}
          onConfirm={() => run(crmDocumentsService.issue(doc.id))}
          onCancel={() => setConfirmIssue(false)}
        />
      )}
      {confirmDelete && doc && (
        <ConfirmModal
          title="Delete draft"
          message="Delete this draft? It has no number yet, so nothing else changes."
          confirmLabel="Delete"
          isLoading={busy}
          onConfirm={() =>
            crmDocumentsService
              .deleteDocument(doc.id)
              .then(() => navigate(`/crm/documents?type=${doc.type}`, { replace: true }))
              .catch((e: unknown) => {
                show(describeApiError(e, 'Could not delete.').message);
                setConfirmDelete(false);
              })
          }
          onCancel={() => setConfirmDelete(false)}
        />
      )}
      {askReason && doc && (
        <PromptModal
          title={askReason === 'credit_note' ? 'Create a credit note' : `${ACTION_LABELS[askReason] ?? crmDocStatusLabel(askReason)} ${doc.number ?? ''}`}
          message={askReason === 'credit_note' ? 'A draft credit note for the full invoice is created; edit its lines before issuing it.' : 'Optionally record why.'}
          label="Reason (optional)"
          confirmLabel={askReason === 'credit_note' ? 'Create draft' : 'Confirm'}
          isLoading={busy}
          onSubmit={(reason) =>
            askReason === 'credit_note'
              ? run(crmDocumentsService.creditNote(doc.id, reason || null), (d) => navigate(`/crm/documents/${(d as CrmDocDetail).id}`))
              : run(crmDocumentsService.changeStatus(doc.id, askReason, reason || null))
          }
          onCancel={() => setAskReason(null)}
        />
      )}
      {sending && doc && (
        <SendDocumentModal
          doc={doc}
          onCancel={() => setSending(false)}
          onDone={(_, message) => {
            setSending(false);
            show(message);
            setVersion((v) => v + 1);
          }}
        />
      )}
      {paying && doc && (
        <RecordPaymentModal
          invoice={doc}
          onCancel={() => setPaying(false)}
          onSaved={(_, message) => {
            setPaying(false);
            show(message);
            setVersion((v) => v + 1);
          }}
        />
      )}
      <Toast message={toast} />
    </div>
  );
}

function AttachmentCard({ doc, readOnly, onDone, onError }: { doc: CrmDocDetail; readOnly: boolean; onDone: (m: string) => void; onError: (m: string) => void }) {
  const [uploading, setUploading] = useState(false);
  if (!doc.attachment && (readOnly || ['void', 'cancelled'].includes(doc.status))) return null;

  return (
    <Card>
      <p className="text-sm font-semibold text-slate-900">{doc.type === 'customer_po' ? "Customer's PO file" : 'Attachment'}</p>
      {doc.attachment ? (
        <button type="button" onClick={() => crmDocumentsService.downloadAttachment(doc.id, doc.attachment!.name).catch(() => onError('Could not download the file.'))} className="mt-2 flex items-center gap-2 text-sm text-emerald-700 hover:underline">
          <Paperclip className="h-4 w-4" /> {doc.attachment.name}
        </button>
      ) : (
        <p className="mt-1 text-sm text-slate-500">No file attached.</p>
      )}
      {!readOnly && !['void', 'cancelled'].includes(doc.status) && (
        <label className="mt-2 inline-flex cursor-pointer items-center gap-2 rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
          {uploading ? 'Uploading…' : doc.attachment ? 'Replace file' : 'Attach PDF / image'}
          <input
            type="file"
            accept="application/pdf,image/png,image/jpeg"
            className="sr-only"
            aria-label="Attach a file"
            disabled={uploading}
            onChange={(e) => {
              const file = e.target.files?.[0];
              if (!file) return;
              setUploading(true);
              crmDocumentsService
                .uploadAttachment(doc.id, file)
                .then((r) => onDone(r.message))
                .catch((err: unknown) => onError(describeApiError(err, 'Could not attach the file.').message))
                .finally(() => setUploading(false));
            }}
          />
        </label>
      )}
    </Card>
  );
}

function RemindersCard({ docId }: { docId: number }) {
  const q = useCrmQuery(`doc-reminders:${docId}`, () => crmDocumentsService.documentReminders(docId), 'Failed to load reminders.');
  const rows = q.data ?? [];
  if (rows.length === 0) return null;

  return (
    <Card>
      <p className="text-sm font-semibold text-slate-900">Payment reminders</p>
      <ul className="mt-2 space-y-1 text-sm" data-testid="crm-doc-reminders">
        {rows.map((r) => (
          <li key={r.id} className="flex justify-between gap-2">
            <span className="text-slate-700">
              {r.rule_key.replace(':', ' ')} · {formatDate(r.scheduled_for)}
              {r.last_error && <span className="block text-xs text-slate-500">{r.last_error}</span>}
            </span>
            <DocStatusBadge status={r.status} />
          </li>
        ))}
      </ul>
    </Card>
  );
}

function Info({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</dt>
      <dd className="mt-1 text-slate-800">{children}</dd>
    </div>
  );
}

function Row({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) {
  return (
    <div className={`flex justify-between gap-6 ${strong ? 'border-t border-slate-200 pt-1 font-semibold text-slate-900' : 'text-slate-600'}`}>
      <dt>{label}</dt>
      <dd>{value}</dd>
    </div>
  );
}
