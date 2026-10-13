import axiosInstance from '../core/api/axiosInstance';

/** Free daily AI credits (backend: AiDailyAllowance / AiCreditStatusController). */
export interface AiCreditStatus {
  unlimited: boolean;
  daily?: { per_day: number; used: number; remaining: number; resets_at: string };
  wallet_available?: number;
}

export interface FreeDailyCreditSettings {
  client_per_day: number;
  agent_per_day: number;
}

/** Fired after an AI action so the header chip re-reads the balance. */
export const AI_CREDITS_CHANGED_EVENT = 'ai-credits-changed';

const aiCreditService = {
  /** The signed-in user's own account: today's free credits and the paid wallet. */
  status() {
    return axiosInstance.get<{ data: AiCreditStatus | null }>('/ai/credit-status').then((res) => res.data.data);
  },

  /** Super Admin only. */
  settings() {
    return axiosInstance.get<{ data: FreeDailyCreditSettings }>('/admin/ai/free-daily-credits').then((res) => res.data.data);
  },

  /** Super Admin only. */
  saveSettings(payload: FreeDailyCreditSettings) {
    return axiosInstance
      .put<{ message: string; data: FreeDailyCreditSettings }>('/admin/ai/free-daily-credits', payload)
      .then((res) => res.data);
  },
};

export default aiCreditService;
