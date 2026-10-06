import axiosInstance from '../core/api/axiosInstance';
import type { CreateOrderResponse } from '../types/billing';
import type {
  AdminWhatsAppDevice,
  WhatsAppStatus,
  WhatsAppStatusResponse,
  WhatsAppNumbersResponse,
  AddonInvoice,
  MetaConfigResponse,
  SaveMetaConfigPayload,
  SaveMetaConfigResponse,
  TestMetaConnectionPayload,
  TestMetaConnectionResult,
} from '../types/whatsapp';

const BASE = '/whatsapp';

/**
 * Axios service for Module 4's Laravel-side WhatsApp endpoints. These only
 * ever forward to qr-engine-service (see backend-api/AccountController);
 * the live QR/status stream itself comes over Socket.IO — see QRScannerModal.
 */
const whatsappService = {
  /**
   * Super Admin WhatsApp Device Integration — `accountId` lets a Super
   * Admin act on a SPECIFIC row's account (the Device Settings overview
   * table lists every tenant at once) even when the header's tenant
   * selector points elsewhere or is unset — same override pattern as
   * developerService.createApiKey()/createWebhook(). Omitted, these three
   * calls fall back to the axios interceptor's default `?account_id=`
   * (the header selector) exactly as before, so every existing caller
   * (WhatsAppSetupPage, QRScannerModal without an override) is unaffected.
   */
  status(accountId?: number) {
    return axiosInstance
      .get<WhatsAppStatusResponse>(`${BASE}/status`, accountId ? { params: { account_id: accountId } } : undefined)
      .then((res) => res.data);
  },

  /**
   * `phoneNumber` (digits, country code included) starts pairing-code login
   * instead of QR. The code arrives on the Socket.IO stream as `pairing_code`,
   * not in this response. Omitted, this is the unchanged QR flow.
   */
  startSession(accountId?: number, phoneNumber?: string, numberId?: number | null) {
    const params = {
      ...(accountId ? { account_id: accountId } : {}),
      ...(numberId ? { number_id: numberId } : {}),
    };
    return axiosInstance
      .post<{ message: string; status?: string }>(
        `${BASE}/start-session`,
        phoneNumber ? { phone_number: phoneNumber } : undefined,
        Object.keys(params).length ? { params } : undefined,
      )
      .then((res) => res.data);
  },

  logout(accountId?: number) {
    return axiosInstance
      .post<{ message: string }>(`${BASE}/logout`, undefined, accountId ? { params: { account_id: accountId } } : undefined)
      .then((res) => res.data);
  },

  /** WhatsApp number slots of the account (included number + paid add-ons). */
  listNumbers(accountId?: number) {
    return axiosInstance
      .get<WhatsAppNumbersResponse>(`${BASE}/numbers`, accountId ? { params: { account_id: accountId } } : undefined)
      .then((res) => res.data);
  },

  setDefaultNumber(id: number, accountId?: number) {
    return axiosInstance
      .put(`${BASE}/numbers/${id}/default`, undefined, accountId ? { params: { account_id: accountId } } : undefined)
      .then((res) => res.data);
  },

  removeNumber(id: number, accountId?: number) {
    return axiosInstance
      .delete(`${BASE}/numbers/${id}`, accountId ? { params: { account_id: accountId } } : undefined)
      .then((res) => res.data);
  },

  /** Price of one extra number per term (GST included), and the term length. */
  addonPrice() {
    return axiosInstance
      .get<{ price: number; currency: string; includes_gst: boolean; term_months: number; max_per_purchase: number }>(
        `${BASE}/numbers/addon-price`,
      )
      .then((res) => res.data);
  },

  /** Creates one invoice for the chosen extra numbers. They are connected after payment. */
  purchaseAddons(phoneNumbers: string[], accountId?: number) {
    return axiosInstance
      .post<{ message: string; invoice: AddonInvoice }>(
        `${BASE}/numbers/purchase`,
        { phone_numbers: phoneNumbers },
        accountId ? { params: { account_id: accountId } } : undefined,
      )
      .then((res) => res.data);
  },

  /**
   * Records a manual payment for an add-on invoice (Super Admin, or an Agent for its
   * own client). `accountId` is the invoice's account.
   */
  recordAddonPayment(
    invoiceId: number,
    accountId: number,
    body: {
      amount: number;
      method: 'cash' | 'bank_transfer' | 'upi' | 'cheque' | 'other';
      transaction_id: string;
      paid_on: string;
      term_starts_on?: string;
      note?: string;
    },
  ) {
    return axiosInstance
      .post<{ message: string; invoice: AddonInvoice }>(
        `/admin/whatsapp/addon-invoices/${invoiceId}/record-payment`,
        body,
        { params: { account_id: accountId } },
      )
      .then((res) => res.data);
  },

  /**
   * Starts an online payment for an add-on invoice. Returns the same fields as the
   * plan checkout, so the same Razorpay or Stripe checkout opens. Refused by the
   * server (422 gateway_not_configured) until a gateway is configured.
   */
  payAddonInvoice(invoiceId: number, gateway: 'razorpay' | 'stripe', accountId?: number) {
    return axiosInstance
      .post<CreateOrderResponse>(
        `${BASE}/addon-invoices/${invoiceId}/pay`,
        { gateway },
        accountId ? { params: { account_id: accountId } } : undefined,
      )
      .then((res) => res.data);
  },

  cancelAddonInvoice(invoiceId: number, accountId?: number) {
    return axiosInstance
      .delete(`${BASE}/addon-invoices/${invoiceId}`, accountId ? { params: { account_id: accountId } } : undefined)
      .then((res) => res.data);
  },

  /** GET /api/admin/whatsapp/devices — Super Admin Device Settings overview table. */
  adminListDevices() {
    return axiosInstance.get<{ data: AdminWhatsAppDevice[] }>('/admin/whatsapp/devices').then((res) => res.data.data);
  },

  // Super Admin WhatsApp Device Integration — the Super Admin's OWN
  // scannable WhatsApp test device (backend: Account::platformDevice()).
  // Distinct from status()/startSession()/logout() above: those act on a
  // client account_id, this always acts on the one reserved platform
  // device, so no accountId parameter is needed or accepted.
  selfDeviceStatus() {
    return axiosInstance
      .get<{ account_id: number; status: WhatsAppStatus; last_connected_at: string | null }>(
        '/admin/whatsapp/self-device',
      )
      .then((res) => res.data);
  },

  selfDeviceStartSession() {
    return axiosInstance
      .post<{ message: string }>('/admin/whatsapp/self-device/start-session')
      .then((res) => res.data);
  },

  selfDeviceLogout() {
    return axiosInstance.post<{ message: string }>('/admin/whatsapp/self-device/logout').then((res) => res.data);
  },

  // Module 5: Meta Cloud API credential configuration. Admin-only on the
  // backend (role:Admin) — the UI additionally gates visibility, see
  // MetaConfigCard.
  getMetaConfig() {
    return axiosInstance.get<MetaConfigResponse>(`${BASE}/meta-config`).then((res) => res.data);
  },

  saveMetaConfig(payload: SaveMetaConfigPayload) {
    return axiosInstance
      .post<SaveMetaConfigResponse>(`${BASE}/meta-config`, payload)
      .then((res) => res.data);
  },

  testMetaConnection(payload: TestMetaConnectionPayload) {
    return axiosInstance
      .post<TestMetaConnectionResult>(`${BASE}/meta-config/test-connection`, payload)
      .then((res) => res.data);
  },
};

export default whatsappService;
