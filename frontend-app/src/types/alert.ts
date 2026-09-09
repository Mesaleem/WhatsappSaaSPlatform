/**
 * Module 6 — Payment Alert Dispatcher type definitions.
 * Mirrors app/Models/PaymentAlert.php + PaymentAlertController's JSON shape.
 */

export type PaymentAlertStatus = 'pending' | 'queued' | 'sent' | 'failed';

export interface PaymentAlert {
  id: number;
  account_id: number;
  recipient_phone: string;
  customer_name: string;
  /** Laravel's decimal cast serializes as a string — see subscription.ts's rate_per_message note. */
  amount: string;
  payment_ref: string;
  status: PaymentAlertStatus;
  cost_deducted: string;
  error_reason: string | null;
  sent_at: string | null;
  /**
   * Module 7 — the WhatsApp driver's raw sendMessage() result. Omitted by
   * the paginated /alerts/logs list (kept lean); present on the single-row
   * /alerts/logs/{id} detail response the log table's modal fetches.
   */
  raw_response?: Record<string, unknown> | null;
  created_at: string;
  updated_at: string;
  /**
   * Super Admin Multi-Tenant Scoping: present ONLY when the log was
   * fetched with no client selected (MessageLogController/ExportController
   * eager-load this on their 'global' branch only).
   */
  account?: { id: number; company_name: string } | null;
}

/** POST /api/alerts/send */
export interface SendAlertPayload {
  recipient_phone: string;
  customer_name: string;
  amount: number;
  payment_ref: string;
}

export interface SendAlertResponse {
  message: string;
  alert: PaymentAlert;
}

/** Row-level outcome inside a bulk-upload response. */
export interface BulkUploadQueuedRow {
  row: number;
  payment_ref: string;
  id: number;
}

export interface BulkUploadDuplicateRow {
  row: number;
  payment_ref: string;
}

export interface BulkUploadInvalidRow {
  row: number;
  errors: string[];
}

/** POST /api/alerts/bulk-upload */
export interface BulkUploadResponse {
  message: string;
  queued_count: number;
  queued: BulkUploadQueuedRow[];
  skipped_duplicates: BulkUploadDuplicateRow[];
  invalid_rows: BulkUploadInvalidRow[];
}

/** Client-side-only shape used by the bulk upload tab's CSV previewer, before submit. */
export interface ParsedCsvRow {
  row: number;
  phone: string;
  customer_name: string;
  amount: string;
  payment_ref: string;
}
