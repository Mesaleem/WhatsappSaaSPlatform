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
 * WhatsApp session, by asking backend-api the question it already answers
 * for the REST side: GET /api/whatsapp/status?account_id=X runs through
 * TenantIsolationMiddleware (Super Admin: any account; Agent: own account
 * or an owned Sub-Client; everyone else: own account only) and is DB-only,
 * so it never calls back into this service. 200 => allowed; 401/403/404/422
 * => forbidden. Replaces the former /api/auth/me role heuristic, which had
 * no notion of Agent -> Sub-Client ownership and rejected every Agent
 * connection for a Sub-Client with 'forbidden'. One authorization source
 * of truth instead of two that drift.
 * Network/timeout errors still throw (caller maps them to 'token
 * verification failed').
 */
export async function verifyAccountAccess(token, accountId) {
  const response = await backendHttp.get('/api/whatsapp/status', {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    params: { account_id: accountId },
    timeout: 5000,
    validateStatus: (status) => status < 500,
  });

  return response.status === 200;
}
