import type { AxiosError } from 'axios';

/**
 * Phase 9 Task 2 — the safe 409 every social feature returns when the
 * connection it needs is expired or revoked (backend
 * SocialConnectionException::render()): a user-facing sentence, the
 * connection's id/type/status and where to reconnect. Never a token or a
 * raw provider payload.
 */
export interface SocialConnectionErrorInfo {
  message: string;
  status: 'expired' | 'revoked';
  socialAccountId: number | null;
  reconnectPath: string;
}

interface SocialConnectionErrorBody {
  message?: string;
  error_code?: string;
  connection?: { social_account_id?: number; connection_status?: string };
  reconnect_path?: string;
}

export const SOCIAL_CONNECTION_ERROR_CODES = ['SOCIAL_CONNECTION_EXPIRED', 'SOCIAL_CONNECTION_REVOKED'] as const;

/** The connection problem carried by a rejected request, or null for any other error. */
export function socialConnectionError(err: unknown): SocialConnectionErrorInfo | null {
  const data = (err as AxiosError<SocialConnectionErrorBody>)?.response?.data;
  const code = data?.error_code;

  if (!code || !(SOCIAL_CONNECTION_ERROR_CODES as readonly string[]).includes(code)) return null;

  const revoked = code === 'SOCIAL_CONNECTION_REVOKED';

  return {
    message:
      data?.message ??
      (revoked
        ? 'Access to the connected social account was revoked. Reconnect it in Social Accounts, then try again.'
        : 'The connected social account has expired. Reconnect it in Social Accounts, then try again.'),
    status: revoked ? 'revoked' : 'expired',
    socialAccountId: data?.connection?.social_account_id ?? null,
    // Only an in-app path is ever followed.
    reconnectPath: typeof data?.reconnect_path === 'string' && data.reconnect_path.startsWith('/') && !data.reconnect_path.startsWith('//') ? data.reconnect_path : '/social/accounts',
  };
}
