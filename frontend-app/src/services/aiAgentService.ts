import axiosInstance from '../core/api/axiosInstance';

/**
 * Phase 8 Task 11 — the minimal AI Agent read the Journey builder's `agent`
 * node needs: the registered agents of the account being edited (the axios
 * interceptor adds a Super Admin's selected ?account_id=). The list is a
 * convenience only — the backend re-checks ownership on save and resolves
 * the agent again inside the session's own account at run time, so an id
 * typed or crafted by hand is never trusted.
 */
export interface AiAgentSummary {
  id: number;
  name: string;
  is_enabled: boolean;
  version: number | null;
  tools: string[];
}

const aiAgentService = {
  list() {
    return axiosInstance.get<{ data: AiAgentSummary[] }>('/ai-agents').then((res) => res.data.data);
  },
};

export default aiAgentService;
