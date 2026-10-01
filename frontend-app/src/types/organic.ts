/**
 * Social/Ads Launcher Overhaul — Step 3 (Organic Multi-Channel Publishing
 * Engine). Mirrors backend-api's App\Models\OrganicPost + OrganicPostController's
 * JSON shape.
 */

import type { MediaType } from './media';

export type OrganicPlatform = 'facebook' | 'instagram' | 'linkedin';

/**
 * Phase 9 Task 3 — publishing lifecycle. `pending` keeps its meaning (the
 * provider is still processing an Instagram video).
 */
export type OrganicPostStatus =
  | 'scheduled'
  | 'publishing'
  | 'pending'
  | 'published'
  | 'failed'
  | 'reconnect_required'
  | 'cancelled';

export const ORGANIC_STATUS_LABELS: Record<OrganicPostStatus, string> = {
  scheduled: 'Scheduled',
  publishing: 'Publishing',
  pending: 'Processing',
  published: 'Published',
  failed: 'Failed',
  reconnect_required: 'Reconnect required',
  cancelled: 'Cancelled',
};

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

/** Local date/time for a scheduled/published timestamp (ISO 8601 from the API). */
export const formatScheduledAt = (iso: string | null) => (iso ? new Date(iso).toLocaleString() : '—');

export interface PublishOrganicPostPayload {
  platform: OrganicPlatform;
  caption: string;
  media_url?: string | null;
  media_type?: MediaType | null;
  /** Phase 9 Task 3 — ISO 8601; omitted = publish now. */
  scheduled_at?: string | null;
  /** Phase 9 Task 3 — same key for a resubmission of the same post, so it is never posted twice. */
  idempotency_key?: string | null;
}

export interface OrganicPost {
  id: number;
  social_account_id: number | null;
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
  // Phase 9 Task 3
  origin: 'manual' | 'scheduled';
  scheduled_at: string | null;
  failure_code: string | null;
  attempts: number;
  next_attempt_at: string | null;
  cancelled_at: string | null;
  can_cancel: boolean;
  can_retry: boolean;
}
