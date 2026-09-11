import axiosInstance from '../core/api/axiosInstance';
import type {
  ApproveQuotaRequestResponse,
  CreateQuotaRequestPayload,
  CreateQuotaRequestResponse,
  QuotaRequestListResponse,
  QuotaRequestStatus,
} from '../types/quotaRequest';

const quotaRequestService = {
  /** POST /api/quota-requests/store — Client Admin only. */
  store(payload: CreateQuotaRequestPayload) {
    return axiosInstance
      .post<CreateQuotaRequestResponse>('/quota-requests/store', payload)
      .then((res) => res.data);
  },

  /** GET /api/admin/quota-requests — Super Admin only. Defaults to pending-only. */
  list(status: QuotaRequestStatus | 'all' = 'pending', page = 1, perPage = 15) {
    return axiosInstance
      .get<QuotaRequestListResponse>('/admin/quota-requests', {
        params: { status, page, per_page: perPage },
      })
      .then((res) => res.data);
  },

  /** POST /api/admin/quota-requests/{id}/approve — Super Admin only. */
  approve(id: number) {
    return axiosInstance
      .post<ApproveQuotaRequestResponse>(`/admin/quota-requests/${id}/approve`)
      .then((res) => res.data);
  },
};

export default quotaRequestService;
