import { useState } from 'react';
import { Link } from 'react-router-dom';
import { CheckCircle2, Loader2 } from 'lucide-react';
import crmSalesService, { type ActivityFilters } from '../../services/crmSalesService';
import { TableCard } from '../../components/common/Card';
import { TableSkeletonRows } from '../../components/common/Skeleton';
import { EmptyState, ErrorBanner, NoClientSelected, Toast } from '../../components/crm/CrmUi';
import { formatDate } from '../../components/crm/crmFilters';
import { useCrmContext, useCrmQuery, useCrmToast } from '../../components/crm/crmHooks';
import { crmLabel, type CrmActivity } from '../../types/crm';
import { describeApiError } from '../../utils/apiError';

type View = 'overdue' | 'today' | 'upcoming' | 'mine';

const VIEWS: { key: View; label: string; filters: ActivityFilters }[] = [
  { key: 'overdue', label: 'Overdue', filters: { due: 'overdue' } },
  { key: 'today', label: 'Due today', filters: { due: 'today' } },
  { key: 'upcoming', label: 'Upcoming', filters: { due: 'upcoming' } },
  { key: 'mine', label: 'My open tasks', filters: { status: 'pending', assigned_user_id: 'me' } },
];

/** Where an activity belongs, for the "Related to" column. */
function relatedLink(a: CrmActivity): { to: string; label: string } | null {
  if (a.deal) return { to: `/crm/deals/${a.deal.id}`, label: `${a.deal.number} · ${a.deal.name}` };
  if (a.crm_lead_id) return { to: `/crm/leads/${a.crm_lead_id}`, label: `Lead #${a.crm_lead_id}${a.contact ? ` · ${a.contact.name || a.contact.phone_number}` : ''}` };
  if (a.company) return { to: `/crm/companies/${a.company.id}`, label: a.company.name };
  if (a.contact) return { to: `/crm/contacts/${a.contact.id}`, label: a.contact.name || a.contact.phone_number };
  return null;
}

/**
 * CRM Sales Phase 1 — follow-ups across the CRM: overdue, due today, upcoming, and the
 * signed-in user's open tasks. A task is marked done here; new ones are added on a
 * lead, contact, company or deal.
 */
export default function CrmTasksPage() {
  const { needsClient, readOnly, selectedAccountId } = useCrmContext();
  const { toast, show } = useCrmToast();
  const [view, setView] = useState<View>('overdue');
  const [version, setVersion] = useState(0);
  const [busyId, setBusyId] = useState<number | null>(null);
  const filters = VIEWS.find((v) => v.key === view)!.filters;

  const query = useCrmQuery(
    needsClient ? null : `tasks:${selectedAccountId ?? 'own'}:${view}:${version}`,
    () => crmSalesService.listActivities(filters, 1, 100),
    'Failed to load tasks.',
  );
  const rows = query.data?.data ?? [];

  const complete = (a: CrmActivity) => {
    setBusyId(a.id);
    crmSalesService
      .updateActivity(a.id, { status: 'done' })
      .then((res) => {
        setVersion((v) => v + 1);
        show(res.message);
      })
      .catch((err: unknown) => show(describeApiError(err, 'Could not update the task.').message))
      .finally(() => setBusyId(null));
  };

  return (
    <div className="p-6">
      <div className="w-full space-y-6">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">CRM Tasks</h1>
          <p className="mt-1 text-sm text-slate-500">Follow-ups, calls and meetings across your leads, companies and deals.</p>
        </div>

        {needsClient ? (
          <NoClientSelected what="CRM tasks" />
        ) : (
          <>
            <div role="tablist" className="flex flex-wrap gap-2">
              {VIEWS.map((v) => (
                <button
                  key={v.key}
                  role="tab"
                  aria-selected={view === v.key}
                  type="button"
                  onClick={() => setView(v.key)}
                  className={`rounded-lg px-3 py-1.5 text-sm font-medium ${view === v.key ? 'bg-emerald-600 text-white' : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50'}`}
                >
                  {v.label}
                </button>
              ))}
            </div>

            {query.error && <ErrorBanner message={query.error} />}

            <TableCard>
              <table className="min-w-full divide-y divide-slate-200 text-sm" data-testid="crm-tasks-table">
                <thead className="bg-slate-50">
                  <tr>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Task</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Type</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Due</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Related to</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Assigned</th>
                    <th className="px-4 py-3" />
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {query.isLoading ? (
                    <TableSkeletonRows columns={6} />
                  ) : rows.length === 0 ? (
                    <tr>
                      <td colSpan={6}>
                        <EmptyState title="Nothing here." hint="Add follow-ups from a lead, company or deal." />
                      </td>
                    </tr>
                  ) : (
                    rows.map((a) => {
                      const rel = relatedLink(a);
                      return (
                        <tr key={a.id}>
                          <td className="px-4 py-3 font-medium text-slate-900">{a.subject}</td>
                          <td className="px-4 py-3 text-slate-700">{a.type === 'whatsapp' ? 'WhatsApp' : crmLabel(a.type)}</td>
                          <td className={`whitespace-nowrap px-4 py-3 ${a.is_overdue ? 'font-semibold text-red-600' : 'text-slate-700'}`}>{formatDate(a.due_at)}</td>
                          <td className="px-4 py-3">{rel ? <Link to={rel.to} className="text-emerald-700 hover:underline">{rel.label}</Link> : '—'}</td>
                          <td className="px-4 py-3 text-slate-700">{a.assigned_user?.name ?? '—'}</td>
                          <td className="px-4 py-3 text-right">
                            {a.status === 'pending' && (
                              <button
                                type="button"
                                onClick={() => complete(a)}
                                disabled={readOnly || busyId === a.id}
                                className="inline-flex items-center gap-1 rounded-lg border border-emerald-200 px-2.5 py-1 text-xs font-medium text-emerald-800 hover:bg-emerald-50 disabled:opacity-50"
                              >
                                {busyId === a.id ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <CheckCircle2 className="h-3.5 w-3.5" />}
                                Done
                              </button>
                            )}
                          </td>
                        </tr>
                      );
                    })
                  )}
                </tbody>
              </table>
            </TableCard>
          </>
        )}
      </div>
      <Toast message={toast} />
    </div>
  );
}
