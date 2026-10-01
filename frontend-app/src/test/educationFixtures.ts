import type { EducationGroup, EducationPaginated, EducationStudent } from '../types/education';

/** Test-only fixtures shaped like the Education API responses (EducationStudentController::present etc.). */

export function paginated<T>(data: T[], extra: Partial<EducationPaginated<T>> = {}): EducationPaginated<T> {
  return { data, current_page: 1, last_page: 1, per_page: 20, total: data.length, ...extra };
}

export function makeStudent(overrides: Partial<EducationStudent> = {}): EducationStudent {
  const id = overrides.id ?? 1;
  return {
    id,
    contact: { id: 100 + id, name: `Student ${id}`, phone_number: `91980000000${id}`, email: null },
    admission_number: null,
    status: 'active',
    admission_date: null,
    metadata: null,
    guardians: [],
    groups: [],
    created_at: '2026-10-01T10:00:00+00:00',
    updated_at: '2026-10-01T10:00:00+00:00',
    ...overrides,
  };
}

export function makeGroup(overrides: Partial<EducationGroup> = {}): EducationGroup {
  const id = overrides.id ?? 1;
  return {
    id,
    kind: 'class',
    name: `Class ${id}`,
    academic_year: '2026-27',
    status: 'active',
    metadata: null,
    students_count: 0,
    created_at: '2026-10-01T10:00:00+00:00',
    updated_at: '2026-10-01T10:00:00+00:00',
    ...overrides,
  };
}

/** An axios-shaped rejection, as describeApiError reads it. */
export function apiError(status: number, body: Record<string, unknown> = {}) {
  return { isAxiosError: true, response: { status, data: body }, message: 'Request failed' };
}

import type { AttendanceHistory, AttendanceSheet, AttendanceSheetStudent, FeeItem, FeePayment, StudentFee, StudentFeeLedger } from '../types/education';

export function makeSheetStudent(overrides: Partial<AttendanceSheetStudent> = {}): AttendanceSheetStudent {
  const id = overrides.student_id ?? 1;
  return { student_id: id, name: `Student ${id}`, phone_number: `91980000000${id}`, admission_number: null, student_status: 'active', status: null, ...overrides };
}

export function makeSheet(students: AttendanceSheetStudent[], group: Partial<AttendanceSheet['group']> = {}, date = '2026-09-14'): AttendanceSheet {
  const count = (s: string) => students.filter((x) => x.status === s).length;
  const marked = students.filter((x) => x.status !== null).length;
  const attended = count('present') + count('late');
  return {
    date,
    group: { id: 5, name: 'Class 5', kind: 'class', academic_year: '2026-27', status: 'active', ...group },
    students,
    summary: {
      total_students: students.length, marked, unmarked: students.length - marked,
      present: count('present'), absent: count('absent'), late: count('late'),
      attendance_percentage: marked === 0 ? null : Math.round((attended / marked) * 1000) / 10,
    },
  };
}

export function makeHistory(rows: AttendanceHistory['data'], overrides: Partial<AttendanceHistory> = {}): AttendanceHistory {
  const count = (s: string) => rows.filter((r) => r.status === s).length;
  const attended = count('present') + count('late');
  return {
    student: { id: 1, name: 'Asha', phone_number: '919800000001', admission_number: null },
    summary: { total: rows.length, present: count('present'), absent: count('absent'), late: count('late'), attendance_percentage: rows.length === 0 ? null : Math.round((attended / rows.length) * 1000) / 10 },
    data: rows, current_page: 1, last_page: 1, per_page: 31, total: rows.length,
    ...overrides,
  };
}

export function makeFeeItem(overrides: Partial<FeeItem> = {}): FeeItem {
  const id = overrides.id ?? 1;
  return {
    id, name: `Fee ${id}`, description: null, amount: '5000.00', frequency: 'one_time', status: 'active',
    created_at: '2026-10-01T10:00:00+00:00', updated_at: '2026-10-01T10:00:00+00:00', ...overrides,
  };
}

export function makeStudentFee(overrides: Partial<StudentFee> = {}): StudentFee {
  const id = overrides.id ?? 1;
  return {
    id, contact_id: 101, charge_item_id: 1, charge_name: `Fee ${id}`, charge_status: 'active',
    amount_due: '5000.00', amount_paid: '0.00', outstanding: '5000.00', due_date: '2026-12-01', status: 'open',
    created_at: '2026-10-01T10:00:00+00:00', ...overrides,
  };
}

export function makeLedger(fees: StudentFee[], summary: Partial<StudentFeeLedger['summary']> = {}): StudentFeeLedger {
  return {
    data: fees,
    summary: { total_due: '5000.00', total_paid: '0.00', outstanding: '5000.00', overdue: '0.00', count: fees.length, ...summary },
    student: { id: 1, name: 'Student 1', phone_number: '919800000001', admission_number: null },
  };
}

export function makeFeePayment(overrides: Partial<FeePayment> = {}): FeePayment {
  const id = overrides.id ?? 1;
  return {
    id, charge_assignment_id: 1, contact_name: 'Student 1', charge_name: 'Fee 1', amount: '1000.00', payment_date: '2026-10-01',
    payment_method: 'cash', reference: null, created_at: '2026-10-01T10:00:00+00:00', ...overrides,
  };
}
