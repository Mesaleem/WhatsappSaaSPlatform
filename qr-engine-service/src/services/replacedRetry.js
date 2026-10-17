/**
 * [Bug fix 2026-10-17]: what to do after WhatsApp closes a session with 440
 * "replaced" (another client logged in with the same credentials).
 *
 * Seen on Render: every deploy (and every wake from hibernation) briefly runs
 * the new engine instance next to the old one. Both resume the same login, one
 * is kicked out with 440, and the engine used to give up for good -- then
 * Render stopped the other instance, nothing was connected, and backend-api
 * showed "WhatsApp account is disconnected" until someone reconnected by hand.
 *
 * Now a replaced session is retried after a delay, by when the old instance is
 * normally gone. Bounded, so two engines that really do run side by side do not
 * take the login from each other forever: at most MAX_RETRIES within WINDOW_MS.
 */

export const REPLACED_RETRY_DELAYS_MS = [20000, 40000, 60000];
const WINDOW_MS = 10 * 60 * 1000;

// sessionKey -> timestamps of retries already scheduled
const history = new Map();

/** Delay before the next retry for this session, or null when the limit is reached. */
export function nextReplacedRetryDelay(sessionKey, now = Date.now()) {
  const id = String(sessionKey);
  const recent = (history.get(id) ?? []).filter((at) => now - at < WINDOW_MS);
  if (recent.length >= REPLACED_RETRY_DELAYS_MS.length) {
    history.set(id, recent);
    return null;
  }
  recent.push(now);
  history.set(id, recent);
  return REPLACED_RETRY_DELAYS_MS[recent.length - 1];
}

/** Forgets the retries of a session (logout). */
export function resetReplacedRetries(sessionKey) {
  history.delete(String(sessionKey));
}
