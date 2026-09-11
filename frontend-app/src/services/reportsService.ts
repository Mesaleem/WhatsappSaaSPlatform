import axiosInstance from '../core/api/axiosInstance';
import type { ReportPeriodParams, SocialReportSummary } from '../types/reports';

const BASE = '/social/reports';

/**
 * Reads the server-provided filename off Content-Disposition, matching
 * analyticsService's exportPdf()/exportCsv() convention. Falls back to a
 * client-side default if CORS doesn't expose the header — the download
 * itself still works either way.
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
 * /social/reports/generate would surface as an unreadable "[object Blob]".
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

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Final Phase.
 * Axios service for the White-Label Automated PDF Reporting module
 * (SocialReportsPage).
 */
const reportsService = {
  getSummary(params: ReportPeriodParams = {}) {
    return axiosInstance.get<{ data: SocialReportSummary }>(`${BASE}/summary`, { params }).then((res) => res.data.data);
  },

  async downloadPdf(params: ReportPeriodParams = {}): Promise<void> {
    try {
      const response = await axiosInstance.get(`${BASE}/generate`, {
        params,
        responseType: 'blob',
      });
      const filename = extractFilename(response.headers['content-disposition'], 'social-report.pdf');
      triggerBlobDownload(response.data as Blob, filename);
    } catch (err) {
      throw new Error(await extractErrorMessage(err, 'Could not generate the PDF report. Please try again.'));
    }
  },
};

export default reportsService;
