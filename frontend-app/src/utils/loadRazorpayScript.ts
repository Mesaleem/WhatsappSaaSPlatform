import type { RazorpayCheckoutInstance, RazorpayCheckoutOptions } from '../types/billing';

declare global {
  interface Window {
    Razorpay?: new (options: RazorpayCheckoutOptions) => RazorpayCheckoutInstance;
  }
}

const RAZORPAY_SCRIPT_SRC = 'https://checkout.razorpay.com/v1/checkout.js';

let loadPromise: Promise<boolean> | null = null;

/**
 * Razorpay has no official npm package for Checkout.js (unlike Stripe) —
 * their own docs mandate loading it via a plain <script> tag so it always
 * serves their latest, self-hosted build. Dynamically injects it once and
 * caches the in-flight/resolved promise so repeated "Upgrade Plan" clicks
 * don't inject the tag more than once.
 */
export function loadRazorpayScript(): Promise<boolean> {
  if (window.Razorpay) {
    return Promise.resolve(true);
  }

  if (loadPromise) {
    return loadPromise;
  }

  loadPromise = new Promise((resolve) => {
    const existing = document.querySelector(`script[src="${RAZORPAY_SCRIPT_SRC}"]`);
    if (existing) {
      existing.addEventListener('load', () => resolve(true));
      existing.addEventListener('error', () => resolve(false));
      return;
    }

    const script = document.createElement('script');
    script.src = RAZORPAY_SCRIPT_SRC;
    script.async = true;
    script.onload = () => resolve(true);
    script.onerror = () => {
      loadPromise = null; // allow a retry on the next call
      resolve(false);
    };
    document.body.appendChild(script);
  });

  return loadPromise;
}
