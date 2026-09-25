import axios from 'axios';

const BACKEND_API_URL = process.env.BACKEND_API_URL || 'http://localhost:8000';
const INTERNAL_API_SECRET = process.env.INTERNAL_API_SECRET || '';

const backendHttp = axios.create({
  baseURL: BACKEND_API_URL,
  timeout: 5000,
});

/**
 * Internal webhook: POST /api/internal/whatsapp-status.
 * Failures are logged, never thrown — a webhook outage must not break the
 * QR pairing flow for the user who is actively scanning.
 */
export async function notifyBackend(accountId, status) {
  try {
    await backendHttp.post(
      '/api/internal/whatsapp-status',
      { account_id: Number(accountId), status },
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
export async function notifyInboundMessage(accountId, senderPhone, message) {
  try {
    await backendHttp.post(
      '/api/internal/whatsapp-inbound',
      { account_id: Number(accountId), sender_phone: senderPhone, message },
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
 * Verifies a frontend Bearer token AND that its user may manage accountId's
 * WhatsApp session, by asking backend-api the questions it already answers
 * for the REST side — and checking the ANSWER names the requested account:
 *
 * 1. GET /api/whatsapp/status?account_id=X runs through
 *    TenantIsolationMiddleware (Super Admin: any client account; Agent:
 *    own account or an owned Sub-Client; everyone else: own account only)
 *    and now echoes the account_id it actually resolved. Comparing that to
 *    accountId is required, not optional: for a plain tenant user the
 *    middleware silently IGNORES ?account_id= and resolves the caller's
 *    own account, so a bare 200 would let any tenant user join any other
 *    tenant's live QR room (a QR is a pairing credential).
 * 2. The Super Admin platform test device (Account::platformDevice(),
 *    is_platform_device=true) is hidden from step 1 by Account's
 *    exclude_platform_device global scope (its exists() check 404s), so
 *    it is authorized via GET /api/admin/whatsapp/self-device instead —
 *    role:super_admin gated, returns that one account_id. Only consulted
 *    when step 1 did not match, so ordinary connections cost one call.
 *
 * Both endpoints are DB-only and never call back into this service.
 * Non-2xx from either => forbidden. Network/timeout errors still throw
 * (caller maps them to 'token verification failed').
 */
export async function verifyAccountAccess(token, accountId) {
  const wanted = Number(accountId);
  if (!Number.isInteger(wanted) || wanted <= 0) return false;

  const opts = {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    timeout: 5000,
    validateStatus: (status) => status < 500,
  };

  const tenant = await backendHttp.get('/api/whatsapp/status', {
    ...opts,
    params: { account_id: wanted },
  });
  if (tenant.status === 200 && Number(tenant.data?.account_id) === wanted) return true;

  const selfDevice = await backendHttp.get('/api/admin/whatsapp/self-device', opts);
  const allowed = selfDevice.status === 200 && Number(selfDevice.data?.account_id) === wanted;
  if (!allowed) {
    // Rejection diagnostics — never logs the token. Without this a socket
    // auth failure is indistinguishable from the browser for every cause.
    console.warn(
      `[backendClient] socket auth denied for account_id=${wanted}: ` +
        `/whatsapp/status -> ${tenant.status} (account_id=${tenant.data?.account_id ?? 'n/a'}), ` +
        `/admin/whatsapp/self-device -> ${selfDevice.status} (account_id=${selfDevice.data?.account_id ?? 'n/a'})`,
    );
  }
  return allowed;
}
