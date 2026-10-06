import { useState, type ReactNode } from 'react';
import { isAxiosError } from 'axios';
import billingService from '../../services/billingService';
import moduleAddonService from '../../services/moduleAddonService';
import whatsappService from '../../services/whatsappService';
import { loadRazorpayScript } from '../../utils/loadRazorpayScript';
import type { RazorpaySuccessResponse, VerifyPaymentPayload } from '../../types/billing';
import StripeCardModal from './StripeCardModal';

/** An invoice that can be paid online: the fields the payment needs. */
export interface PayableInvoice {
  id: number;
  account_id: number;
  plan_key: string;
  plan_label: string;
}

interface StripeState {
  invoiceId: number;
  clientSecret: string;
  publishableKey: string;
  planLabel: string;
  amountDisplay: string;
}

function errorText(err: unknown, fallback: string): string {
  if (isAxiosError(err)) {
    const message = (err.response?.data as { message?: string } | undefined)?.message;
    if (message) return message;
  }
  return fallback;
}

/**
 * The online payment of a pending invoice, for any kind (plan, WhatsApp number, module
 * add-on): Razorpay checkout, or the Stripe card form, then verification. Used wherever an
 * invoice can be paid online: the pending payments list and the Add-ons page.
 *
 * Call payOnline(invoice, gateway). Render `stripeCard` once in the page: it is the Stripe
 * card form when Stripe is used, and null otherwise.
 */
export function useOnlineInvoicePayment(options: { onPaid: () => void; onError: (message: string) => void }) {
  const [payingId, setPayingId] = useState<number | null>(null);
  const [stripe, setStripe] = useState<StripeState | null>(null);

  const verify = async (body: VerifyPaymentPayload) => {
    await billingService.verifyPayment(body);
    options.onPaid();
  };

  const payOnline = async (invoice: PayableInvoice, gateway: 'razorpay' | 'stripe') => {
    setPayingId(invoice.id);
    try {
      const order = invoice.plan_key.startsWith('module_addon:')
        ? await moduleAddonService.payInvoice(invoice.id, gateway, invoice.account_id)
        : invoice.plan_key === 'whatsapp_addon'
          ? await whatsappService.payAddonInvoice(invoice.id, gateway, invoice.account_id)
          : await billingService.payPlanInvoice(invoice.id, gateway, invoice.account_id);

      if (gateway === 'razorpay') {
        const loaded = await loadRazorpayScript();
        if (!loaded || !window.Razorpay || !order.key_id) {
          options.onError('Could not load the Razorpay checkout. Please check your connection and try again.');
          return;
        }
        const rzp = new window.Razorpay({
          key: order.key_id,
          amount: order.amount,
          currency: order.currency,
          name: 'WhatsApp SaaS Platform',
          description: invoice.plan_label,
          order_id: order.order_id,
          handler: (response: RazorpaySuccessResponse) => {
            verify({
              invoice_id: order.invoice_id,
              razorpay_order_id: response.razorpay_order_id,
              razorpay_payment_id: response.razorpay_payment_id,
              razorpay_signature: response.razorpay_signature,
            }).catch((err: unknown) => options.onError(errorText(err, 'Payment could not be verified. If money was deducted, it will still be reconciled automatically.')));
          },
          theme: { color: '#4f46e5' },
        });
        rzp.open();
      } else {
        if (!order.client_secret || !order.key_id) {
          options.onError('Stripe did not return the data needed to continue. Please try again.');
          return;
        }
        setStripe({
          invoiceId: order.invoice_id,
          clientSecret: order.client_secret,
          publishableKey: order.key_id,
          planLabel: invoice.plan_label,
          amountDisplay: `${order.currency} ${(order.amount / 100).toFixed(2)}`,
        });
      }
    } catch (err) {
      options.onError(errorText(err, 'Could not start the payment for this invoice. Please try again.'));
    } finally {
      setPayingId(null);
    }
  };

  const stripeCard: ReactNode = stripe ? (
    <StripeCardModal
      publishableKey={stripe.publishableKey}
      clientSecret={stripe.clientSecret}
      planLabel={stripe.planLabel}
      amountDisplay={stripe.amountDisplay}
      onSuccess={(paymentIntentId) => {
        const invoiceId = stripe.invoiceId;
        setStripe(null);
        verify({ invoice_id: invoiceId, payment_intent_id: paymentIntentId }).catch((err: unknown) =>
          options.onError(errorText(err, 'Payment could not be verified. If money was deducted, it will still be reconciled automatically.')),
        );
      }}
      onClose={() => setStripe(null)}
    />
  ) : null;

  return { payOnline, payingId, stripeCard };
}

/** The gateway to offer: Razorpay first when both are set up, else whichever is ready; null when neither. */
export function pickOnlineGateway(gateways: string[]): 'razorpay' | 'stripe' | null {
  if (gateways.includes('razorpay')) return 'razorpay';
  if (gateways.includes('stripe')) return 'stripe';
  return null;
}
