import axiosInstance from '../core/api/axiosInstance';
import type { UploadedMedia } from '../types/media';

const BASE = '/social/media';

/**
 * Social/Ads Launcher Overhaul — Step 2 (Multipart Media Upload API).
 * Axios service for the Ad Creation Wizard's creative-media upload
 * (replaces the prior free-text "Image URL" field — see MetaAdsPage.tsx).
 */
const mediaService = {
  /**
   * Uploads a single image/video file as multipart/form-data.
   * `onProgress` (0-100) lets the caller render an upload progress bar;
   * omit it for a simple fire-and-await call.
   */
  upload(file: File, onProgress?: (percent: number) => void) {
    const formData = new FormData();
    formData.append('media', file);

    return axiosInstance
      .post<{ data: UploadedMedia }>(`${BASE}/upload`, formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
        onUploadProgress: onProgress
          ? (event) => {
              if (event.total) {
                onProgress(Math.round((event.loaded / event.total) * 100));
              }
            }
          : undefined,
      })
      .then((res) => res.data.data);
  },
};

export default mediaService;
