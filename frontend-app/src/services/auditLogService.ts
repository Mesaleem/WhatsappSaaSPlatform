import axiosInstance from '../core/api/axiosInstance';
import type { AuditLogFilters, AuditLogsResponse } from '../types/auditLog';

/** Same filename/error-unwrapping helpers as billingService.ts — kept duplicated per this codebase's convention (see that file's docblock). */
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

function cleanFilters(filters: AuditLogFilters): Record<string, string> {
  const out: Record<string, string> = {};
  if (filters.search) out.search = filters.search;
  if (filters.status) out.status = filters.status;
  if (filters.role) out.role = filters.role;
  if (filters.from) out.from = filters.from;
  if (filters.to) out.to = filters.to;
  return out;
}

const auditLogService = {
  list(page: number, perPage: number, filters: AuditLogFilters) {
    return axiosInstance
      .get<AuditLogsResponse>('/audit-logs', { params: { page, per_page: perPage, ...cleanFilters(filters) } })
      .then((res) => res.data);
  },

  async downloadCsv(filters: AuditLogFilters): Promise<void> {
    try {
      const response = await axiosInstance.get('/audit-logs/csv', {
        params: cleanFilters(filters),
        responseType: 'blob',
      });
      const filename = extractFilename(response.headers['content-disposition'], 'login-audit.csv');
      triggerBlobDownload(response.data as Blob, filename);
    } catch (err) {
      throw new Error(await extractErrorMessage(err, 'Could not export the audit log as CSV. Please try again.'));
    }
  },

  async downloadPdf(filters: AuditLogFilters): Promise<void> {
    try {
      const response = await axiosInstance.get('/audit-logs/pdf', {
        params: cleanFilters(filters),
        responseType: 'blob',
      });
      const filename = extractFilename(response.headers['content-disposition'], 'login-audit-summary.pdf');
      triggerBlobDownload(response.data as Blob, filename);
    } catch (err) {
      throw new Error(await extractErrorMessage(err, 'Could not export the audit log as PDF. Please try again.'));
    }
  },
};

export default auditLogService;
