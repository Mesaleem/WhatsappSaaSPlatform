<?php

namespace App\Services\Education;

use App\Models\Contact;
use App\Models\Education\EducationStudent;
use App\Models\Education\EducationStudentGuardian;
use Illuminate\Validation\ValidationException;

/**
 * Phase 11 Task 2 — what the Education module holds against a CRM contact, and what happens to it when
 * the CRM changes that contact. The CRM's own rules are unchanged; this only answers for Education rows.
 *
 *  - Delete: a contact that is a student or a parent/guardian is NOT deletable (409 CONTACT_HAS_DEPENDENTS,
 *    the same answer as for a contact with leads). The foreign keys are RESTRICT, so this is also a
 *    database guarantee. Removing the education link first is the explicit way out.
 *  - Merge: the source's education rows move to the surviving contact, so no history is lost; a merge that
 *    would make one person two students, or a student their own guardian, is refused (422) before anything moves.
 */
class EducationContactReferences
{
    /** @return array<string, int> non-zero counts, for Contact::blockingDependencies() */
    public function blocking(Contact $contact): array
    {
        return array_filter([
            'education_students' => EducationStudent::query()->where('contact_id', $contact->getKey())->where('account_id', $contact->account_id)->count(),
            'education_guardian_links' => EducationStudentGuardian::query()->where('contact_id', $contact->getKey())->where('account_id', $contact->account_id)->count(),
        ]);
    }

    /** Throws 422 when moving the source's education rows to the target would break an education rule. */
    public function assertMergeable(Contact $source, Contact $target): void
    {
        $accountId = (int) $source->account_id;

        $sourceStudent = EducationStudent::query()->forAccount($accountId)->where('contact_id', $source->getKey())->first();
        $targetStudent = EducationStudent::query()->forAccount($accountId)->where('contact_id', $target->getKey())->first();

        if ($sourceStudent && $targetStudent) {
            throw ValidationException::withMessages(['target' => ['Both contacts are students. Merge is refused so no student record is lost.']]);
        }

        // A student cannot become their own guardian through a merge.
        if ($sourceStudent && EducationStudentGuardian::query()->where('student_id', $sourceStudent->getKey())->where('contact_id', $target->getKey())->exists()) {
            throw ValidationException::withMessages(['target' => ['The target is a guardian of this student; a student cannot be their own guardian.']]);
        }
        if ($targetStudent && EducationStudentGuardian::query()->where('student_id', $targetStudent->getKey())->where('contact_id', $source->getKey())->exists()) {
            throw ValidationException::withMessages(['target' => ['The source is a guardian of the target student; a student cannot be their own guardian.']]);
        }
    }

    /** Move the source's education rows to the target. Call inside the merge transaction, after assertMergeable(). */
    public function repoint(Contact $source, Contact $target): void
    {
        $accountId = (int) $source->account_id;

        EducationStudent::query()->forAccount($accountId)->where('contact_id', $source->getKey())
            ->update(['contact_id' => $target->getKey()]);

        EducationStudentGuardian::query()->where('account_id', $accountId)->where('contact_id', $source->getKey())
            ->orderBy('id')->get()->each(function (EducationStudentGuardian $link) use ($target): void {
                $alreadyLinked = EducationStudentGuardian::query()
                    ->where('student_id', $link->student_id)->where('contact_id', $target->getKey())->exists();

                // The target already guards this student: the source's link is a duplicate — drop it.
                $alreadyLinked ? $link->delete() : $link->forceFill(['contact_id' => $target->getKey()])->save();
            });
    }
}
