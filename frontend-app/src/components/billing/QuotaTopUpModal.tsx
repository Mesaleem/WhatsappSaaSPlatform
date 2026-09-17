import { useState } from 'react';
import { AxiosError } from 'axios';
import { Loader2, XCircle, Zap } from 'lucide-react';
import { useAuth } from '../../core/context/AuthContext';
import quotaRequestService from '../../services/quotaRequestService';
import type { ApiErrorResponse } from '../../types/auth';

function extractMessage(err: unknown, fallback: string): string {
  const axiosErr = err as AxiosError<ApiErrorResponse>;
  return axiosErr.response?.data?.message ?? fallback;
}

/**
 * Quota Exhaustion Request Workflow — "Request Extra Quota" / "Add
 * Funds" modal. Same fixed-overlay modal shell as StripeCardModal (this
 * codebase's one existing full-screen modal pattern). Submits POST
 * /quota-requests/store and hands the created QuotaRequest back to the
 * caller on success — callers (DashboardPage / BillingPage) decide what
 * to show next (a local success banner/toast), this component only owns
 * the form itself.
 *
 * Per-Message Wallet Top-Up Requests: `billingModel` switches the form
 * between the original flat_quota shape (a whole-number message count,
 * charged at the placeholder RATE_PER_EXTRA_MESSAGE server-side) and a
 * per_message shape (a rupee amount, charged verbatim — see
 * QuotaRequestController::approveWalletTopUp()). The two are mutually
 * exclusive request payloads, matching the backend's own validation.
 */
export default function QuotaTopUpModal({
  billingModel,
  onClose,
  onSubmitted,
}: {
  billingModel: 'flat_quota' | 'per_message';
  onClose: () => void;
  onSubmitted: (message: string) => void;
}) {
  // Agent-Routed Quota Top-Up Requests — mirrors the backend's own
  // routing (QuotaRequestController::store()): an Admin under an Agent
  // (account.agent_id set) has their request reviewed by that Agent, not
  // the Super Admin; a directly-onboarded Admin's still goes to Super
  // Admin, since no Agent can ever see or approve it.
  const { user } = useAuth();
  const reviewerLabel = user?.account?.agent_id != null ? 'Your Agent' : 'Your Super Admin';
  const isWalletTopUp = billingModel === 'per_message';

  const [requestedExtraMessages, setRequestedExtraMessages] = useState('');
  const [requestedTopUpAmount, setRequestedTopUpAmount] = useState('');
  const [reason, setReason] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const parsedCount = Number(requestedExtraMessages);
  const parsedAmount = Number(requestedTopUpAmount);
  const isValid = isWalletTopUp
    ? requestedTopUpAmount.trim() !== '' && Number.isFinite(parsedAmount) && parsedAmount >= 1 && parsedAmount <= 1_000_000
    : requestedExtraMessages.trim() !== '' && Number.isInteger(parsedCount) && parsedCount >= 1 && parsedCount <= 1_000_000;

  const handleSubmit = async () => {
    if (!isValid) {
      setError(
        isWalletTopUp
          ? 'Enter an amount between ₹1 and ₹1,000,000.'
          : 'Enter a whole number of extra messages between 1 and 1,000,000.',
      );
      return;
    }
    setIsSubmitting(true);
    setError(null);
    try {
      const result = await quotaRequestService.store(
        isWalletTopUp
          ? { requested_topup_amount: parsedAmount, reason: reason.trim() || undefined }
          : { requested_extra_messages: parsedCount, reason: reason.trim() || undefined },
      );
      onSubmitted(result.message);
    } catch (err) {
      setError(extractMessage(err, 'Could not submit this request. Please try again.'));
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
        <div className="flex items-center justify-between">
          <h3 className="flex items-center gap-2 text-base font-semibold text-slate-900">
            <Zap className="h-4 w-4 text-indigo-600" />
            {isWalletTopUp ? 'Add Funds' : 'Request Extra Quota'}
          </h3>
          <button onClick={onClose} className="text-slate-400 hover:text-slate-600" disabled={isSubmitting}>
            <XCircle className="h-5 w-5" />
          </button>
        </div>
        <p className="mt-1 text-sm text-slate-500">
          {reviewerLabel} will review this request and, once approved,{' '}
          {isWalletTopUp ? 'credit your balance and generate an invoice for the amount.' : 'generate an invoice for the extra messages.'}
        </p>

        <div className="mt-4 space-y-4">
          {isWalletTopUp ? (
            <div>
              <label htmlFor="requested-topup-amount" className="text-sm font-medium text-slate-700">
                Amount to Add (₹)
              </label>
              <input
                id="requested-topup-amount"
                type="number"
                min={1}
                max={1_000_000}
                step={0.01}
                value={requestedTopUpAmount}
                onChange={(e) => setRequestedTopUpAmount(e.target.value)}
                placeholder="e.g. 100"
                className="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
              />
            </div>
          ) : (
            <div>
              <label htmlFor="requested-extra-messages" className="text-sm font-medium text-slate-700">
                Requested Extra Messages
              </label>
              <input
                id="requested-extra-messages"
                type="number"
                min={1}
                max={1_000_000}
                step={1}
                value={requestedExtraMessages}
                onChange={(e) => setRequestedExtraMessages(e.target.value)}
                placeholder="e.g. 5000"
                className="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
              />
            </div>
          )}
          <div>
            <label htmlFor="quota-reason" className="text-sm font-medium text-slate-700">
              Reason / Notes <span className="font-normal text-slate-400">(optional)</span>
            </label>
            <textarea
              id="quota-reason"
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              rows={3}
              maxLength={2000}
              placeholder={isWalletTopUp ? 'Why do you need more balance right now?' : 'Why do you need extra quota right now?'}
              className="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
            />
          </div>
        </div>

        {error && (
          <div className="mt-4 flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
            <XCircle className="h-4 w-4 flex-shrink-0" />
            {error}
          </div>
        )}

        <div className="mt-5 flex justify-end gap-2">
          <button
            onClick={onClose}
            disabled={isSubmitting}
            className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60"
          >
            Cancel
          </button>
          <button
            onClick={() => void handleSubmit()}
            disabled={isSubmitting || !isValid}
            className="flex items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60"
          >
            {isSubmitting ? <Loader2 className="h-4 w-4 animate-spin" /> : <Zap className="h-4 w-4" />}
            Submit
          </button>
        </div>
      </div>
    </div>
  );
}
