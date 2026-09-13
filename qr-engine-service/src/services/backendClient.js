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
