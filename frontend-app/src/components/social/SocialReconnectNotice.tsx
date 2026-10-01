import { AlertCircle, ExternalLink } from 'lucide-react';
import type { SocialConnectionErrorInfo } from '../../utils/socialConnectionError';

/**
 * Phase 9 Task 2 — shown in place of a generic error when a social action
 * failed because its connection is expired or revoked. The reconnect link
 * opens Social Accounts in a NEW TAB so the form the user was filling in
 * (campaign wizard, organic post) stays exactly as it is; after
 * reconnecting they come back and submit again.
 */
export default function SocialReconnectNotice({
  info,
  canManageSocialAccounts,
}: {
  info: SocialConnectionErrorInfo;
  canManageSocialAccounts: boolean;
}) {
  return (
    <div className="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800" role="alert" data-testid="social-reconnect-notice">
      <AlertCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
      <div className="space-y-1">
        <p className="font-medium">{info.status === 'revoked' ? 'Access revoked' : 'Connection expired'}</p>
        <p>{info.message}</p>
        {canManageSocialAccounts ? (
          <p>
            <a
              href={info.reconnectPath}
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex items-center gap-1 font-semibold underline"
              data-testid="social-reconnect-link"
            >
              Reconnect in Social Accounts
              <ExternalLink className="h-3.5 w-3.5" />
            </a>{' '}
            <span className="text-amber-700">(opens in a new tab — what you entered here is kept)</span>
          </p>
        ) : (
          <p className="text-amber-700">Ask a team member with access to Social Accounts to reconnect it.</p>
        )}
      </div>
    </div>
  );
}
