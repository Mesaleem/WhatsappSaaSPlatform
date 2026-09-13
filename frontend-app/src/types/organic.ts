/**
 * Social/Ads Launcher Overhaul — Step 3 (Organic Multi-Channel Publishing
 * Engine). Mirrors backend-api's App\Models\OrganicPost + OrganicPostController's
 * JSON shape.
 */

import type { MediaType } from './media';

export type OrganicPlatform = 'facebook' | 'instagram' | 'linkedin';

export type OrganicPostStatus = 'pending' | 'published' | 'failed';

export const ORGANIC_PLATFORM_LABELS: Record<OrganicPlatform, string> = {
  facebook: 'Facebook Page',
  instagram: 'Instagram',
  linkedin: 'LinkedIn',
};

/**
 * LinkedIn is listed for UI completeness, but publishing to it will
 * currently always fail server-side ("No connected LinkedIn asset") —
 * this codebase has no LinkedIn OAuth connect flow yet
 * (SocialOAuthProviderFactory::IMPLEMENTED_PROVIDERS = ['meta'] only,
 * verified on the backend). Surfaced here as a disclosed, honest UI
 * note rather than hidden.
 */
export const LINKEDIN_UNAVAILABLE_NOTE =
  'LinkedIn publishing requires a connected LinkedIn account. LinkedIn Connect is not yet available in the Social Hub.';

export interface PublishOrganicPostPayload {
  platform: OrganicPlatform;
  caption: string;
  media_url?: string | null;
  media_type?: MediaType | null;
}

export interface OrganicPost {
  id: number;
  provider: string;
  platform: OrganicPlatform;
  caption: string;
  media_url: string | null;
  media_type: MediaType | null;
  status: OrganicPostStatus;
  external_post_id: string | null;
  error_message: string | null;
  published_at: string | null;
  created_at: string | null;
}
