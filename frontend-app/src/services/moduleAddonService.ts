import axiosInstance from '../core/api/axiosInstance';
import type { CreateOrderResponse } from '../types/billing';

export type ModuleAddonStatus = 'requested' | 'invoiced' | 'paid' | 'expired' | 'rejected';

export interface ModuleAddonRow {
  id: number;
  module: string;
  label: string;
  status: ModuleAddonStatus;
  reason: string | null;
  invoice_id: number | null;
  total_amount: number | null;
  term_starts_at: string | null;
  term_ends_at: string | null;
  created_at: string | null;
  account?: { id: number; name: string | null };
}

export interface ModuleAddonOffer {
  module: string;
  label: string;
  price: number;
  term_months: number;
  units_included: number;
  is_active: boolean;
}

const moduleAddonService = {
  /** The client's own requests, and the offers it can request. */
  list(accountId?: number) {
    return axiosInstance
      .get<{ data: ModuleAddonRow[]; offers: ModuleAddonOffer[]; gateway_ready: boolean }>(
        '/module-addons',
        accountId ? { params: { account_id: accountId } } : undefined,
      )
      .then((res) => res.data);
  },

  request(module: string, reason?: string, accountId?: number) {
    return axiosInstance
      .post<{ message: string; data: ModuleAddonRow }>(
        '/module-addons/request',
        { module, reason: reason || undefined },
        accountId ? { params: { account_id: accountId } } : undefined,
      )
      .then((res) => res.data);
  },

  /** Requests the caller may act on: all for a Super Admin, its own clients for an agent. */
  pending() {
    return axiosInstance.get<{ data: ModuleAddonRow[] }>('/admin/module-addons/pending').then((res) => res.data.data);
  },

  recordPayment(id: number, body: Record<string, unknown>) {
    return axiosInstance.post<{ message: string; data: ModuleAddonRow }>(`/admin/module-addons/${id}/record-payment`, body).then((res) => res.data);
  },

  approve(id: number) {
    return axiosInstance.post<{ message: string; data: ModuleAddonRow }>(`/admin/module-addons/${id}/approve`).then((res) => res.data);
  },

  reject(id: number, note?: string) {
    return axiosInstance
      .post<{ message: string; data: ModuleAddonRow }>(`/admin/module-addons/${id}/reject`, { note: note || undefined })
      .then((res) => res.data);
  },

  /** Online payment for a module add-on invoice. Refused (422) until a gateway is configured. */
  payInvoice(invoiceId: number, gateway: 'razorpay' | 'stripe', accountId?: number) {
    return axiosInstance
      .post<CreateOrderResponse>(
        `/module-addons/invoices/${invoiceId}/pay`,
        { gateway },
        accountId ? { params: { account_id: accountId } } : undefined,
      )
      .then((res) => res.data);
  },

  /** Every offer (price, term, units, on sale). Visible to the Super Admin and agents. */
  offers() {
    return axiosInstance.get<{ data: ModuleAddonOffer[] }>('/admin/module-offers').then((res) => res.data.data);
  },

  /** Super Admin only. Changes apply to new requests; issued invoices keep their price. */
  updateOffer(module: string, body: Omit<ModuleAddonOffer, 'module'>) {
    return axiosInstance
      .put<{ message: string; data: ModuleAddonOffer }>(`/admin/module-offers/${module}`, body)
      .then((res) => res.data);
  },
};

export default moduleAddonService;
