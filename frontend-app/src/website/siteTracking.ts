import { useEffect } from 'react';
import { useLocation } from 'react-router-dom';
import { useAuth } from '../core/context/AuthContext';

/**
 * Website visitor analytics — the public site's tracker (report: Super Admin → Website Analytics).
 *
 * A visitor id stays in localStorage (recognises a returning browser, no login, no cookie); a
 * session id lives in sessionStorage and starts over after 30 idle minutes. Each page opened is a
 * "view"; while it is on screen a "ping" reports its visible seconds every 15 s and when the tab
 * is hidden, closed or the visitor moves to another page. Events go out with sendBeacon / fetch
 * keepalive as text/plain, so the last ping still arrives when the tab closes and no CORS
 * preflight is needed. Nothing here can break or slow a page: every failure is swallowed.
 */

const API = (import.meta.env.VITE_API_BASE_URL as string | undefined) ?? 'http://localhost:8000/api';
const ENDPOINT = `${API.replace(/\/$/, '')}/public/site-events`;
const VISITOR_KEY = 'waphub_vid';
const SESSION_KEY = 'waphub_sid';
const SESSION_IDLE_MS = 30 * 60 * 1000;
const PING_EVERY_MS = 15_000;

type SiteEvent = Record<string, unknown> & { type: 'view' | 'ping' | 'signup'; session_id: string };

function uuid(): string {
  if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) return crypto.randomUUID();
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
  });
}

function stored(storage: () => Storage, key: string): string | null {
  try {
    return storage().getItem(key);
  } catch {
    return null;
  }
}

function store(storage: () => Storage, key: string, value: string) {
  try {
    storage().setItem(key, value);
  } catch {
    // Private mode / blocked storage: the ids just last for this page load.
  }
}

let memoryVisitor: string | null = null;
let memorySession: { id: string; last: number; fresh: boolean } | null = null;

function visitorId(): string {
  const id = stored(() => localStorage, VISITOR_KEY) ?? memoryVisitor ?? uuid();
  memoryVisitor = id;
  store(() => localStorage, VISITOR_KEY, id);
  return id;
}

/** The current session id; `fresh` is true for the first page of a new session. */
function sessionId(): { id: string; fresh: boolean } {
  const now = Date.now();
  let current = memorySession;
  const raw = stored(() => sessionStorage, SESSION_KEY);
  if (raw) {
    try {
      current = { ...(JSON.parse(raw) as { id: string; last: number }), fresh: false };
    } catch {
      current = null;
    }
  }
  const fresh = !current || now - current.last > SESSION_IDLE_MS;
  const id = fresh ? uuid() : current!.id;
  memorySession = { id, last: now, fresh };
  store(() => sessionStorage, SESSION_KEY, JSON.stringify({ id, last: now }));
  return { id, fresh };
}

function touchSession() {
  if (!memorySession) return;
  memorySession.last = Date.now();
  store(() => sessionStorage, SESSION_KEY, JSON.stringify({ id: memorySession.id, last: memorySession.last }));
}

let sender: ((event: SiteEvent) => void) | null = null;

/** Tests only: capture events instead of sending them. */
export function setSiteEventSenderForTests(fn: ((event: SiteEvent) => void) | null): void {
  sender = fn;
}

/** Fire-and-forget. Never throws, never waits. */
export function sendSiteEvent(event: SiteEvent): void {
  if (sender) {
    sender(event);
    return;
  }
  if (import.meta.env.MODE === 'test') return;
  try {
    const body = JSON.stringify(event);
    if (typeof navigator !== 'undefined' && typeof navigator.sendBeacon === 'function' && navigator.sendBeacon(ENDPOINT, new Blob([body], { type: 'text/plain' }))) return;
    void fetch(ENDPOINT, { method: 'POST', body, keepalive: true, credentials: 'omit', headers: { 'Content-Type': 'text/plain' } }).catch(() => undefined);
  } catch {
    // Analytics must never affect the page.
  }
}

/** Marks the current session as one that created an account (called after a successful signup). */
export function trackSignup(): void {
  const raw = stored(() => sessionStorage, SESSION_KEY);
  const id = memorySession?.id ?? (raw ? (JSON.parse(raw) as { id?: string }).id : undefined);
  if (id) sendSiteEvent({ type: 'signup', session_id: id });
}

function utm(search: string) {
  const q = new URLSearchParams(search);
  const pick = (k: string) => q.get(k)?.slice(0, 100) || null;
  const out = { source: pick('utm_source'), medium: pick('utm_medium'), campaign: pick('utm_campaign') };
  return out.source || out.medium || out.campaign ? out : null;
}

/**
 * Records the page the visitor is on, and how long it stays visible, for every website route.
 * The Super Admin's own browsing is not recorded.
 */
export function useSiteTracking(): void {
  const location = useLocation();
  const { isSuperAdmin } = useAuth();
  const skip = isSuperAdmin();

  useEffect(() => {
    if (skip) return undefined;

    const session = sessionId();
    const viewId = uuid();
    let visibleMs = 0;
    let visibleSince = document.visibilityState === 'visible' ? Date.now() : null;
    let lastSent = -1;

    sendSiteEvent({
      type: 'view',
      session_id: session.id,
      visitor_id: visitorId(),
      view_id: viewId,
      path: location.pathname,
      title: document.title || null,
      host: window.location.hostname,
      // Where the visitor came from is only meaningful on the first page of the session.
      ...(session.fresh ? { referrer: document.referrer || null, utm: utm(location.search) } : {}),
    });

    const seconds = () => Math.round((visibleMs + (visibleSince !== null ? Date.now() - visibleSince : 0)) / 1000);
    const ping = () => {
      const s = seconds();
      if (s <= lastSent) return;
      lastSent = s;
      touchSession();
      sendSiteEvent({ type: 'ping', session_id: session.id, view_id: viewId, seconds: s });
    };
    const onVisibility = () => {
      if (document.visibilityState === 'visible') {
        visibleSince = Date.now();
      } else {
        if (visibleSince !== null) visibleMs += Date.now() - visibleSince;
        visibleSince = null;
        ping();
      }
    };

    const timer = window.setInterval(() => {
      if (visibleSince !== null) ping();
    }, PING_EVERY_MS);
    document.addEventListener('visibilitychange', onVisibility);
    window.addEventListener('pagehide', ping);

    return () => {
      window.clearInterval(timer);
      document.removeEventListener('visibilitychange', onVisibility);
      window.removeEventListener('pagehide', ping);
      ping();
    };
  }, [location.pathname, location.search, skip]);
}
