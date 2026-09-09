import axiosInstance from '../core/api/axiosInstance';
import type { BulkUploadResponse, SendAlertPayload, SendAlertResponse } from '../types/alert';

const BASE = '/alerts';

/**
 * Axios service for Module 6's payment alert endpoints. A 409 on send()
 * means the payment_ref was already sent for this account — callers should
 * surface that distinctly from a generic error (see SendAlertPage).
 */
const alertService = {
  send(payload: SendAlertPayload) {
    return axiosInstance.post<SendAlertResponse>(`${BASE}/send`, payload).then((res) => res.data);
  },

  bulkUpload(file: File) {
    const formData = new FormData();
    formData.append('file', file);

    // No explicit Content-Type — axios sets multipart/form-data with the
    // correct boundary automatically when the body is a FormData instance.
    return axiosInstance
      .post<BulkUploadResponse>(`${BASE}/bulk-upload`, formData)
      .then((res) => res.data);
  },
};

export default alertService;
