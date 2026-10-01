/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 4.
 * Mirrors backend-api's SocialInboxController JSON shape. Thread `id` is
 * an opaque composite string (see that controller's docblock) — never
 * parsed on the frontend, only round-tripped back to messages()/send().
 */

export type InboxPlatform = 'facebook' | 'instagram' | 'lead';

export const INBOX_PLATFORM_LABELS: Record<InboxPlatform, string> = {
  facebook: 'Facebook',
  instagram: 'Instagram',
  lead: 'Lead Form',
};

export interface InboxThread {
  id: string;
  platform: InboxPlatform;
  participant_name: string;
  snippet: string;
  updated_at: string | null;
  unread: boolean;
}

/**
 * Phase 9 Task 2 — a connected Page / Instagram account whose conversations
 * could not be listed because the connection is expired or revoked
 * (GET /social/inbox/threads `connection_issues`). Safe fields only.
 */
export interface InboxConnectionIssue {
  social_account_id: number;
  asset_type: string;
  name: string | null;
  connection_status: 'expired' | 'revoked';
  message: string;
  reconnect_path: string;
}

export interface InboxThreadList {
  threads: InboxThread[];
  connectionIssues: InboxConnectionIssue[];
}

export interface InboxMessage {
  id: string;
  from_me: boolean;
  text: string;
  created_at: string | null;
}

export interface SendMessagePayload {
  thread_id: string;
  message: string;
}
