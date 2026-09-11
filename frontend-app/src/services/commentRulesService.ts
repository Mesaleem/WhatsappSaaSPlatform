import axiosInstance from '../core/api/axiosInstance';
import type { CommentAutomationRule, CommentAutomationRulePayload } from '../types/commentRules';

const BASE = '/social/comment-rules';

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 4.
 * Axios service for CommentRulesPage's CRUD.
 */
const commentRulesService = {
  list() {
    return axiosInstance.get<{ data: CommentAutomationRule[] }>(`${BASE}/`).then((res) => res.data.data);
  },

  create(payload: CommentAutomationRulePayload) {
    return axiosInstance
      .post<{ message: string; data: CommentAutomationRule }>(`${BASE}/`, payload)
      .then((res) => res.data);
  },

  update(id: number, payload: Partial<CommentAutomationRulePayload>) {
    return axiosInstance
      .put<{ message: string; data: CommentAutomationRule }>(`${BASE}/${id}`, payload)
      .then((res) => res.data);
  },

  remove(id: number) {
    return axiosInstance.delete<{ message: string }>(`${BASE}/${id}`).then((res) => res.data);
  },
};

export default commentRulesService;
