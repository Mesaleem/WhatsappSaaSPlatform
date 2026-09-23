import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowRightLeft, Loader2, Trash2, UserRound, XCircle } from 'lucide-react';
import crmService from '../../services/crmService';
import { Card } from '../../components/common/Card';
import ConfirmModal from '../../components/common/ConfirmModal';
import { SearchInput } from '../../components/common/DataTableControls';
import {
  AssigneeControl,
  ErrorBanner,
  LeadTagEditor,
  NoClientSelected,
  READ_ONLY_TITLE,
  Spinner,
  StatusBadge,
  StatusControl,
  Toast,
} from '../../components/crm/CrmUi';
import { formatDate } from '../../components/crm/crmFilters';
import { useCrmAssignees, useCrmContext, useCrmQuery, useCrmToast } from '../../components/crm/crmHooks';
import { describeApiError } from '../../utils/apiError';
import { crmSourceLabel, type CrmContact, type CrmLead } from '../../types/crm';

/**
 * Phase 6 — CRM Task 8. One CRM lead — GET /api/crm/leads/{id}.
 *
 * Actions all go through the existing dedicated endpoints:
 *   status         PATCH /crm/leads/{id}/status
 *   assignee       PATCH /crm/leads/{id}/assignee
 *   tags           POST|DELETE /crm/leads/{id}/tags/{tag}
 *   contact        PATCH /crm/leads/{id}/contact
 *   delete         DELETE /crm/leads/{id} (hard delete; the contact stays)
 * The lead each call returns replaces the page's copy, so what is shown is
 * always the server's state. A foreign or missing id is the backend's
 * identical 404, shown as "not found".
 */
export default function CrmLeadDetailPage() {
  const { id } = useParams();
  const leadId = Number(id);
  const navigate = useNavigate();
  const { needsClient, readOnly, selectedAccountId } = useCrmContext();
  const { assignees } = useCrmAssignees(!needsClient, selectedAccountId);
  const { toast, show } = useCrmToast();

  const [confirmDelete, setConfirmDelete] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [reassigning, setReassigning] = useState(false);

  const validId = Number.isInteger(leadId) && leadId > 0;
  const query = useCrmQuery(
    needsClient || !validId ? null : `lead:${selectedAccountId ?? 'own'}:${leadId}`,
    () => crmService.getLead(leadId),
    'Failed to load this lead.',
  );
  const lead = query.data;
  const isLoading = query.isLoading;
  const loadError = !validId
    ? { status: 404, message: 'Lead not found.' }
    : query.error !== null
      ? { status: query.status, message: query.error }
      : null;

  /** Every action returns the updated lead; it replaces the page's copy. */
  const onUpdated = (updated: CrmLead, message: string) => {
    query.setData(() => updated);
    show(message);
  };

  const doDelete = () => {
    if (!lead || deleting) return;
    setDeleting(true);
    crmService
      .deleteLead(lead.id)
      .then(() => navigate('/crm/leads', { replace: true }))
      .catch((err: unknown) => {
        show(describeApiError(err, 'Failed to delete the lead.').message);
        setDeleting(false);
        setConfirmDelete(false);
      });
  };

  return (
    <div className="p-6">
      <div className="w-full space-y-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <Link to="/crm/leads" className="text-xs font-medium text-indigo-600 hover:underline">
              All leads
            </Link>
            <h1 className="mt-1 text-xl font-semibold text-slate-900">
              {lead ? lead.contact?.name || 'Unnamed contact' : 'Lead'}
            </h1>
            <p className="mt-1 text-sm text-slate-500">One CRM opportunity: its lifecycle, owner, tags and contact.</p>
          </div>
          {lead && (
            <button
              type="button"
              onClick={() => setConfirmDelete(true)}
              disabled={readOnly}
              title={readOnly ? READ_ONLY_TITLE : undefined}
              className="flex items-center gap-2 rounded-lg border border-red-200 bg-white px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50"
            >
              <Trash2 className="h-4 w-4" />
              Delete lead
            </button>
          )}
        </div>

        {needsClient ? (
          <NoClientSelected what="CRM leads" />
        ) : isLoading ? (
          <Spinner />
        ) : loadError ? (
          <div className="space-y-3">
            <ErrorBanner message={loadError.status === 404 ? 'This lead could not be found. It may have been deleted.' : loadError.message} />
            <Link to="/crm/leads" className="text-sm font-medium text-indigo-600 hover:underline">
              Back to leads
            </Link>
          </div>
        ) : lead ? (
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <Card className="space-y-5 lg:col-span-2">
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                  <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Status</p>
                  <div className="mt-1.5 flex flex-wrap items-center gap-2">
                    <StatusBadge status={lead.status} />
                    <StatusControl lead={lead} disabled={readOnly} onUpdated={onUpdated} onError={show} />
                  </div>
                  {lead.status === 'not_converted' && lead.not_converted_reason && (
                    <p className="mt-1 text-xs text-slate-500">Reason: {lead.not_converted_reason}</p>
                  )}
                </div>
                <div>
                  <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Assignee</p>
                  <div className="mt-1.5">
                    <AssigneeControl lead={lead} assignees={assignees} disabled={readOnly} onUpdated={onUpdated} onError={show} />
                  </div>
                </div>
              </div>

              <div>
                <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Tags</p>
                <div className="mt-1.5">
                  <LeadTagEditor lead={lead} disabled={readOnly} onUpdated={onUpdated} onError={show} />
                </div>
              </div>

              <dl className="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                <div>
                  <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">Source</dt>
                  <dd className="mt-1 text-slate-800">{crmSourceLabel(lead.source)}</dd>
                </div>
                <div>
                  <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">Created</dt>
                  <dd className="mt-1 text-slate-800">{formatDate(lead.created_at)}</dd>
                </div>
                <div>
                  <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">Last updated</dt>
                  <dd className="mt-1 text-slate-800">{formatDate(lead.updated_at)}</dd>
                </div>
                {lead.converted_at && (
                  <div>
                    <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">Converted</dt>
                    <dd className="mt-1 text-slate-800">{formatDate(lead.converted_at)}</dd>
                  </div>
                )}
                {lead.not_converted_at && (
                  <div>
                    <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">Closed (not converted)</dt>
                    <dd className="mt-1 text-slate-800">{formatDate(lead.not_converted_at)}</dd>
                  </div>
                )}
              </dl>

              {lead.capture_lead && (
                <div className="rounded-lg bg-slate-50 p-3 text-sm" data-testid="crm-capture-info">
                  <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Captured from</p>
                  <p className="mt-1 text-slate-800">
                    {lead.capture_lead.provider ?? 'Unknown provider'}
                    {lead.capture_lead.provider_lead_id && (
                      <span className="text-slate-500"> · {lead.capture_lead.provider_lead_id}</span>
                    )}
                  </p>
                  <p className="text-xs text-slate-500">{formatDate(lead.capture_lead.captured_at)}</p>
                </div>
              )}
            </Card>

            <Card className="space-y-3">
              <div className="flex items-center gap-2">
                <UserRound className="h-4 w-4 text-slate-400" />
                <p className="text-sm font-semibold text-slate-900">Contact</p>
              </div>
              {lead.contact ? (
                <>
                  <div className="text-sm">
                    <p className="font-medium text-slate-900">{lead.contact.name || 'Unnamed contact'}</p>
                    <p className="text-slate-600">{lead.contact.phone_number}</p>
                    {lead.contact.email && <p className="text-slate-600">{lead.contact.email}</p>}
                  </div>
                  <div className="flex flex-wrap gap-2">
                    <Link
                      to={`/crm/contacts/${lead.contact.id}`}
                      className="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
                    >
                      Open contact
                    </Link>
                    <button
                      type="button"
                      onClick={() => setReassigning(true)}
                      disabled={readOnly}
                      title={readOnly ? READ_ONLY_TITLE : undefined}
                      className="flex items-center gap-1 rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                      <ArrowRightLeft className="h-3.5 w-3.5" />
                      Move to another contact
                    </button>
                  </div>
                </>
              ) : (
                <p className="text-sm text-slate-500">No contact.</p>
              )}
            </Card>
          </div>
        ) : null}
      </div>

      {confirmDelete && lead && (
        <ConfirmModal
          title="Delete lead"
          message={`Delete this lead for ${lead.contact?.name || lead.contact?.phone_number || 'this contact'}? The lead and its tag assignments are removed. The contact itself is kept.`}
          confirmLabel="Delete lead"
          isLoading={deleting}
          onConfirm={doDelete}
          onCancel={() => setConfirmDelete(false)}
        />
      )}

      {reassigning && lead && (
        <ReassignContactModal
          lead={lead}
          onCancel={() => setReassigning(false)}
          onDone={(updated, message) => {
            setReassigning(false);
            onUpdated(updated, message);
          }}
        />
      )}

      <Toast message={toast} />
    </div>
  );
}

/**
 * PATCH /crm/leads/{id}/contact. Candidates come from the account's own
 * GET /crm/contacts?search= — the backend re-checks ownership and answers
 * a foreign/missing contact with its identical 404.
 */
function ReassignContactModal({
  lead,
  onCancel,
  onDone,
}: {
  lead: CrmLead;
  onCancel: () => void;
  onDone: (lead: CrmLead, message: string) => void;
}) {
  const [search, setSearch] = useState('');
  const [saving, setSaving] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  const candidates = useCrmQuery(
    `reassign-candidates:${search}`,
    () => crmService.listContacts(search, 1, 10),
    'Failed to load contacts.',
  );
  const options = (candidates.data?.data ?? []).filter((c) => c.id !== lead.contact?.id);
  const loading = candidates.isLoading;
  const loadError = candidates.error;

  const pick = (contact: CrmContact) => {
    if (saving !== null) return;
    setSaving(contact.id);
    setError(null);
    crmService
      .reassignContact(lead.id, contact.id)
      .then((res) => onDone(res.data, res.message))
      .catch((err: unknown) => {
        setError(describeApiError(err, 'Failed to move the lead.').message);
        setSaving(null);
      });
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl" data-testid="crm-reassign-contact">
        <div className="flex items-center justify-between">
          <h3 className="text-base font-semibold text-slate-900">Move lead to another contact</h3>
          <button type="button" onClick={onCancel} className="text-slate-400 hover:text-slate-600" aria-label="Close">
            <XCircle className="h-5 w-5" />
          </button>
        </div>
        <p className="mt-1 text-xs text-slate-500">Only the lead’s contact changes. Status, owner, tags and history stay as they are.</p>
        <div className="mt-4">
          <SearchInput value={search} onChange={setSearch} placeholder="Search contacts…" />
        </div>
        {(error ?? loadError) && <p className="mt-3 text-sm text-red-600" role="alert">{error ?? loadError}</p>}
        <div className="mt-3 max-h-72 space-y-1 overflow-y-auto">
          {loading ? (
            <Spinner />
          ) : options.length === 0 ? (
            <p className="py-4 text-center text-sm text-slate-500">No other contacts found.</p>
          ) : (
            options.map((c) => (
              <button
                key={c.id}
                type="button"
                onClick={() => pick(c)}
                disabled={saving !== null}
                className="flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm hover:bg-indigo-50 disabled:opacity-60"
              >
                <span>
                  <span className="font-medium text-slate-900">{c.name || 'Unnamed contact'}</span>
                  <span className="ml-2 text-slate-500">{c.phone_number}</span>
                </span>
                {saving === c.id && <Loader2 className="h-4 w-4 animate-spin text-slate-400" />}
              </button>
            ))
          )}
        </div>
      </div>
    </div>
  );
}
