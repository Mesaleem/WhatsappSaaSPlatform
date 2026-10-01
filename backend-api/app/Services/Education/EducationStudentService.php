<?php

namespace App\Services\Education;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Education\EducationGroup;
use App\Models\Education\EducationStudent;
use App\Models\Education\EducationStudentGuardian;
use App\Services\Crm\ContactResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 11 Task 2 — the single write path for students, their parents/guardians and their class/batch
 * membership. The HTTP controller calls it today; a Journey node, the public API, an import or a messaging
 * automation call the same methods later. NOTHING here looks at who is calling or where the data came
 * from — authorization is IndustryAuthorizer's, at the edge, identically for every path.
 *
 * Every public method validates first and writes inside ONE transaction: a failure part-way (including a
 * CRM contact created for the request) rolls everything back — no partial writes.
 *
 * Contacts are the CRM's. A person is named either by `contact_id` (must belong to THIS account — a foreign
 * id is "not found", never "belongs to someone else") or by `phone_number` (+ name/email), resolved through
 * the CRM's own ContactResolver (find-or-create per account).
 */
class EducationStudentService
{
    public function __construct(private readonly ContactResolver $contacts)
    {
    }

    /**
     * @param  array<string, mixed>  $data  contact_id|phone_number(+name,email), admission_number, status,
     *                                      admission_date, metadata, guardians[], group_ids[]
     */
    public function create(Account $account, array $data): EducationStudent
    {
        return DB::transaction(function () use ($account, $data): EducationStudent {
            $contact = $this->resolveContact($account, $data, 'contact', 'contact_id');

            if (EducationStudent::query()->forAccount($account->id)->where('contact_id', $contact->getKey())->exists()) {
                $this->fail('contact_id', 'This contact is already a student.');
            }
            $this->assertAdmissionNumberFree($account, $data['admission_number'] ?? null, null);

            $groups = $this->resolveGroups($account, $data['group_ids'] ?? []);
            $guardians = $this->resolveGuardians($account, $data['guardians'] ?? [], $contact);

            $student = EducationStudent::create([
                'account_id' => $account->id,
                'contact_id' => $contact->getKey(),
                'admission_number' => $this->blankToNull($data['admission_number'] ?? null),
                'status' => $data['status'] ?? 'active',
                'admission_date' => $data['admission_date'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);

            foreach ($guardians as $guardian) {
                $this->link($student, $guardian['contact'], $guardian['relationship']);
            }
            $this->attachGroups($student, $groups);

            return $this->fresh($student);
        });
    }

    /** @param array<string, mixed> $data admission_number, status, admission_date, metadata, group_ids (replaces the set when present) */
    public function update(EducationStudent $student, array $data): EducationStudent
    {
        return DB::transaction(function () use ($student, $data): EducationStudent {
            $account = $this->accountOf($student);

            if (array_key_exists('admission_number', $data)) {
                $this->assertAdmissionNumberFree($account, $data['admission_number'], $student->getKey());
            }
            $groups = array_key_exists('group_ids', $data) ? $this->resolveGroups($account, $data['group_ids'] ?? []) : null;

            $attributes = array_intersect_key($data, array_flip(['admission_number', 'status', 'admission_date', 'metadata']));
            if (array_key_exists('admission_number', $attributes)) {
                $attributes['admission_number'] = $this->blankToNull($attributes['admission_number']);
            }
            $student->fill($attributes)->save();

            if ($groups !== null) {
                $this->replaceGroups($student, $groups);
            }

            return $this->fresh($student);
        });
    }

    /** @param array<string, mixed> $data contact_id|phone_number(+name,email), relationship */
    public function addGuardian(EducationStudent $student, array $data): EducationStudent
    {
        return DB::transaction(function () use ($student, $data): EducationStudent {
            $account = $this->accountOf($student);
            $student->loadMissing('contact');

            $guardian = $this->resolveGuardians($account, [$data], $student->contact, '')[0];

            if ($student->guardians()->where('contact_id', $guardian['contact']->getKey())->exists()) {
                $this->fail('contact_id', 'This contact is already linked to the student.');
            }
            $this->link($student, $guardian['contact'], $guardian['relationship']);

            return $this->fresh($student);
        });
    }

    public function removeGuardian(EducationStudent $student, int $linkId): EducationStudent
    {
        $link = $student->guardians()->where('account_id', $student->account_id)->find($linkId);
        abort_if(! $link, 404, 'Guardian link not found.');
        $link->delete();

        return $this->fresh($student);
    }

    /** @param list<int|string> $groupIds the student's complete set of classes/batches */
    public function syncGroups(EducationStudent $student, array $groupIds): EducationStudent
    {
        return DB::transaction(function () use ($student, $groupIds): EducationStudent {
            $groups = $this->resolveGroups($this->accountOf($student), $groupIds);
            $this->replaceGroups($student, $groups);

            return $this->fresh($student);
        });
    }

    // ------------------------------------------------------------------ internals

    private function accountOf(EducationStudent $student): Account
    {
        return Account::findOrFail($student->account_id);
    }

    private function fresh(EducationStudent $student): EducationStudent
    {
        return $student->fresh(['contact', 'guardians.contact', 'groups']);
    }

    /** @param array<string, mixed> $data */
    private function resolveContact(Account $account, array $data, string $label, string $errorKey): Contact
    {
        $idKey = $errorKey;
        $contactId = $data['contact_id'] ?? null;
        $phone = $data['phone_number'] ?? null;

        if ($contactId !== null && $contactId !== '') {
            // Account-scoped: another tenant's contact is indistinguishable from a missing one.
            $contact = Contact::query()->forAccount($account->id)->find((int) $contactId);
            if (! $contact) {
                $this->fail($idKey, "The selected {$label} was not found.");
            }

            return $contact;
        }
        if ($phone === null || trim((string) $phone) === '') {
            $this->fail($idKey, "Choose an existing {$label} (contact_id) or give a phone_number.");
        }

        return $this->contacts->resolve($account->id, (string) $phone, $data['name'] ?? null, $data['email'] ?? null);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{contact: Contact, relationship: string}>
     */
    private function resolveGuardians(Account $account, array $rows, Contact $studentContact, ?string $errorPrefix = null): array
    {
        $out = [];
        $seen = [];
        foreach (array_values($rows) as $i => $row) {
            // Error keys: `guardians.<i>.…` inside a student payload, plain `contact_id` for the single-link endpoint.
            $at = $errorPrefix ?? "guardians.{$i}.";
            $relationship = (string) ($row['relationship'] ?? '');
            if (! in_array($relationship, EducationStudentGuardian::RELATIONSHIPS, true)) {
                $this->fail("{$at}relationship", 'Relationship must be parent or guardian.');
            }
            $contact = $this->resolveContact($account, $row, 'parent/guardian', "{$at}contact_id");
            if ($contact->getKey() === $studentContact->getKey()) {
                $this->fail("{$at}contact_id", 'A student cannot be their own parent or guardian.');
            }
            if (isset($seen[$contact->getKey()])) {
                $this->fail("{$at}contact_id", 'This contact is listed twice.');
            }
            $seen[$contact->getKey()] = true;
            $out[] = ['contact' => $contact, 'relationship' => $relationship];
        }

        return $out;
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<int>
     */
    private function resolveGroups(Account $account, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $found = EducationGroup::query()->forAccount($account->id)->whereIn('id', $ids)->pluck('id')->all();
        if (count($found) !== count($ids)) {
            $this->fail('group_ids', 'One or more classes/batches were not found.');
        }

        return $ids;
    }

    private function assertAdmissionNumberFree(Account $account, mixed $number, ?int $exceptStudentId): void
    {
        $number = $this->blankToNull($number);
        if ($number === null) {
            return;
        }
        $taken = EducationStudent::query()->forAccount($account->id)->where('admission_number', $number)
            ->when($exceptStudentId, fn ($q) => $q->where('id', '!=', $exceptStudentId))->exists();
        if ($taken) {
            $this->fail('admission_number', 'This admission number is already used by another student.');
        }
    }

    private function link(EducationStudent $student, Contact $contact, string $relationship): void
    {
        EducationStudentGuardian::create([
            'account_id' => $student->account_id,
            'student_id' => $student->getKey(),
            'contact_id' => $contact->getKey(),
            'relationship' => $relationship,
        ]);
    }

    /** @param list<int> $groupIds */
    private function attachGroups(EducationStudent $student, array $groupIds): void
    {
        foreach ($groupIds as $id) {
            $student->groups()->attach($id, ['account_id' => $student->account_id]);
        }
    }

    /** @param list<int> $groupIds */
    private function replaceGroups(EducationStudent $student, array $groupIds): void
    {
        $current = $student->groups()->pluck('education_groups.id')->map(fn ($v) => (int) $v)->all();
        $student->groups()->detach(array_values(array_diff($current, $groupIds)));
        $this->attachGroups($student, array_values(array_diff($groupIds, $current)));
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = $value === null ? null : trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
