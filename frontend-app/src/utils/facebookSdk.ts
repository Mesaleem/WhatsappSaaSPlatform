/**
 * Phase 1 — Meta Channel Creation / Embedded Signup. Lazily injects
 * Facebook's JS SDK (per-tenant App model: the SDK is initialized with
 * whichever tenant's own App ID is currently being connected, so it is
 * re-initialized rather than loaded once at app startup).
 *
 * NOTE: written against Meta's documented SDK bootstrap + Embedded
 * Signup shape. Not yet exercised against a real Facebook App (none
 * existed in this environment when this was written) — verify the exact
 * `fbAsyncInit`/`FB.login` option names against Meta's current docs
 * once a real App + test WABA is available.
 */

declare global {
  interface Window {
    FB?: {
      init: (opts: { appId: string; cookie?: boolean; xfbml?: boolean; version: string }) => void;
      login: (
        callback: (response: { authResponse?: { code?: string } | null; status?: string }) => void,
        options: Record<string, unknown>,
      ) => void;
    };
    fbAsyncInit?: () => void;
  }
}

const SDK_VERSION = 'v18.0'; // matches backend MetaConfigController::API_VERSION
let loadedForAppId: string | null = null;
let loadingPromise: Promise<void> | null = null;

/** Resolves once window.FB is initialized for the given (tenant-specific) App ID. */
export function loadFacebookSdk(appId: string): Promise<void> {
  if (loadedForAppId === appId && window.FB) {
    return Promise.resolve();
  }

  if (loadingPromise && loadedForAppId === appId) {
    return loadingPromise;
  }

  loadedForAppId = appId;
  loadingPromise = new Promise<void>((resolve) => {
    window.fbAsyncInit = () => {
      window.FB?.init({ appId, cookie: true, xfbml: false, version: SDK_VERSION });
      resolve();
    };

    const existing = document.getElementById('facebook-jssdk');
    if (existing) {
      existing.remove(); // re-inject: a different tenant's App ID needs a fresh FB.init
    }

    const script = document.createElement('script');
    script.id = 'facebook-jssdk';
    script.src = 'https://connect.facebook.net/en_US/sdk.js';
    script.async = true;
    script.defer = true;
    document.body.appendChild(script);
  });

  return loadingPromise;
}

/** One WhatsApp Embedded Signup event posted by Meta's signup popup/iframe via window.postMessage. */
export interface EmbeddedSignupMessageEvent {
  event: 'WA_EMBEDDED_SIGNUP';
  data: {
    event: 'FINISH' | 'CANCEL' | 'ERROR';
    data?: {
      phone_number_id?: string;
      waba_id?: string;
    };
  };
}

function isEmbeddedSignupEvent(value: unknown): value is EmbeddedSignupMessageEvent {
  return (
    typeof value === 'object' &&
    value !== null &&
    (value as { event?: unknown }).event === 'WA_EMBEDDED_SIGNUP'
  );
}

/**
 * Listens once for the Embedded Signup popup's completion message. Resolves
 * with the chosen WABA/number on 'FINISH', rejects on 'CANCEL'/'ERROR' or
 * timeout (the user closed the popup without finishing).
 */
export function waitForEmbeddedSignupMessage(timeoutMs = 120000): Promise<{ wabaId: string; phoneNumberId: string }> {
  return new Promise((resolve, reject) => {
    const timer = window.setTimeout(() => {
      window.removeEventListener('message', handler);
      reject(new Error('Timed out waiting for Meta sign-in to finish.'));
    }, timeoutMs);

    function handler(event: MessageEvent) {
      let parsed: unknown;
      try {
        parsed = typeof event.data === 'string' ? JSON.parse(event.data) : event.data;
      } catch {
        return; // not JSON — not Meta's message, ignore
      }

      if (!isEmbeddedSignupEvent(parsed)) return;

      if (parsed.data.event === 'FINISH' && parsed.data.data?.waba_id && parsed.data.data?.phone_number_id) {
        window.clearTimeout(timer);
        window.removeEventListener('message', handler);
        resolve({ wabaId: parsed.data.data.waba_id, phoneNumberId: parsed.data.data.phone_number_id });
      } else if (parsed.data.event === 'CANCEL' || parsed.data.event === 'ERROR') {
        window.clearTimeout(timer);
        window.removeEventListener('message', handler);
        reject(new Error(parsed.data.event === 'CANCEL' ? 'Sign-in with Meta was cancelled.' : 'Meta reported an error during sign-in.'));
      }
    }

    window.addEventListener('message', handler);
  });
}
