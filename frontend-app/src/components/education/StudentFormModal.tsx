import { useState, type FormEvent } from 'react';
import { Loader2, XCircle } from 'lucide-react';
import educationService from '../../services/educationService';
import { inputClass } from '../common/Card';
import { describeApiError } from '../../utils/apiError';
import {
  GUARDIAN_RELATIONSHIPS,
  STUDENT_STATUSES,
  type EducationGroup,
  type EducationStudent,
  type GuardianRelationship,
  type StudentPayload,
  type StudentStatus,
} from '../../types/education';
import DismissibleAlert from '../common/DismissibleAlert';

/**
 * Phase 11 Task 2 — create (POST /students) or edit (PATCH /students/{id}) a student.
 *
 * Name / phone / email belong to the CRM contact: on create they identify (or create) that contact; on
 * edit they are shown read-only — change them in CRM Contacts. Duplicate admission numbers, foreign
 * classes, duplicate guardians etc. are the server's decisions; its field messages are shown inline.
 */
export default function StudentFormModal({
  student,
  groups,
  onSaved,
  onCancel,
}: {
  student?: EducationStudent;
  groups: EducationGroup[];
  onSaved: (student: EducationStudent, message: string) => void;
  onCancel: () => void;
}) {
  const [phone, setPhone] = useState('');
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [admissionNumber, setAdmissionNumber] = useState(student?.admission_number ?? '');
  const [admissionDate, setAdmissionDate] = useState(student?.admission_date ?? '');
  const [status, setStatus] = useState<StudentStatus>(student?.status ?? 'active');
  const [groupIds, setGroupIds] = useState<number[]>(student?.groups.map((g) => g.id) ?? []);
  const [guardianPhone, setGuardianPhone] = useState('');
  const [guardianName, setGuardianName] = useState('');
  const [relationship, setRelationship] = useState<GuardianRelationship>('parent');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [localError, setLocalError] = useState<string | null>(null);

  const toggleGroup = (id: number) => setGroupIds((cur) => (cur.includes(id) ? cur.filter((g) => g !== id) : [...cur, id]));

  const submit = (e: FormEvent) => {
    e.preventDefault();
    if (saving) return;
    setError(null);
    setFieldErrors({});
    setLocalError(null);

    if (!student && phone.trim() === '') {
      setLocalError("Enter the student's phone number.");
      return;
    }

    setSaving(true);
    let call: Promise<{ message: string; data: EducationStudent }>;
    if (student) {
      call = educationService.updateStudent(student.id, {
        admission_number: admissionNumber.trim() === '' ? null : admissionNumber.trim(),
        admission_date: admissionDate === '' ? null : admissionDate,
        status,
        group_ids: groupIds,
      });
    } else {
      const payload: StudentPayload = {
        phone_number: phone.trim(),
        name: name.trim() === '' ? null : name.trim(),
        email: email.trim() === '' ? null : email.trim(),
        admission_number: admissionNumber.trim() === '' ? null : admissionNumber.trim(),
        admission_date: admissionDate === '' ? null : admissionDate,
        status,
        group_ids: groupIds,
      };
      if (guardianPhone.trim() !== '') {
        payload.guardians = [{ phone_number: guardianPhone.trim(), name: guardianName.trim() === '' ? null : guardianName.trim(), relationship }];
      }
      call = educationService.createStudent(payload);
    }

    call
      .then((res) => onSaved(res.data, res.message))
      .catch((err: unknown) => {
        const described = describeApiError(err, 'Failed to save the student.');
        setError(described.message);
        setFieldErrors(described.fieldErrors);
      })
      .finally(() => setSaving(false));
  };

  const field = (key: string) =>
    fieldErrors[key] ? <p className="mt-1 text-xs text-red-600">{fieldErrors[key]}</p> : null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-900/50 p-4">
      <form onSubmit={submit} className="my-8 w-full max-w-lg rounded-xl bg-white p-6 shadow-xl" data-testid="student-form">
        <div className="flex items-center justify-between">
          <h3 className="text-base font-semibold text-slate-900">{student ? 'Edit student' : 'New student'}</h3>
          <button type="button" onClick={onCancel} disabled={saving} className="text-slate-400 hover:text-slate-600" aria-label="Close">
            <XCircle className="h-5 w-5" />
          </button>
        </div>

        <div className="mt-4 space-y-3">
          {student ? (
            <div className="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700" data-testid="student-form-contact">
              <span className="font-medium">{student.contact?.name || 'Unnamed contact'}</span> · {student.contact?.phone_number}
              <p className="mt-0.5 text-xs text-slate-500">Name, phone and email are managed in CRM Contacts.</p>
            </div>
          ) : (
            <>
              <label className="block text-xs font-medium text-slate-600">
                Phone number *
                <input className={inputClass} value={phone} onChange={(e) => setPhone(e.target.value)} maxLength={32} />
                {field('phone_number')}
                {field('contact_id')}
              </label>
              <div className="grid grid-cols-2 gap-3">
                <label className="block text-xs font-medium text-slate-600">
                  Name
                  <input className={inputClass} value={name} onChange={(e) => setName(e.target.value)} maxLength={255} />
                  {field('name')}
                </label>
                <label className="block text-xs font-medium text-slate-600">
                  Email
                  <input className={inputClass} type="email" value={email} onChange={(e) => setEmail(e.target.value)} maxLength={255} />
                  {field('email')}
                </label>
              </div>
            </>
          )}

          <div className="grid grid-cols-2 gap-3">
            <label className="block text-xs font-medium text-slate-600">
              Admission number
              <input className={inputClass} value={admissionNumber} onChange={(e) => setAdmissionNumber(e.target.value)} maxLength={64} />
              {field('admission_number')}
            </label>
            <label className="block text-xs font-medium text-slate-600">
              Admission date
              <input className={inputClass} type="date" value={admissionDate} onChange={(e) => setAdmissionDate(e.target.value)} />
              {field('admission_date')}
            </label>
          </div>

          <label className="block text-xs font-medium text-slate-600">
            Status
            <select className={inputClass} value={status} onChange={(e) => setStatus(e.target.value as StudentStatus)}>
              {STUDENT_STATUSES.map((s) => (
                <option key={s} value={s}>
                  {s.charAt(0).toUpperCase() + s.slice(1)}
                </option>
              ))}
            </select>
            {field('status')}
          </label>

          <fieldset>
            <legend className="text-xs font-medium text-slate-600">Classes / batches</legend>
            {groups.length === 0 ? (
              <p className="mt-1 text-xs text-slate-500">No classes or batches yet — create one in the Classes / Batches tab.</p>
            ) : (
              <div className="mt-1.5 flex flex-wrap gap-2">
                {groups.map((g) => (
                  <label key={g.id} className="flex items-center gap-1.5 rounded-lg border border-slate-200 px-2.5 py-1 text-xs text-slate-700">
                    <input type="checkbox" checked={groupIds.includes(g.id)} onChange={() => toggleGroup(g.id)} />
                    {g.name}
                    {g.academic_year ? ` (${g.academic_year})` : ''}
                  </label>
                ))}
              </div>
            )}
            {field('group_ids')}
          </fieldset>

          {!student && (
            <fieldset className="rounded-lg border border-slate-200 p-3">
              <legend className="px-1 text-xs font-medium text-slate-600">Parent / guardian (optional)</legend>
              <div className="grid grid-cols-3 gap-3">
                <label className="col-span-3 block text-xs font-medium text-slate-600 sm:col-span-1">
                  Phone
                  <input className={inputClass} aria-label="Guardian phone" value={guardianPhone} onChange={(e) => setGuardianPhone(e.target.value)} maxLength={32} />
                  {field('guardians.0.contact_id')}
                </label>
                <label className="col-span-3 block text-xs font-medium text-slate-600 sm:col-span-1">
                  Name
                  <input className={inputClass} aria-label="Guardian name" value={guardianName} onChange={(e) => setGuardianName(e.target.value)} maxLength={255} />
                </label>
                <label className="col-span-3 block text-xs font-medium text-slate-600 sm:col-span-1">
                  Relationship
                  <select className={inputClass} aria-label="Guardian relationship" value={relationship} onChange={(e) => setRelationship(e.target.value as GuardianRelationship)}>
                    {GUARDIAN_RELATIONSHIPS.map((r) => (
                      <option key={r} value={r}>
                        {r.charAt(0).toUpperCase() + r.slice(1)}
                      </option>
                    ))}
                  </select>
                  {field('guardians.0.relationship')}
                </label>
              </div>
            </fieldset>
          )}
        </div>

        {localError && <DismissibleAlert as="p" className="mt-3 text-sm text-red-600 flex items-start justify-between gap-2" role="alert">{localError}</DismissibleAlert>}
        {error && <DismissibleAlert as="p" className="mt-3 text-sm text-red-600 flex items-start justify-between gap-2" role="alert">{error}</DismissibleAlert>}
        <div className="mt-5 flex justify-end gap-2">
          <button type="button" onClick={onCancel} disabled={saving} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60">
            Cancel
          </button>
          <button type="submit" disabled={saving} className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60">
            {saving && <Loader2 className="h-4 w-4 animate-spin" />}
            {student ? 'Save changes' : 'Create student'}
          </button>
        </div>
      </form>
    </div>
  );
}
