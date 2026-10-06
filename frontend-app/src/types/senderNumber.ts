/** A WhatsApp number a send can go out from: this account's linked, active number. From GET /api/alerts/sender-numbers. */
export interface SenderNumber {
  id: number;
  phone_number: string;
  is_default: boolean;
}
