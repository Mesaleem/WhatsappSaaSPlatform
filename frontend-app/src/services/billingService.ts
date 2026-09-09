import axiosInstance from '../core/api/axiosInstance';
import type { PaginatedResponse } from '../types/account';
import type {
  ClientBillingSummaryResponse,
  CreateOrderPayload,
  CreateOrderResponse,
  Invoice,
  PlansResponse,
  VerifyPaymentPayload,
  VerifyPaymentResponse,
} from '../types/billing';

/**
 * Reads the server-provided filename off Content-Disposition, same helper
 * as analyticsService.ts (Module 7) — kept duplicated rather than shared
 * across services to avoid a cross-service import for three lines; see
 * that file's docblock for the CORS `exposed_headers` caveat.
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

/** Same Blob-error-unwrapping helper as analyticsService.ts — see that file for why it's needed. */
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

const billingService = {
  getPlans() {
    return axiosInstance.get<PlansResponse>('/billing/plans').then((res) => res.data);
  },

  createOrder(payload: CreateOrderPayload) {
    return axiosInstance.post<CreateOrderResponse>('/billing/create-order', payload).then((res) => res.data);
  },

  verifyPayment(payload: VerifyPaymentPayload) {
    return axiosInstance.post<VerifyPaymentResponse>('/billing/verify-payment', payload).then((res) => res.data);
  },

  getInvoices(page = 1, perPage = 10) {
    return axiosInstance
      .get<PaginatedResponse<Invoice>>('/billing/invoices', { params: { page, per_page: perPage } })
      .then((res) => res.data);
  },

  /**
   * Super Admin Billing Overview — platform-wide (or one-client, via
   * ?account_id=) billing summary table. Universal Table & Filter
   * Standardization: search/status/from/to are now sent server-side
   * (mirrors auditLogService.list()'s params shape) rather than filtered
   * client-side on just the current page's rows.
   */
  getClientSummary(
    page = 1,
    perPage = 15,
    filters: { search?: string; status?: string; from?: string; to?: string } = {},
  ) {
    return axiosInstance
      .get<ClientBillingSummaryResponse>('/billing/client-summary', {
        params: {
          page,
          per_page: perPage,
          search: filters.search || undefined,
          status: filters.status || undefined,
          from: filters.from || undefined,
          to: filters.to || undefined,
        },
      })
      .then((res) => res.data);
  },

  async downloadInvoicePdf(invoiceId: number, invoiceNumber: string): Promise<void> {
    try {
      const response = await axiosInstance.get(`/billing/invoices/${invoiceId}/pdf`, {
        responseType: 'blob',
      });
      const filename = extractFilename(response.headers['content-disposition'], `invoice-${invoiceNumber}.pdf`);
      triggerBlobDownload(response.data as Blob, filename);
    } catch (err) {
      throw new Error(await extractErrorMessage(err, 'Could not download this invoice. Please try again.'));
    }
  },
};

export default billingService;
