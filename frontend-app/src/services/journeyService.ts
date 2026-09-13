import axiosInstance from '../core/api/axiosInstance';
import type { SaveFlowPayload, WhatsAppFlow, WhatsAppFlowSession } from '../types/journey';

const BASE = '/whatsapp/flows';

/**
 * Module 5 — No-Code WhatsApp Journey Builder. Axios service for
 * JourneyBuilderPage.tsx.
 */
const journeyService = {
  list() {
    return axiosInstance.get<{ data: WhatsAppFlow[] }>(`${BASE}/`).then((res) => res.data.data);
  },

  get(id: number) {
    return axiosInstance.get<{ data: WhatsAppFlow }>(`${BASE}/${id}`).then((res) => res.data.data);
  },

  create(payload: SaveFlowPayload) {
    return axiosInstance.post<{ message: string; data: WhatsAppFlow }>(`${BASE}/`, payload).then((res) => res.data.data);
  },

  update(id: number, payload: SaveFlowPayload) {
    return axiosInstance.put<{ message: string; data: WhatsAppFlow }>(`${BASE}/${id}`, payload).then((res) => res.data.data);
  },

  remove(id: number) {
    return axiosInstance.delete<{ message: string }>(`${BASE}/${id}`).then((res) => res.data);
  },

  toggle(id: number) {
    return axiosInstance.post<{ message: string; data: WhatsAppFlow }>(`${BASE}/${id}/toggle`).then((res) => res.data.data);
  },

  sessions(id: number) {
    return axiosInstance.get<{ data: WhatsAppFlowSession[] }>(`${BASE}/${id}/sessions`).then((res) => res.data.data);
  },

  /** Sends REAL WhatsApp messages — see backend WhatsAppFlowController::test()'s docblock. */
  test(id: number, phoneNumber: string) {
    return axiosInstance.post<{ message: string }>(`${BASE}/${id}/test`, { phone_number: phoneNumber }).then((res) => res.data);
  },
};

export default journeyService;
