/** WhatsApp inbox (QA fixes, 2026-10-14) — backend WhatsAppInboxController. */

export interface WaThread {
  id: number;
  contact_phone: string;
  contact_name: string | null;
  last_message_preview: string | null;
  last_direction: 'in' | 'out' | null;
  last_message_at: string | null;
  last_inbound_at: string | null;
  unread_count: number;
  /** Meta only: false when more than 24 h passed since the customer wrote (template needed). */
  can_reply_freely: boolean;
}

export interface WaMessage {
  id: number;
  direction: 'in' | 'out';
  message_type: string;
  body: string | null;
  status: string | null;
  source: string | null;
  created_at: string | null;
}

export interface WaThreadsResponse {
  data: WaThread[];
  current_page: number;
  last_page: number;
  total: number;
  unread_total: number;
}
