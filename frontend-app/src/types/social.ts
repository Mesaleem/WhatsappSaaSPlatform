/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 1.
 * Mirrors backend-api's App\Models\SocialAccount + SocialAuthController's
 * JSON shape.
 */

export type SocialProvider = 'meta' | 'linkedin' | 'google';

export type SocialAssetType =
  | 'facebook_page'
  | 'instagram'
  | 'meta_ad_account'
  | 'linkedin_page'
  | 'youtube_channel';

export type SocialHealthStatus = 'connected' | 'token_expired' | 'reauth_required';

/**
 * Phase 9 Task 1 — the lifecycle status the API derives per connection
 * (SocialConnectionStatus): revoked = the provider no longer accepts the
 * grant; expired = the token expired. The client-only states
 * (not_connected / connecting / failed / cancelled / disconnecting) live in
 * SocialAccountsPage.
 */
export type SocialConnectionStatus = 'connected' | 'expired' | 'revoked';

/**
 * Phase 9 Task 2 — the lifecycle status of a connection as the API reports
 * it (`connection_status`: stored health + token expiry), falling back to
 * health_status for older payloads the way the backend maps it.
 */
export function connectionStatusOf(account: Pick<SocialAccount, 'connection_status' | 'health_status'>): SocialConnectionStatus {
  if (account.connection_status) return account.connection_status;
  if (account.health_status === 'reauth_required') return 'revoked';
  if (account.health_status === 'token_expired') return 'expired';
  return 'connected';
}

export const SOCIAL_ASSET_TYPE_LABELS: Record<SocialAssetType, string> = {
  facebook_page: 'Facebook Page',
  instagram: 'Instagram Account',
  meta_ad_account: 'Meta Ad Account',
  linkedin_page: 'LinkedIn Page',
  youtube_channel: 'YouTube Channel',
};

export interface SocialAccount {
  id: number;
  provider: SocialProvider;
  asset_type: SocialAssetType;
  provider_id: string;
  name: string | null;
  avatar_url: string | null;
  health_status: SocialHealthStatus;
  /** Phase 9 Task 1 — optional so older API payloads / fixtures still type-check. */
  connection_status?: SocialConnectionStatus;
  status_reason?: string | null;
  status_checked_at?: string | null;
  capabilities?: string[];
  token_expires_at: string | null;
  created_at: string | null;
}

/** GET /api/social/providers — Phase 9 Task 1. */
export interface SocialProviderInfo {
  key: SocialProvider;
  label: string;
  asset_types: SocialAssetType[];
  capabilities: Record<string, string[]>;
  configured: boolean;
  enabled_for_account: boolean;
}

/** POST /api/social/accounts/{id}/check */
export interface SocialConnectionCheckResponse {
  data: SocialAccount;
  check: { status: SocialConnectionStatus | 'unknown'; reason: string | null };
}

/** GET /api/social/oauth/{provider}/redirect */
export interface OAuthRedirectResponse {
  url: string;
  /** Lets the page poll the server for the outcome (the popup's postMessage can be lost after facebook.com). */
  nonce?: string;
}

/** GET /api/social/oauth/{provider}/result/{nonce} */
export type OAuthResultResponse =
  | { status: 'pending' }
  | { status: 'success'; provider: SocialProvider; nonce: string; assets: OfferedAsset[] }
  | { status: 'error' | 'cancelled'; message: string };

/** One asset offered by the provider after a successful code exchange, not yet bound. */
export interface OfferedAsset {
  asset_type: SocialAssetType;
  provider_id: string;
  name: string | null;
  avatar_url: string | null;
}

/** postMessage payload the OAuth popup (SocialAuthController::callback()) sends back. */
export interface OAuthPopupSuccessMessage {
  type: 'social-oauth-success';
  provider: SocialProvider;
  nonce: string;
  assets: OfferedAsset[];
}

export interface OAuthPopupErrorMessage {
  type: 'social-oauth-error';
  message: string;
}

/** Phase 9 Task 1 — the user declined or closed the provider's consent screen. */
export interface OAuthPopupCancelledMessage {
  type: 'social-oauth-cancelled';
  message: string;
}

export type OAuthPopupMessage = OAuthPopupSuccessMessage | OAuthPopupErrorMessage | OAuthPopupCancelledMessage;

/** POST /api/social/accounts/bind */
export interface BindAccountsPayload {
  nonce: string;
  selections: Array<{ asset_type: SocialAssetType; provider_id: string }>;
}

export interface BindAccountsResponse {
  message: string;
  data: SocialAccount[];
}


/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 2.
 * Mirrors Admin\SocialGatewayController::present()'s JSON shape exactly.
 * Secrets are never returned — only the `*_set` booleans, same contract
 * as GatewaySettings (types/billing.ts).
 */
export interface SocialProviderConfigRow {
  provider: SocialProvider;
  is_active: boolean;
  is_fully_configured: boolean;
  client_id_set: boolean;
  client_secret_set: boolean;
  redirect_uri: string | null;
  webhook_verify_token_set: boolean;
  webhook_url: string;
}

/** POST /api/admin/social/provider-configs/{provider} — partial update; omitted fields are left untouched. */
export interface UpdateSocialProviderConfigPayload {
  client_id?: string | null;
  client_secret?: string | null;
  redirect_uri?: string | null;
  is_active?: boolean;
  regenerate_webhook_verify_token?: boolean;
}
