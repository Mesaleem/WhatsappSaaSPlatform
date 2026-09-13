import axiosInstance from '../core/api/axiosInstance';
import type { OrganicPost, PublishOrganicPostPayload } from '../types/organic';

const BASE = '/social/organic-posts';

/**
 * Social/Ads Launcher Overhaul — Step 3. Axios service for the Organic
 * Multi-Channel Publishing Engine (Mode Toggle's "Organic Post" side).
 */
const organicPostService = {
  list() {
    return axiosInstance.get<{ data: OrganicPost[] }>(`${BASE}/`).then((res) => res.data.data);
  },

  publish(payload: PublishOrganicPostPayload) {
    return axiosInstance.post<{ data: OrganicPost }>(`${BASE}/`, payload).then((res) => res.data.data);
  },
};

export default organicPostService;
