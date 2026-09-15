/**
 * Group Messaging Phase 2, extended by the Native WhatsApp Group
 * Re-Architecture — Contact Group Management.
 * Mirrors ContactGroup/ContactGroupMember models and
 * ContactGroupController's JSON shape (see that controller's docblock
 * for the {success, data} envelope this endpoint set uses).
 */

export type ContactGroupType = 'internal_segment' | 'native_wa_group';

export type ContactGroupSyncStatus = 'pending' | 'synced' | 'failed';

export interface ContactGroup {
  id: number;
  account_id: number;
  name: string;
  is_default: boolean;
  /** Added via ContactGroupController::index()'s withCount('members'). */
  members_count: number;
  /** Defaults to 'internal_segment' server-side for every group created before this re-architecture. */
  group_type: ContactGroupType;
  /** Native WhatsApp Group Re-Architecture. Set once CreateNativeWhatsAppGroupJob resolves; null for internal_segment groups and for a native group still pending/failed. */
  wa_group_jid: string | null;
  /** e.g. "https://chat.whatsapp.com/<code>" — null until synced, and may stay null even once synced (groupInviteCode() can fail non-fatally — see sessionManager.js's createGroup()). */
  invite_link: string | null;
  /** null for internal_segment groups (the concept doesn't apply); 'pending' | 'synced' | 'failed' for native_wa_group. */
  sync_status: ContactGroupSyncStatus | null;
  /** Failure reason when sync_status === 'failed'; otherwise null. */
  sync_error: string | null;
  created_at: string;
  updated_at: string;
}

export interface ContactGroupContactInput {
  phone_number: string;
  name?: string;
}

export interface AddContactsResult {
  group_id: number;
  members_count: number;
}

/** Payload accepted by contactGroupsService.create(); group_type/contacts are optional and only meaningful together (native_wa_group requires at least one contact — see ContactGroupController::store()). */
export interface CreateContactGroupPayload {
  name: string;
  group_type?: ContactGroupType;
  contacts?: ContactGroupContactInput[];
}

/** POST /api/groups/{id}/send-template — payload accepted by contactGroupsService.sendTemplate(). */
export interface SendGroupTemplatePayload {
  template_id: number;
  variables: Record<string, string>;
}

/**
 * "Select an existing group" extension — one entry from
 * GET /api/groups/available-native (ContactGroupController::
 * availableNativeGroups()), a REAL WhatsApp group the tenant's connected
 * number already belongs to that hasn't been imported as a ContactGroup
 * yet. Deliberately thinner than ContactGroup itself — this is only ever
 * used to populate a picker, not rendered as a row in the main table.
 */
export interface AvailableNativeGroup {
  jid: string;
  subject: string;
  participants_count: number;
}

/** Payload accepted by contactGroupsService.importNative(); name is optional and defaults server-side to the WhatsApp group's own subject (see ContactGroupController::importNative()). */
export interface ImportNativeGroupPayload {
  group_jid: string;
  name?: string;
}

export interface SendGroupTemplateResponse {
  success: boolean;
  message: string;
  /** Present only when success === true. */
  dispatch_id?: number;
  /** Present only when success === true — 1 for a native_wa_group (one message, one credit), N for an internal_segment (one credit per member). */
  queued_recipients_count?: number;
}
