/**
 * [New feature, disclosed] — Message Logs / Audit Trail sidebar page.
 * Mirrors MessageDispatchLog + MessageDispatchLogController's JSON shape.
 * Deliberately separate from types/alert.ts's PaymentAlert (the
 * pre-existing `/alerts/logs` grid embedded in Analytics/Dashboard) — see
 * MessageDispatchLogController's own docblock for why these are two
 * different tables/endpoints, not a rename.
 */

import type { PaginatedResponse } from './account';

/**
 * Matches MessageDispatchLog::record()'s $success param ('sent'/'failed')
 * plus, since Group Messaging Phase 4, 'queued' — the in-flight state a
 * group-dispatch batch's row sits in between
 * MessageDispatchLog::recordGroupDispatchQueued() (enqueue time) and
 * resolveGroupDispatch() (once ProcessGroupDispatchJob finishes every
 * member). Individual sends (web_ui/web_template/api/chatbot/journey)
 * never produce 'queued' — only a group-recipient dispatch does.
 */
export type MessageDispatchStatus = 'sent' | 'failed' | 'queued';

/** Matches every source string a dispatcher currently writes — see TemplateMessageDispatcher/PaymentAlertDispatcher/ChatbotEngineService/WhatsAppJourneyEngine. */
export type MessageDispatchSource = 'web_ui' | 'web_template' | 'api' | 'chatbot' | 'journey';

/** Group Messaging Phase 5 — matches recipient_type's DB default ('individual', see the migration adding it) plus the one other value recordGroupDispatchQueued() ever writes ('group'). */
export type MessageDispatchRecipientType = 'individual' | 'group';

export interface MessageDispatchLog {
  id: number;
  account_id: number;
  /** Present only when scope === 'global' (Super Admin, no client selected) — MessageDispatchLogController::index() eager-loads it only then. */
  account?: { id: number; company_name: string } | null;
  source: MessageDispatchSource;
  api_key_id: number | null;
  recipient_phone: string;
  /** Group Messaging Phase 5 — 'individual' for every send() row (the DB default); 'group' only for a recordGroupDispatchQueued() batch row. */
  recipient_type: MessageDispatchRecipientType;
  /** Non-null only when recipient_type === 'group'. */
  group_id: number | null;
  /** Non-null only when recipient_type === 'group' — a snapshot of the group's name at dispatch time, not a live lookup (the group could be renamed/deleted since). */
  group_name: string | null;
  /** 1 for every individual send; the batch's total member count for a group row. */
  recipient_count: number;
  status: MessageDispatchStatus;
  error_reason: string | null;
  /**
   * [Disclosed]: always false today — no dispatch pathway in this codebase
   * attaches media to an outbound send yet (WhatsAppDriverInterface::
   * sendMessage has no media parameter). This column exists so the
   * column/badge is ready the moment that capability is built, without a
   * second migration.
   */
  has_media: boolean;
  reference_type: string | null;
  reference_id: number | null;
  /** [New feature, disclosed]: written only by TemplateMessageDispatcher — null for web_ui/api payment alerts, chatbot and journey sends. */
  template_name: string | null;
  /** [New feature, disclosed]: a short snippet of the actual outgoing text, truncated server-side (see MessageDispatchLog::record()'s PREVIEW_MAX_LENGTH) — not the full message. */
  message_preview: string | null;
  sent_at: string | null;
  created_at: string;
  updated_at: string;
}

/** GET /api/message-logs — a Laravel paginate() response plus `scope`, same pattern as MessageLogsResponse (types/analytics.ts). */
export interface MessageDispatchLogsResponse extends PaginatedResponse<MessageDispatchLog> {
  scope: 'account' | 'global';
}

export interface MessageDispatchLogFilters {
  search?: string;
  status?: MessageDispatchStatus | '';
  source?: MessageDispatchSource | '';
  /** Group Messaging Phase 5. */
  recipient_type?: MessageDispatchRecipientType | '';
  from?: string;
  to?: string;
}
