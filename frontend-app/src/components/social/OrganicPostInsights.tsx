import { useState } from 'react';
import { AlertCircle, Loader2, RefreshCw } from 'lucide-react';
import organicPostService from '../../services/organicPostService';
import { extractErrorMessage } from '../../utils/apiError';
import SocialReconnectNotice from './SocialReconnectNotice';
import { formatScheduledAt } from '../../types/organic';
import { formatInsightValue, INSIGHT_METRICS, UNAVAILABLE_REASON_LABELS } from '../../types/organicInsights';
import type { PostInsights, RefreshOutcome } from '../../types/organicInsights';

const OUTCOME_NOTES: Partial<Record<RefreshOutcome, string>> = {
  recently_refreshed: 'Insights were updated a few minutes ago — showing the stored figures.',
  backing_off: 'The platform asked us to slow down — insights will be retried automatically.',
  in_progress: 'A refresh is already running — showing the stored figures.',
};

const STATE_NOTES: Partial<Record<PostInsights['state'], string>> = {
  not_fetched: 'Insights have not been fetched yet.',
  post_not_found: 'The platform no longer has this post (it may have been deleted). Last known figures are shown.',
};

/**
 * Phase 9 Task 4 — insights for one organic post, shown inside the Organic
 * Posts panel. Reads the stored snapshot the panel already loaded; the
 * Refresh button asks the backend for ONE controlled fetch (the backend
 * throttles, backs off and checks the connection). Each metric shows its
 * value (0 is a real zero), "Not available" with the reason, or "—" when
 * insights were never fetched.
 */
export default function OrganicPostInsights({
  initial,
  canManageSocialAccounts = false,
  onChange,
}: {
  initial: PostInsights;
  canManageSocialAccounts?: boolean;
  onChange?: (insights: PostInsights) => void;
}) {
  const [insights, setInsights] = useState<PostInsights>(initial);
  const [isRefreshing, setIsRefreshing] = useState(false);
  const [note, setNote] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  if (insights.state === 'not_applicable') {
    return null;
  }

  const refresh = () => {
    setIsRefreshing(true);
    setNote(null);
    setError(null);
    organicPostService
      .refreshInsights(insights.post_id)
      .then(({ insights: updated, outcome }) => {
        setInsights(updated);
        onChange?.(updated);
        setNote(OUTCOME_NOTES[outcome] ?? null);
      })
      .catch((err: unknown) => setError(extractErrorMessage(err, 'Could not refresh insights.')))
      .finally(() => setIsRefreshing(false));
  };

  const reconnect =
    insights.state === 'reconnect_required'
      ? {
          message: insights.error_message ?? 'The connected social account needs to be reconnected.',
          status: insights.error_code?.includes('revoked') ? ('revoked' as const) : ('expired' as const),
          socialAccountId: null,
          reconnectPath:
            insights.reconnect_path && insights.reconnect_path.startsWith('/') && !insights.reconnect_path.startsWith('//') ? insights.reconnect_path : '/social/accounts',
        }
      : null;

  const providerProblem = insights.state === 'rate_limited' || insights.state === 'provider_error';

  return (
    <div className="space-y-3 rounded-xl border border-slate-200 bg-slate-50/60 p-4" data-testid={`organic-insights-${insights.post_id}`}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-xs text-slate-600">
          {insights.fetched_at ? `Updated ${formatScheduledAt(insights.fetched_at)}` : STATE_NOTES.not_fetched}
        </p>
        <button
          type="button"
          onClick={refresh}
          disabled={isRefreshing}
          className="inline-flex items-center gap-1 rounded-lg border border-indigo-200 px-2.5 py-1 text-xs font-semibold text-indigo-700 hover:bg-indigo-50 disabled:opacity-60"
        >
          {isRefreshing ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <RefreshCw className="h-3.5 w-3.5" />}
          {insights.fetched_at ? 'Refresh insights' : 'Fetch insights'}
        </button>
      </div>

      {reconnect && <SocialReconnectNotice info={reconnect} canManageSocialAccounts={canManageSocialAccounts} />}
      {providerProblem && insights.error_message && (
        <div className="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800" data-testid="insights-provider-error">
          <AlertCircle className="mt-0.5 h-3.5 w-3.5 flex-shrink-0" />
          {insights.error_message}
        </div>
      )}
      {insights.state === 'post_not_found' && <p className="text-xs text-slate-600">{STATE_NOTES.post_not_found}</p>}
      {note && <p className="text-xs text-slate-600">{note}</p>}
      {error && <p className="text-xs font-medium text-red-600">{error}</p>}

      <dl className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
        {INSIGHT_METRICS.map(({ key, label }) => {
          const metric = insights.metrics[key];
          return (
            <div key={key} className="rounded-lg bg-white px-3 py-2 shadow-sm" data-testid={`insight-${key}`}>
              <dt className="text-[11px] font-medium text-slate-500">{label}</dt>
              {metric.status === 'available' && metric.value !== null ? (
                <dd className="text-sm font-semibold text-slate-900">{formatInsightValue(key, metric.value)}</dd>
              ) : metric.status === 'unavailable' ? (
                <dd className="text-xs text-slate-400">
                  Not available
                  {metric.reason && <span className="block text-[10px] leading-tight text-slate-400">{UNAVAILABLE_REASON_LABELS[metric.reason]}</span>}
                </dd>
              ) : (
                <dd className="text-sm text-slate-400">—</dd>
              )}
            </div>
          );
        })}
      </dl>
    </div>
  );
}
