import axiosInstance from '../core/api/axiosInstance';
import type {
  AddContactsResult,
  ContactGroup,
  ContactGroupContactInput,
  CreateContactGroupPayload,
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
};

export default contactGroupsService;
