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
 * Verifies a frontend Bearer token by asking backend-api who it belongs to,
 * and confirms that user is allowed to see accountId's session (their own
 * account, or Super Admin). This is the multi-tenant isolation boundary for
 * the *live* Socket.IO stream — see server.js's io.use() middleware.
 */
export async function verifyAccountAccess(token, accountId) {
  const { data } = await backendHttp.get('/api/auth/me', {
    headers: { Authorization: `Bearer ${token}` },
    timeout: 5000,
  });

  const user = data?.user;
  if (!user) return false;

  const isSuperAdmin = user.role?.name === 'Super Admin';
  return isSuperAdmin || String(user.account_id) === String(accountId);
}
