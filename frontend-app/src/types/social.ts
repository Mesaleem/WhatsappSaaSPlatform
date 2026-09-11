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
  token_expires_at: string | null;
  created_at: string | null;
}

/** GET /api/social/oauth/{provider}/redirect */
export interface OAuthRedirectResponse {
  url: string;
  nonce: string;
}

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

export type OAuthPopupMessage = OAuthPopupSuccessMessage | OAuthPopupErrorMessage;

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
