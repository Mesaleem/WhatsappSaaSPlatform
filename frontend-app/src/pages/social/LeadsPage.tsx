import { useCallback, useEffect, useState } from 'react';
import { AlertCircle, Building2, Camera, CheckCircle2, Loader2, MessageSquare, Search, X, XCircle } from 'lucide-react';
import { useAuth } from '../../core/context/AuthContext';
import { useTenant } from '../../core/context/TenantContext';
import leadsService from '../../services/leadsService';
import { TableCard } from '../../components/common/Card';
import { extractErrorMessage } from '../../utils/apiError';
import { indigo } from '../../theme/signalIndigo';
import type { Lead, LeadDetail, LeadPlatform } from '../../types/leads';

const PLATFORM_ICON: Record<LeadPlatform, typeof MessageSquare> = {
  facebook: MessageSquare,
  instagram: Camera,
};

function formatDate(value: string | null): string {
  if (!value) return '—';
  return new Date(value).toLocaleString();
}

/** Delivery status badge for one of the two independent WhatsApp sends Phase 2's Instant Lead Bridge makes. */
function DeliveryStatus({ sentAt, error }: { sentAt: string | null; error: string | null }) {
  if (sentAt) {
    return (
      <span className="inline-flex items-center gap-1 text-xs font-medium text-emerald-600" title={formatDate(sentAt)}>
        <CheckCircle2 className="h-3.5 w-3.5" />
        Sent
      </span>
    );
  }
  if (error) {
    return (
      <span className="inline-flex items-center gap-1 text-xs font-medium text-red-600" title={error}>
        <XCircle className="h-3.5 w-3.5" />
        Failed
      </span>
    );
  }
  return <span className="text-xs text-slate-400">Pending</span>;
}

function LeadDetailModal({ leadId, onClose }: { leadId: number; onClose: () => void }) {
  const [lead, setLead] = useState<LeadDetail | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    setIsLoading(true);
    setError(null);
    leadsService
      .get(leadId)
      .then((data) => {
        if (!cancelled) setLead(data);
      })
      .catch((err: unknown) => {
        if (!cancelled) setError(extractErrorMessage(err, 'Failed to load this lead.'));
      })
      .finally(() => {
        if (!cancelled) setIsLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [leadId]);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl">
        <div className="flex items-center justify-between">
          <h2 className="font-display text-base font-bold" style={{ color: indigo.ink }}>
            Lead Details
          </h2>
          <button onClick={onClose} className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Close">
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className="mt-4">
          {isLoading ? (
            <div className="flex items-center justify-center py-10">
              <Loader2 className="h-5 w-5 animate-spin" style={{ color: indigo.muted }} />
            </div>
          ) : error ? (
            <div className="flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
              <AlertCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
              {error}
            </div>
          ) : lead ? (
            <div className="space-y-3 text-sm">
              <div>
                <span className="font-medium text-slate-700">Name: </span>
                {lead.lead_name ?? '—'}
              </div>
              <div>
                <span className="font-medium text-slate-700">Phone: </span>
                {lead.lead_phone ?? '—'}
              </div>
              <div>
                <span className="font-medium text-slate-700">Email: </span>
                {lead.lead_email ?? '—'}
              </div>
              <div>
                <span className="font-medium text-slate-700">Form ID: </span>
                {lead.form_id ?? '—'}
              </div>
              <div>
                <span className="font-medium text-slate-700">Ad ID: </span>
                {lead.ad_id ?? '—'}
              </div>
              {lead.raw_field_data && Object.keys(lead.raw_field_data).length > 0 && (
                <div>
                  <p className="mb-1 font-medium text-slate-700">Full form submission</p>
                  <pre className="max-h-48 overflow-auto rounded-lg bg-slate-50 p-3 text-xs text-slate-700">
                    {JSON.stringify(lead.raw_field_data, null, 2)}
                  </pre>
                </div>
              )}
            </div>
          ) : null}
        </div>
      </div>
    </div>
  );
}

export default function LeadsPage() {
  const { isSuperAdmin } = useAuth();
  const { selectedAccountId } = useTenant();
  const superAdmin = isSuperAdmin();
  const noTenantSelected = superAdmin && selectedAccountId === null;

  const [leads, setLeads] = useState<Lead[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [pageError, setPageError] = useState<string | null>(null);
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [detailId, setDetailId] = useState<number | null>(null);

  const loadLeads = useCallback(() => {
    if (noTenantSelected) {
      setLeads([]);
      setIsLoading(false);
      return;
    }

    setIsLoading(true);
    setPageError(null);
    leadsService
      .list({ page, search: search.trim() || undefined })
      .then((res) => {
        setLeads(res.data);
        setLastPage(res.last_page);
      })
      .catch((err: unknown) => setPageError(extractErrorMessage(err, 'Failed to load leads.')))
      .finally(() => setIsLoading(false));
  }, [noTenantSelected, page, search]);

  useEffect(() => {
    loadLeads();
  }, [loadLeads, selectedAccountId]);

  // Debounced search — resets to page 1 whenever the search text settles.
  useEffect(() => {
    const timer = setTimeout(() => setPage(1), 300);
    return () => clearTimeout(timer);
  }, [search]);

  return (
    <div className="p-6">
      <div className="w-full">
        <div className="mb-6">
          <h1 className="font-display text-lg font-bold" style={{ color: indigo.ink }}>
            Instant Lead CRM
          </h1>
          <p className="mt-1 text-sm" style={{ color: indigo.muted }}>
            Every Meta Lead Ads submission received by the Instant Lead Bridge, and its WhatsApp delivery status.
          </p>
        </div>

        {noTenantSelected ? (
          <div className="flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm" style={{ color: indigo.muted }}>
            <Building2 className="mt-0.5 h-4 w-4 flex-shrink-0" />
            Select a client from the switcher at the top of the page to view their leads.
          </div>
        ) : (
          <>
            <div className="mb-4 flex items-center gap-2">
              <div className="relative w-full max-w-xs">
                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2" style={{ color: indigo.muted }} />
                <input
                  type="text"
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder="Search name, phone, email…"
                  className="w-full rounded-lg border border-slate-300 py-2 pl-9 pr-3 text-sm text-slate-900 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
                />
              </div>
            </div>

            {pageError && (
              <div className="mb-4 flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                <AlertCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
                {pageError}
              </div>
            )}

            <TableCard>
              <table className="min-w-full divide-y divide-slate-200 text-sm">
                <thead className="bg-slate-50">
                  <tr>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Lead</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Phone</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Source</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Tenant Notified</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Lead Welcomed</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Received</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {isLoading ? (
                    <tr>
                      <td colSpan={6} className="px-4 py-10 text-center">
                        <Loader2 className="mx-auto h-5 w-5 animate-spin" style={{ color: indigo.muted }} />
                      </td>
                    </tr>
                  ) : leads.length === 0 ? (
                    <tr>
                      <td colSpan={6} className="px-4 py-10 text-center text-sm text-slate-500">
                        No leads received yet.
                      </td>
                    </tr>
                  ) : (
                    leads.map((lead) => {
                      const Icon = PLATFORM_ICON[lead.platform];
                      return (
                        <tr
                          key={lead.id}
                          className="cursor-pointer hover:bg-slate-50"
                          onClick={() => setDetailId(lead.id)}
                        >
                          <td className="px-4 py-3">
                            <div className="flex items-center gap-2">
                              <Icon className="h-3.5 w-3.5 flex-shrink-0" style={{ color: indigo.muted }} />
                              <div>
                                <p className="font-medium text-slate-900">{lead.lead_name ?? 'Unknown'}</p>
                                <p className="text-xs" style={{ color: indigo.muted }}>
                                  {lead.lead_email ?? '—'}
                                </p>
                              </div>
                            </div>
                          </td>
                          <td className="px-4 py-3 text-slate-700">{lead.lead_phone ?? '—'}</td>
                          <td className="px-4 py-3 capitalize text-slate-700">{lead.platform}</td>
                          <td className="px-4 py-3">
                            <DeliveryStatus sentAt={lead.tenant_notified_at} error={lead.tenant_notify_error} />
                          </td>
                          <td className="px-4 py-3">
                            <DeliveryStatus sentAt={lead.lead_welcomed_at} error={lead.lead_welcome_error} />
                          </td>
                          <td className="px-4 py-3 text-slate-500">{formatDate(lead.created_at)}</td>
                        </tr>
                      );
                    })
                  )}
                </tbody>
              </table>
            </TableCard>

            {lastPage > 1 && (
              <div className="mt-4 flex items-center justify-center gap-3 text-sm">
                <button
                  type="button"
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  disabled={page <= 1}
                  className="rounded-lg border border-slate-300 px-3 py-1.5 font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-40"
                >
                  Previous
                </button>
                <span style={{ color: indigo.muted }}>
                  Page {page} of {lastPage}
                </span>
                <button
                  type="button"
                  onClick={() => setPage((p) => Math.min(lastPage, p + 1))}
                  disabled={page >= lastPage}
                  className="rounded-lg border border-slate-300 px-3 py-1.5 font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-40"
                >
                  Next
                </button>
              </div>
            )}
          </>
        )}
      </div>

      {detailId !== null && <LeadDetailModal leadId={detailId} onClose={() => setDetailId(null)} />}
    </div>
  );
}
