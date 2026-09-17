import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { AxiosError } from 'axios';
import {
  AlertTriangle,
  ArrowLeft,
  CheckCircle2,
  CreditCard,
  Download,
  Loader2,
  Package,
  ShieldCheck,
  XCircle,
  Zap,
} from 'lucide-react';
import { useAuth } from '../../core/context/AuthContext';
import billingService from '../../services/billingService';
import StripeCardModal from '../../components/billing/StripeCardModal';
import QuotaTopUpModal from '../../components/billing/QuotaTopUpModal';
import ClientBillingSummaryTable from '../../components/billing/ClientBillingSummaryTable';
import { PageHeader, PageShell } from '../../components/common/PageShell';
import { TableSkeletonRows } from '../../components/common/Skeleton';
import { loadRazorpayScript } from '../../utils/loadRazorpayScript';
import type { ApiErrorResponse } from '../../types/auth';
import type { PaginatedResponse } from '../../types/account';
import type { BillingModel, Subscription } from '../../types/subscription';
import type {
  CreateOrderResponse,
  Invoice,
  Plan,
  PlansResponse,
  PaymentGateway,
  RazorpaySuccessResponse,
} from '../../types/billing';

const badgeClass: Record<string, string> = {
  active: 'bg-emerald-50 text-emerald-700 border-emerald-200',
  expired: 'bg-red-50 text-red-700 border-red-200',
  exhausted: 'bg-amber-50 text-amber-700 border-amber-200',
  paid: 'bg-emerald-50 text-emerald-700 border-emerald-200',
  pending: 'bg-amber-50 text-amber-700 border-amber-200',
  failed: 'bg-red-50 text-red-700 border-red-200',
};

function StatusBadge({ status }: { status: string }) {
  const cls = badgeClass[status] ?? 'bg-slate-50 text-slate-700 border-slate-200';
  return (
    <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium capitalize ${cls}`}>
      {status}
    </span>
  );
}

function formatMoney(amount: string, currency = 'INR'): string {
  const symbol = currency === 'INR' ? 'Rs.' : currency;
  return `${symbol} ${Number(amount).toFixed(2)}`;
}

function formatDate(value: string | null): string {
  if (!value) return '—';
  return new Date(value).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

function extractMessage(err: unknown, fallback: string): string {
  if (err instanceof Error && err.message) return err.message;
  const axiosErr = err as AxiosError<ApiErrorResponse>;
  return axiosErr.response?.data?.message ?? fallback;
}

interface StripeModalState {
  invoiceId: number;
  clientSecret: string;
  publishableKey: string;
  planLabel: string;
  amountDisplay: string;
}

/**
 * Module 8 — self-service Billing & Upgrade page. Tenant Admin only
 * (route-gated permission="manage-subscriptions"). Orchestrates both
 * gateway client SDKs from one place: Razorpay Checkout.js is imperative
 * (loaded once, then `new window.Razorpay(...).open()`), while Stripe
 * needs a mounted <Elements> tree — that half is delegated to
 * StripeCardModal.
 */
export default function BillingPage() {
  const { user, isSuperAdmin } = useAuth();
  const account = user?.account ?? null;
  // Wallet Visibility for Agents, disclosed: an Agent (Reseller) keeps its
  // own self-service checkout/invoice UI below exactly as before — this
  // only ADDS the same Client Billing Summary table Super Admin sees,
  // scoped server-side to the Agent's own Sub-Client tree (see
  // BillingController::clientSummary()'s agent_scope_id branch). Never
  // true for Super Admin (isSuperAdmin() already returns above it), so
  // this can't double-render the table for that role.
  const isAgent = !isSuperAdmin() && account?.account_type === 'agent';

  const [subscription, setSubscription] = useState<Subscription | null>(account?.current_subscription ?? null);
  useEffect(() => {
    setSubscription(account?.current_subscription ?? null);
  }, [account?.current_subscription]);

  const [plansData, setPlansData] = useState<PlansResponse | null>(null);
  const [isLoadingPlans, setIsLoadingPlans] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [selectedGateway, setSelectedGateway] = useState<PaymentGateway | null>(null);

  const [invoices, setInvoices] = useState<PaginatedResponse<Invoice> | null>(null);
  const [invoicePage, setInvoicePage] = useState(1);
  const [isLoadingInvoices, setIsLoadingInvoices] = useState(true);
  const [downloadingId, setDownloadingId] = useState<number | null>(null);

  const [checkoutPlanKey, setCheckoutPlanKey] = useState<string | null>(null);
  const [checkoutError, setCheckoutError] = useState<string | null>(null);
  const [checkoutSuccess, setCheckoutSuccess] = useState<string | null>(null);
  const [stripeModal, setStripeModal] = useState<StripeModalState | null>(null);
  const [isQuotaModalOpen, setIsQuotaModalOpen] = useState(false);

  const loadPlans = useCallback(async () => {
    setIsLoadingPlans(true);
    setLoadError(null);
    try {
      const data = await billingService.getPlans();
      setPlansData(data);
      setSelectedGateway((prev) => prev ?? data.available_gateways[0] ?? null);
    } catch (err) {
      setLoadError(extractMessage(err, 'Failed to load plans.'));
    } finally {
      setIsLoadingPlans(false);
    }
  }, []);

  const loadInvoices = useCallback(async (page: number) => {
    setIsLoadingInvoices(true);
    try {
      const data = await billingService.getInvoices(page);
      setInvoices(data);
    } catch {
      // Non-fatal — the plan/checkout section above is the primary content;
      // an empty invoice table with no error banner is an acceptable
      // degraded state here rather than blocking the whole page.
    } finally {
      setIsLoadingInvoices(false);
    }
  }, []);

  useEffect(() => {
    if (!account) {
      setIsLoadingPlans(false);
      setIsLoadingInvoices(false);
      return;
    }
    void loadPlans();
    void loadInvoices(invoicePage);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [account?.id]);

  useEffect(() => {
    if (account) void loadInvoices(invoicePage);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [invoicePage]);

  const applyPaymentResult = (invoiceUpdate: Invoice, subscriptionUpdate: unknown) => {
    setCheckoutSuccess(`Payment confirmed for invoice ${invoiceUpdate.invoice_number} — your plan has been updated.`);
    if (subscriptionUpdate && typeof subscriptionUpdate === 'object') {
      setSubscription(subscriptionUpdate as Subscription);
    }
    void loadInvoices(1);
    setInvoicePage(1);
  };

  const handleRazorpaySuccess = async (invoiceId: number, response: RazorpaySuccessResponse) => {
    try {
      const result = await billingService.verifyPayment({
        invoice_id: invoiceId,
        razorpay_order_id: response.razorpay_order_id,
        razorpay_payment_id: response.razorpay_payment_id,
        razorpay_signature: response.razorpay_signature,
      });
      applyPaymentResult(result.invoice, result.subscription);
    } catch (err) {
      setCheckoutError(extractMessage(err, 'Payment could not be verified. If money was deducted, it will still be reconciled automatically.'));
    }
  };

  const handleStripeSuccess = async (paymentIntentId: string) => {
    if (!stripeModal) return;
    try {
      const result = await billingService.verifyPayment({
        invoice_id: stripeModal.invoiceId,
        payment_intent_id: paymentIntentId,
      });
      setStripeModal(null);
      applyPaymentResult(result.invoice, result.subscription);
    } catch (err) {
      setCheckoutError(extractMessage(err, 'Payment could not be verified. If money was deducted, it will still be reconciled automatically.'));
      setStripeModal(null);
    }
  };

  const handleUpgrade = async (plan: Plan) => {
    if (!selectedGateway) return;
    setCheckoutError(null);
    setCheckoutSuccess(null);
    setCheckoutPlanKey(plan.key);

    try {
      const order: CreateOrderResponse = await billingService.createOrder({
        plan_key: plan.key,
        gateway: selectedGateway,
      });

      if (selectedGateway === 'razorpay') {
        const loaded = await loadRazorpayScript();
        if (!loaded || !window.Razorpay || !order.key_id) {
          setCheckoutError('Could not load the Razorpay checkout. Please check your connection and try again.');
          return;
        }
        const rzp = new window.Razorpay({
          key: order.key_id,
          amount: order.amount,
          currency: order.currency,
          name: 'WhatsApp SaaS Platform',
          description: `${order.plan.label} plan`,
          order_id: order.order_id,
          handler: (response) => {
            void handleRazorpaySuccess(order.invoice_id, response);
          },
          modal: { ondismiss: () => setCheckoutPlanKey(null) },
          theme: { color: '#4f46e5' },
        });
        rzp.open();
      } else {
        if (!order.client_secret || !order.key_id) {
          setCheckoutError('Stripe did not return the data needed to continue. Please try again.');
          return;
        }
        setStripeModal({
          invoiceId: order.invoice_id,
          clientSecret: order.client_secret,
          publishableKey: order.key_id,
          planLabel: order.plan.label,
          amountDisplay: `${order.currency} ${(order.amount / 100).toFixed(2)}`,
        });
      }
    } catch (err) {
      setCheckoutError(extractMessage(err, 'Could not start checkout for this plan. Please try again.'));
    } finally {
      setCheckoutPlanKey(null);
    }
  };

  const handleDownload = async (invoice: Invoice) => {
    setDownloadingId(invoice.id);
    try {
      await billingService.downloadInvoicePdf(invoice.id, invoice.invoice_number);
    } catch (err) {
      setCheckoutError(extractMessage(err, 'Could not download this invoice.'));
    } finally {
      setDownloadingId(null);
    }
  };

  // Billing & Plans Module Overhaul — Super Admin Billing Overview.
  // Super Admin has no account of their own (user.account is always
  // null for that role), so this branch replaces the self-service
  // checkout/invoice UI below with the platform-wide Client Billing
  // Summary table instead — Super Admin manages a specific client's
  // subscription via Manage Clients, not via this self-service page.
  if (isSuperAdmin()) {
    return (
      <PageShell maxWidthClassName="max-w-full">
        <PageHeader title="Billing & Plans" subtitle="Platform-wide billing overview across every client." />
        <ClientBillingSummaryTable />
      </PageShell>
    );
  }

  if (!account) {
    return (
      <div className="flex h-full items-center justify-center p-6">
        <div className="max-w-md rounded-xl border border-slate-200 bg-white p-6 text-center shadow-sm">
          <AlertTriangle className="mx-auto h-8 w-8 text-amber-500" />
          <p className="mt-3 text-sm font-medium text-slate-900">No tenant account to bill</p>
          <p className="mt-1 text-sm text-slate-500">
            Your user is not linked to any account. Contact your Super Admin.
          </p>
          <Link to="/" className="mt-4 inline-block text-sm font-medium text-indigo-600 hover:text-indigo-700">
            Back to dashboard
          </Link>
        </div>
      </div>
    );
  }

  const remaining =
    subscription?.total_allocated_messages != null
      ? Math.max(subscription.total_allocated_messages - subscription.used_messages, 0)
      : null;
  const quotaPercent =
    subscription?.total_allocated_messages != null && subscription.total_allocated_messages > 0
      ? Math.min(Math.round((subscription.used_messages / subscription.total_allocated_messages) * 100), 100)
      : null;
  // Per Message Wallet Breakdown, disclosed: rate_per_message is only
  // ever non-null for billing_model 'per_message' (Subscription's own
  // field contract), so isPerMessage alone is enough to gate every
  // derived value below without a redundant null check at each call site.
  const isPerMessage: boolean = subscription?.billing_model === ('per_message' satisfies BillingModel);
  const rate = isPerMessage && subscription?.rate_per_message != null ? Number(subscription.rate_per_message) : null;
  const amountSpent = rate !== null ? subscription!.used_messages * rate : null;
  const amountRemaining = rate !== null && remaining !== null ? remaining * rate : null;

  return (
    <div className="p-6">
      <div className="w-full space-y-6">
        <div>
          <Link to="/" className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700">
            <ArrowLeft className="h-4 w-4" />
            Back to dashboard
          </Link>
          <h1 className="mt-2 text-xl font-semibold text-slate-900">Billing & Plans</h1>
          <p className="mt-1 text-sm text-slate-500">Manage your subscription, upgrade plans, and view invoices.</p>
        </div>

        {/* Wallet Visibility for Agents, disclosed: this Agent's OWN plan
            (Current Plan Status + checkout below) is unchanged — this
            section is purely additive, showing the same wallet breakdown
            Super Admin sees, narrowed server-side to this Agent's own
            Sub-Clients. */}
        {isAgent && (
          <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 className="text-sm font-semibold text-slate-900">My Clients&rsquo; Billing</h2>
            <p className="mt-1 text-sm text-slate-500">Plan, usage and wallet balance for every client assigned to you.</p>
            <div className="mt-4">
              <ClientBillingSummaryTable />
            </div>
          </div>
        )}

        {loadError && (
          <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <XCircle className="h-4 w-4 flex-shrink-0" />
            {loadError}
          </div>
        )}

        {/* Current plan status */}
        <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
          <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-900">
            <Package className="h-4 w-4 text-indigo-600" />
            Current Plan Status
          </h2>
          {subscription ? (
            <div className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
              <div>
                <p className="text-xs text-slate-500">Status</p>
                <div className="mt-1">
                  <StatusBadge status={subscription.status} />
                </div>
              </div>
              <div>
                <p className="text-xs text-slate-500">Engine</p>
                <p className="mt-1 text-sm font-medium text-slate-900 uppercase">{subscription.engine_type}</p>
              </div>
              <div>
                <p className="text-xs text-slate-500">Remaining credits</p>
                <p className="mt-1 text-sm font-medium text-slate-900">
                  {remaining !== null ? remaining.toLocaleString() : 'Unlimited'}
                </p>
              </div>
              <div>
                <p className="text-xs text-slate-500">Expires</p>
                <p className="mt-1 text-sm font-medium text-slate-900">{formatDate(subscription.expires_at)}</p>
              </div>
              {quotaPercent !== null && (
                <div className="col-span-2 sm:col-span-4">
                  <div className="flex items-center justify-between text-xs text-slate-500">
                    <span>
                      {subscription.used_messages.toLocaleString()} / {subscription.total_allocated_messages?.toLocaleString()} messages used
                    </span>
                    <span>{quotaPercent}%</span>
                  </div>
                  <div className="mt-1 h-2 w-full overflow-hidden rounded-full bg-slate-100">
                    <div
                      className={`h-2 rounded-full ${quotaPercent >= 90 ? 'bg-red-500' : quotaPercent >= 70 ? 'bg-amber-500' : 'bg-indigo-600'}`}
                      style={{ width: `${quotaPercent}%` }}
                    />
                  </div>
                  {isPerMessage && rate !== null && (
                    <div className="mt-2 grid grid-cols-1 gap-1 text-xs text-slate-500 sm:grid-cols-3">
                      <div>Used: {subscription.used_messages.toLocaleString()} msgs (₹{amountSpent!.toFixed(2)})</div>
                      {remaining !== null && (
                        <div>Remaining: {remaining.toLocaleString()} msgs (₹{amountRemaining!.toFixed(2)})</div>
                      )}
                      <div>Rate: ₹{rate.toFixed(2)}/msg</div>
                    </div>
                  )}
                </div>
              )}
              {/* Conditional Quota Top-Up Button — server-mirrored gate,
                  same as SubscriptionHealthCard on the dashboard: only a
                  flat_quota plan at 90%+ usage can request a top-up. */}
              {subscription.billing_model === 'flat_quota' && quotaPercent !== null && quotaPercent >= 90 && (
                <div className="col-span-2 sm:col-span-4">
                  <button
                    onClick={() => setIsQuotaModalOpen(true)}
                    className="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-indigo-700"
                  >
                    <Zap className="h-3.5 w-3.5" />
                    Request Extra Quota
                  </button>
                </div>
              )}
            </div>
          ) : (
            <p className="mt-3 text-sm text-slate-500">No active subscription yet — choose a plan below to get started.</p>
          )}
        </div>

        {checkoutSuccess && (
          <div className="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
            <CheckCircle2 className="h-4 w-4 flex-shrink-0" />
            {checkoutSuccess}
          </div>
        )}
        {checkoutError && (
          <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <XCircle className="h-4 w-4 flex-shrink-0" />
            {checkoutError}
          </div>
        )}

        {/* Plans */}
        <div>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <h2 className="text-sm font-semibold text-slate-900">Plans</h2>
            {plansData && plansData.available_gateways.length > 1 && (
              <div className="flex items-center gap-2 text-sm">
                <span className="text-slate-500">Pay with:</span>
                {plansData.available_gateways.map((gw) => (
                  <button
                    key={gw}
                    onClick={() => setSelectedGateway(gw)}
                    className={`rounded-full border px-3 py-1 text-xs font-medium capitalize ${
                      selectedGateway === gw
                        ? 'border-indigo-600 bg-indigo-50 text-indigo-700'
                        : 'border-slate-300 text-slate-600 hover:bg-slate-50'
                    }`}
                  >
                    {gw}
                  </button>
                ))}
              </div>
            )}
          </div>

          {plansData && plansData.available_gateways.length === 0 && (
            <div className="mt-3 flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
              <AlertTriangle className="h-4 w-4 flex-shrink-0" />
              No payment gateway is configured yet. Ask your Super Admin to enable Razorpay or Stripe in Payment
              Gateway Settings before you can upgrade.
            </div>
          )}

          {isLoadingPlans ? (
            <div className="mt-4 flex items-center gap-2 text-sm text-slate-500">
              <Loader2 className="h-4 w-4 animate-spin" />
              Loading plans…
            </div>
          ) : (
            <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
              {plansData?.plans.map((plan) => (
                <div key={plan.key} className="flex flex-col rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                  <div className="flex items-center gap-2">
                    <Zap className="h-4 w-4 text-indigo-600" />
                    <h3 className="text-base font-semibold text-slate-900">{plan.label}</h3>
                  </div>
                  <p className="mt-1 text-sm text-slate-500">{plan.description}</p>
                  <p className="mt-4 text-2xl font-bold text-slate-900">
                    {formatMoney(plan.price)}
                    <span className="text-sm font-normal text-slate-500"> / {plan.duration_days} days</span>
                  </p>
                  <p className="text-xs text-slate-500">+ tax: {formatMoney(plan.tax_amount)} · total {formatMoney(plan.total_amount)}</p>
                  <ul className="mt-4 space-y-1 text-sm text-slate-600">
                    <li>{plan.total_allocated_messages?.toLocaleString() ?? 'Unlimited'} messages</li>
                    <li className="uppercase">{plan.engine_type} engine</li>
                  </ul>
                  <button
                    onClick={() => void handleUpgrade(plan)}
                    disabled={!selectedGateway || checkoutPlanKey === plan.key}
                    className="mt-5 flex items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60"
                  >
                    {checkoutPlanKey === plan.key ? (
                      <Loader2 className="h-4 w-4 animate-spin" />
                    ) : (
                      <CreditCard className="h-4 w-4" />
                    )}
                    Upgrade Plan
                  </button>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Invoice history */}
        <div className="rounded-xl border border-slate-200 bg-white shadow-sm">
          <div className="border-b border-slate-200 px-6 py-4">
            <h2 className="text-sm font-semibold text-slate-900">Invoice History</h2>
          </div>
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-slate-200 text-sm">
              <thead className="bg-slate-50 text-left text-xs font-medium uppercase text-slate-500">
                <tr>
                  <th className="px-6 py-3">Invoice #</th>
                  <th className="px-6 py-3">Plan</th>
                  <th className="px-6 py-3">Total</th>
                  <th className="px-6 py-3">Gateway</th>
                  <th className="px-6 py-3">Status</th>
                  <th className="px-6 py-3">Paid At</th>
                  <th className="px-6 py-3 text-right">Action</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {isLoadingInvoices ? (
                  <TableSkeletonRows columns={7} />
                ) : invoices && invoices.data.length > 0 ? (
                  invoices.data.map((invoice) => (
                    <tr key={invoice.id}>
                      <td className="px-6 py-3 font-mono text-xs text-slate-700">{invoice.invoice_number}</td>
                      <td className="px-6 py-3 text-slate-700">{invoice.plan_label}</td>
                      <td className="px-6 py-3 text-slate-700">{formatMoney(invoice.total_amount, invoice.currency)}</td>
                      <td className="px-6 py-3 capitalize text-slate-700">{invoice.payment_gateway}</td>
                      <td className="px-6 py-3">
                        <StatusBadge status={invoice.status} />
                      </td>
                      <td className="px-6 py-3 text-slate-700">{formatDate(invoice.paid_at)}</td>
                      <td className="px-6 py-3 text-right">
                        <button
                          onClick={() => void handleDownload(invoice)}
                          disabled={downloadingId === invoice.id}
                          title="Downloads the invoice PDF. Client Billing & Invoice Notification: no separate gateway checkout exists yet for a Super-Admin-generated top-up invoice — see the Quota Top-Up audit report."
                          className="inline-flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-700 disabled:opacity-60"
                        >
                          {downloadingId === invoice.id ? (
                            <Loader2 className="h-3 w-3 animate-spin" />
                          ) : (
                            <Download className="h-3 w-3" />
                          )}
                          {invoice.status === 'paid' ? 'View Bill' : 'Pay Invoice'}
                        </button>
                      </td>
                    </tr>
                  ))
                ) : (
                  <tr>
                    <td colSpan={7} className="px-6 py-6 text-center text-slate-400">
                      No invoices yet.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
          {invoices && invoices.last_page > 1 && (
            <div className="flex items-center justify-between border-t border-slate-200 px-6 py-3 text-sm text-slate-600">
              <span>
                Page {invoices.current_page} of {invoices.last_page}
              </span>
              <div className="flex gap-2">
                <button
                  onClick={() => setInvoicePage((p) => Math.max(1, p - 1))}
                  disabled={invoices.current_page <= 1}
                  className="rounded-lg border border-slate-300 px-3 py-1 disabled:opacity-50"
                >
                  Prev
                </button>
                <button
                  onClick={() => setInvoicePage((p) => Math.min(invoices.last_page, p + 1))}
                  disabled={invoices.current_page >= invoices.last_page}
                  className="rounded-lg border border-slate-300 px-3 py-1 disabled:opacity-50"
                >
                  Next
                </button>
              </div>
            </div>
          )}
        </div>

        <p className="flex items-center gap-1.5 text-xs text-slate-400">
          <ShieldCheck className="h-3.5 w-3.5" />
          Payments are processed directly by Razorpay/Stripe — card details never touch our servers.
        </p>
      </div>

      {stripeModal && (
        <StripeCardModal
          publishableKey={stripeModal.publishableKey}
          clientSecret={stripeModal.clientSecret}
          planLabel={stripeModal.planLabel}
          amountDisplay={stripeModal.amountDisplay}
          onSuccess={(paymentIntentId) => void handleStripeSuccess(paymentIntentId)}
          onClose={() => setStripeModal(null)}
        />
      )}

      {isQuotaModalOpen && (
        <QuotaTopUpModal
          onClose={() => setIsQuotaModalOpen(false)}
          onSubmitted={(message) => {
            setIsQuotaModalOpen(false);
            setCheckoutSuccess(message);
          }}
        />
      )}
    </div>
  );
}
