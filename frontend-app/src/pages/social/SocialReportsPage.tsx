import { useCallback, useEffect, useMemo, useState } from 'react';
import { AlertCircle, Building2, Download, Loader2 } from 'lucide-react';
import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { useAuth } from '../../core/context/AuthContext';
import { useTenant } from '../../core/context/TenantContext';
import reportsService from '../../services/reportsService';
import { TableCard, inputClass } from '../../components/common/Card';
import { extractErrorMessage } from '../../utils/apiError';
import { indigo } from '../../theme/signalIndigo';
import type { SocialReportSummary } from '../../types/reports';

function currentMonthValue(): string {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
}

function formatMoney(value: number): string {
  return `$${value.toFixed(2)}`;
}

function KpiCard({ label, value, sub }: { label: string; value: string; sub?: string }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
      <p className="text-xs font-medium uppercase tracking-wide" style={{ color: indigo.muted }}>
        {label}
      </p>
      <p className="mt-2 text-2xl font-semibold" style={{ color: indigo.ink }}>
        {value}
      </p>
      {sub && (
        <p className="mt-1 text-xs" style={{ color: indigo.muted }}>
          {sub}
        </p>
      )}
    </div>
  );
}

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Final Phase.
 * White-Label Automated PDF Reporting: monthly metric charts fed by
 * GET /api/social/reports/summary, plus a 1-click PDF download hitting
 * GET /api/social/reports/generate (tenant branding — company name, logo,
 * accent color — is attached server-side by SimplePdfWriter::renderBrandedReport()).
 */
export default function SocialReportsPage() {
  const { isSuperAdmin } = useAuth();
  const { selectedAccountId } = useTenant();
  const superAdmin = isSuperAdmin();
  const noTenantSelected = superAdmin && selectedAccountId === null;

  const [month, setMonth] = useState(currentMonthValue());
  const [summary, setSummary] = useState<SocialReportSummary | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [pageError, setPageError] = useState<string | null>(null);
  const [isDownloading, setIsDownloading] = useState(false);
  const [downloadError, setDownloadError] = useState<string | null>(null);

  const loadSummary = useCallback(() => {
    if (noTenantSelected) {
      setSummary(null);
      setIsLoading(false);
      return;
    }

    setIsLoading(true);
    setPageError(null);
    reportsService
      .getSummary({ month })
      .then((data) => setSummary(data))
      .catch((err: unknown) => setPageError(extractErrorMessage(err, 'Failed to load the report.')))
      .finally(() => setIsLoading(false));
  }, [noTenantSelected, month]);

  useEffect(() => {
    loadSummary();
  }, [loadSummary, selectedAccountId]);

  const handleDownload = () => {
    setDownloadError(null);
    setIsDownloading(true);
    reportsService
      .downloadPdf({ month })
      .catch((err: unknown) => setDownloadError(err instanceof Error ? err.message : 'Could not download the PDF.'))
      .finally(() => setIsDownloading(false));
  };

  const chartData = useMemo(
    () =>
      (summary?.daily_series ?? []).map((point) => ({
        date: point.date.slice(5),
        Spend: point.spend,
        Leads: point.leads,
      })),
    [summary],
  );

  return (
    <div className="p-6">
      <div className="w-full">
        <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
          <div>
            <h1 className="font-display text-lg font-bold" style={{ color: indigo.ink }}>
              Social Reports
            </h1>
            <p className="mt-1 text-sm" style={{ color: indigo.muted }}>
              White-label monthly performance report — branded with your company name, logo, and accent color.
            </p>
          </div>
          {!noTenantSelected && (
            <div className="flex items-center gap-2">
              <input
                type="month"
                value={month}
                onChange={(e) => setMonth(e.target.value)}
                className={`${inputClass} mt-0 w-auto`}
              />
              <button
                type="button"
                onClick={handleDownload}
                disabled={isDownloading || isLoading}
                className="flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60"
              >
                {isDownloading ? <Loader2 className="h-4 w-4 animate-spin" /> : <Download className="h-4 w-4" />}
                Download PDF
              </button>
            </div>
          )}
        </div>

        {noTenantSelected ? (
          <div className="flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm" style={{ color: indigo.muted }}>
            <Building2 className="mt-0.5 h-4 w-4 flex-shrink-0" />
            Select a client from the switcher at the top of the page to view their report.
          </div>
        ) : (
          <>
            {downloadError && (
              <div className="mb-4 flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                <AlertCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
                {downloadError}
              </div>
            )}
            {pageError && (
              <div className="mb-4 flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                <AlertCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
                {pageError}
              </div>
            )}

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
              <KpiCard label="Total Ad Spend" value={isLoading ? '…' : formatMoney(summary?.total_ad_spend ?? 0)} />
              <KpiCard
                label="Total Leads Generated"
                value={isLoading ? '…' : String(summary?.total_leads_generated ?? 0)}
                sub="Instant Lead Bridge"
              />
              <KpiCard
                label="Meta-Attributed Leads"
                value={isLoading ? '…' : String(summary?.meta_attributed_leads ?? 0)}
                sub="Launched campaigns"
              />
              <KpiCard
                label="Average CPL"
                value={isLoading ? '…' : summary?.average_cpl !== null && summary?.average_cpl !== undefined ? formatMoney(summary.average_cpl) : 'N/A'}
              />
              <KpiCard
                label="Combined Social Reach"
                value={isLoading ? '…' : (summary?.combined_impressions ?? 0).toLocaleString()}
                sub="Ad impressions"
              />
            </div>

            <div className="mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
              <p className="text-sm font-medium" style={{ color: indigo.ink }}>
                Daily Spend &amp; Leads — {summary?.period.label ?? ''}
              </p>
              <div className="mt-4 h-72">
                {isLoading ? (
                  <div className="flex h-full items-center justify-center">
                    <Loader2 className="h-5 w-5 animate-spin" style={{ color: indigo.muted }} />
                  </div>
                ) : chartData.length === 0 ? (
                  <div className="flex h-full items-center justify-center text-sm" style={{ color: indigo.muted }}>
                    No ad activity recorded for this period yet.
                  </div>
                ) : (
                  <ResponsiveContainer width="100%" height="100%">
                    <AreaChart data={chartData}>
                      <defs>
                        <linearGradient id="reportSpend" x1="0" y1="0" x2="0" y2="1">
                          <stop offset="5%" stopColor="#4F46E5" stopOpacity={0.35} />
                          <stop offset="95%" stopColor="#4F46E5" stopOpacity={0} />
                        </linearGradient>
                        <linearGradient id="reportLeads" x1="0" y1="0" x2="0" y2="1">
                          <stop offset="5%" stopColor="#10b981" stopOpacity={0.3} />
                          <stop offset="95%" stopColor="#10b981" stopOpacity={0} />
                        </linearGradient>
                      </defs>
                      <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" vertical={false} />
                      <XAxis dataKey="date" tick={{ fontSize: 12, fill: '#64748b' }} axisLine={false} tickLine={false} />
                      <YAxis allowDecimals={false} tick={{ fontSize: 12, fill: '#64748b' }} axisLine={false} tickLine={false} />
                      <Tooltip />
                      <Area type="monotone" dataKey="Spend" stroke="#4F46E5" strokeWidth={2.5} fill="url(#reportSpend)" dot={false} />
                      <Area type="monotone" dataKey="Leads" stroke="#10b981" strokeWidth={2} fill="url(#reportLeads)" dot={false} />
                    </AreaChart>
                  </ResponsiveContainer>
                )}
              </div>
            </div>

            <div className="mt-4">
              <p className="mb-2 text-sm font-medium" style={{ color: indigo.ink }}>
                Top Performing Ad Campaigns
              </p>
              <TableCard>
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                  <thead className="bg-slate-50">
                    <tr>
                      <th className="px-4 py-3 text-left font-medium text-slate-600">Campaign</th>
                      <th className="px-4 py-3 text-left font-medium text-slate-600">Spend</th>
                      <th className="px-4 py-3 text-left font-medium text-slate-600">Leads</th>
                      <th className="px-4 py-3 text-left font-medium text-slate-600">CPL</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {isLoading ? (
                      <tr>
                        <td colSpan={4} className="px-4 py-10 text-center">
                          <Loader2 className="mx-auto h-5 w-5 animate-spin" style={{ color: indigo.muted }} />
                        </td>
                      </tr>
                    ) : (summary?.top_campaigns.length ?? 0) === 0 ? (
                      <tr>
                        <td colSpan={4} className="px-4 py-10 text-center text-sm text-slate-500">
                          No campaign activity recorded for this period yet.
                        </td>
                      </tr>
                    ) : (
                      summary?.top_campaigns.map((campaign, i) => (
                        <tr key={i}>
                          <td className="px-4 py-3 font-medium text-slate-900">{campaign.name}</td>
                          <td className="px-4 py-3 text-slate-700">{formatMoney(campaign.spend)}</td>
                          <td className="px-4 py-3 text-slate-700">{campaign.leads}</td>
                          <td className="px-4 py-3 text-slate-700">{campaign.cpl !== null ? formatMoney(campaign.cpl) : 'N/A'}</td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </TableCard>
            </div>
          </>
        )}
      </div>
    </div>
  );
}
