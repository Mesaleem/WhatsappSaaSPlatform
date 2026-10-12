import axiosInstance from '../core/api/axiosInstance';
import type { LoginResponse } from '../types/auth';
import type {
  ContactMessageRow,
  ContactPayload,
  PublicPlan,
  RegisterPayload,
  SiteContent,
  SiteContentKey,
} from '../types/site';

/**
 * Public marketing website (no login needed) plus the Super Admin's
 * Website Content editor — see Api\PublicSiteController and
 * Api\SiteContentController on the backend.
 */
const publicSiteService = {
  site() {
    return axiosInstance.get<{ data: SiteContent }>('/public/site').then((res) => res.data.data);
  },

  plans() {
    return axiosInstance
      .get<{ data: PublicPlan[]; tax_rate: number }>('/public/plans')
      .then((res) => res.data);
  },

  contact(payload: ContactPayload) {
    return axiosInstance.post<{ message: string }>('/public/contact', payload).then((res) => res.data);
  },

  /** Signup step 1: checks the email is real and emails a 6-digit code. */
  sendSignupCode(email: string) {
    return axiosInstance
      .post<{ message: string; expires_in_minutes: number }>('/auth/register/send-code', { email })
      .then((res) => res.data);
  },

  register(payload: RegisterPayload) {
    return axiosInstance.post<LoginResponse>('/auth/register', payload).then((res) => res.data);
  },

  forgotPassword(email: string) {
    return axiosInstance.post<{ message: string }>('/auth/forgot-password', { email }).then((res) => res.data);
  },

  resetPassword(payload: { token: string; email: string; password: string; password_confirmation: string }) {
    return axiosInstance.post<{ message: string }>('/auth/reset-password', payload).then((res) => res.data);
  },

  // ---- Super Admin ----

  adminContent() {
    return axiosInstance
      .get<{ data: SiteContent; defaults: SiteContent; customized: SiteContentKey[] }>('/admin/site-content')
      .then((res) => res.data);
  },

  saveSection(key: SiteContentKey, value: unknown) {
    return axiosInstance
      .put<{ message: string; data: unknown }>(`/admin/site-content/${key}`, { value })
      .then((res) => res.data);
  },

  resetSection(key: SiteContentKey) {
    return axiosInstance
      .delete<{ message: string; data: unknown }>(`/admin/site-content/${key}`)
      .then((res) => res.data);
  },

  messages(status: 'open' | 'handled' | 'all', page = 1) {
    return axiosInstance
      .get<{ data: ContactMessageRow[]; current_page: number; last_page: number; total: number; open_count: number }>(
        '/admin/contact-messages',
        { params: { status, page } },
      )
      .then((res) => res.data);
  },

  setHandled(id: number, handled: boolean) {
    return axiosInstance
      .patch<{ message: string; data: ContactMessageRow }>(`/admin/contact-messages/${id}`, { handled })
      .then((res) => res.data);
  },
};

export default publicSiteService;
