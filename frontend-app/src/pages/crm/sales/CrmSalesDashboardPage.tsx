import { Link } from 'react-router-dom';
import crmDocumentsService from '../../../services/crmDocumentsService';
import { Card } from '../../../components/common/Card';
import { ErrorBanner, NoClientSelected, Spinner } from '../../../components/crm/CrmUi';
import { useCrmContext, useCrmQuery } from '../../../components/crm/crmHooks';
import { formatMoney } from '../../../types/crm';

const AGING: { key: 'current' | '1_30' | '31_60' | '61_90' | '90_plus'; label: string }[] = [
  { key: 'current', label: 'Not yet due' },
  { key: '1_30', label: '1–30 days late' },
  { key: '31_60', label: '31–60 days late' },
  { key: '61_90', label: '61–90 days late' },
  { key: '90_plus', label: '90+ days late' },
];

/** CRM Sales Phase 6 — outstanding and overdue money, invoice aging, this month and the open pipeline. */
export default function CrmSalesDashboardPage() {
  const { needsClient, selectedAccountId } = useCrmContext();
  const query = useCrmQuery(needsClient ? null : `sales-report:${selectedAccountId ?? 'own'}`, () => crmDocumentsService.salesReport(), 'Failed to load the sales report.');
  const r = query.data;
  const max = r ? Math.max(1, ...AGING.map((a) => Number(r.receivables.aging[a.key]))) : 1;

  return (
    <div className="p-6">
      <div className="w-full space-y-6">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">Sales & receivables</h1>
          <p className="mt-1 text-sm text-slate-500">What customers owe you, how late it is, and this month's sales.</p>
        </div>
        {needsClient ? (
          <NoClientSelected what="sales report" />
        ) : query.isLoading ? (
          <Spinner />
        ) : query.error ? (
          <ErrorBanner message={query.error} />
        ) : r ? (
          <>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4" data-testid="crm-sales-cards">
              <Stat label="Outstanding" value={formatMoney(r.receivables.outstanding)} hint={`${r.receivables.open_invoices} open invoices`} />
              <Stat label="Overdue" value={formatMoney(r.receivables.overdue)} tone="red" link="/crm/documents?type=invoice&overdue=1" />
              <Stat label="Collected this month" value={formatMoney(r.this_month.collected)} hint={`Invoiced ${formatMoney(r.this_month.invoiced)}`} />
              <Stat label="Open pipeline" value={formatMoney(r.pipeline.open_value)} hint={`${r.pipeline.open_deals} open deals`} link="/crm/deals" />
            </div>
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
              <Card className="lg:col-span-2">
                <p className="text-sm font-semibold text-slate-900">Invoice aging</p>
                <ul className="mt-3 space-y-2">
                  {AGING.map((a) => (
                    <li key={a.key} className="grid grid-cols-[8rem_1fr_7rem] items-center gap-3 text-sm">
                      <span className="text-slate-600">{a.label}</span>
                      <span className="h-2.5 overflow-hidden rounded-full bg-slate-100">
                        <span className={`block h-full rounded-full ${a.key === 'current' ? 'bg-emerald-500' : 'bg-red-400'}`} style={{ width: `${(Number(r.receivables.aging[a.key]) / max) * 100}%` }} />
                      </span>
                      <span className="text-right font-medium text-slate-900">{formatMoney(r.receivables.aging[a.key])}</span>
                    </li>
                  ))}
                </ul>
              </Card>
              <Card>
                <p className="text-sm font-semibold text-slate-900">Who owes the most</p>
                {r.receivables.top_debtors.length === 0 ? (
                  <p className="mt-1 text-sm text-slate-500">Nobody owes you anything.</p>
                ) : (
                  <ul className="mt-2 space-y-1 text-sm">
                    {r.receivables.top_debtors.map((d) => (
                      <li key={d.name} className="flex justify-between">
                        <span className="truncate text-slate-700">{d.name}</span>
                        <span className="font-medium text-slate-900">{formatMoney(d.balance)}</span>
                      </li>
                    ))}
                  </ul>
                )}
              </Card>
            </div>
            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
              <Stat label="Quotations sent" value={String(r.this_month.quotations_sent)} hint="this month" />
              <Stat label="Quotations accepted" value={String(r.this_month.quotations_accepted)} hint="this month" />
              <Stat label="Deals won" value={String(r.this_month.deals_won)} hint="this month" />
              <Stat label="Won value" value={formatMoney(r.this_month.won_value)} hint="this month" />
            </div>
          </>
        ) : null}
      </div>
    </div>
  );
}

function Stat({ label, value, hint, tone, link }: { label: string; value: string; hint?: string; tone?: 'red'; link?: string }) {
  const body = (
    <Card className="h-full">
      <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</p>
      <p className={`mt-1 text-xl font-semibold ${tone === 'red' ? 'text-red-700' : 'text-slate-900'}`}>{value}</p>
      {hint && <p className="text-xs text-slate-500">{hint}</p>}
    </Card>
  );
  return link ? (
    <Link to={link} className="block">
      {body}
    </Link>
  ) : (
    body
  );
}
