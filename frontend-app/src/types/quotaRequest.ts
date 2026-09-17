/**
 * Quota Exhaustion Request Workflow & Custom Invoice Generation type
 * definitions. Mirrors QuotaRequestController's JSON shape — see that
 * controller's docblock for the disclosed schema/status corrections this
 * feature was implemented against.
 */

import type { PaginatedResponse } from './account';
import type { Invoice } from './billing';
import type { Subscription } from './subscription';

export type QuotaRequestStatus = 'pending' | 'approved' | 'rejected';

export interface QuotaRequestAccountSummary {
  id: number;
  company_name: string;
}

export interface QuotaRequestUserSummary {
  id: number;
  name: string;
  email: string;
}

export interface QuotaRequest {
  id: number;
  account_id: number;
  requested_by: number;
  /** Set for a flat_quota request; null for a per_message wallet top-up (see requested_topup_amount instead). */
  requested_extra_messages: number | null;
  /** Set for a per_message wallet top-up (a rupee amount); null for a flat_quota request. Exactly one of the two is ever non-null on a row. */
  requested_topup_amount: number | null;
  reason: string | null;
  status: QuotaRequestStatus;
  reviewed_by: number | null;
  reviewed_at: string | null;
  invoice_id: number | null;
  created_at: string;
  updated_at: string;
  account?: QuotaRequestAccountSummary;
  /**
   * CAMELCASE KEY, DELIBERATELY (not a snake_case bug): Eloquent's
   * relationsToArray() serializes an eager-loaded relation under the
   * exact string passed to with()/load() (QuotaRequestController
   * loads 'requestedBy'), while `requested_by` above stays the raw
   * requested_by FK column, which Eloquent's attribute lookup takes
   * priority over — so both keys coexist in the same JSON object.
   */
  requestedBy?: QuotaRequestUserSummary;
}

/**
 * POST /api/quota-requests/store — exactly one of the two amount fields
 * is sent, matching the target subscription's billing_model
 * (QuotaRequestController::store() rejects a request carrying the wrong
 * one for that account's plan).
 */
export type CreateQuotaRequestPayload =
  | { requested_extra_messages: number; reason?: string }
  | { requested_topup_amount: number; reason?: string };

export interface CreateQuotaRequestResponse {
  message: string;
  data: QuotaRequest;
}

/** GET /api/admin/quota-requests */
export type QuotaRequestListResponse = PaginatedResponse<QuotaRequest>;

/** POST /api/admin/quota-requests/{id}/approve */
export interface ApproveQuotaRequestResponse {
  message: string;
  data: {
    quota_request: QuotaRequest;
    subscription: Subscription;
    invoice: Invoice;
  };
}
