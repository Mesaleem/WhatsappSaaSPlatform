import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import EducationPage from './EducationPage';
import educationService from '../../services/educationService';
import industryService from '../../services/industryService';
import { apiError, makeGroup, makeStudent, paginated } from '../../test/educationFixtures';

/**
 * Phase 11 Task 2 — the Education landing page: Students and Classes / Batches, loading / empty / error
 * states, create + edit validation, read-only and permission gating, client switching.
 */

vi.mock('../../services/educationService', () => ({
  default: {
    listStudents: vi.fn(),
    createStudent: vi.fn(),
    updateStudent: vi.fn(),
    listGroups: vi.fn(),
    createGroup: vi.fn(),
    updateGroup: vi.fn(),
  },
}));

const auth = { superAdmin: false, readOnly: false, permissions: ['view-education', 'manage-education'] };
vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({
    isSuperAdmin: () => auth.superAdmin,
    isReadOnly: () => auth.readOnly,
    hasPermission: (p: string) => auth.permissions.includes(p),
    user: { platform_crm_account: null, industry_modules: ['education.students', 'education.batches'] },
  }),
}));
const tenant: { selectedAccountId: number | null } = { selectedAccountId: null };
vi.mock('../../core/context/TenantContext', () => ({
  useTenant: () => ({ selectedAccountId: tenant.selectedAccountId, accounts: [], selectAccount: vi.fn() }),
}));

vi.mock('../../services/industryService', () => ({ default: { context: vi.fn() } }));

const edu = educationService as unknown as Record<string, Mock>;

const class5 = makeGroup({ id: 5, name: 'Class 5', students_count: 2 });
const batchA = makeGroup({ id: 6, name: 'Evening Batch', kind: 'batch', academic_year: null });
const asha = makeStudent({
  id: 1,
  contact: { id: 101, name: 'Asha', phone_number: '919800000001', email: null },
  admission_number: 'ADM-1',
  groups: [{ id: 5, name: 'Class 5', kind: 'class', academic_year: '2026-27', status: 'active' }],
  guardians: [{ id: 9, relationship: 'parent', contact: { id: 201, name: 'Mrs Rao', phone_number: '919800000201', email: null } }],
});
const bala = makeStudent({ id: 2, contact: { id: 102, name: 'Bala', phone_number: '919800000002', email: null }, status: 'inactive' });

function renderPage(url = '/education') {
  return render(
    <MemoryRouter initialEntries={[url]}>
      <Routes>
        <Route path="/education" element={<EducationPage />} />
      </Routes>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  (industryService.context as unknown as Mock).mockResolvedValue([]);
  auth.superAdmin = false;
  auth.readOnly = false;
  auth.permissions = ['view-education', 'manage-education'];
  tenant.selectedAccountId = null;
  edu.listStudents.mockResolvedValue(paginated([asha, bala]));
  edu.listGroups.mockResolvedValue(paginated([class5, batchA]));
  edu.createStudent.mockResolvedValue({ message: 'Student created.', data: asha });
  edu.updateStudent.mockResolvedValue({ message: 'Student updated.', data: asha });
  edu.createGroup.mockResolvedValue({ message: 'Created.', data: class5 });
  edu.updateGroup.mockResolvedValue({ message: 'Updated.', data: class5 });
});

describe('students list', () => {
  it('renders the students with their CRM contact, classes and guardians', async () => {
    renderPage();

    const row = await screen.findByTestId('student-row-1');
    expect(within(row).getByText('Asha')).toBeTruthy();
    expect(within(row).getByText('919800000001')).toBeTruthy();
    expect(within(row).getByText('ADM-1')).toBeTruthy();
    expect(within(row).getByText('Class 5')).toBeTruthy();
    expect(within(row).getByText('Mrs Rao (parent)')).toBeTruthy();
    expect(within(screen.getByTestId('student-row-2')).getByText('inactive')).toBeTruthy();
  });

  it('shows a loading state until the first response, then the rows', async () => {
    let resolve!: (v: unknown) => void;
    edu.listStudents.mockReturnValue(new Promise((r) => { resolve = r; }));
    renderPage();

    await waitFor(() => expect(edu.listStudents).toHaveBeenCalled());
    expect(screen.queryByText('No students yet.')).toBeNull();
    expect(document.querySelectorAll('tr.animate-pulse').length).toBeGreaterThan(0);

    await act(async () => resolve(paginated([asha])));
    expect(await screen.findByTestId('student-row-1')).toBeTruthy();
    expect(document.querySelectorAll('tr.animate-pulse').length).toBe(0);
  });

  it('shows an empty state, and a different one when filters exclude everything', async () => {
    const user = userEvent.setup();
    edu.listStudents.mockResolvedValue(paginated([]));
    renderPage();
    expect(await screen.findByText('No students yet.')).toBeTruthy();

    await user.selectOptions(screen.getByLabelText('Filter by status'), 'graduated');
    expect(await screen.findByText('No students match these filters.')).toBeTruthy();
    await waitFor(() => expect(edu.listStudents).toHaveBeenLastCalledWith({ search: '', status: 'graduated', group_id: null }, 1, 20));
  });

  it('shows the API error and keeps the page usable', async () => {
    edu.listStudents.mockRejectedValue(apiError(500));
    renderPage();

    expect(await screen.findByRole('alert')).toBeTruthy();
    expect(screen.queryByTestId('student-row-1')).toBeNull();
    expect(screen.getByRole('button', { name: /new student/i })).toBeTruthy();
  });

  it('filters and searches on the server', async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByTestId('student-row-1');

    await user.selectOptions(screen.getByLabelText('Filter by class or batch'), '5');
    await waitFor(() => expect(edu.listStudents).toHaveBeenLastCalledWith({ search: '', status: '', group_id: 5 }, 1, 20));
    await user.type(screen.getByPlaceholderText(/search name, phone or admission/i), 'Asha');
    await waitFor(() => expect(edu.listStudents).toHaveBeenLastCalledWith({ search: 'Asha', status: '', group_id: 5 }, 1, 20));
  });
});

describe('Students and Classes / Batches are separate', () => {
  it('shows the classes and batches on their own tab', async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByTestId('students-panel');
    expect(screen.queryByTestId('groups-panel')).toBeNull();

    await user.click(screen.getByRole('tab', { name: 'Classes / Batches' }));

    const table = await screen.findByTestId('groups-table');
    expect(within(table).getByText('Class 5')).toBeTruthy();
    expect(within(within(table).getByTestId('group-row-6')).getByText('batch')).toBeTruthy();
    expect(screen.queryByTestId('students-panel')).toBeNull();
  });

  it('opens on the classes tab from the URL, with its empty and error states', async () => {
    edu.listGroups.mockResolvedValue(paginated([]));
    renderPage('/education?tab=groups');
    expect(await screen.findByText('No classes or batches yet.')).toBeTruthy();
  });

  it('shows a class-list error on the classes tab', async () => {
    edu.listGroups.mockRejectedValue(apiError(500));
    renderPage('/education?tab=groups');
    expect(await screen.findByRole('alert')).toBeTruthy();
  });

  it('creates a class and re-reads the list', async () => {
    const user = userEvent.setup();
    renderPage('/education?tab=groups');
    await screen.findByTestId('groups-table');

    await user.click(screen.getByRole('button', { name: /new class \/ batch/i }));
    await user.type(screen.getByLabelText(/^name/i), 'Class 7');
    await user.type(screen.getByLabelText(/academic year/i), '2026-27');
    await user.click(screen.getByRole('button', { name: 'Create' }));

    await waitFor(() => expect(edu.createGroup).toHaveBeenCalledWith({ name: 'Class 7', academic_year: '2026-27', status: 'active' }));
    await waitFor(() => expect(edu.listGroups.mock.calls.length).toBeGreaterThan(1));
    expect(await screen.findByText('Created.')).toBeTruthy();
  });

  it('shows the server\'s duplicate-name message on the field', async () => {
    const user = userEvent.setup();
    edu.createGroup.mockRejectedValue(apiError(422, { message: 'x', errors: { name: ['A class with this name already exists for this academic year.'] } }));
    renderPage('/education?tab=groups');
    await screen.findByTestId('groups-table');

    await user.click(screen.getByRole('button', { name: /new class \/ batch/i }));
    await user.type(screen.getByLabelText(/^name/i), 'Class 5');
    await user.click(screen.getByRole('button', { name: 'Create' }));

    expect(await screen.findAllByText(/already exists/i)).not.toHaveLength(0);
  });
});

describe('create and edit a student', () => {
  it('validates the phone number locally and makes no request', async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByTestId('student-row-1');

    await user.click(screen.getByRole('button', { name: /new student/i }));
    await user.click(screen.getByRole('button', { name: 'Create student' }));

    expect(screen.getByText("Enter the student's phone number.")).toBeTruthy();
    expect(edu.createStudent).not.toHaveBeenCalled();
  });

  it('creates a student with a class and a guardian', async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByTestId('student-row-1');

    await user.click(screen.getByRole('button', { name: /new student/i }));
    const form = within(screen.getByTestId('student-form'));
    await user.type(form.getByLabelText(/phone number/i), '9198000099');
    await user.type(form.getAllByLabelText(/^name$/i)[0], 'New Kid');
    await user.type(form.getByLabelText(/admission number/i), 'ADM-9');
    await user.click(form.getByLabelText(/Class 5/));
    await user.type(form.getByLabelText('Guardian phone'), '9198000100');
    await user.selectOptions(form.getByLabelText('Guardian relationship'), 'guardian');
    await user.click(form.getByRole('button', { name: 'Create student' }));

    await waitFor(() => expect(edu.createStudent).toHaveBeenCalledTimes(1));
    expect(edu.createStudent).toHaveBeenCalledWith({
      phone_number: '9198000099', name: 'New Kid', email: null, admission_number: 'ADM-9', admission_date: null, status: 'active', group_ids: [5],
      guardians: [{ phone_number: '9198000100', name: null, relationship: 'guardian' }],
    });
    expect(await screen.findByText('Student created.')).toBeTruthy();
    await waitFor(() => expect(edu.listStudents.mock.calls.length).toBeGreaterThan(1));
    expect(screen.queryByTestId('student-form')).toBeNull();
  });

  it('shows the server\'s per-field errors (duplicate admission number) and keeps the form open', async () => {
    const user = userEvent.setup();
    edu.createStudent.mockRejectedValue(apiError(422, { message: 'x', errors: { admission_number: ['This admission number is already used by another student.'] } }));
    renderPage();
    await screen.findByTestId('student-row-1');

    await user.click(screen.getByRole('button', { name: /new student/i }));
    const form = within(screen.getByTestId('student-form'));
    await user.type(form.getByLabelText(/phone number/i), '9198000099');
    await user.type(form.getByLabelText(/admission number/i), 'ADM-1');
    await user.click(form.getByRole('button', { name: 'Create student' }));

    expect((await screen.findAllByText(/already used by another student/i)).length).toBeGreaterThan(0);
    expect(screen.getByTestId('student-form')).toBeTruthy();
  });

  it('edits a student: contact is read-only, classes and status are sent', async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByTestId('student-row-1');

    await user.click(within(screen.getByTestId('student-row-1')).getByRole('button', { name: 'Edit' }));
    const form = within(screen.getByTestId('student-form'));
    expect(form.getByTestId('student-form-contact').textContent).toContain('Asha');
    expect(form.queryByLabelText(/phone number/i)).toBeNull();

    await user.click(form.getByLabelText(/Evening Batch/));
    await user.selectOptions(form.getByLabelText(/^status/i), 'graduated');
    await user.click(form.getByRole('button', { name: 'Save changes' }));

    await waitFor(() => expect(edu.updateStudent).toHaveBeenCalledWith(1, { admission_number: 'ADM-1', admission_date: null, status: 'graduated', group_ids: [5, 6] }));
    expect(await screen.findByText('Student updated.')).toBeTruthy();
  });
});

describe('gating', () => {
  it('disables writes on an expired subscription but still lists', async () => {
    auth.readOnly = true;
    renderPage();
    await screen.findByTestId('student-row-1');

    expect((screen.getByRole('button', { name: /new student/i }) as HTMLButtonElement).disabled).toBe(true);
    expect((within(screen.getByTestId('student-row-1')).getByRole('button', { name: 'Edit' }) as HTMLButtonElement).disabled).toBe(true);
  });

  it('hides every write control without manage-education', async () => {
    auth.permissions = ['view-education'];
    renderPage();
    await screen.findByTestId('student-row-1');

    expect(screen.queryByRole('button', { name: /new student/i })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Edit' })).toBeNull();
  });

  it('asks a Super Admin to pick a client and makes no Education request', async () => {
    auth.superAdmin = true;
    renderPage();

    expect(await screen.findByTestId('crm-no-client')).toBeTruthy();
    expect(edu.listStudents).not.toHaveBeenCalled();
    expect(edu.listGroups).not.toHaveBeenCalled();
  });

  it('loads for a Super Admin once a client is selected', async () => {
    auth.superAdmin = true;
    tenant.selectedAccountId = 42;
    renderPage();

    expect(await screen.findByTestId('student-row-1')).toBeTruthy();
    expect(edu.listStudents).toHaveBeenCalled();
  });
});

describe('client switching and stale responses', () => {
  it('re-reads for the newly selected client and ignores a late answer for the previous one', async () => {
    auth.superAdmin = true;
    tenant.selectedAccountId = 1;
    let resolveFirst!: (v: unknown) => void;
    edu.listStudents
      .mockReturnValueOnce(new Promise((r) => { resolveFirst = r; }))
      .mockResolvedValue(paginated([bala]));

    const { rerender } = renderPage();
    await waitFor(() => expect(edu.listStudents).toHaveBeenCalledTimes(1));

    tenant.selectedAccountId = 2;
    rerender(
      <MemoryRouter initialEntries={['/education']}>
        <Routes>
          <Route path="/education" element={<EducationPage />} />
        </Routes>
      </MemoryRouter>,
    );
    expect(await screen.findByTestId('student-row-2')).toBeTruthy();

    // The slow response for client 1 arrives afterwards — it must not replace client 2's rows.
    await act(async () => resolveFirst(paginated([asha])));
    expect(screen.queryByTestId('student-row-1')).toBeNull();
    expect(screen.getByTestId('student-row-2')).toBeTruthy();
    expect(edu.listStudents).toHaveBeenCalledTimes(2);
  });
});
