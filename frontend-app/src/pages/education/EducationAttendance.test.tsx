import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import EducationPage from './EducationPage';
import educationService from '../../services/educationService';
import { apiError, makeGroup, makeHistory, makeSheet, makeSheetStudent, makeStudent, paginated } from '../../test/educationFixtures';

/**
 * Phase 11 Task 3 — attendance UI: select class/batch + date, mark Present/Absent/Late, one save for the
 * whole sheet, states, history, read-only, client switching, stale responses.
 */

vi.mock('../../services/educationService', () => ({
  default: {
    listStudents: vi.fn(), createStudent: vi.fn(), updateStudent: vi.fn(),
    listGroups: vi.fn(), createGroup: vi.fn(), updateGroup: vi.fn(),
    attendanceSheet: vi.fn(), saveAttendance: vi.fn(), studentAttendance: vi.fn(),
  },
}));
const industry = { context: vi.fn() };
vi.mock('../../services/industryService', () => ({ default: { context: (...a: unknown[]) => industry.context(...a) } }));

const ALL_KEYS = ['education.students', 'education.batches', 'education.attendance'];
const auth = { superAdmin: false, readOnly: false, permissions: ['view-education', 'manage-education'], keys: ALL_KEYS };
vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({
    isSuperAdmin: () => auth.superAdmin,
    isReadOnly: () => auth.readOnly,
    hasPermission: (p: string) => auth.permissions.includes(p),
    user: { platform_crm_account: null, industry_modules: auth.keys },
  }),
}));
const tenant: { selectedAccountId: number | null } = { selectedAccountId: null };
vi.mock('../../core/context/TenantContext', () => ({
  useTenant: () => ({ selectedAccountId: tenant.selectedAccountId, accounts: [], selectAccount: vi.fn() }),
}));

const edu = educationService as unknown as Record<string, Mock>;
const DATE = '2026-09-14';

const class5 = makeGroup({ id: 5, name: 'Class 5' });
const old = makeGroup({ id: 7, name: 'Class 4', status: 'archived' });
const asha = makeSheetStudent({ student_id: 1, name: 'Asha' });
const bala = makeSheetStudent({ student_id: 2, name: 'Bala', status: 'absent' });
const chitra = makeSheetStudent({ student_id: 3, name: 'Chitra' });

function tree() {
  return (
    <MemoryRouter initialEntries={['/education?tab=attendance']}>
      <Routes>
        <Route path="/education" element={<EducationPage />} />
      </Routes>
    </MemoryRouter>
  );
}

/** Open the attendance tab, pick Class 5 and the date. */
async function openSheet(group = '5', date = DATE) {
  const view = render(tree());
  await waitFor(() => expect(screen.getByLabelText(/class \/ batch/i)).toBeTruthy());
  await screen.findByRole('option', { name: /Class 5/ });
  fireEvent.change(screen.getByLabelText('Date'), { target: { value: date } });
  fireEvent.change(screen.getByLabelText(/class \/ batch/i), { target: { value: group } });
  return view;
}
const radio = (row: HTMLElement, name: string) => within(row).getByRole('radio', { name });

beforeEach(() => {
  auth.superAdmin = false;
  auth.readOnly = false;
  auth.permissions = ['view-education', 'manage-education'];
  auth.keys = ALL_KEYS;
  tenant.selectedAccountId = null;
  Object.values(edu).forEach((m) => m.mockReset());
  industry.context.mockReset();
  edu.listStudents.mockResolvedValue(paginated([makeStudent({ id: 1, contact: { id: 1, name: 'Asha', phone_number: '919800000001', email: null } })]));
  edu.listGroups.mockResolvedValue(paginated([class5, old]));
  edu.attendanceSheet.mockResolvedValue(makeSheet([asha, bala, chitra]));
  edu.saveAttendance.mockImplementation(async () => ({ message: 'Attendance saved.', created: 2, updated: 0, unchanged: 1, data: makeSheet([{ ...asha, status: 'present' }, bala, { ...chitra, status: 'late' }]) }));
});

describe('attendance tabs', () => {
  it('shows the Attendance tabs only when the module is usable', async () => {
    auth.keys = ['education.students', 'education.batches'];
    render(tree());
    await screen.findByTestId('students-panel');

    expect(screen.queryByRole('tab', { name: 'Attendance' })).toBeNull();
    expect(screen.queryByTestId('attendance-panel')).toBeNull(); // ?tab=attendance falls back to Students
  });

  it('shows them, and opens on the requested tab', async () => {
    render(tree());
    expect(await screen.findByTestId('attendance-panel')).toBeTruthy();
    expect(screen.getByRole('tab', { name: 'Attendance history' })).toBeTruthy();
  });
});

describe('marking attendance', () => {
  it('starts with an empty state and makes no sheet request until a class and date are chosen', async () => {
    render(tree());

    expect(await screen.findByText('Select a class or batch and a date.')).toBeTruthy();
    expect(edu.attendanceSheet).not.toHaveBeenCalled();
  });

  it('loads the enrolled students for the chosen class and date, with their saved marks and a summary', async () => {
    await openSheet();

    const row = await screen.findByTestId('attendance-row-2');
    expect(edu.attendanceSheet).toHaveBeenLastCalledWith(5, DATE);
    expect(within(screen.getByTestId('attendance-row-1')).getByText('Asha')).toBeTruthy();
    expect(radio(row, 'Absent').getAttribute('aria-checked')).toBe('true');
    expect(radio(screen.getByTestId('attendance-row-1'), 'Present').getAttribute('aria-checked')).toBe('false');
    const summary = within(screen.getByTestId('attendance-summary'));
    expect(summary.getByText('Unmarked').nextSibling?.textContent).toBe('2');
    expect(summary.getByText('Attendance').nextSibling?.textContent).toBe('0%'); // 1 marked, absent: real data, not N/A
  });

  it('shows N/A (not 0%) when nothing has been marked', async () => {
    edu.attendanceSheet.mockResolvedValue(makeSheet([asha, chitra]));
    await openSheet();

    await screen.findByTestId('attendance-row-1');
    expect(within(screen.getByTestId('attendance-summary')).getByText('Attendance').nextSibling?.textContent).toBe('N/A');
  });

  it('saves the whole sheet in ONE request, then shows the saved sheet', async () => {
    const user = userEvent.setup();
    await openSheet();
    await screen.findByTestId('attendance-row-1');
    expect((screen.getByRole('button', { name: 'Save attendance' }) as HTMLButtonElement).disabled).toBe(true);

    await user.click(radio(screen.getByTestId('attendance-row-1'), 'Present'));
    await user.click(radio(screen.getByTestId('attendance-row-3'), 'Late'));
    await user.click(screen.getByRole('button', { name: 'Save attendance' }));

    await waitFor(() => expect(edu.saveAttendance).toHaveBeenCalledTimes(1));
    expect(edu.saveAttendance).toHaveBeenCalledWith(5, DATE, [
      { student_id: 1, status: 'present' }, { student_id: 2, status: 'absent' }, { student_id: 3, status: 'late' },
    ]);
    expect(await screen.findByText('Attendance saved.')).toBeTruthy();
    expect(radio(screen.getByTestId('attendance-row-3'), 'Late').getAttribute('aria-checked')).toBe('true');
    expect((screen.getByRole('button', { name: 'Save attendance' }) as HTMLButtonElement).disabled).toBe(true); // nothing unsaved now
  });

  it('marks everyone present in one click', async () => {
    const user = userEvent.setup();
    await openSheet();
    await screen.findByTestId('attendance-row-1');

    await user.click(screen.getByRole('button', { name: 'Mark all present' }));
    await user.click(screen.getByRole('button', { name: 'Save attendance' }));

    await waitFor(() => expect(edu.saveAttendance).toHaveBeenCalledWith(5, DATE, [
      { student_id: 1, status: 'present' }, { student_id: 2, status: 'present' }, { student_id: 3, status: 'present' },
    ]));
  });

  it('cannot be submitted twice while a save is in flight', async () => {
    const user = userEvent.setup();
    let resolve!: (v: unknown) => void;
    edu.saveAttendance.mockReturnValue(new Promise((r) => { resolve = r; }));
    await openSheet();
    await screen.findByTestId('attendance-row-1');
    await user.click(radio(screen.getByTestId('attendance-row-1'), 'Present'));

    const button = screen.getByRole('button', { name: 'Save attendance' }) as HTMLButtonElement;
    await user.click(button);
    await user.click(button);
    fireEvent.click(button);

    expect(edu.saveAttendance).toHaveBeenCalledTimes(1);
    expect(button.disabled).toBe(true);
    expect((radio(screen.getByTestId('attendance-row-1'), 'Late') as HTMLButtonElement).disabled).toBe(true);

    await act(async () => resolve({ message: 'Attendance saved.', created: 1, updated: 0, unchanged: 0, data: makeSheet([{ ...asha, status: 'present' }, bala, chitra]) }));
    expect(await screen.findByText('Attendance saved.')).toBeTruthy();
  });

  it('shows a save error and keeps the marks so nothing is lost', async () => {
    const user = userEvent.setup();
    edu.saveAttendance.mockRejectedValue(apiError(500));
    await openSheet();
    await screen.findByTestId('attendance-row-1');
    await user.click(radio(screen.getByTestId('attendance-row-1'), 'Present'));
    await user.click(screen.getByRole('button', { name: 'Save attendance' }));

    expect(await screen.findByRole('alert')).toBeTruthy();
    expect(radio(screen.getByTestId('attendance-row-1'), 'Present').getAttribute('aria-checked')).toBe('true');
    expect((screen.getByRole('button', { name: 'Save attendance' }) as HTMLButtonElement).disabled).toBe(false); // can retry
  });

  it('puts a server validation message on the offending student', async () => {
    const user = userEvent.setup();
    edu.saveAttendance.mockRejectedValue(apiError(422, { message: 'x', errors: { 'records.2.student_id': ['This student is not a member of the selected class/batch.'] } }));
    await openSheet();
    await screen.findByTestId('attendance-row-1');
    await user.click(radio(screen.getByTestId('attendance-row-1'), 'Present'));
    await user.click(radio(screen.getByTestId('attendance-row-3'), 'Late'));
    await user.click(screen.getByRole('button', { name: 'Save attendance' }));

    // records[2] is the third marked student (Chitra)
    expect(await within(screen.getByTestId('attendance-row-3')).findByText(/not a member of the selected class/i)).toBeTruthy();
    expect(within(screen.getByTestId('attendance-row-1')).queryByText(/not a member/i)).toBeNull();
  });

  it('shows a loading state, then an empty state for a class with no students', async () => {
    let resolve!: (v: unknown) => void;
    edu.attendanceSheet.mockReturnValue(new Promise((r) => { resolve = r; }));
    await openSheet();

    await waitFor(() => expect(document.querySelectorAll('tr.animate-pulse').length).toBeGreaterThan(0));
    await act(async () => resolve(makeSheet([])));
    expect(await screen.findByText('No students are enrolled in this class/batch yet.')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Save attendance' })).toBeNull();
  });

  it('shows an API error when the sheet cannot be loaded', async () => {
    edu.attendanceSheet.mockRejectedValue(apiError(500));
    await openSheet();

    expect(await screen.findByRole('alert')).toBeTruthy();
    expect(screen.queryByTestId('attendance-row-1')).toBeNull();
  });

  it('rejects an invalid date without asking the server', async () => {
    await openSheet('5', '');

    expect(await screen.findByText('Choose a valid date.')).toBeTruthy();
    expect(edu.attendanceSheet).not.toHaveBeenCalled();
  });
});

describe('archived group, permissions, expired subscription', () => {
  it('an archived group is shown read-only with a notice and no save', async () => {
    edu.attendanceSheet.mockResolvedValue(makeSheet([{ ...asha, status: 'present' }], { id: 7, name: 'Class 4', status: 'archived' }));
    const view = render(tree());
    await screen.findByRole('option', { name: /Class 4/ });
    fireEvent.change(screen.getByLabelText('Date'), { target: { value: DATE } });
    fireEvent.change(screen.getByLabelText(/class \/ batch/i), { target: { value: '7' } });

    expect(await screen.findByTestId('attendance-archived')).toBeTruthy();
    expect((radio(screen.getByTestId('attendance-row-1'), 'Late') as HTMLButtonElement).disabled).toBe(true);
    expect(radio(screen.getByTestId('attendance-row-1'), 'Present').getAttribute('aria-checked')).toBe('true'); // history readable
    expect(screen.queryByRole('button', { name: 'Save attendance' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Mark all present' })).toBeNull();
    view.unmount();
  });

  it('an expired subscription lets you look but not mark or save', async () => {
    auth.readOnly = true;
    await openSheet();
    await screen.findByTestId('attendance-row-1');

    expect((radio(screen.getByTestId('attendance-row-1'), 'Present') as HTMLButtonElement).disabled).toBe(true);
    expect((screen.getByRole('button', { name: 'Save attendance' }) as HTMLButtonElement).disabled).toBe(true);
    expect((screen.getByRole('button', { name: 'Mark all present' }) as HTMLButtonElement).disabled).toBe(true);
    expect(edu.saveAttendance).not.toHaveBeenCalled();
  });

  it('without manage-education the sheet is view-only', async () => {
    auth.permissions = ['view-education'];
    await openSheet();
    await screen.findByTestId('attendance-row-1');

    expect((radio(screen.getByTestId('attendance-row-1'), 'Present') as HTMLButtonElement).disabled).toBe(true);
    expect(screen.queryByRole('button', { name: 'Save attendance' })).toBeNull();
  });
});

describe('client switching and stale responses', () => {
  it('a late sheet for the previous date never replaces the one now selected', async () => {
    let resolveFirst!: (v: unknown) => void;
    edu.attendanceSheet
      .mockReturnValueOnce(new Promise((r) => { resolveFirst = r; }))
      .mockResolvedValue(makeSheet([{ ...asha, status: 'present' }], {}, '2026-09-15'));
    await openSheet('5', DATE);
    await waitFor(() => expect(edu.attendanceSheet).toHaveBeenCalledTimes(1));

    fireEvent.change(screen.getByLabelText('Date'), { target: { value: '2026-09-15' } });
    await screen.findByTestId('attendance-row-1');
    expect(screen.queryByTestId('attendance-row-2')).toBeNull();

    await act(async () => resolveFirst(makeSheet([asha, bala, chitra])));
    expect(screen.queryByTestId('attendance-row-2')).toBeNull();
    expect(screen.getByTestId('attendance-row-1')).toBeTruthy();
  });

  it('switching client clears the class selection and re-reads the class list', async () => {
    auth.superAdmin = true;
    tenant.selectedAccountId = 1;
    industry.context.mockResolvedValue([{ industry: 'education', label: 'Education', subtype: 'school', subtype_label: 'School', allowed: true, modules: [{ key: 'students', label: 'S', allowed: true }, { key: 'attendance', label: 'A', allowed: true }] }]);
    const view = await openSheet();
    await screen.findByTestId('attendance-row-1');
    expect(edu.listGroups).toHaveBeenCalledTimes(1);

    tenant.selectedAccountId = 2;
    view.rerender(tree());

    await waitFor(() => expect(edu.listGroups).toHaveBeenCalledTimes(2));
    await waitFor(() => expect((screen.getByLabelText(/class \/ batch/i) as HTMLSelectElement).value).toBe(''));
    expect(screen.queryByTestId('attendance-row-1')).toBeNull();
    expect(edu.attendanceSheet).toHaveBeenCalledTimes(1);
  });

  it('a save that finishes after the user moved to another date does not overwrite the new sheet', async () => {
    const user = userEvent.setup();
    let resolveSave!: (v: unknown) => void;
    edu.saveAttendance.mockReturnValue(new Promise((r) => { resolveSave = r; }));
    await openSheet('5', DATE);
    await screen.findByTestId('attendance-row-1');
    await user.click(radio(screen.getByTestId('attendance-row-1'), 'Present'));
    await user.click(screen.getByRole('button', { name: 'Save attendance' }));

    edu.attendanceSheet.mockResolvedValue(makeSheet([chitra], {}, '2026-09-15'));
    fireEvent.change(screen.getByLabelText('Date'), { target: { value: '2026-09-15' } });
    await screen.findByTestId('attendance-row-3');

    await act(async () => resolveSave({ message: 'Attendance saved.', created: 1, updated: 0, unchanged: 0, data: makeSheet([{ ...asha, status: 'present' }, bala, chitra]) }));
    expect(screen.getByTestId('attendance-row-3')).toBeTruthy();
    expect(screen.queryByTestId('attendance-row-1')).toBeNull();
  });
});

describe('attendance history', () => {
  const rows = [
    { id: 1, attendance_date: '2026-09-13', status: 'present' as const, group: { id: 5, name: 'Class 5', kind: 'class' as const, academic_year: '2026-27', status: 'active' as const } },
    { id: 2, attendance_date: '2026-09-12', status: 'absent' as const, group: { id: 5, name: 'Class 5', kind: 'class' as const, academic_year: '2026-27', status: 'active' as const } },
    { id: 3, attendance_date: '2026-09-11', status: 'late' as const, group: null },
  ];

  function historyTree() {
    return (
      <MemoryRouter initialEntries={['/education?tab=history']}>
        <Routes>
          <Route path="/education" element={<EducationPage />} />
        </Routes>
      </MemoryRouter>
    );
  }
  async function pickStudent() {
    render(historyTree());
    await screen.findByRole('option', { name: 'Asha' });
    fireEvent.change(screen.getByLabelText('Student'), { target: { value: '1' } });
  }

  it('asks for a student first, then shows totals and the rows', async () => {
    edu.studentAttendance.mockResolvedValue(makeHistory(rows));
    render(historyTree());
    expect(await screen.findByText('Select a student to see their attendance.')).toBeTruthy();
    expect(edu.studentAttendance).not.toHaveBeenCalled();

    fireEvent.change(await screen.findByLabelText('Student'), { target: { value: '' } });
    await screen.findByRole('option', { name: 'Asha' });
    fireEvent.change(screen.getByLabelText('Student'), { target: { value: '1' } });

    const totals = within(await screen.findByTestId('history-totals'));
    expect(totals.getByText('Present').nextSibling?.textContent).toBe('1');
    expect(totals.getByText('Absent').nextSibling?.textContent).toBe('1');
    expect(totals.getByText('Late').nextSibling?.textContent).toBe('1');
    expect(totals.getByText('Total days').nextSibling?.textContent).toBe('3');
    expect(totals.getByText('Attendance').nextSibling?.textContent).toBe('66.7%');
    expect(within(screen.getByTestId('history-row-2')).getByText('Absent')).toBeTruthy();
    expect(within(screen.getByTestId('history-row-2')).getByText('2026-09-12')).toBeTruthy();
  });

  it('filters by class/batch and by date on the server', async () => {
    edu.studentAttendance.mockResolvedValue(makeHistory(rows));
    await pickStudent();
    await screen.findByTestId('history-totals');

    fireEvent.change(screen.getByLabelText(/class \/ batch/i), { target: { value: '5' } });
    await waitFor(() => expect(edu.studentAttendance).toHaveBeenLastCalledWith(1, { group_id: 5, from: '', to: '' }, 1));
    fireEvent.change(screen.getByLabelText('From'), { target: { value: '2026-09-01' } });
    fireEvent.change(screen.getByLabelText('To'), { target: { value: '2026-09-30' } });
    await waitFor(() => expect(edu.studentAttendance).toHaveBeenLastCalledWith(1, { group_id: 5, from: '2026-09-01', to: '2026-09-30' }, 1));
  });

  it('shows N/A for a student with no records, and an empty state', async () => {
    edu.studentAttendance.mockResolvedValue(makeHistory([]));
    await pickStudent();

    expect(await screen.findByText('No attendance recorded for these filters.')).toBeTruthy();
    expect(within(screen.getByTestId('history-totals')).getByText('Attendance').nextSibling?.textContent).toBe('N/A');
  });

  it('shows an API error', async () => {
    edu.studentAttendance.mockRejectedValue(apiError(500));
    await pickStudent();

    expect(await screen.findByRole('alert')).toBeTruthy();
  });

  it('forgets the selected student when the client changes', async () => {
    auth.superAdmin = true;
    tenant.selectedAccountId = 1;
    industry.context.mockResolvedValue([{ industry: 'education', label: 'Education', subtype: null, subtype_label: null, allowed: true, modules: [{ key: 'students', label: 'S', allowed: true }, { key: 'attendance', label: 'A', allowed: true }] }]);
    edu.studentAttendance.mockResolvedValue(makeHistory(rows));
    const view = render(historyTree());
    await screen.findByRole('option', { name: 'Asha' });
    fireEvent.change(screen.getByLabelText('Student'), { target: { value: '1' } });
    await screen.findByTestId('history-totals');
    const calls = edu.studentAttendance.mock.calls.length;

    tenant.selectedAccountId = 2;
    view.rerender(historyTree());

    expect(await screen.findByText('Select a student to see their attendance.')).toBeTruthy();
    expect(edu.studentAttendance.mock.calls.length).toBe(calls);
  });
});
