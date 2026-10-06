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
  /** How many units (groups) the client asked for; null for flat-price modules. */
  units?: number | null;
  /** When it was approved (invoice issued) or rejected. */
  decided_at?: string | null;
  term_starts_at: string | null;
  term_ends_at: string | null;
  created_at: string | null;
  account?: { id: number; name: string | null };
}

/** One add-on request, with its timeline. */
export interface AddonHistoryRequest {
  id: number;
  client: string | null;
  module: string;
  label: string;
  status: ModuleAddonStatus;
  units: number | null;
  reason: string | null;
  decision_note: string | null;
  requested_at: string | null;
  decided_at: string | null;
  paid_at: string | null;
  term_starts_at: string | null;
  term_ends_at: string | null;
  invoice_number: string | null;
  total_amount: number | null;
}

/** One WhatsApp number add-on purchase (one invoice, one line per number). */
export interface AddonHistoryNumberPurchase {
  term_months?: number;
  invoice_id: number | null;
  account_id: number;
  invoice_number: string | null;
  client: string | null;
  number_count: number;
  numbers: { phone_number: string; status: string }[];
  status: string | null;
  total_amount: number | null;
  bought_at: string | null;
  paid_at: string | null;
  term_ends_at: string | null;
}

export interface AddonHistory {
  requests: AddonHistoryRequest[];
  number_purchases: AddonHistoryNumberPurchase[];
  /** Gateways fully configured right now; online payment needs one of them. */
  gateways: string[];
}

export interface ModuleAddonTier {
  from_units: number;
  /** null = this many and above. */
  to_units: number | null;
  price: number;
}

export interface ModuleAddonOffer {
  module: string;
  label: string;
  price: number;
  term_months: number;
  units_included: number;
  is_active: boolean;
  /** Price tiers by number of units, lowest first. Empty for a flat price. */
  tiers?: ModuleAddonTier[];
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

  /** `units` is how many the client needs (groups, for Custom Contact Groups); the price follows its tier. */
  request(module: string, reason?: string, accountId?: number, units?: number) {
    return axiosInstance
      .post<{ message: string; data: ModuleAddonRow }>(
        '/module-addons/request',
        { module, reason: reason || undefined, units },
        accountId ? { params: { account_id: accountId } } : undefined,
      )
      .then((res) => res.data);
  },

  /**
   * Every request and paid number purchase the caller may see. `admin` is true for a Super
   * Admin or an agent (all clients, or its own clients); a client leaves it false.
   */
  history(options: { admin: boolean; accountId?: number }) {
    const path = options.admin ? '/admin/module-addons/history' : '/module-addons/history';
    const params = options.accountId ? { params: { account_id: options.accountId } } : undefined;
    return axiosInstance.get<AddonHistory>(path, params).then((res) => res.data);
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

  /** Super Admin only. Replaces the tiers; they must not overlap. Issued invoices keep their price. */
  updateTiers(module: string, tiers: { from_units: number; to_units: number | null; price: number }[]) {
    return axiosInstance
      .put<{ message: string }>(`/admin/module-offers/${module}/tiers`, { tiers })
      .then((res) => res.data);
  },

  /** Super Admin only. Changes apply to new requests; issued invoices keep their price. */
  updateOffer(module: string, body: Omit<ModuleAddonOffer, 'module'>) {
    return axiosInstance
      .put<{ message: string; data: ModuleAddonOffer }>(`/admin/module-offers/${module}`, body)
      .then((res) => res.data);
  },
};

export default moduleAddonService;
