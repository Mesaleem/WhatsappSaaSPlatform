import axiosInstance from '../core/api/axiosInstance';
import type { PaginatedResponse } from '../types/account';
import type { ChatbotLog, ChatbotLogFilters, ChatbotRule, SaveChatbotRulePayload } from '../types/chatbot';

const chatbotService = {
  listRules() {
    return axiosInstance.get<{ data: ChatbotRule[] }>('/chatbot/rules').then((res) => res.data.data);
  },

  createRule(payload: SaveChatbotRulePayload) {
    return axiosInstance
      .post<{ message: string; data: ChatbotRule }>('/chatbot/rules', payload)
      .then((res) => res.data.data);
  },

  updateRule(id: number, payload: SaveChatbotRulePayload) {
    return axiosInstance
      .put<{ message: string; data: ChatbotRule }>(`/chatbot/rules/${id}`, payload)
      .then((res) => res.data.data);
  },

  deleteRule(id: number) {
    return axiosInstance.delete<{ message: string }>(`/chatbot/rules/${id}`).then((res) => res.data);
  },

  toggleRule(id: number) {
    return axiosInstance
      .post<{ message: string; data: ChatbotRule }>(`/chatbot/rules/${id}/toggle`)
      .then((res) => res.data.data);
  },

  getLogs(filters: ChatbotLogFilters) {
    return axiosInstance
      .get<PaginatedResponse<ChatbotLog>>('/chatbot/logs', { params: filters })
      .then((res) => res.data);
  },
};

export default chatbotService;
