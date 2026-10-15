import { useState } from 'react';
import { ChevronDown, ChevronUp, ExternalLink, Wallet } from 'lucide-react';

/** Meta billing for one ad account when its number is known ("act_123" or "123"), else the billing hub. */
function metaBillingUrl(adAccountId?: string | null): string {
  const number = adAccountId ? adAccountId.replace(/^act_/, '') : '';
  return /^[0-9]+$/.test(number)
    ? `https://business.facebook.com/billing_hub/payment_settings/?asset_id=${number}`
    : 'https://business.facebook.com/billing_hub/';
}

const STEPS: { title: string; detail: string }[] = [
  { title: 'Open Meta billing', detail: 'Use the button below and log in with the same Facebook account you connected to WapHub.' },
  { title: 'Pick the right ad account', detail: 'At the top left, choose the ad account WapHub uses (the name shown here, e.g. “Shana Trends Ads”).' },
  { title: 'Add a payment method', detail: 'Payment settings → Add payment method. In India you can use UPI, a debit / credit card (RuPay, Visa, Mastercard) or net banking.' },
  { title: 'Add money (prepaid accounts)', detail: 'If you see “Add funds”, click it, enter the amount in ₹ (for example ₹1,000) and pay by UPI or card. It shows as available funds within a few minutes.' },
  { title: 'Fill business details if asked', detail: 'Meta may ask for your business name and address (GSTIN is optional) before taking the first payment.' },
  { title: 'Come back to WapHub', detail: 'Launch your campaign, or press Resume on a paused one. WapHub checks the ad account before every launch and resume.' },
];

/**
 * How to add money to a Meta ad account, step by step. Meta's API does not let
 * any app add a card or funds, so this is the one step done on Meta; WapHub
 * never sees the card. Collapsible so it fits inside banners and forms.
 */
export default function MetaPaymentSteps({ adAccountId = null, startOpen = false }: { adAccountId?: string | null; startOpen?: boolean }) {
  const [open, setOpen] = useState(startOpen);

  return (
    <div className="mt-2 text-left" data-testid="meta-payment-steps">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        className="inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-800 hover:text-emerald-900"
      >
        <Wallet className="h-3.5 w-3.5" />
        How to add money to your Meta ad account
        {open ? <ChevronUp className="h-3.5 w-3.5" /> : <ChevronDown className="h-3.5 w-3.5" />}
      </button>
      {open && (
        <div className="mt-2 rounded-lg border border-slate-200 bg-white p-3 text-xs text-slate-700">
          <ol className="space-y-1.5">
            {STEPS.map((step, i) => (
              <li key={step.title} className="flex gap-2">
                <span className="flex h-5 w-5 flex-shrink-0 items-center justify-center rounded-full bg-emerald-50 text-[11px] font-semibold text-emerald-800">{i + 1}</span>
                <span>
                  <span className="font-semibold text-slate-900">{step.title}.</span> {step.detail}
                </span>
              </li>
            ))}
          </ol>
          <a
            href={metaBillingUrl(adAccountId)}
            target="_blank"
            rel="noopener noreferrer"
            className="mt-3 inline-flex items-center gap-1 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700"
          >
            Open Meta billing <ExternalLink className="h-3.5 w-3.5" />
          </a>
          <p className="mt-2 text-[11px] text-slate-500">Meta charges your ad spend directly to this method. WapHub never sees or stores your card or UPI details.</p>
        </div>
      )}
    </div>
  );
}
