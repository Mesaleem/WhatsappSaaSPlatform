import axiosInstance from '../core/api/axiosInstance';
import type { InboxConnectionIssue, InboxMessage, InboxThread, InboxThreadList, SendMessagePayload } from '../types/inbox';

const BASE = '/social/inbox';

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 4.
 * Axios service for the Unified Social Inbox.
 */
const inboxService = {
  /** Phase 9 Task 2 — also returns the connections that need reconnecting. */
  listThreads(): Promise<InboxThreadList> {
    return axiosInstance
      .get<{ data: InboxThread[]; connection_issues?: InboxConnectionIssue[] }>(`${BASE}/threads`)
      .then((res) => ({ threads: res.data.data, connectionIssues: res.data.connection_issues ?? [] }));
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
