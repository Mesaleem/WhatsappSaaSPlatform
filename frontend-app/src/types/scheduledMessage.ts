/**
 * A message scheduled for later from the Send Notification page -- template or "No template", to one
 * number or to a contact group. Mirrors ScheduledMessageController::present(). Previously this list
 * could only be seen or cancelled through the Developer API; this is the internal (session-auth) view
 * of the same rows, whichever screen (template send, "No template" send, or the API) created them.
 */
export type ScheduledMessageStatus = 'pending' | 'sent' | 'failed' | 'cancelled';

export interface ScheduledMessageRow {
  id: number;
  kind: 'template_individual' | 'template_group' | 'direct_text' | 'group_direct_text';
  is_template: boolean;
  recipient_type: 'individual' | 'group';
  /** The phone number, or the group's name. */
  recipient: string;
  /** The template's title, or the first part of the free-text message. */
  preview: string;
  /** The WhatsApp number it will send from, when known. */
  sender_number: string | null;
  source: 'web_ui' | 'api' | string;
  status: ScheduledMessageStatus;
  send_at: string;
  created_at: string | null;
  sent_at: string | null;
  last_error: string | null;
  can_cancel: boolean;
}

export interface ScheduledMessageListResponse {
  data: ScheduledMessageRow[];
  meta: { current_page: number; last_page: number; total: number };
}
