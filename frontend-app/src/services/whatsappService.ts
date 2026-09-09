import axiosInstance from '../core/api/axiosInstance';
import type {
  AdminWhatsAppDevice,
  WhatsAppStatus,
  WhatsAppStatusResponse,
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

  startSession(accountId?: number) {
    return axiosInstance
      .post<{ message: string }>(
        `${BASE}/start-session`,
        undefined,
        accountId ? { params: { account_id: accountId } } : undefined,
      )
      .then((res) => res.data);
  },

  logout(accountId?: number) {
    return axiosInstance
      .post<{ message: string }>(`${BASE}/logout`, undefined, accountId ? { params: { account_id: accountId } } : undefined)
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
