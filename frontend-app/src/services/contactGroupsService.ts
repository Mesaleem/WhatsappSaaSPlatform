import axiosInstance from '../core/api/axiosInstance';
import type {
  AddContactsResult,
  ContactGroup,
  ContactGroupContactInput,
  CreateContactGroupPayload,
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
