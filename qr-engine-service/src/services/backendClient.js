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

  // NOTE: the platform's Super Admin role was renamed 'Super Admin' ->
  // 'super_admin' (see backend-api's RolePermissionSeeder::LEGACY_ROLE_RENAMES)
  // in the Spatie roles table. This comparison was never updated for that
  // rename, so it silently always evaluated to false — every Super Admin
  // request fell through to the String(account_id) === String(accountId)
  // branch, which can never match a Super Admin whose own account_id is
  // null. That made the live Socket.IO QR/status stream reject a Super
  // Admin's connection ('forbidden') for BOTH their own test device and
  // every client account, even though the REST endpoints on the Laravel
  // side (TenantIsolationMiddleware / SubscriptionGuardMiddleware) already
  // correctly allow it. Root cause confirmed by reading the seeder's
  // LEGACY_ROLE_RENAMES map and User::isSuperAdmin() (hasRole('super_admin')).
  const isSuperAdmin = user.role?.name === 'super_admin';
  return isSuperAdmin || String(user.account_id) === String(accountId);
}
