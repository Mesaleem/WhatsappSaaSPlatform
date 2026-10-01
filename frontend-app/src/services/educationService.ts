import axiosInstance from '../core/api/axiosInstance';
import type {
  AttendanceHistory,
  AttendanceHistoryFilters,
  AttendanceSaveResult,
  AttendanceSheet,
  AttendanceStatus,
  EducationEnvelope,
  EducationGroup,
  EducationPaginated,
  EducationStudent,
  GroupPayload,
  StudentFilters,
  StudentPayload,
  FeeItem,
  FeeItemPayload,
  FeePaymentPage,
  FeePaymentResult,
  AssignFeePayload,
  RecordPaymentPayload,
  StudentFee,
  StudentFeeLedger,
} from '../types/education';

/**
 * Phase 11 Task 2 — thin client over /api/industry/education/*. One method per endpoint, no business
 * rules (duplicates, tenant ownership, permissions, subscription are the server's).
 *
 * Tenant: never sends account_id. A Super Admin's / Agent's selected client is appended by
 * axiosInstance's interceptor (TenantContext → setSelectedAccountId), like every other page.
 */
const BASE = '/industry/education';

const educationService = {
  listStudents(filters: StudentFilters, page = 1, perPage = 20) {
    const params: Record<string, unknown> = { page, per_page: perPage };
    if (filters.search?.trim()) params.search = filters.search.trim();
    if (filters.status) params.status = filters.status;
    if (filters.group_id) params.group_id = filters.group_id;
    return axiosInstance.get<EducationPaginated<EducationStudent>>(`${BASE}/students`, { params }).then((res) => res.data);
  },
  createStudent(payload: StudentPayload) {
    return axiosInstance.post<EducationEnvelope<EducationStudent>>(`${BASE}/students`, payload).then((res) => res.data);
  },
  updateStudent(id: number, payload: Partial<StudentPayload>) {
    return axiosInstance.patch<EducationEnvelope<EducationStudent>>(`${BASE}/students/${id}`, payload).then((res) => res.data);
  },
  listGroups(filters: { kind?: string; status?: string; search?: string } = {}, page = 1, perPage = 100) {
    const params: Record<string, unknown> = { page, per_page: perPage };
    if (filters.kind) params.kind = filters.kind;
    if (filters.status) params.status = filters.status;
    if (filters.search?.trim()) params.search = filters.search.trim();
    return axiosInstance.get<EducationPaginated<EducationGroup>>(`${BASE}/groups`, { params }).then((res) => res.data);
  },
  createGroup(payload: GroupPayload) {
    return axiosInstance.post<EducationEnvelope<EducationGroup>>(`${BASE}/groups`, payload).then((res) => res.data);
  },
  updateGroup(id: number, payload: GroupPayload) {
    return axiosInstance.patch<EducationEnvelope<EducationGroup>>(`${BASE}/groups/${id}`, payload).then((res) => res.data);
  },

  // ---------------------------------------------------------------- attendance (Phase 11 Task 3)
  attendanceSheet(groupId: number, date: string) {
    return axiosInstance.get<{ data: AttendanceSheet }>(`${BASE}/groups/${groupId}/attendance`, { params: { date } }).then((res) => res.data.data);
  },
  /** One request for the whole sheet — transactional and idempotent server-side. */
  saveAttendance(groupId: number, date: string, records: Array<{ student_id: number; status: AttendanceStatus }>) {
    return axiosInstance.put<AttendanceSaveResult>(`${BASE}/groups/${groupId}/attendance`, { date, records }).then((res) => res.data);
  },
  studentAttendance(studentId: number, filters: AttendanceHistoryFilters = {}, page = 1, perPage = 31) {
    const params: Record<string, unknown> = { page, per_page: perPage };
    if (filters.group_id) params.group_id = filters.group_id;
    if (filters.from) params.from = filters.from;
    if (filters.to) params.to = filters.to;
    return axiosInstance.get<AttendanceHistory>(`${BASE}/students/${studentId}/attendance`, { params }).then((res) => res.data);
  },

  // ---------------------------------------------------------------- fees (Phase 11 Task 4)
  listFeeItems(filters: { status?: string; search?: string } = {}, page = 1, perPage = 100) {
    const params: Record<string, unknown> = { page, per_page: perPage };
    if (filters.status) params.status = filters.status;
    if (filters.search?.trim()) params.search = filters.search.trim();
    return axiosInstance.get<EducationPaginated<FeeItem>>(`${BASE}/fee-items`, { params }).then((res) => res.data);
  },
  createFeeItem(payload: FeeItemPayload) {
    return axiosInstance.post<EducationEnvelope<FeeItem>>(`${BASE}/fee-items`, payload).then((res) => res.data);
  },
  updateFeeItem(id: number, payload: Partial<FeeItemPayload>) {
    return axiosInstance.patch<EducationEnvelope<FeeItem>>(`${BASE}/fee-items/${id}`, payload).then((res) => res.data);
  },
  /** The student's charges with server-computed paid / outstanding / status, plus totals. */
  studentFees(studentId: number) {
    return axiosInstance.get<StudentFeeLedger>(`${BASE}/students/${studentId}/fees`).then((res) => res.data);
  },
  assignFee(studentId: number, payload: AssignFeePayload) {
    return axiosInstance.post<EducationEnvelope<StudentFee>>(`${BASE}/students/${studentId}/fees`, payload).then((res) => res.data);
  },
  /** The idempotency key makes a retried submission return the original payment instead of a second one. */
  recordFeePayment(studentId: number, feeId: number, payload: RecordPaymentPayload) {
    return axiosInstance.post<FeePaymentResult>(`${BASE}/students/${studentId}/fees/${feeId}/payments`, payload).then((res) => res.data);
  },
  studentFeePayments(studentId: number, page = 1, perPage = 20) {
    return axiosInstance.get<FeePaymentPage>(`${BASE}/students/${studentId}/fee-payments`, { params: { page, per_page: perPage } }).then((res) => res.data);
  },
};

export default educationService;
