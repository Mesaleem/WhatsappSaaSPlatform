import axios from 'axios';

const BACKEND_API_URL = process.env.BACKEND_API_URL || 'http://localhost:8000';
const INTERNAL_API_SECRET = process.env.INTERNAL_API_SECRET || '';

const backendHttp = axios.create({
  baseURL: BACKEND_API_URL,
  timeout: 5000,
});

/**
 * Which account and number slot each live session belongs to. Filled by
 * sessionManager when a session starts, so every callback can name the slot
 * (session_id) and its account. Legacy sessions (no slot) map to the account only.
 */
const sessionOwners = new Map();

export function registerSessionOwner(sessionKey, { accountId, slotBased }) {
  sessionOwners.set(String(sessionKey), { accountId: Number(accountId), slotBased: !!slotBased });
}

export function hasSessionOwner(sessionKey) {
  return sessionOwners.has(String(sessionKey));
}

/**
 * Asks the backend which account owns a number slot. Used when a slot session starts
 * without its owner (a boot resume, or a reconnect after a restart), so its status
 * callbacks reach the right account. Returns null for an unknown slot.
 */
export async function fetchNumberOwner(sessionId) {
  try {
    const { data } = await backendHttp.get(`/api/internal/whatsapp-numbers/${Number(sessionId)}`, {
      headers: internalHeaders(),
      timeout: AUTH_STATE_TIMEOUT_MS,
    });
    return data?.account_id != null ? { accountId: Number(data.account_id) } : null;
  } catch {
    return null;
  }
}

export function unregisterSessionOwner(sessionKey) {
  sessionOwners.delete(String(sessionKey));
}

function ownerFields(sessionKey) {
  const owner = sessionOwners.get(String(sessionKey));
  if (!owner) {
    return { account_id: Number(sessionKey) };
  }
  return owner.slotBased
    ? { account_id: owner.accountId, session_id: Number(sessionKey) }
    : { account_id: owner.accountId };
}

/**
 * Internal webhook: POST /api/internal/whatsapp-status.
 * Failures are logged, never thrown — a webhook outage must not break the
 * QR pairing flow for the user who is actively scanning.
 */
export async function notifyBackend(sessionKey, status, extra = {}) {
  try {
    await backendHttp.post(
      '/api/internal/whatsapp-status',
      { ...ownerFields(sessionKey), status, ...extra },
      { headers: { 'X-Internal-Secret': INTERNAL_API_SECRET } },
    );
  } catch (err) {
    console.error(
      `[backendClient] failed to notify backend-api of status="${status}" for account_id=${accountId}:`,
      err.response?.data ?? err.message,
    );
  }
}

/**
 * Module 10 (qr-engine-service side) — Baileys-side counterpart to
 * MetaWebhookController's inbound-message handling. Notifies
 * backend-api of an inbound WhatsApp message received on a Baileys
 * ('qr' engine) session, so ChatbotEngineService::handleInboundMessage()
 * can evaluate chatbot rules / Journey Builder for this tenant exactly
 * as it already does for Meta-engine accounts.
 *
 * Mirrors notifyBackend()'s fire-and-forget error handling: a delivery
 * failure here must never crash this process or interrupt the live
 * Baileys socket — it is logged and dropped, same as a status-webhook
 * failure. Posts to the existing, already-secured
 * /api/internal/whatsapp-inbound endpoint (WhatsAppInboundController),
 * which was built for this call and previously had no caller.
 */
export async function notifyInboundMessage(accountId, senderPhone, message, messageId = null) {
  try {
    await backendHttp.post(
      '/api/internal/whatsapp-inbound',
      // Phase 7 Task 3 — message_id: the Baileys message key id (msg.key.id),
      // the message's stable WhatsApp identity. backend-api uses it to
      // process each inbound message at most once (durable de-duplication).
      { ...ownerFields(accountId), sender_phone: senderPhone, message, message_id: messageId },
      { headers: { 'X-Internal-Secret': INTERNAL_API_SECRET } },
    );
  } catch (err) {
    console.error(
      `[backendClient] failed to notify backend-api of inbound message for account_id=${accountId}:`,
      err.response?.data ?? err.message,
    );
  }
}

/**
 * Baileys auth-state storage (see authStore.js). Every call is an internal
 * request, so backend-api answers only to this process's shared secret.
 * Unlike the fire-and-forget notifications above, these THROW on failure:
 * the caller must know whether a credential update was stored.
 */
const internalHeaders = () => ({ 'X-Internal-Secret': INTERNAL_API_SECRET });

// Credential storage is on the critical path (a missed write can force a
// re-pair), so it gets more headroom than the 5 s status webhooks above.
const AUTH_STATE_TIMEOUT_MS = 15000;

export async function fetchBackendAuthState(accountId) {
  const { data } = await backendHttp.get(`/api/internal/whatsapp-auth/${Number(accountId)}`, {
    headers: internalHeaders(),
    timeout: AUTH_STATE_TIMEOUT_MS,
  });
  return data?.entries ?? {};
}

// Large first-time imports are split so no single request carries an
// unbounded body. Matches the server-side per-request cap.
const AUTH_STATE_CHUNK = 200;

export async function patchBackendAuthState(accountId, { set = {}, delete: remove = [] }) {
  const entries = Object.entries(set);
  const url = `/api/internal/whatsapp-auth/${Number(accountId)}`;

  for (let i = 0; i < entries.length; i += AUTH_STATE_CHUNK) {
    const chunk = Object.fromEntries(entries.slice(i, i + AUTH_STATE_CHUNK));
    await backendHttp.put(url, { set: chunk, delete: [] }, { headers: internalHeaders(), timeout: AUTH_STATE_TIMEOUT_MS });
  }

  if (remove.length > 0) {
    await backendHttp.put(url, { set: {}, delete: remove }, { headers: internalHeaders(), timeout: AUTH_STATE_TIMEOUT_MS });
  }
}

export async function removeBackendAuthState(accountId) {
  await backendHttp.delete(`/api/internal/whatsapp-auth/${Number(accountId)}`, { headers: internalHeaders(), timeout: AUTH_STATE_TIMEOUT_MS });
}

export async function fetchBackendPairedAccountIds() {
  const { data } = await backendHttp.get('/api/internal/whatsapp-auth/paired', { headers: internalHeaders(), timeout: AUTH_STATE_TIMEOUT_MS });
  return data?.number_ids ?? [];
}

/**
 * True only when number slot `sessionId` belongs to `accountId`. A browser may
 * only watch the QR/status of a slot its own account owns.
 */
export async function verifyNumberOwner(sessionId, accountId) {
  try {
    const { data } = await backendHttp.get(`/api/internal/whatsapp-numbers/${Number(sessionId)}`, {
      headers: internalHeaders(),
      timeout: AUTH_STATE_TIMEOUT_MS,
    });
    return Number(data?.account_id) === Number(accountId);
  } catch {
    return false;
  }
}

/**
 * Verifies a frontend Bearer token by asking backend-api who it belongs to,
 * and confirms that user is allowed to see accountId's session (their own
 * account, or Super Admin). This is the multi-tenant isolation boundary for
 * the *live* Socket.IO stream — see server.js's io.use() middleware.
 */
export async function verifyAccountAccess(token, accountId) {
  const { data } = await backendHttp.get('/api/auth/me', {
    headers: { Authorization: `Bearer ${token}` },
    timeout: 10000, // handshake check; the socket client retries a failed one
  });

  const user = data?.user;
  if (!user) return false;

  // [Bug fix, disclosed — corrects the previous comment here, which
  // misdiagnosed this]: backend-api's GET /api/auth/me never returns a
  // singular `user.role` field at all — AuthController::formatUser()
  // returns `'roles' => $user->roles` (the PLURAL Eloquent collection of
  // every role the user holds; see that method's own docblock: it was
  // deliberately changed from a single arbitrary role to the full array
  // so multi-role users are represented correctly). So `user.role?.name`
  // here was ALWAYS undefined, for every user including Super Admin — a
  // field-name/shape mismatch (`role` vs `roles`, singular object vs
  // array), not a stale role-name string as the previous comment claimed
  // (that string, 'super_admin', was already correct; it just could
  // never be reached through `user.role?.name`). This made isSuperAdmin
  // always false, so a Super Admin's Socket.IO connection always fell
  // through to `String(user.account_id) === String(accountId)` — which
  // can never match, since a Super Admin's own account_id is null —
  // rejecting ('forbidden') their own test device AND every client
  // account's live QR/status stream, exactly the symptom reported
  // ("You aren't authorized to manage this account's WhatsApp
  // connection.") even though the REST endpoints on the Laravel side
  // (TenantIsolationMiddleware) already correctly allow Super Admin.
  // Root cause confirmed by reading AuthController::formatUser() and
  // User::isSuperAdmin() (hasRole('super_admin')) directly, not inferred.
  const roles = Array.isArray(user.roles) ? user.roles : [];
  const isSuperAdmin = roles.some((role) => role?.name === 'super_admin');
  return isSuperAdmin || String(user.account_id) === String(accountId);
}
