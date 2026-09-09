import axiosInstance from '../core/api/axiosInstance';
import type {
  InAppNotificationsResponse,
  MailLogFilters,
  MailLogsResponse,
  NotificationBroadcastsResponse,
  NotificationTemplate,
  NotificationTemplatesResponse,
  SaveNotificationTemplatePayload,
  SendBroadcastPayload,
  NotificationBroadcast,
} from '../types/notifications';

const notificationService = {
  // Mail Template Manager
  listTemplates(page = 1, perPage = 15, search = '') {
    return axiosInstance
      .get<NotificationTemplatesResponse>('/notification-templates', {
        params: { page, per_page: perPage, search: search || undefined },
      })
      .then((res) => res.data);
  },

  createTemplate(payload: SaveNotificationTemplatePayload) {
    return axiosInstance.post<NotificationTemplate>('/notification-templates', payload).then((res) => res.data);
  },

  updateTemplate(id: number, payload: SaveNotificationTemplatePayload) {
    return axiosInstance.put<NotificationTemplate>(`/notification-templates/${id}`, payload).then((res) => res.data);
  },

  deleteTemplate(id: number) {
    return axiosInstance.delete<{ message: string }>(`/notification-templates/${id}`).then((res) => res.data);
  },

  // Advanced Broadcast Engine — Universal Table & Filter Standardization:
  // search (subject) + a sent_at date range, mirroring auditLogService's
  // params shape. No status/role param — a broadcast row has neither.
  listBroadcasts(page = 1, perPage = 15, filters: { search?: string; from?: string; to?: string } = {}) {
    return axiosInstance
      .get<NotificationBroadcastsResponse>('/notification-broadcasts', {
        params: {
          page,
          per_page: perPage,
          search: filters.search || undefined,
          from: filters.from || undefined,
          to: filters.to || undefined,
        },
      })
      .then((res) => res.data);
  },

  sendBroadcast(payload: SendBroadcastPayload) {
    return axiosInstance.post<NotificationBroadcast>('/notification-broadcasts', payload).then((res) => res.data);
  },

  // Mail Log — Track Record of Mail Sends. Per-recipient send attempts
  // behind a broadcast's aggregate email_sent_count/email_failed_count.
  listMailLogs(page = 1, perPage = 15, filters: MailLogFilters = {}) {
    return axiosInstance
      .get<MailLogsResponse>('/mail-logs', {
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

  // In-app notification inbox (every authenticated user, own rows only)
  listInbox(page = 1, perPage = 10, unreadOnly = false) {
    return axiosInstance
      .get<InAppNotificationsResponse>('/notifications/inbox', {
        params: { page, per_page: perPage, unread_only: unreadOnly || undefined },
      })
      .then((res) => res.data);
  },

  unreadCount() {
    return axiosInstance
      .get<{ unread_count: number }>('/notifications/inbox/unread-count')
      .then((res) => res.data.unread_count);
  },

  markRead(id: number) {
    return axiosInstance.post(`/notifications/inbox/${id}/read`).then((res) => res.data);
  },

  markAllRead() {
    return axiosInstance.post('/notifications/inbox/read-all').then((res) => res.data);
  },
};

export default notificationService;
