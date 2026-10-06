/** A batch send from an Excel/CSV list (Send Notification page). Mirrors MessageBatchController::present(). */
export type MessageBatchStatus = 'scheduled' | 'running' | 'paused' | 'stopped' | 'completed';

export interface MessageBatch {
  id: number;
  title: string;
  source_filename: string | null;
  status: MessageBatchStatus;
  total: number;
  sender_number_ids: number[];
  sent_count: number;
  failed_count: number;
  pending_count: number;
  batch_size: number;
  interval_minutes: number;
  /** ISO timestamp when the batch was set to start, or null when it started at once. */
  scheduled_at: string | null;
  /** When the next message is due, ISO UTC, or null when none is waiting. */
  next_send_at: string | null;
  /** When the last waiting message is due: the expected finish time, ISO UTC. */
  finishes_at: string | null;
  stop_reason: string | null;
  created_at: string | null;
  completed_at: string | null;
}

/** POST /api/alerts/bulk-sends: the numbers typed on the Send page (up to 30), sent from the ticked numbers in turn. */
export interface CreateBulkSendPayload {
  recipient_phones: string[];
  template_id: number;
  variables: Record<string, string>;
  media_url?: string;
  sender_number_ids: number[];
  /** "yyyy-mm-dd hh:mm:ss", read as Indian Standard Time. Omit to send at once. */
  scheduled_at?: string;
}

export interface CreateMessageBatchPayload {
  file: File;
  template_id: number;
  /** The numbers to send from, in turn: the 1st message from the 1st number, the 2nd from the 2nd, and so on. */
  sender_number_ids: number[];
  variables: Record<string, string>;
  media_url?: string;
  title?: string;
  batch_size: number;
  interval_minutes: number;
  /** "yyyy-mm-dd hh:mm:ss", read as Indian Standard Time. Omit to start at once. */
  scheduled_at?: string;
}
