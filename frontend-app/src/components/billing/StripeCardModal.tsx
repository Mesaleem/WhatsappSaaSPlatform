import { useMemo, useState } from 'react';
import { loadStripe } from '@stripe/stripe-js';
import {
  CardElement,
  Elements,
  useElements,
  useStripe,
} from '@stripe/react-stripe-js';
import { Loader2, ShieldCheck, XCircle } from 'lucide-react';

interface StripeCardModalProps {
  /** Publishable key (CreateOrderResponse.key_id) — safe for the browser. */
  publishableKey: string;
  /** PaymentIntent client_secret from POST /billing/create-order. */
  clientSecret: string;
  planLabel: string;
  /** Smallest-unit amount + currency, purely for display — Stripe already knows the real amount server-side. */
  amountDisplay: string;
  onSuccess: (paymentIntentId: string) => void;
  onClose: () => void;
}

const cardElementOptions = {
  style: {
    base: {
      fontSize: '14px',
      color: '#0f172a',
      '::placeholder': { color: '#94a3b8' },
    },
    invalid: { color: '#dc2626' },
  },
};

/**
 * Inner form — must render INSIDE <Elements> to use useStripe()/useElements()
 * (both throw/return null otherwise, which is why StripeCardModal below
 * only mounts this once the Elements provider is ready).
 */
function CardForm({ planLabel, amountDisplay, onSuccess, onClose, clientSecret }: Omit<StripeCardModalProps, 'publishableKey'>) {
  const stripe = useStripe();
  const elements = useElements();
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleSubmit = async () => {
    if (!stripe || !elements) return;
    const card = elements.getElement(CardElement);
    if (!card) return;

    setIsSubmitting(true);
    setError(null);

    const { error: confirmError, paymentIntent } = await stripe.confirmCardPayment(clientSecret, {
      payment_method: { card },
    });

    if (confirmError) {
      setError(confirmError.message ?? 'Your card was declined. Please try a different card.');
      setIsSubmitting(false);
      return;
    }

    if (paymentIntent?.status === 'succeeded') {
      onSuccess(paymentIntent.id);
      return;
    }

    // requires_action / processing / etc — Stripe's own modal (3DS) already
    // handled the redirect-and-back loop by the time confirmCardPayment
    // resolves in this flow; any other status here means it genuinely
    // didn't complete.
    setError('Payment was not completed. Please try again.');
    setIsSubmitting(false);
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
        <div className="flex items-center justify-between">
          <h3 className="text-base font-semibold text-slate-900">Pay with card</h3>
          <button onClick={onClose} className="text-slate-400 hover:text-slate-600" disabled={isSubmitting}>
            <XCircle className="h-5 w-5" />
          </button>
        </div>
        <p className="mt-1 text-sm text-slate-500">
          {planLabel} — {amountDisplay}
        </p>

        <div className="mt-4 rounded-lg border border-slate-300 px-3 py-3 shadow-sm">
          <CardElement options={cardElementOptions} />
        </div>

        {error && (
          <div className="mt-3 flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
            <XCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
            {error}
          </div>
        )}

        <div className="mt-5 flex items-center justify-end gap-3">
          <button
            type="button"
            onClick={onClose}
            disabled={isSubmitting}
            className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60"
          >
            Cancel
          </button>
          <button
            type="button"
            onClick={() => void handleSubmit()}
            disabled={!stripe || isSubmitting}
            className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60"
          >
            {isSubmitting ? <Loader2 className="h-4 w-4 animate-spin" /> : <ShieldCheck className="h-4 w-4" />}
            Pay {amountDisplay}
          </button>
        </div>
      </div>
    </div>
  );
}

/**
 * Module 8 — Stripe Elements checkout modal. Each mount creates a fresh
 * Stripe object scoped to the order's own publishable key (loadStripe is
 * memoized per-key by the SDK internally, but this component re-derives
 * it whenever the key/clientSecret pair changes, i.e. a new order).
 */
export default function StripeCardModal(props: StripeCardModalProps) {
  const stripePromise = useMemo(() => loadStripe(props.publishableKey), [props.publishableKey]);

  return (
    <Elements stripe={stripePromise} options={{ clientSecret: props.clientSecret }}>
      <CardForm
        planLabel={props.planLabel}
        amountDisplay={props.amountDisplay}
        clientSecret={props.clientSecret}
        onSuccess={props.onSuccess}
        onClose={props.onClose}
      />
    </Elements>
  );
}
