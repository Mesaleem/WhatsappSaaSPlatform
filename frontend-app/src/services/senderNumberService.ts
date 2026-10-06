import axiosInstance from '../core/api/axiosInstance';
import type { SenderNumber } from '../types/senderNumber';

/** The linked, active WhatsApp numbers a send can go out from (default first). */
const senderNumberService = {
  list() {
    return axiosInstance.get<{ data: SenderNumber[] }>('/alerts/sender-numbers').then((res) => res.data.data);
  },
};

export default senderNumberService;
