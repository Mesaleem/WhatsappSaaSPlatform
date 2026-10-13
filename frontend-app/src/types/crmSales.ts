/** CRM Sales Phases 2–6 — products, documents, payments, reminders and settings. Money is always a decimal string. */

export const CRM_DOC_TYPES = ['quotation', 'proposal', 'sales_order', 'customer_po', 'invoice', 'credit_note'] as const;
export type CrmDocType = (typeof CRM_DOC_TYPES)[number];

export const CRM_DOC_TYPE_LABELS: Record<CrmDocType, string> = {
  quotation: 'Quotation',
  proposal: 'Proposal',
  sales_order: 'Sales order',
  customer_po: 'Customer PO',
  invoice: 'Invoice',
  credit_note: 'Credit note',
};

export const CRM_DOC_TYPE_PLURALS: Record<CrmDocType, string> = {
  quotation: 'Quotations',
  proposal: 'Proposals',
  sales_order: 'Sales orders',
  customer_po: 'Customer POs',
  invoice: 'Invoices',
  credit_note: 'Credit notes',
};

/**
 * Status moves a user may make (mirror of the backend's CrmDocument::USER_TRANSITIONS —
 * the server still decides; this only chooses which buttons to show).
 */
export const CRM_DOC_ACTIONS: Record<CrmDocType, Record<string, string[]>> = {
  proposal: { draft: ['cancelled'], sent: ['accepted', 'rejected', 'cancelled'] },
  quotation: { draft: ['cancelled'], sent: ['accepted', 'rejected', 'cancelled'] },
  customer_po: { received: ['approved', 'cancelled'], approved: ['fulfilled', 'cancelled'] },
  sales_order: { draft: ['cancelled'], confirmed: ['fulfilled', 'cancelled'] },
  invoice: { draft: ['void'], issued: ['void'] },
  credit_note: { draft: ['void'], issued: ['void'] },
};

/** What each type can be turned into (mirror of CrmDocument::CONVERSIONS). */
export const CRM_DOC_CONVERSIONS: Partial<Record<CrmDocType, CrmDocType[]>> = {
  proposal: ['quotation'],
  quotation: ['sales_order', 'invoice'],
  customer_po: ['sales_order', 'invoice'],
  sales_order: ['invoice'],
};

export interface CrmProduct {
  id: number;
  code: string | null;
  name: string;
  description: string | null;
  unit: string;
  unit_price: string;
  tax_rate: string;
  hsn_sac: string | null;
  billing_frequency: 'one_time' | 'monthly' | 'yearly';
  is_active: boolean;
}

export interface CrmDocItem {
  id?: number;
  crm_product_id: number | null;
  description: string;
  hsn_sac: string | null;
  quantity: string;
  unit: string;
  unit_price: string;
  discount_amount: string;
  tax_rate: string;
  taxable_amount?: string;
  cgst_amount?: string;
  sgst_amount?: string;
  igst_amount?: string;
  line_total?: string;
}

export interface CrmDocSummary {
  id: number;
  type: CrmDocType;
  status: string;
  number: string | null;
  title: string | null;
  issue_date: string | null;
  valid_until: string | null;
  due_date: string | null;
  is_overdue: boolean;
  currency: string;
  tax_mode: 'none' | 'intra' | 'inter';
  grand_total: string;
  amount_paid: string;
  amount_credited: string;
  balance_due: string;
  parent_id: number | null;
  crm_deal_id: number | null;
  contact_id: number | null;
  crm_company_id: number | null;
  customer_name: string | null;
  external_reference: string | null;
  editable: boolean;
  created_at: string | null;
}

export interface CrmDelivery {
  id: number;
  channel: 'whatsapp' | 'email' | 'manual';
  purpose: string;
  recipient: string;
  status: 'sent' | 'failed' | 'recorded' | 'queued';
  error: string | null;
  sent_at: string | null;
  created_at: string | null;
  sent_by: { id: number; name: string } | null;
}

export interface CrmDocDetail extends CrmDocSummary {
  place_of_supply: string | null;
  subtotal: string;
  discount_total: string;
  taxable_total: string;
  cgst_total: string;
  sgst_total: string;
  igst_total: string;
  tax_total: string;
  round_off: string;
  body: string | null;
  terms: string | null;
  notes: string | null;
  customer_snapshot: Record<string, unknown> | null;
  seller_snapshot: Record<string, unknown> | null;
  issued_at: string | null;
  cancel_reason: string | null;
  decided_at?: string | null;
  created_by?: { id: number; name: string } | null;
  issued_by?: { id: number; name: string } | null;
  decided_by?: { id: number; name: string } | null;
  attachment?: { name: string } | null;
  gateway_orders?: { id: number; gateway: string; amount: string; status: string; created_at: string | null }[];
  items: CrmDocItem[];
  children: CrmDocSummary[];
  allocations: { id: number; amount: string; reversed: boolean; payment: { id: number; number: string; method: string; paid_on: string | null } }[];
  deliveries: CrmDelivery[];
  share: { view_url: string; pdf_url: string } | null;
}

export const CRM_PAYMENT_METHODS = ['cash', 'upi', 'bank_transfer', 'cheque', 'card', 'other'] as const;
export type CrmPaymentMethod = (typeof CRM_PAYMENT_METHODS)[number] | 'gateway';

export interface CrmPayment {
  id: number;
  number: string;
  amount: string;
  currency: string;
  unallocated: string;
  method: CrmPaymentMethod;
  reference: string | null;
  paid_on: string | null;
  status: 'successful' | 'reversed';
  source: 'manual' | 'gateway';
  gateway: string | null;
  notes: string | null;
  contact: { id: number; name: string | null; phone_number: string } | null;
  company: { id: number; name: string } | null;
  recorded_by: { id: number; name: string } | null;
  reversed_at: string | null;
  reversal_reason: string | null;
  allocations: { id: number; crm_document_id: number; amount: string; reversed: boolean; number: string | null }[];
  created_at: string | null;
}

export interface CrmBusinessProfile {
  legal_name: string | null;
  default_legal_name: string | null;
  gstin: string | null;
  state: string | null;
  state_code: string | null;
  address_line1: string | null;
  address_line2: string | null;
  city: string | null;
  pincode: string | null;
  phone: string | null;
  email: string | null;
  bank_details: string | null;
  upi_id: string | null;
  default_terms: string | null;
  round_off: boolean;
  default_due_days: number;
}

export interface CrmGatewaySettings {
  gateway: 'razorpay' | 'stripe';
  mode: 'test' | 'live';
  key_id: string | null;
  is_enabled: boolean;
  has_key_secret: boolean;
  has_webhook_secret: boolean;
  webhook_url: string | null;
}

export interface CrmReminderSettings {
  enabled: boolean;
  days_before: number[];
  on_due_date: boolean;
  days_after: number[];
  channel: 'whatsapp' | 'email' | 'both';
  auto_send: boolean;
  email_fallback: boolean;
  quiet_start_hour: number | null;
  quiet_end_hour: number | null;
  max_attempts: number;
}

export interface CrmReminder {
  id: number;
  rule_key: string;
  scheduled_for: string | null;
  status: 'pending' | 'awaiting_approval' | 'sent' | 'skipped' | 'failed';
  attempts: number;
  last_error: string | null;
  balance_at_send: string | null;
  sent_at: string | null;
  document: { id: number; number: string | null; balance_due: string; customer_name: string | null; due_date: string | null } | null;
}

export interface CrmSalesReport {
  receivables: {
    outstanding: string;
    overdue: string;
    open_invoices: number;
    aging: Record<'current' | '1_30' | '31_60' | '61_90' | '90_plus', string>;
    top_debtors: { name: string; balance: string }[];
  };
  this_month: { invoiced: string; collected: string; quotations_sent: number; quotations_accepted: number; deals_won: number; won_value: string };
  pipeline: { open_deals: number; open_value: string };
}

/** Customer-facing (public link) view of a document. */
export interface CrmPublicDocument {
  type: CrmDocType;
  number: string;
  status: string;
  issue_date: string | null;
  valid_until: string | null;
  due_date: string | null;
  currency: string;
  seller: { name?: string; gstin?: string | null; address?: string[]; phone?: string | null; email?: string | null; upi_id?: string | null; bank_details?: string | null };
  customer: { name?: string | null; contact_name?: string | null; gstin?: string | null; address?: string[] };
  items: { description: string; quantity: string; unit: string; unit_price: string; tax_rate: string; line_total: string }[];
  taxable_total: string;
  tax_total: string;
  grand_total: string;
  amount_paid: string;
  balance_due: string;
  payable_online: boolean;
  body: string | null;
  terms: string | null;
}

export interface CrmCheckoutOrder {
  gateway: 'razorpay' | 'stripe';
  key_id: string;
  order_id: string;
  client_secret: string | null;
  amount_minor: number;
  amount: string;
  currency: string;
  invoice_number: string;
  seller_name: string | null;
}

/** Human status label. */
export function crmDocStatusLabel(status: string): string {
  return status.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());
}
