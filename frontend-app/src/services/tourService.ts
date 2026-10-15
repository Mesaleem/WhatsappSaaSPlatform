import axiosInstance from '../core/api/axiosInstance';

/** Product tours (POST /api/auth/tours): which tours the user has finished, kept on the server. */
const tourService = {
  complete(tour: string) {
    return axiosInstance.post<{ completed_tours: string[] }>('/auth/tours', { tour }).then((res) => res.data.completed_tours);
  },

  /** No tour = every tour shows again. */
  reset(tour?: string) {
    return axiosInstance
      .post<{ completed_tours: string[] }>('/auth/tours', { action: 'reset', ...(tour ? { tour } : {}) })
      .then((res) => res.data.completed_tours);
  },
};

export default tourService;
