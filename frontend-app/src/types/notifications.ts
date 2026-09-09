/**
 * Advanced Broadcast Engine + Mail Template Manager — mirrors
 * NotificationTemplate / NotificationBroadcast / InAppNotification +
 * NotificationTemplateController / NotificationBroadcastController /
 * InAppNotificationController's JSON shapes.
 */

import type { PaginatedResponse } from './account';

export type NotificationTemplateType = 'rich_text' | 'raw_html';

export interface NotificationTemplate {
  id: number;
  name: string;
  type: NotificationTemplateType;
  category: string | null;
  subject: string;
  body: string;
  creator: { id: number; name: string } | null;
  created_at: string;
  updated_at: string;
}

export type NotificationTemplatesResponse = PaginatedResponse<NotificationTemplate>;

export interface SaveNotificationTemplatePayload {
  name: string;
  type: NotificationTemplateType;
  category?: string | null;
  subject: string;
  body: string;
}

export type BroadcastChannel = 'email' | 'in_app';

/**
 * Non-Super-Admin senders always end up as 'account_users' server-side
 * regardless of what's selected client-side (NotificationBroadcastController::store()
 * force-overrides it) — the composer still only OFFERS 'account_users' to
 * an Admin so the UI doesn't promise a targeting option that will be
 * silently downgraded.
 */
export type BroadcastTargetType = 'account_users' | 'specific_clients' | 'all_users' | 'custom_emails';

export interface NotificationBroadcast {
  id: number;
  template: { id: number; name: string } | null;
  account: { id: number; company_name: string } | null;
  subject: string;
  body: string;
  category: string | null;
  channels: BroadcastChannel[];
  target_type: BroadcastTargetType;
  target_summary: string | null;
  recipient_count: number;
  email_sent_count: number;
  email_failed_count: number;
  sender: { id: number; name: string } | null;
  sent_at: string | null;
  created_at: string;
}

export interface NotificationBroadcastsResponse extends PaginatedResponse<NotificationBroadcast> {
  scope: 'account' | 'global';
}

export interface SendBroadcastPayload {
  template_id?: number | null;
  subject: string;
  body: string;
  category?: string | null;
  channels: BroadcastChannel[];
  target_type: BroadcastTargetType;
  account_ids?: number[];
  emails?: string[];
}

export interface InAppNotification {
  id: number;
  title: string;
  body: string;
  category: string | null;
  is_read: boolean;
  read_at: string | null;
  created_at: string;
}

export type InAppNotificationsResponse = PaginatedResponse<InAppNotification>;

/**
 * Mail Log — Track Record of Mail Sends. One row per individual email
 * send ATTEMPT (mirrors backend-api's MailLog model/migration docblock),
 * not per broadcast campaign — NotificationBroadcast's email_sent_count/
 * email_failed_count are the aggregate, this is the per-recipient detail
 * behind those numbers.
 */
export type MailLogStatus = 'sent' | 'failed';

export interface MailLog {
  id: number;
  broadcast: { id: number; subject: string } | null;
  recipient_email: string;
  recipient_name: string | null;
  subject: string;
  status: MailLogStatus;
  error_message: string | null;
  sent_at: string;
}

export interface MailLogFilters {
  search?: string;
  status?: MailLogStatus | '';
  from?: string;
  to?: string;
}

export interface MailLogsResponse extends PaginatedResponse<MailLog> {
  scope: 'account' | 'global';
}
