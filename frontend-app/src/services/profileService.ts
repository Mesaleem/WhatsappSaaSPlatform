import axiosInstance from '../core/api/axiosInstance';
import type { User } from '../types/auth';

export interface UpdateProfilePayload {
  name: string;
  email: string;
  /** Omit (or leave blank) to keep the current password unchanged. */
  password?: string;
}

/**
 * NOTE: backend-api has no route for this yet — routes/api.php only
 * defines GET /auth/me, POST /auth/login, POST /auth/logout. This calls
 * a PATCH /auth/profile that does not exist on the server yet, so Save
 * Changes will 404 until that route + AuthController method are added.
 * Kept as a real axios call (not a stub) so wiring it up later is a
 * one-file backend change with nothing to touch here.
 */
const profileService = {
  updateProfile(payload: UpdateProfilePayload) {
    return axiosInstance
      .patch<{ message: string; user: User }>('/auth/profile', payload)
      .then((res) => res.data);
  },
};

export default profileService;
