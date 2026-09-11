import axiosInstance from '../core/api/axiosInstance';
import type { InboxMessage, InboxThread, SendMessagePayload } from '../types/inbox';

const BASE = '/social/inbox';

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 4.
 * Axios service for the Unified Social Inbox.
 */
const inboxService = {
  listThreads() {
    return axiosInstance.get<{ data: InboxThread[] }>(`${BASE}/threads`).then((res) => res.data.data);
  },

  getMessages(threadId: string) {
    return axiosInstance
      .get<{ data: InboxMessage[] }>(`${BASE}/threads/${encodeURIComponent(threadId)}/messages`)
      .then((res) => res.data.data);
  },

  send(payload: SendMessagePayload) {
    return axiosInstance.post<{ message: string }>(`${BASE}/send`, payload).then((res) => res.data);
  },
};

export default inboxService;
