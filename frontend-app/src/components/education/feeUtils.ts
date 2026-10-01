import type { FeeFrequency, PaymentMethod, StudentFeeStatus } from '../../types/education';

export const FEE_STATUS_LABELS: Record<StudentFeeStatus, string> = {
  open: 'Open',
  partially_paid: 'Partially paid',
  paid: 'Paid',
  overdue: 'Overdue',
  cancelled: 'Cancelled',
};

export const FEE_STATUS_STYLES: Record<StudentFeeStatus, string> = {
  open: 'border-slate-200 bg-slate-50 text-slate-700',
  partially_paid: 'border-amber-200 bg-amber-50 text-amber-800',
  paid: 'border-emerald-200 bg-emerald-50 text-emerald-800',
  overdue: 'border-red-200 bg-red-50 text-red-700',
  cancelled: 'border-slate-200 bg-slate-100 text-slate-500 line-through',
};

export const FREQUENCY_LABELS: Record<FeeFrequency, string> = {
  one_time: 'One time',
  monthly: 'Monthly',
  quarterly: 'Quarterly',
  half_yearly: 'Half-yearly',
  yearly: 'Yearly',
};

export const METHOD_LABELS: Record<PaymentMethod, string> = {
  cash: 'Cash',
  upi: 'UPI',
  card: 'Card',
  bank_transfer: 'Bank transfer',
  cheque: 'Cheque',
  other: 'Other',
};

const MONEY = /^\d{1,10}(\.\d{1,2})?$/;

/**
 * Local sanity check only (the server is authoritative): a positive amount with at most 2 decimals.
 * Done on the string — money is never turned into a float on the client.
 */
export function validateAmount(raw: string): string | null {
  const value = raw.trim();
  if (value === '') return 'Enter an amount.';
  if (!MONEY.test(value)) return 'Enter an amount with at most 2 decimal places.';
  if (/^0+(\.0{1,2})?$/.test(value)) return 'The amount must be greater than zero.';
  return null;
}

/** A fresh key per payment form: a retried submit of THIS form is deduplicated by the server. */
export function newIdempotencyKey(): string {
  const random = typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`;
  return `pay-${random}`;
}
