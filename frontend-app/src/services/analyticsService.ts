import axiosInstance from '../core/api/axiosInstance';
import type { PaymentAlert } from '../types/alert';
import type {
  AnalyticsChartsResponse,
  AnalyticsSummary,
  ChartRange,
  ExportFilters,
  GlobalAnalyticsSummary,
  MessageLogFilters,
  MessageLogsResponse,
} from '../types/analytics';

/**
 * Reads the server-provided filename off Content-Disposition. NOTE: this
 * only works if Laravel's CORS config exposes the header
 * (`exposed_headers` in config/cors.php) — if it doesn't, this silently
 * falls back to a client-side default name; the download itself still
 * works either way, since header exposure only gates JS's ability to
 * *read* the header, not the browser's ability to receive the blob body.
 */
function extractFilename(contentDisposition: unknown, fallback: string): string {
  if (typeof contentDisposition !== 'string') return fallback;
  const match = /filename="?([^"]+)"?/.exec(contentDisposition);
  return match?.[1] ?? fallback;
}

function triggerBlobDownload(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
}

/**
 * With `responseType: 'blob'`, axios delivers an ERROR response body as a
 * Blob too (not parsed JSON) — without this, a validation error from
 * /exports/* would surface as an unreadable "[object Blob]" instead of the
 * server's actual message.
 */
async function extractErrorMessage(err: unknown, fallback: string): Promise<string> {
  const maybeBlob = (err as { response?: { data?: unknown } })?.response?.data;
  if (maybeBlob instanceof Blob) {
    try {
      const text = await maybeBlob.text();
      const parsed = JSON.parse(text) as { message?: string };
      if (parsed.message) return parsed.message;
    } catch {
      // Not JSON (or empty) — fall through to the generic fallback.
    }
  }
  return fallback;
}

// API Performance — in-flight request dedup for the two endpoints this
// audit measured taking 1-4s and getting hit in duplicate, near-simultaneous
// bursts (both /analytics/charts and /analytics/global-summary have at
// least two independent call sites — DashboardPage's DailyPulseChart AND
// RevenuePulseChart both call getCharts() on the same page load with
// different `range` params, and React 18 StrictMode's dev-only
// mount->cleanup->mount double-invoke fires every data-fetching useEffect
// twice regardless). Rather than chase each call site individually, this
// collapses any second call that arrives for the exact same
// endpoint+params while the first is still in flight into the SAME
// promise — no second network request goes out, and both callers resolve
// together from the one real response. The map entry is removed as soon
// as the request settles (success or failure), so a genuinely new
// request (different params, or simply after the first one finished)
// always goes out fresh; this is a request-level dedup, not a
// result cache — the 60s server-side Cache::remember() on both endpoints
// is what makes a *later* repeat fast, this is what stops two requests
// that are happening at the same instant from becoming two requests.
const inFlightRequests = new Map<string, Promise<unknown>>();

function dedupedGet<T>(key: string, run: () => Promise<T>): Promise<T> {
  const existing = inFlightRequests.get(key);
  if (existing) return existing as Promise<T>;

  const request = run().finally(() => {
    inFlightRequests.delete(key);
  });
  inFlightRequests.set(key, request);
  return request;
}

const analyticsService = {
  getSummary(params?: { from?: string; to?: string }) {
    return axiosInstance.get<AnalyticsSummary>('/analytics/summary', { params }).then((res) => res.data);
  },

  getCharts(params?: { range?: ChartRange; from?: string; to?: string }) {
    return dedupedGet(`charts:${JSON.stringify(params ?? {})}`, () =>
      axiosInstance.get<AnalyticsChartsResponse>('/analytics/charts', { params }).then((res) => res.data),
    );
  },

  /** GET /api/analytics/global-summary — Super Admin only; platform-wide KPIs for the Dashboard. */
  getGlobalSummary() {
    return dedupedGet('global-summary', () =>
      axiosInstance.get<GlobalAnalyticsSummary>('/analytics/global-summary').then((res) => res.data),
    );
  },

  getLogs(filters: MessageLogFilters) {
    return axiosInstance
      .get<MessageLogsResponse>('/alerts/logs', { params: filters })
      .then((res) => res.data);
  },

  getLogDetail(id: number) {
    return axiosInstance.get<PaymentAlert>(`/alerts/logs/${id}`).then((res) => res.data);
  },

  async exportCsv(filters: ExportFilters): Promise<void> {
    try {
      const response = await axiosInstance.get('/exports/csv', {
        params: filters,
        responseType: 'blob',
      });
      const filename = extractFilename(response.headers['content-disposition'], 'payment-alerts.csv');
      triggerBlobDownload(response.data as Blob, filename);
    } catch (err) {
      throw new Error(await extractErrorMessage(err, 'Could not export the CSV. Please try again.'));
    }
  },

  async exportPdf(filters: ExportFilters): Promise<void> {
    try {
      const response = await axiosInstance.get('/exports/pdf', {
        params: filters,
        responseType: 'blob',
      });
      const filename = extractFilename(response.headers['content-disposition'], 'payment-alerts-summary.pdf');
      triggerBlobDownload(response.data as Blob, filename);
    } catch (err) {
      throw new Error(await extractErrorMessage(err, 'Could not export the PDF. Please try again.'));
    }
  },
};

export default analyticsService;
