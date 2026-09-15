import axiosInstance from '../core/api/axiosInstance';
import type {
  AddContactsResult,
  AvailableNativeGroup,
  ContactGroup,
  ContactGroupContactInput,
  CreateContactGroupPayload,
  ImportNativeGroupPayload,
  SendGroupTemplatePayload,
  SendGroupTemplateResponse,
} from '../types/contactGroup';

/**
 * Group Messaging Phase 2, extended by the Native WhatsApp Group
 * Re-Architecture — ContactGroupController's client. Every response is
 * wrapped in {success, data} server-side; these calls unwrap to just the
 * payload, same as chatbotService/messageLogsService already do for
 * their own controllers.
 */
const contactGroupsService = {
  list() {
    return axiosInstance
      .get<{ success: boolean; data: ContactGroup[] }>('/groups')
      .then((res) => res.data.data);
  },

  /**
   * Native WhatsApp Group Re-Architecture: `payload` now supports
   * `group_type`/`contacts` (both optional — omitting them keeps the
   * original internal_segment, name-only behavior). Kept as one method
   * (not create() + createNative()) so callers who don't care about
   * native groups keep working unchanged; ContactGroupsPage's create
   * modal is the only caller that ever passes the extra fields.
   */
  create(payload: CreateContactGroupPayload) {
    return axiosInstance
      .post<{ success: boolean; data: ContactGroup }>('/groups/create', payload)
      .then((res) => res.data.data);
  },

  /**
   * GET /api/groups/available-native — "select an existing group"
   * extension. Lists the tenant's real WhatsApp groups not yet imported.
   * Throws (doesn't unwrap error_code) the same way create() does for a
   * disconnected session/non-qr engine — ContactGroupsPage.tsx's caller
   * catches and renders the message, same pattern as storeNativeGroup's
   * existing 422s.
   */
  availableNative() {
    return axiosInstance
      .get<{ success: boolean; data: AvailableNativeGroup[] }>('/groups/available-native')
      .then((res) => res.data.data);
  },

  /**
   * POST /api/groups/import-native — "select an existing group"
   * extension. Adopts one of availableNative()'s groups as a
   * ContactGroup, importing its real current member list server-side.
   */
  importNative(payload: ImportNativeGroupPayload) {
    return axiosInstance
      .post<{ success: boolean; data: ContactGroup }>('/groups/import-native', payload)
      .then((res) => res.data.data);
  },

  addContacts(groupId: number, contacts: ContactGroupContactInput[]) {
    return axiosInstance
      .post<{ success: boolean; data: AddContactsResult }>('/groups/add-contacts', {
        group_id: groupId,
        contacts,
      })
      .then((res) => res.data.data);
  },

  remove(id: number) {
    return axiosInstance.delete<{ success: boolean }>(`/groups/${id}`).then((res) => res.data);
  },

  /**
   * POST /api/groups/{id}/send-template — the internal, session-authenticated
   * counterpart to the external Developer API's POST /api/v1/send-message
   * (recipient_type: "group"). Sends ONE template to ONE group; a caller
   * sending to multiple groups calls this once per group id (see
   * SendAlertPage.tsx's group-recipient submit handler).
   */
  sendTemplate(groupId: number, payload: SendGroupTemplatePayload) {
    return axiosInstance
      .post<SendGroupTemplateResponse>(`/groups/${groupId}/send-template`, payload)
      .then((res) => res.data);
  },

  /**
   * POST /api/groups/{id}/recreate — only valid for a native_wa_group
   * whose sync_status is 'failed'. See ContactGroupController::
   * recreate()'s docblock for the disclosed duplicate-group risk: this
   * ALWAYS makes a brand-new WhatsApp group, it can never reuse or
   * verify the old one — ContactGroupsPage.tsx's confirm() prompt
   * surfaces that before calling this.
   */
  recreate(groupId: number) {
    return axiosInstance
      .post<{ success: boolean; data: ContactGroup; message?: string }>(`/groups/${groupId}/recreate`)
      .then((res) => res.data);
  },
};

export default contactGroupsService;
