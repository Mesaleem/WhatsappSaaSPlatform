/**
 * Social/Ads Launcher Overhaul — Step 2 (Multipart Media Upload API).
 * Mirrors backend-api's SocialMediaController JSON shape.
 */

export type MediaType = 'image' | 'video';

export interface UploadedMedia {
  /** Durable, publicly fetchable URL — safe to hand to Meta's adcreatives `picture` field or render directly. */
  url: string;
  /** Storage path, relative to the `public` disk — not generally needed by callers, kept for debugging/future reuse. */
  path: string;
  type: MediaType;
  mime: string;
  /** Bytes. */
  size: number;
}
