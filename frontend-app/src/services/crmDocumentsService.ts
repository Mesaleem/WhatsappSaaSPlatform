import axios from 'axios';
import axiosInstance from '../core/api/axiosInstance';
import type { CrmMessageResponse, CrmPaginated } from '../types/crm';
import type {
  CrmBusinessProfile,
  CrmCheckoutOrder,
  CrmDelivery,
  CrmDocDetail,
  CrmDocItem,
  CrmDocSummary,
  CrmDocType,
  CrmGatewaySettings,
  CrmPayment,
  CrmPaymentMethod,
  CrmProduct,
  CrmPublicDocument,
  CrmReminder,
  CrmReminderSettings,
  CrmSalesReport,
} from '../types/crmSales';

/**
 * CRM Sales Phases 2–6 — thin client over /api/crm/products|documents|payments|reminders|
 * settings|reports and the public document link. No business rules: numbering, GST,
 * totals, transitions, allocations and reminders are decided by the backend.
 */
const BASE = '/crm';

export interface DocumentPayload {
  type?: CrmDocType;
  title?: string | null;
  contact_id?: number | null;
  crm_company_id?: number | null;
  crm_deal_id?: number | null;
  issue_date?: string | null;
  valid_until?: string | null;
  due_date?: string | null;
  place_of_supply?: string | null;
  body?: string | null;
  terms?: string | null;
  notes?: string | null;
  external_reference?: string | null;
  items?: Omit<CrmDocItem, 'id' | 'taxable_amount' | 'cgst_amount' | 'sgst_amount' | 'igst_amount' | 'line_total'>[];
}

export interface PaymentPayload {
  amount: string;
  method: Exclude<CrmPaymentMethod, 'gateway'>;
  paid_on?: string | null;
  reference?: string | null;
  notes?: string | null;
  contact_id?: number | null;
  crm_company_id?: number | null;
  idempotency_key?: string;
  allocations?: { crm_document_id: number; amount: string }[];
}

function clean(params: Record<string, unknown>): Record<string, unknown> {
  return Object.fromEntries(Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== ''));
}

/** Fetch a file through the authenticated client (tenant header included) and save it. */
async function saveBlob(path: string, filename: string) {
  const res = await axiosInstance.get<Blob>(path, { responseType: 'blob' });
  const url = URL.createObjectURL(res.data);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  a.click();
  URL.revokeObjectURL(url);
}

/** The public link API lives under the same backend, but is called without the tenant interceptor. */
const publicApi = axios.create({ baseURL: axiosInstance.defaults.baseURL, headers: { Accept: 'application/json' } });

const crmDocumentsService = {
  // products
  listProducts(search = '', page = 1, activeOnly = false) {
    return axiosInstance
      .get<CrmPaginated<CrmProduct>>(`${BASE}/products`, { params: clean({ search: search.trim(), page, per_page: 50, active: activeOnly ? 1 : undefined }) })
      .then((r) => r.data);
  },
  saveProduct(id: number | null, payload: Partial<CrmProduct>) {
    return (id ? axiosInstance.patch<CrmMessageResponse<CrmProduct>>(`${BASE}/products/${id}`, payload) : axiosInstance.post<CrmMessageResponse<CrmProduct>>(`${BASE}/products`, payload)).then((r) => r.data);
  },
  deleteProduct(id: number) {
    return axiosInstance.delete<{ message: string }>(`${BASE}/products/${id}`).then((r) => r.data);
  },

  // documents
  listDocuments(params: { type?: CrmDocType; status?: string; search?: string; overdue?: boolean; contact_id?: number; crm_company_id?: number; crm_deal_id?: number; page?: number; per_page?: number }) {
    return axiosInstance
      .get<CrmPaginated<CrmDocSummary>>(`${BASE}/documents`, { params: clean({ ...params, overdue: params.overdue ? 1 : undefined }) })
      .then((r) => r.data);
  },
  getDocument(id: number) {
    return axiosInstance.get<{ data: CrmDocDetail }>(`${BASE}/documents/${id}`).then((r) => r.data.data);
  },
  createDocument(payload: DocumentPayload) {
    return axiosInstance.post<CrmMessageResponse<CrmDocDetail>>(`${BASE}/documents`, payload).then((r) => r.data);
  },
  updateDocument(id: number, payload: DocumentPayload) {
    return axiosInstance.patch<CrmMessageResponse<CrmDocDetail>>(`${BASE}/documents/${id}`, payload).then((r) => r.data);
  },
  deleteDocument(id: number) {
    return axiosInstance.delete<{ message: string }>(`${BASE}/documents/${id}`).then((r) => r.data);
  },
  issue(id: number) {
    return axiosInstance.post<CrmMessageResponse<CrmDocDetail>>(`${BASE}/documents/${id}/issue`).then((r) => r.data);
  },
  changeStatus(id: number, status: string, reason?: string | null) {
    return axiosInstance.post<CrmMessageResponse<CrmDocDetail>>(`${BASE}/documents/${id}/status`, clean({ status, reason })).then((r) => r.data);
  },
  convert(id: number, type: CrmDocType) {
    return axiosInstance.post<CrmMessageResponse<CrmDocDetail>>(`${BASE}/documents/${id}/convert`, { type }).then((r) => r.data);
  },
  creditNote(id: number, reason?: string | null) {
    return axiosInstance.post<CrmMessageResponse<CrmDocDetail>>(`${BASE}/documents/${id}/credit-note`, clean({ reason })).then((r) => r.data);
  },
  /** Downloads the PDF through the authenticated client and saves it. */
  async downloadPdf(id: number, filename: string) {
    await saveBlob(`${BASE}/documents/${id}/pdf`, filename);
  },
  send(id: number, payload: { channel: 'whatsapp' | 'email' | 'manual'; recipient?: string | null; subject?: string | null; message?: string | null; how?: string | null }) {
    return axiosInstance.post<CrmMessageResponse<CrmDelivery>>(`${BASE}/documents/${id}/send`, clean(payload)).then((r) => r.data);
  },
  remind(id: number, channel?: 'whatsapp' | 'email' | 'both') {
    return axiosInstance.post<CrmMessageResponse<CrmDelivery[]>>(`${BASE}/documents/${id}/remind`, clean({ channel })).then((r) => r.data);
  },
  uploadAttachment(id: number, file: File) {
    const form = new FormData();
    form.append('file', file);
    return axiosInstance.post<CrmMessageResponse<CrmDocDetail>>(`${BASE}/documents/${id}/attachment`, form).then((r) => r.data);
  },
  async downloadAttachment(id: number, filename: string) {
    await saveBlob(`${BASE}/documents/${id}/attachment`, filename);
  },
  async downloadReceipt(id: number, filename: string) {
    await saveBlob(`${BASE}/payments/${id}/receipt`, filename);
  },
  sendReceipt(id: number, channel: 'whatsapp' | 'email', recipient?: string | null) {
    return axiosInstance.post<{ message: string }>(`${BASE}/payments/${id}/send-receipt`, clean({ channel, recipient })).then((r) => r.data);
  },
  documentReminders(id: number) {
    return axiosInstance.get<{ data: CrmReminder[] }>(`${BASE}/documents/${id}/reminders`).then((r) => r.data.data);
  },

  // payments + reminders
  listPayments(params: { search?: string; contact_id?: number; crm_company_id?: number; status?: string; page?: number }) {
    return axiosInstance.get<CrmPaginated<CrmPayment>>(`${BASE}/payments`, { params: clean(params) }).then((r) => r.data);
  },
  recordPayment(payload: PaymentPayload) {
    return axiosInstance.post<CrmMessageResponse<CrmPayment>>(`${BASE}/payments`, payload).then((r) => r.data);
  },
  allocatePayment(id: number, allocations: { crm_document_id: number; amount: string }[]) {
    return axiosInstance.post<CrmMessageResponse<CrmPayment>>(`${BASE}/payments/${id}/allocate`, { allocations }).then((r) => r.data);
  },
  reversePayment(id: number, reason: string) {
    return axiosInstance.post<CrmMessageResponse<CrmPayment>>(`${BASE}/payments/${id}/reverse`, { reason }).then((r) => r.data);
  },
  listReminders(status = 'awaiting_approval') {
    return axiosInstance.get<CrmPaginated<CrmReminder>>(`${BASE}/reminders`, { params: { status } }).then((r) => r.data);
  },
  approveReminder(id: number) {
    return axiosInstance.post<{ message: string }>(`${BASE}/reminders/${id}/approve`).then((r) => r.data);
  },
  skipReminder(id: number) {
    return axiosInstance.post<{ message: string }>(`${BASE}/reminders/${id}/skip`).then((r) => r.data);
  },

  // settings + report
  getProfile() {
    return axiosInstance.get<{ data: CrmBusinessProfile }>(`${BASE}/settings/profile`).then((r) => r.data.data);
  },
  saveProfile(payload: Partial<CrmBusinessProfile>) {
    return axiosInstance.put<CrmMessageResponse<CrmBusinessProfile>>(`${BASE}/settings/profile`, payload).then((r) => r.data);
  },
  getGateway() {
    return axiosInstance.get<{ data: CrmGatewaySettings }>(`${BASE}/settings/gateway`).then((r) => r.data.data);
  },
  saveGateway(payload: { gateway: string; mode: string; key_id?: string | null; key_secret?: string | null; webhook_secret?: string | null; is_enabled: boolean }) {
    return axiosInstance.put<CrmMessageResponse<CrmGatewaySettings>>(`${BASE}/settings/gateway`, payload).then((r) => r.data);
  },
  getReminderSettings() {
    return axiosInstance.get<{ data: CrmReminderSettings }>(`${BASE}/settings/reminders`).then((r) => r.data.data);
  },
  saveReminderSettings(payload: CrmReminderSettings) {
    return axiosInstance.put<CrmMessageResponse<CrmReminderSettings>>(`${BASE}/settings/reminders`, payload).then((r) => r.data);
  },
  salesReport() {
    return axiosInstance.get<{ data: CrmSalesReport }>(`${BASE}/reports/sales`).then((r) => r.data.data);
  },

  // public link (customer side, no login)
  publicDocument(token: string) {
    return publicApi.get<{ data: CrmPublicDocument }>(`/public/crm-documents/${encodeURIComponent(token)}`).then((r) => r.data.data);
  },
  publicPdfUrl(token: string) {
    return `${axiosInstance.defaults.baseURL ?? ''}/public/crm-documents/${encodeURIComponent(token)}/pdf`;
  },
  publicPay(token: string) {
    return publicApi.post<{ data: CrmCheckoutOrder }>(`/public/crm-documents/${encodeURIComponent(token)}/pay`).then((r) => r.data.data);
  },
  publicConfirm(token: string, payload: Record<string, string>) {
    return publicApi.post<{ message: string; data: { receipt: string | null } }>(`/public/crm-documents/${encodeURIComponent(token)}/confirm`, payload).then((r) => r.data);
  },
};

export default crmDocumentsService;
