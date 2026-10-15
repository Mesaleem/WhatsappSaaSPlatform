import axiosInstance from '../core/api/axiosInstance';
import type { WaMessage, WaThread, WaThreadsResponse } from '../types/whatsappInbox';

/** WhatsApp inbox (QA fixes, 2026-10-14). The tenant comes from the ambient account selection, like every page. */
const whatsappInboxService = {
  threads(params: { search?: string; unread?: boolean; per_page?: number } = {}) {
    return axiosInstance
      .get<WaThreadsResponse>('/whatsapp/inbox/threads', { params: { ...params, unread: params.unread ? 1 : undefined } })
      .then((res) => res.data);
  },

  messages(threadId: number) {
    return axiosInstance
      .get<{ thread: WaThread; data: WaMessage[] }>(`/whatsapp/inbox/threads/${threadId}/messages`)
      .then((res) => res.data);
  },

  reply(threadId: number, text: string) {
    return axiosInstance
      .post<{ message: string; data: WaMessage }>(`/whatsapp/inbox/threads/${threadId}/reply`, { text })
      .then((res) => res.data);
  },
};

export default whatsappInboxService;
