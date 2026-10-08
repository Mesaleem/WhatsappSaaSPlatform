import axiosInstance from '../core/api/axiosInstance';
import type { CreateBulkSendPayload, CreateMessageBatchPayload, MessageBatch } from '../types/messageBatch';

const BASE = '/alerts/message-batches';

/** Batch sends from an Excel/CSV list, on the Send Notification page. Not part of the Developer API. */
const messageBatchService = {
  list() {
    return axiosInstance.get<{ data: MessageBatch[] }>(BASE).then((res) => res.data.data);
  },

  create(payload: CreateMessageBatchPayload) {
    const form = new FormData();
    form.append('file', payload.file);
    if (payload.template_id) form.append('template_id', String(payload.template_id));
    if (payload.message_text) form.append('message_text', payload.message_text);
    payload.sender_number_ids.forEach((id) => form.append('sender_number_ids[]', String(id)));
    form.append('batch_size', String(payload.batch_size));
    if (payload.media_url) form.append('media_url', payload.media_url);
    if (payload.title) form.append('title', payload.title);
    if (payload.scheduled_at) form.append('scheduled_at', payload.scheduled_at);
    Object.entries(payload.variables).forEach(([key, value]) => form.append(`variables[${key}]`, value));

    return axiosInstance
      .post<{ message: string; data: MessageBatch }>(BASE, form, { headers: { 'Content-Type': 'multipart/form-data' } })
      .then((res) => res.data);
  },

  /** Bulk send from the typed numbers. Stored as a batch run (kind 'bulk') so it appears in the history. */
  createBulk(payload: CreateBulkSendPayload) {
    return axiosInstance
      .post<{ message: string; data: { id: number; status: string } }>('/alerts/bulk-sends', payload)
      .then((res) => res.data);
  },

  pause(id: number) {
    return axiosInstance.post<{ data: MessageBatch }>(`${BASE}/${id}/pause`).then((res) => res.data.data);
  },

  resume(id: number) {
    return axiosInstance.post<{ data: MessageBatch }>(`${BASE}/${id}/resume`).then((res) => res.data.data);
  },

  stop(id: number) {
    return axiosInstance.post<{ data: MessageBatch }>(`${BASE}/${id}/stop`).then((res) => res.data.data);
  },
};

export default messageBatchService;
