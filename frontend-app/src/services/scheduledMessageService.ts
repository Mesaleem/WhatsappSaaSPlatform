import axiosInstance from '../core/api/axiosInstance';
import type { ScheduledMessageListResponse } from '../types/scheduledMessage';

/** Session-authenticated list/cancel for a scheduled send (Send Notification page). Not part of the Developer API. */
const scheduledMessageService = {
  list(page = 1) {
    return axiosInstance
      .get<ScheduledMessageListResponse>('/alerts/scheduled-messages', { params: { page } })
      .then((res) => res.data);
  },

  cancel(id: number) {
    return axiosInstance.post<{ message: string }>(`/alerts/scheduled-messages/${id}/cancel`).then((res) => res.data);
  },
};

export default scheduledMessageService;
