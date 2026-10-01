/**
 * Phase 11 Task 2 — Education foundation, mirroring /api/industry/education/*.
 * A student is a profile on an existing CRM contact; parents/guardians are CRM contacts too.
 */

export const STUDENT_STATUSES = ['active', 'inactive', 'graduated', 'withdrawn'] as const;
export type StudentStatus = (typeof STUDENT_STATUSES)[number];

export const GUARDIAN_RELATIONSHIPS = ['parent', 'guardian'] as const;
export type GuardianRelationship = (typeof GUARDIAN_RELATIONSHIPS)[number];

export const GROUP_KINDS = ['class', 'batch'] as const;
export type GroupKind = (typeof GROUP_KINDS)[number];

export const GROUP_STATUSES = ['active', 'archived'] as const;
export type GroupStatus = (typeof GROUP_STATUSES)[number];

export interface EducationContact {
  id: number;
  name: string | null;
  phone_number: string;
  email: string | null;
}

export interface EducationGuardianLink {
  id: number;
  relationship: GuardianRelationship;
  contact: EducationContact | null;
}

export interface EducationGroupRef {
  id: number;
  name: string;
  kind: GroupKind;
  academic_year: string | null;
  status: GroupStatus;
}

export interface EducationStudent {
  id: number;
  contact: EducationContact | null;
  admission_number: string | null;
  status: StudentStatus;
  admission_date: string | null;
  metadata: Record<string, unknown> | null;
  guardians: EducationGuardianLink[];
  groups: EducationGroupRef[];
  created_at: string;
  updated_at: string;
}

export interface EducationGroup extends EducationGroupRef {
  metadata: Record<string, unknown> | null;
  students_count: number;
  created_at: string;
  updated_at: string;
}

export interface EducationPaginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface EducationEnvelope<T> {
  message: string;
  data: T;
}

export interface StudentFilters {
  search?: string;
  status?: StudentStatus | '';
  group_id?: number | null;
}

export interface StudentPayload {
  contact_id?: number;
  phone_number?: string;
  name?: string | null;
  email?: string | null;
  admission_number?: string | null;
  admission_date?: string | null;
  status?: StudentStatus;
  group_ids?: number[];
  guardians?: Array<{
    contact_id?: number;
    phone_number?: string;
    name?: string | null;
    relationship: GuardianRelationship;
  }>;
}

export interface GroupPayload {
  name?: string;
  kind?: GroupKind;
  academic_year?: string | null;
  status?: GroupStatus;
}

/** One entry of GET /api/industry/context — what the industry navigation is derived from. */
export interface IndustryContextModule {
  key: string;
  label: string;
  allowed: boolean;
}
export interface IndustryContext {
  industry: string;
  label: string;
  subtype: string | null;
  subtype_label: string | null;
  allowed: boolean;
  modules: IndustryContextModule[];
}

// ---------------------------------------------------------------- attendance (Phase 11 Task 3)

/** Add a status here when the backend's EducationAttendance::STATUSES grows. */
export const ATTENDANCE_STATUSES = ['present', 'absent', 'late'] as const;
export type AttendanceStatus = (typeof ATTENDANCE_STATUSES)[number];

export interface AttendanceSummary {
  present: number;
  absent: number;
  late: number;
  /** null = no records yet (shown as N/A, never 0%). Present + late count as attended. */
  attendance_percentage: number | null;
}

export interface AttendanceSheetStudent {
  student_id: number;
  name: string | null;
  phone_number: string | null;
  admission_number: string | null;
  student_status: StudentStatus;
  status: AttendanceStatus | null;
}

export interface AttendanceSheet {
  date: string;
  group: EducationGroupRef;
  students: AttendanceSheetStudent[];
  summary: AttendanceSummary & { total_students: number; marked: number; unmarked: number };
}

export interface AttendanceSaveResult {
  message: string;
  created: number;
  updated: number;
  unchanged: number;
  data: AttendanceSheet;
}

export interface AttendanceHistoryRow {
  id: number;
  attendance_date: string;
  status: AttendanceStatus;
  group: EducationGroupRef | null;
}

export interface AttendanceHistory extends EducationPaginated<AttendanceHistoryRow> {
  student: { id: number; name: string | null; phone_number: string | null; admission_number: string | null };
  summary: AttendanceSummary & { total: number };
}

export interface AttendanceHistoryFilters {
  group_id?: number | null;
  from?: string;
  to?: string;
}

// ---------------------------------------------------------------- fees (Phase 11 Task 4)
// Education's adapter over the generic Billing & Collections core. Money is always a decimal STRING
// ("1500.00") from the server — never parsed into a float on the client.

export const FEE_FREQUENCIES = ['one_time', 'monthly', 'quarterly', 'half_yearly', 'yearly'] as const;
export type FeeFrequency = (typeof FEE_FREQUENCIES)[number];

export const FEE_ITEM_STATUSES = ['active', 'archived'] as const;
export type FeeItemStatus = (typeof FEE_ITEM_STATUSES)[number];

export const PAYMENT_METHODS = ['cash', 'upi', 'card', 'bank_transfer', 'cheque', 'other'] as const;
export type PaymentMethod = (typeof PAYMENT_METHODS)[number];

export type StudentFeeStatus = 'open' | 'partially_paid' | 'paid' | 'overdue' | 'cancelled';

export interface FeeItem {
  id: number;
  name: string;
  description: string | null;
  amount: string;
  frequency: FeeFrequency;
  status: FeeItemStatus;
  created_at: string;
  updated_at: string;
}

export interface FeeItemPayload {
  name: string;
  description: string | null;
  amount: string;
  frequency: FeeFrequency;
  status: FeeItemStatus;
}

export interface StudentFee {
  id: number;
  contact_id: number;
  charge_item_id: number;
  charge_name: string | null;
  charge_status: FeeItemStatus | null;
  amount_due: string;
  amount_paid: string;
  outstanding: string;
  due_date: string | null;
  status: StudentFeeStatus;
  /** Phase 11 Task 5 — set only while the charge is cancelled; a cancelled charge owes nothing. */
  cancelled_at?: string | null;
  cancellation_reason?: string | null;
  created_at: string;
}

export interface StudentFeeSummary {
  total_due: string;
  total_paid: string;
  outstanding: string;
  overdue: string;
  count: number;
}

export interface StudentFeeLedger {
  data: StudentFee[];
  summary: StudentFeeSummary;
  student: { id: number; name: string | null; phone_number: string | null; admission_number: string | null };
}

export interface FeePayment {
  id: number;
  charge_assignment_id: number;
  contact_name: string | null;
  charge_name: string | null;
  amount: string;
  payment_date: string;
  payment_method: PaymentMethod | null;
  reference: string | null;
  created_at: string;
}

export interface FeePaymentPage {
  data: FeePayment[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface FeePaymentResult {
  message: string;
  idempotent: boolean;
  payment: FeePayment;
  data: StudentFee;
}

export interface AssignFeePayload {
  charge_item_id: number;
  amount_due?: string;
  due_date?: string | null;
}

export interface RecordPaymentPayload {
  amount: string;
  payment_date: string;
  payment_method: PaymentMethod | null;
  reference: string | null;
  idempotency_key: string;
}
