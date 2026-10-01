import axiosInstance from '../core/api/axiosInstance';
import type { IndustryContext } from '../types/education';

/** Phase 11 Task 1 — GET /api/industry/context for the TARGET account (axios adds the selected client). */
const industryService = {
  context() {
    return axiosInstance.get<{ data: IndustryContext[] }>('/industry/context').then((res) => res.data.data);
  },
};

export default industryService;
