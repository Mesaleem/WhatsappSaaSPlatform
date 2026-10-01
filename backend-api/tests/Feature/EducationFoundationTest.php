<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\AccountIndustry;
use App\Models\Capability;
use App\Models\Contact;
use App\Models\Education\EducationGroup;
use App\Models\Education\EducationStudent;
use App\Models\Education\EducationStudentGuardian;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 11 Task 2 — the Education foundation: students (a profile on a CRM contact), parents/guardians
 * (CRM contacts), classes/batches, membership — behind the ONE IndustryAuthorizer.
 */
class EducationFoundationTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/industry/education';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    // ------------------------------------------------------------------ fixtures

    private function grant(Account $account, string $slug): void
    {
        AccountEntitlement::firstOrCreate(
            ['account_id' => $account->id, 'capability_id' => Capability::where('slug', $slug)->firstOrFail()->id],
            ['source' => 'manual_grant'],
        );
    }

    /** An education tenant: subscription, capability, industry assigned. */
    private function school(string $subtype = 'school', array $attributes = []): Account
    {
        $account = Account::factory()->create($attributes);
        Subscription::factory()->create(['account_id' => $account->id]);
        $this->grant($account, 'industry_education');
        AccountIndustry::create(['account_id' => $account->id, 'industry' => 'education', 'subtype' => $subtype]);

        return $account->fresh();
    }

    private function userOf(Account $account, string $role = 'admin'): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function contact(Account $account, string $phone = '919800000001', string $name = 'Asha'): Contact
    {
        return Contact::create(['account_id' => $account->id, 'phone_number' => $phone, 'name' => $name]);
    }

    private function group(Account $account, string $name = 'Class 5', string $kind = 'class', ?string $year = '2026-27'): EducationGroup
    {
        return EducationGroup::create(['account_id' => $account->id, 'kind' => $kind, 'name' => $name, 'academic_year' => $year]);
    }

    private function student(Account $account, ?Contact $contact = null, array $attributes = []): EducationStudent
    {
        return EducationStudent::create(array_merge(['account_id' => $account->id, 'contact_id' => ($contact ?? $this->contact($account, (string) random_int(910000000000, 919999999999)))->id], $attributes));
    }

    /** @return array{0: Account, 1: User} */
    private function ready(): array
    {
        $account = $this->school();

        return [$account, $this->userOf($account)];
    }

    private function rows(): array
    {
        return [EducationStudent::count(), Contact::count(), EducationStudentGuardian::count(), DB::table('education_group_members')->count(), EducationGroup::count()];
    }

    // ================================================================== students

    public function test_a_student_is_created_from_a_phone_number_and_reuses_the_crm_contact_table(): void
    {
        [$account, $admin] = $this->ready();

        $response = $this->actingAs($admin)->postJson(self::BASE.'/students', [
            'phone_number' => '+91 98000 00002', 'name' => 'Ravi Kumar', 'email' => 'ravi@example.com',
            'admission_number' => 'ADM-001', 'admission_date' => '2026-06-01', 'status' => 'active', 'metadata' => ['house' => 'Blue'],
        ])->assertCreated()->assertJsonPath('data.contact.name', 'Ravi Kumar')->assertJsonPath('data.contact.phone_number', '919800000002')
            ->assertJsonPath('data.admission_number', 'ADM-001')->assertJsonPath('data.metadata.house', 'Blue');

        $student = EducationStudent::firstOrFail();
        $this->assertSame($account->id, $student->account_id);
        $this->assertSame('Ravi Kumar', Contact::findOrFail($student->contact_id)->name);
        $this->assertSame($response->json('data.contact.id'), $student->contact_id);

        // The student table holds NO copy of the person.
        foreach (['name', 'phone_number', 'email'] as $column) {
            $this->assertFalse(Schema::hasColumn('education_students', $column), "education_students has no {$column}");
        }
    }

    public function test_a_student_can_be_created_from_an_existing_crm_contact_and_is_visible_in_the_crm(): void
    {
        [$account, $admin] = $this->ready();
        $contact = $this->contact($account, '919800000003', 'Meera');
        $admin->givePermissionTo('manage-crm');
        $this->grant($account, 'crm');

        $this->actingAs($admin)->postJson(self::BASE.'/students', ['contact_id' => $contact->id])->assertCreated()->assertJsonPath('data.contact.id', $contact->id);
        $this->assertSame(1, Contact::count(), 'no second contact was made');

        // The same person is still an ordinary CRM contact.
        $this->actingAs($admin)->getJson('/api/crm/contacts')->assertOk()->assertJsonPath('data.0.id', $contact->id);
    }

    public function test_the_same_contact_cannot_be_a_student_twice_or_unknown_contacts_used(): void
    {
        [$account, $admin] = $this->ready();
        $contact = $this->contact($account);
        $this->student($account, $contact);
        $before = $this->rows();

        $this->actingAs($admin)->postJson(self::BASE.'/students', ['contact_id' => $contact->id])->assertStatus(422)->assertJsonValidationErrors('contact_id');
        $this->actingAs($admin)->postJson(self::BASE.'/students', ['contact_id' => 999999])->assertStatus(422)->assertJsonValidationErrors('contact_id');
        $this->actingAs($admin)->postJson(self::BASE.'/students', [])->assertStatus(422)->assertJsonValidationErrors('contact_id');
        $this->actingAs($admin)->postJson(self::BASE.'/students', ['phone_number' => 'abc'])->assertStatus(422);
        $this->assertSame($before, $this->rows());
    }

    public function test_students_are_listed_filtered_searched_and_shown(): void
    {
        [$account, $admin] = $this->ready();
        $class5 = $this->group($account);
        $asha = $this->student($account, $this->contact($account, '919800000010', 'Asha'), ['admission_number' => 'A-1']);
        $bala = $this->student($account, $this->contact($account, '919800000011', 'Bala'), ['status' => 'inactive']);
        $asha->groups()->attach($class5->id, ['account_id' => $account->id]);

        $names = fn ($r) => array_column($r->json('data'), 'id');
        $this->assertSame([$asha->id, $bala->id], $names($this->actingAs($admin)->getJson(self::BASE.'/students')->assertOk()));
        $this->assertSame([$bala->id], $names($this->actingAs($admin)->getJson(self::BASE.'/students?status=inactive')));
        $this->assertSame([$asha->id], $names($this->actingAs($admin)->getJson(self::BASE.'/students?group_id='.$class5->id)));
        $this->assertSame([$asha->id], $names($this->actingAs($admin)->getJson(self::BASE.'/students?search=A-1')));
        $this->assertSame([$bala->id], $names($this->actingAs($admin)->getJson(self::BASE.'/students?search=800000011')));
        $this->actingAs($admin)->getJson(self::BASE.'/students?status=nope')->assertStatus(422);
        $this->actingAs($admin)->getJson(self::BASE.'/students?per_page=1')->assertOk()->assertJsonPath('last_page', 2);

        $this->actingAs($admin)->getJson(self::BASE.'/students/'.$asha->id)->assertOk()->assertJsonPath('data.groups.0.name', 'Class 5')->assertJsonPath('data.contact.name', 'Asha');
        $this->actingAs($admin)->getJson(self::BASE.'/students/999999')->assertNotFound();
    }

    public function test_a_student_is_updated_but_its_contact_and_owner_cannot_be_changed(): void
    {
        [$account, $admin] = $this->ready();
        $other = $this->school();
        $student = $this->student($account);
        $contactId = $student->contact_id;

        $this->actingAs($admin)->patchJson(self::BASE.'/students/'.$student->id, [
            'status' => 'graduated', 'admission_number' => 'X-9', 'admission_date' => '2025-04-01',
            // Forged ownership / re-pointing — not part of the contract, ignored.
            'account_id' => $other->id, 'contact_id' => $this->contact($other, '919800000099')->id,
        ])->assertOk()->assertJsonPath('data.status', 'graduated')->assertJsonPath('data.admission_number', 'X-9');

        $student->refresh();
        $this->assertSame($account->id, $student->account_id);
        $this->assertSame($contactId, $student->contact_id);
        $this->actingAs($admin)->patchJson(self::BASE.'/students/'.$student->id, ['status' => 'enrolled'])->assertStatus(422);
        $this->actingAs($admin)->patchJson(self::BASE.'/students/'.$student->id, ['admission_date' => '01/04/2025'])->assertStatus(422);
    }

    public function test_admission_numbers_are_unique_per_account_only(): void
    {
        [$account, $admin] = $this->ready();
        $other = $this->school();
        $this->student($account, null, ['admission_number' => 'ADM-7']);
        $this->student($other, null, ['admission_number' => 'ADM-7']); // another tenant may reuse it
        $second = $this->student($account);

        $before = $this->rows();
        $this->actingAs($admin)->postJson(self::BASE.'/students', ['phone_number' => '919800000050', 'admission_number' => 'ADM-7'])
            ->assertStatus(422)->assertJsonValidationErrors('admission_number');
        $this->assertSame($before, $this->rows(), 'the CRM contact for the rejected student was rolled back too');

        $this->actingAs($admin)->patchJson(self::BASE.'/students/'.$second->id, ['admission_number' => 'ADM-7'])->assertStatus(422)->assertJsonValidationErrors('admission_number');
        // Re-saving a student's own number is fine; so is any number of students with none.
        $this->actingAs($admin)->patchJson(self::BASE.'/students/'.EducationStudent::where('admission_number', 'ADM-7')->where('account_id', $account->id)->value('id'), ['admission_number' => 'ADM-7'])->assertOk();
        $this->actingAs($admin)->postJson(self::BASE.'/students', ['phone_number' => '919800000051'])->assertCreated();
        $this->actingAs($admin)->postJson(self::BASE.'/students', ['phone_number' => '919800000052'])->assertCreated();
    }

    // ================================================================== classes / batches

    public function test_groups_are_created_listed_shown_and_updated_with_the_default_kind_from_the_vertical(): void
    {
        foreach (['school' => 'class', 'coaching' => 'batch', 'institute' => 'batch'] as $subtype => $kind) {
            $account = $this->school($subtype);
            $admin = $this->userOf($account);

            $this->actingAs($admin)->postJson(self::BASE.'/groups', ['name' => 'Group A', 'academic_year' => '2026-27'])
                ->assertCreated()->assertJsonPath('data.kind', $kind)->assertJsonPath('data.students_count', 0);
        }

        $account = $this->school('school');
        $admin = $this->userOf($account);
        $this->actingAs($admin)->postJson(self::BASE.'/groups', ['name' => 'Evening Batch', 'kind' => 'batch'])->assertCreated()->assertJsonPath('data.kind', 'batch');
        $group = EducationGroup::where('account_id', $account->id)->first();
        $student = $this->student($account);
        $student->groups()->attach($group->id, ['account_id' => $account->id]);

        $this->actingAs($admin)->getJson(self::BASE.'/groups')->assertOk()->assertJsonPath('data.0.students_count', 1);
        $this->actingAs($admin)->getJson(self::BASE.'/groups?kind=class')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($admin)->getJson(self::BASE.'/groups/'.$group->id)->assertOk()->assertJsonPath('data.students.0.id', $student->id);
        $this->actingAs($admin)->patchJson(self::BASE.'/groups/'.$group->id, ['name' => 'Night Batch', 'status' => 'archived'])->assertOk()->assertJsonPath('data.name', 'Night Batch')->assertJsonPath('data.status', 'archived');
        // The kind is fixed once created; a bad value or a bad kind on create is a 422.
        $this->actingAs($admin)->patchJson(self::BASE.'/groups/'.$group->id, ['kind' => 'class'])->assertStatus(422);
        $this->actingAs($admin)->postJson(self::BASE.'/groups', ['name' => 'X', 'kind' => 'house'])->assertStatus(422);
        $this->actingAs($admin)->postJson(self::BASE.'/groups', [])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_duplicate_groups_are_refused_per_account_kind_and_year(): void
    {
        [$account, $admin] = $this->ready();
        $other = $this->school();
        $this->group($account, 'Class 5', 'class', '2026-27');
        $this->group($account, 'Class 6', 'class', null);
        $this->group($other, 'Class 5', 'class', '2026-27');

        $this->actingAs($admin)->postJson(self::BASE.'/groups', ['name' => ' class 5 ', 'academic_year' => '2026-27'])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->actingAs($admin)->postJson(self::BASE.'/groups', ['name' => 'Class 6'])->assertStatus(422); // null year collides with null year
        $this->actingAs($admin)->postJson(self::BASE.'/groups', ['name' => 'Class 5', 'academic_year' => '2027-28'])->assertCreated();
        $this->actingAs($admin)->postJson(self::BASE.'/groups', ['name' => 'Class 5', 'kind' => 'batch', 'academic_year' => '2026-27'])->assertCreated();
        $dup = $this->group($account, 'Class 7');
        $this->actingAs($admin)->patchJson(self::BASE.'/groups/'.$dup->id, ['name' => 'Class 5', 'academic_year' => '2026-27'])->assertStatus(422);
    }

    // ================================================================== membership

    public function test_students_are_assigned_to_classes_on_create_and_by_sync(): void
    {
        [$account, $admin] = $this->ready();
        $a = $this->group($account, 'Class 5');
        $b = $this->group($account, 'Class 6');

        $created = $this->actingAs($admin)->postJson(self::BASE.'/students', ['phone_number' => '919800000060', 'group_ids' => [$a->id]])->assertCreated()->assertJsonCount(1, 'data.groups');
        $id = $created->json('data.id');

        $this->actingAs($admin)->putJson(self::BASE."/students/{$id}/groups", ['group_ids' => [$a->id, $b->id]])->assertOk()->assertJsonCount(2, 'data.groups');
        $this->actingAs($admin)->putJson(self::BASE."/students/{$id}/groups", ['group_ids' => [$b->id, $b->id]])->assertOk()->assertJsonCount(1, 'data.groups');
        $this->assertSame([$b->id], DB::table('education_group_members')->where('student_id', $id)->pluck('group_id')->all());
        $this->actingAs($admin)->patchJson(self::BASE."/students/{$id}", ['group_ids' => []])->assertOk()->assertJsonCount(0, 'data.groups');
        $this->actingAs($admin)->putJson(self::BASE."/students/{$id}/groups", [])->assertStatus(422);
    }

    public function test_a_foreign_or_unknown_class_is_refused_and_nothing_is_written(): void
    {
        [$account, $admin] = $this->ready();
        $other = $this->school();
        $foreign = $this->group($other, 'Other Class');
        $own = $this->group($account, 'Class 5');
        $student = $this->student($account);
        $student->groups()->attach($own->id, ['account_id' => $account->id]);
        $before = $this->rows();

        $this->actingAs($admin)->postJson(self::BASE.'/students', ['phone_number' => '919800000070', 'group_ids' => [$own->id, $foreign->id]])->assertStatus(422)->assertJsonValidationErrors('group_ids');
        $this->actingAs($admin)->postJson(self::BASE.'/students', ['phone_number' => '919800000070', 'group_ids' => [999999]])->assertStatus(422);
        $this->assertSame($before, $this->rows());

        $this->actingAs($admin)->putJson(self::BASE."/students/{$student->id}/groups", ['group_ids' => [$foreign->id]])->assertStatus(422);
        $this->actingAs($admin)->patchJson(self::BASE."/students/{$student->id}", ['status' => 'inactive', 'group_ids' => [$foreign->id]])->assertStatus(422);
        $this->assertSame('active', $student->fresh()->status, 'the failed update changed nothing');
        $this->assertSame([$own->id], DB::table('education_group_members')->where('student_id', $student->id)->pluck('group_id')->all());
    }

    // ================================================================== parents / guardians

    public function test_a_student_can_have_several_parents_and_guardians_and_a_contact_can_guard_siblings(): void
    {
        [$account, $admin] = $this->ready();
        $mother = $this->contact($account, '919800000080', 'Mother');
        $s1 = $this->student($account);
        $s2 = $this->student($account);

        $this->actingAs($admin)->postJson(self::BASE."/students/{$s1->id}/guardians", ['contact_id' => $mother->id, 'relationship' => 'parent'])->assertCreated();
        $this->actingAs($admin)->postJson(self::BASE."/students/{$s1->id}/guardians", ['phone_number' => '919800000081', 'name' => 'Uncle', 'relationship' => 'guardian'])->assertCreated()->assertJsonCount(2, 'data.guardians');
        $this->actingAs($admin)->postJson(self::BASE."/students/{$s2->id}/guardians", ['contact_id' => $mother->id, 'relationship' => 'parent'])->assertCreated();

        $this->assertSame(2, EducationStudentGuardian::where('student_id', $s1->id)->count());
        $this->assertSame(2, EducationStudentGuardian::where('contact_id', $mother->id)->count());
        $this->assertNull(EducationStudent::where('contact_id', $mother->id)->first(), 'being a parent does not make her a student');

        // A student with no guardian is fine too.
        $this->actingAs($admin)->postJson(self::BASE.'/students', ['phone_number' => '919800000082'])->assertCreated()->assertJsonCount(0, 'data.guardians');
    }

    public function test_guardians_can_be_given_when_creating_the_student(): void
    {
        [$account, $admin] = $this->ready();

        $this->actingAs($admin)->postJson(self::BASE.'/students', [
            'phone_number' => '919800000090', 'name' => 'Kid',
            'guardians' => [['phone_number' => '919800000091', 'name' => 'Dad', 'relationship' => 'parent'], ['phone_number' => '919800000092', 'relationship' => 'guardian']],
        ])->assertCreated()->assertJsonCount(2, 'data.guardians')->assertJsonPath('data.guardians.0.relationship', 'parent');
        $this->assertSame(3, Contact::count());
    }

    public function test_guardian_validation_and_duplicates_with_no_partial_writes(): void
    {
        [$account, $admin] = $this->ready();
        $other = $this->school();
        $foreignContact = $this->contact($other, '919800000100', 'Foreign');
        $dad = $this->contact($account, '919800000101', 'Dad');
        $student = $this->student($account);
        EducationStudentGuardian::create(['account_id' => $account->id, 'student_id' => $student->id, 'contact_id' => $dad->id, 'relationship' => 'parent']);

        // duplicate link, even with another relationship label
        $this->actingAs($admin)->postJson(self::BASE."/students/{$student->id}/guardians", ['contact_id' => $dad->id, 'relationship' => 'guardian'])->assertStatus(422);
        // the student themselves
        $this->actingAs($admin)->postJson(self::BASE."/students/{$student->id}/guardians", ['contact_id' => $student->contact_id, 'relationship' => 'parent'])->assertStatus(422);
        // another tenant's contact, unknown contact, bad relationship, nothing given
        $this->actingAs($admin)->postJson(self::BASE."/students/{$student->id}/guardians", ['contact_id' => $foreignContact->id, 'relationship' => 'parent'])->assertStatus(422)->assertJsonValidationErrors('contact_id');
        $this->actingAs($admin)->postJson(self::BASE."/students/{$student->id}/guardians", ['contact_id' => 999999, 'relationship' => 'parent'])->assertStatus(422);
        $this->actingAs($admin)->postJson(self::BASE."/students/{$student->id}/guardians", ['contact_id' => $dad->id, 'relationship' => 'friend'])->assertStatus(422);
        $this->actingAs($admin)->postJson(self::BASE."/students/{$student->id}/guardians", ['relationship' => 'parent'])->assertStatus(422);
        $this->assertSame(1, EducationStudentGuardian::count());

        // A bad second guardian rolls back the student AND the first guardian's brand-new contact.
        $before = $this->rows();
        foreach ([
            [['phone_number' => '919800000110', 'relationship' => 'parent'], ['contact_id' => $foreignContact->id, 'relationship' => 'guardian']],
            [['phone_number' => '919800000111', 'relationship' => 'parent'], ['phone_number' => '919800000111', 'relationship' => 'guardian']],
            [['phone_number' => '919800000112', 'relationship' => 'parent'], ['phone_number' => '919800000113', 'relationship' => 'cousin']],
        ] as $guardians) {
            $this->actingAs($admin)->postJson(self::BASE.'/students', ['phone_number' => '919800000120', 'guardians' => $guardians])->assertStatus(422);
            $this->assertSame($before, $this->rows());
        }
    }

    public function test_a_guardian_link_can_be_removed_without_touching_the_contact(): void
    {
        [$account, $admin] = $this->ready();
        $dad = $this->contact($account, '919800000130', 'Dad');
        $student = $this->student($account);
        $link = EducationStudentGuardian::create(['account_id' => $account->id, 'student_id' => $student->id, 'contact_id' => $dad->id, 'relationship' => 'parent']);
        $otherStudent = $this->student($account);
        $otherLink = EducationStudentGuardian::create(['account_id' => $account->id, 'student_id' => $otherStudent->id, 'contact_id' => $dad->id, 'relationship' => 'parent']);

        // a link id from a different student is not this student's link
        $this->actingAs($admin)->deleteJson(self::BASE."/students/{$student->id}/guardians/{$otherLink->id}")->assertNotFound();
        $this->actingAs($admin)->deleteJson(self::BASE."/students/{$student->id}/guardians/{$link->id}")->assertOk()->assertJsonCount(0, 'data.guardians');
        $this->assertNotNull(Contact::find($dad->id));
        $this->assertNotNull(EducationStudentGuardian::find($otherLink->id));
    }

    // ================================================================== CRM integration

    public function test_a_contact_that_is_a_student_or_guardian_cannot_be_deleted_from_the_crm(): void
    {
        [$account, $admin] = $this->ready();
        $this->grant($account, 'crm');
        $admin->givePermissionTo('manage-crm');
        $student = $this->student($account);
        $dad = $this->contact($account, '919800000140', 'Dad');
        $link = EducationStudentGuardian::create(['account_id' => $account->id, 'student_id' => $student->id, 'contact_id' => $dad->id, 'relationship' => 'parent']);
        $free = $this->contact($account, '919800000141', 'Nobody');

        $this->actingAs($admin)->deleteJson('/api/crm/contacts/'.$student->contact_id)->assertStatus(409)->assertJsonPath('error_code', 'CONTACT_HAS_DEPENDENTS')->assertJsonPath('dependents.education_students', 1);
        $this->actingAs($admin)->deleteJson('/api/crm/contacts/'.$dad->id)->assertStatus(409)->assertJsonPath('dependents.education_guardian_links', 1);
        $this->actingAs($admin)->deleteJson('/api/crm/contacts/'.$free->id)->assertOk();

        // Unlinking is the explicit way out.
        $link->delete();
        $this->actingAs($admin)->deleteJson('/api/crm/contacts/'.$dad->id)->assertOk();
    }

    public function test_the_database_itself_refuses_orphaning_and_cross_tenant_links(): void
    {
        $a = $this->school();
        $b = $this->school();
        $student = $this->student($a);
        $foreignContact = $this->contact($b, '919800000150');

        try {
            DB::table('contacts')->where('id', $student->contact_id)->delete();
            $this->fail('deleting a student\'s contact must be refused by the foreign key');
        } catch (QueryException) {
            $this->assertNotNull(Contact::find($student->contact_id));
        }
        try {
            DB::table('education_students')->insert(['account_id' => $a->id, 'contact_id' => $foreignContact->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $this->fail('a student cannot point at another account\'s contact');
        } catch (QueryException) {
            $this->assertSame(1, EducationStudent::count());
        }
        try {
            DB::table('education_group_members')->insert(['account_id' => $a->id, 'student_id' => $student->id, 'group_id' => $this->group($b)->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->fail('a student cannot join another account\'s group');
        } catch (QueryException) {
            $this->assertSame(0, DB::table('education_group_members')->count());
        }
        try {
            DB::table('education_student_guardians')->insert(['account_id' => $a->id, 'student_id' => $student->id, 'contact_id' => $foreignContact->id, 'relationship' => 'parent', 'created_at' => now(), 'updated_at' => now()]);
            $this->fail('a guardian cannot be another account\'s contact');
        } catch (QueryException) {
            $this->assertSame(0, EducationStudentGuardian::count());
        }
    }

    public function test_merging_contacts_moves_education_rows_and_refuses_the_unsafe_cases(): void
    {
        [$account, $admin] = $this->ready();
        $this->grant($account, 'crm');
        $admin->givePermissionTo('manage-crm');

        // source is a student → the profile follows the surviving contact
        $source = $this->contact($account, '919800000160', 'Dup');
        $target = $this->contact($account, '919800000161', 'Keep');
        $student = $this->student($account, $source);
        $this->actingAs($admin)->postJson("/api/crm/contacts/{$source->id}/merge/{$target->id}", ['confirm_phone_discard' => true])->assertOk();
        $this->assertSame($target->id, $student->fresh()->contact_id);
        $this->assertNull(Contact::find($source->id));

        // a guardian merged into a contact that already guards the same student → one link remains
        $dad = $this->contact($account, '919800000162', 'Dad');
        $dadDup = $this->contact($account, '919800000163', 'Dad again');
        foreach ([$dad, $dadDup] as $c) {
            EducationStudentGuardian::create(['account_id' => $account->id, 'student_id' => $student->id, 'contact_id' => $c->id, 'relationship' => 'parent']);
        }
        $this->actingAs($admin)->postJson("/api/crm/contacts/{$dadDup->id}/merge/{$dad->id}", ['confirm_phone_discard' => true])->assertOk();
        $this->assertSame([$dad->id], EducationStudentGuardian::where('student_id', $student->id)->pluck('contact_id')->all());

        $student->refresh();

        // two students → refused, nothing moves
        $s2 = $this->student($account, $this->contact($account, '919800000164'));
        $this->actingAs($admin)->postJson("/api/crm/contacts/{$s2->contact_id}/merge/{$student->contact_id}", ['confirm_phone_discard' => true])->assertStatus(422);
        $this->assertNotNull(Contact::find($s2->contact_id));

        // a student cannot become their own guardian through a merge
        $this->actingAs($admin)->postJson("/api/crm/contacts/{$dad->id}/merge/{$student->contact_id}", ['confirm_phone_discard' => true])->assertStatus(422);
        $this->assertNotNull(Contact::find($dad->id));
    }

    // ================================================================== authorization

    public function test_every_gate_denies_every_education_route(): void
    {
        [$account, $admin] = $this->ready();
        $student = $this->student($account);
        $group = $this->group($account);
        $routes = [
            ['getJson', '/students'], ['postJson', '/students', ['phone_number' => '919800000170']], ['getJson', "/students/{$student->id}"], ['patchJson', "/students/{$student->id}", ['status' => 'inactive']],
            ['postJson', "/students/{$student->id}/guardians", ['phone_number' => '919800000171', 'relationship' => 'parent']], ['putJson', "/students/{$student->id}/groups", ['group_ids' => []]],
            ['getJson', '/groups'], ['postJson', '/groups', ['name' => 'Z']], ['getJson', "/groups/{$group->id}"], ['patchJson', "/groups/{$group->id}", ['name' => 'Y']],
        ];
        $call = fn (User $u, array $r) => $this->actingAs($u)->{$r[0]}(self::BASE.$r[1], $r[2] ?? []);

        foreach ($routes as $r) {
            $status = $call($admin, $r)->status();
            $this->assertTrue($status >= 200 && $status < 300, $r[0].' '.$r[1]." works when everything is granted (got {$status})");
        }

        // missing capability
        AccountEntitlement::where('account_id', $account->id)->update(['revoked_at' => now()]);
        foreach ($routes as $r) {
            $call($admin, $r)->assertForbidden()->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
        }
        AccountEntitlement::where('account_id', $account->id)->update(['revoked_at' => null]);

        // missing module
        $account->forceFill(['allowed_modules' => array_values(array_diff(Account::MODULES, ['industry_modules']))])->save();
        foreach ($routes as $r) {
            $call($admin, $r)->assertForbidden()->assertJsonPath('error_code', 'MODULE_DISABLED');
        }
        $account->forceFill(['allowed_modules' => null])->save();

        // industry not assigned
        AccountIndustry::where('account_id', $account->id)->delete();
        foreach ($routes as $r) {
            $call($admin, $r)->assertForbidden()->assertJsonPath('error_code', 'INDUSTRY_NOT_ASSIGNED');
        }
        AccountIndustry::create(['account_id' => $account->id, 'industry' => 'education', 'subtype' => 'school']);

        // no permission at all
        Role::firstOrCreate(['name' => 'edu_none', 'guard_name' => 'web'])->syncPermissions(['view-analytics']);
        $none = $this->userOf($account, 'edu_none');
        foreach ($routes as $r) {
            $call($none, $r)->assertForbidden()->assertJsonPath('error_code', 'PERMISSION_DENIED');
        }

        // suspended
        $account->forceFill(['status' => 'suspended'])->save();
        $this->assertContains($call($admin, $routes[0])->status(), [401, 403]);
    }

    public function test_reads_need_view_education_and_writes_need_manage_education(): void
    {
        [$account] = $this->ready();
        $student = $this->student($account);
        Role::firstOrCreate(['name' => 'edu_viewer', 'guard_name' => 'web'])->syncPermissions(['view-education']);
        $viewer = $this->userOf($account, 'edu_viewer');

        $this->actingAs($viewer)->getJson(self::BASE.'/students')->assertOk();
        $this->actingAs($viewer)->getJson(self::BASE.'/groups')->assertOk();
        $this->actingAs($viewer)->getJson(self::BASE."/students/{$student->id}")->assertOk();
        $this->actingAs($viewer)->postJson(self::BASE.'/students', ['phone_number' => '919800000180'])->assertForbidden()->assertJsonPath('error_code', 'PERMISSION_DENIED');
        $this->actingAs($viewer)->patchJson(self::BASE."/students/{$student->id}", ['status' => 'inactive'])->assertForbidden();
        $this->actingAs($viewer)->postJson(self::BASE.'/groups', ['name' => 'Nope'])->assertForbidden();
        $this->assertSame(1, EducationStudent::count());

        // view-education alone is enough for a read — it does NOT need the generic industry permission
        $this->assertFalse($viewer->can('view-industry-modules'));
        // and the generic permission alone does not open Education
        Role::firstOrCreate(['name' => 'edu_generic', 'guard_name' => 'web'])->syncPermissions(['view-industry-modules']);
        $this->actingAs($this->userOf($account, 'edu_generic'))->getJson(self::BASE.'/students')->assertForbidden();
    }

    public function test_an_expired_subscription_keeps_reads_and_blocks_writes(): void
    {
        [$account, $admin] = $this->ready();
        $student = $this->student($account);
        Subscription::where('account_id', $account->id)->update(['expires_at' => now()->subDay()]);

        $this->actingAs($admin)->getJson(self::BASE.'/students')->assertOk();
        $this->actingAs($admin)->getJson(self::BASE.'/groups')->assertOk();
        $this->actingAs($admin)->postJson(self::BASE.'/students', ['phone_number' => '919800000190'])->assertForbidden();
        $this->actingAs($admin)->patchJson(self::BASE."/students/{$student->id}", ['status' => 'inactive'])->assertForbidden();
        $this->actingAs($admin)->postJson(self::BASE.'/groups', ['name' => 'Late'])->assertForbidden();
        $this->actingAs($admin)->putJson(self::BASE."/students/{$student->id}/groups", ['group_ids' => []])->assertForbidden();
        $this->assertSame([1, 0], [EducationStudent::count(), EducationGroup::count()]);
    }

    public function test_tenant_isolation_between_accounts(): void
    {
        [$a, $adminA] = $this->ready();
        [$b, $adminB] = $this->ready();
        $studentA = $this->student($a, $this->contact($a, '919800000200', 'A-kid'));
        $groupA = $this->group($a, 'A-class');
        $studentB = $this->student($b, $this->contact($b, '919800000201', 'B-kid'));
        $groupB = $this->group($b, 'B-class');
        $dadB = $this->contact($b, '919800000202', 'B-dad');

        // reading
        $this->actingAs($adminB)->getJson(self::BASE."/students/{$studentA->id}")->assertNotFound();
        $this->actingAs($adminB)->getJson(self::BASE."/groups/{$groupA->id}")->assertNotFound();
        $this->assertSame([$studentB->id], array_column($this->actingAs($adminB)->getJson(self::BASE.'/students')->json('data'), 'id'));
        $this->assertSame([$groupB->id], array_column($this->actingAs($adminB)->getJson(self::BASE.'/groups')->json('data'), 'id'));
        $this->assertStringNotContainsString('A-kid', $this->actingAs($adminB)->getJson(self::BASE.'/students?search=A-kid')->getContent());

        // writing to the other tenant's records
        $this->actingAs($adminB)->patchJson(self::BASE."/students/{$studentA->id}", ['status' => 'inactive'])->assertNotFound();
        $this->actingAs($adminB)->patchJson(self::BASE."/groups/{$groupA->id}", ['name' => 'Hijacked'])->assertNotFound();
        $this->actingAs($adminB)->putJson(self::BASE."/students/{$studentA->id}/groups", ['group_ids' => [$groupB->id]])->assertNotFound();
        $this->actingAs($adminB)->postJson(self::BASE."/students/{$studentA->id}/guardians", ['contact_id' => $dadB->id, 'relationship' => 'parent'])->assertNotFound();
        $this->assertSame('active', $studentA->fresh()->status);

        // linking the other tenant's contact / group to MY student
        $this->actingAs($adminA)->postJson(self::BASE."/students/{$studentA->id}/guardians", ['contact_id' => $dadB->id, 'relationship' => 'parent'])->assertStatus(422);
        $this->actingAs($adminA)->putJson(self::BASE."/students/{$studentA->id}/groups", ['group_ids' => [$groupB->id]])->assertStatus(422);
        $this->actingAs($adminA)->postJson(self::BASE.'/students', ['contact_id' => $dadB->id])->assertStatus(422);
        $this->assertSame(0, EducationStudentGuardian::count());
        $this->assertSame(0, DB::table('education_group_members')->count());
    }

    public function test_a_forged_account_id_cannot_change_who_owns_a_record(): void
    {
        [$a, $adminA] = $this->ready();
        [$b] = $this->ready();

        // body, and query
        $this->actingAs($adminA)->postJson(self::BASE.'/students', ['phone_number' => '919800000210', 'account_id' => $b->id])->assertCreated();
        $this->actingAs($adminA)->postJson(self::BASE.'/groups?account_id='.$b->id, ['name' => 'Forged', 'account_id' => $b->id]);

        $this->assertSame(0, EducationStudent::where('account_id', $b->id)->count());
        $this->assertSame(0, EducationGroup::where('account_id', $b->id)->count());
        $this->assertSame(1, EducationStudent::where('account_id', $a->id)->count());
        $this->assertSame(0, Contact::where('account_id', $b->id)->count());
    }

    public function test_an_agent_reaches_only_its_own_clients_and_cannot_use_its_clients_data_by_default(): void
    {
        $agent = $this->school('school', ['account_type' => 'agent']);
        $own = $this->school();
        $own->forceFill(['agent_id' => $agent->id])->save();
        $otherAgent = $this->school('school', ['account_type' => 'agent']);
        $strangers = $this->school();
        $strangers->forceFill(['agent_id' => $otherAgent->id])->save();
        $strangerStudent = $this->student($strangers);
        $ownStudent = $this->student($own);
        $actor = $this->userOf($agent);

        $this->actingAs($actor)->getJson(self::BASE.'/students?account_id='.$own->id)->assertOk()->assertJsonPath('data.0.id', $ownStudent->id);
        $this->actingAs($actor)->postJson(self::BASE.'/students?account_id='.$own->id, ['phone_number' => '919800000220'])->assertCreated();
        $this->assertSame(1, EducationStudent::where('account_id', $own->id)->where('id', '!=', $ownStudent->id)->count());

        $this->actingAs($actor)->getJson(self::BASE.'/students?account_id='.$strangers->id)->assertNotFound();
        $this->actingAs($actor)->getJson(self::BASE."/students/{$strangerStudent->id}?account_id=".$strangers->id)->assertNotFound();
        $this->actingAs($actor)->postJson(self::BASE.'/students?account_id='.$strangers->id, ['phone_number' => '919800000221'])->assertNotFound();
        $this->assertSame(1, EducationStudent::where('account_id', $strangers->id)->count());

        // Acting as itself, the agent sees only its own (empty) education data, never a child's.
        $this->assertSame([], $this->actingAs($actor)->getJson(self::BASE.'/students')->json('data'));
        // The target is still gated by the TARGET's industry/capability: a client without the capability is refused.
        $noCap = Account::factory()->create(['agent_id' => $agent->id]);
        Subscription::factory()->create(['account_id' => $noCap->id]);
        AccountIndustry::create(['account_id' => $noCap->id, 'industry' => 'education', 'subtype' => 'coaching']);
        $this->actingAs($actor)->getJson(self::BASE.'/students?account_id='.$noCap->id)->assertForbidden()->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
    }

    public function test_super_admin_must_pick_a_client_and_never_falls_back_to_the_platform_account(): void
    {
        $client = $this->school();
        $this->student($client, $this->contact($client, '919800000230', 'Client kid'));
        $platform = Account::factory()->create(['account_type' => 'super_admin', 'company_name' => 'Platform (Super Admin)']);
        Subscription::factory()->create(['account_id' => $platform->id]);
        $this->grant($platform, 'industry_education');
        AccountIndustry::create(['account_id' => $platform->id, 'industry' => 'education', 'subtype' => 'school']);
        $this->student($platform, $this->contact($platform, '919800000231', 'Platform kid'));
        $admin = $this->superAdmin();
        $this->app['env'] = 'local';

        foreach ([['getJson', '/students', []], ['getJson', '/groups', []], ['postJson', '/students', ['phone_number' => '919800000232']], ['postJson', '/groups', ['name' => 'X']]] as [$method, $path, $body]) {
            $this->actingAs($admin)->{$method}(self::BASE.$path, $body)->assertStatus(422);
        }
        $this->assertSame(1, EducationStudent::where('account_id', $platform->id)->count());

        $this->actingAs($admin)->getJson(self::BASE.'/students?account_id='.$client->id)->assertOk()->assertJsonPath('data.0.contact.name', 'Client kid')->assertJsonCount(1, 'data');
        $this->actingAs($admin)->postJson(self::BASE.'/students?account_id='.$client->id, ['phone_number' => '919800000233'])->assertCreated();
        $this->assertSame(1, EducationStudent::where('account_id', $platform->id)->count(), 'nothing landed on the Platform account');
        $this->actingAs($admin)->getJson(self::BASE.'/students?account_id=99999999')->assertNotFound();

        // The client's own gates still apply to a Super Admin acting on it (capability / industry), but not its plan.
        $bare = Account::factory()->create();
        $this->actingAs($admin)->getJson(self::BASE.'/students?account_id='.$bare->id)->assertForbidden();
    }

    public function test_the_source_of_a_student_never_changes_authorization(): void
    {
        [$account, $admin] = $this->ready();
        // Created directly (as a Journey / API / import would, through the same service) …
        $service = app(\App\Services\Education\EducationStudentService::class);
        $auto = $service->create($account, ['phone_number' => '919800000240', 'name' => 'Imported']);
        // … or by hand: the same row, the same gate.
        $manual = $this->actingAs($admin)->postJson(self::BASE.'/students', ['phone_number' => '919800000241'])->assertCreated()->json('data.id');

        Role::firstOrCreate(['name' => 'edu_viewer2', 'guard_name' => 'web'])->syncPermissions(['view-education']);
        $viewer = $this->userOf($account, 'edu_viewer2');
        foreach ([$auto->id, $manual] as $id) {
            $this->actingAs($viewer)->getJson(self::BASE."/students/{$id}")->assertOk();
            $this->actingAs($viewer)->patchJson(self::BASE."/students/{$id}", ['status' => 'inactive'])->assertForbidden();
            $this->actingAs($admin)->patchJson(self::BASE."/students/{$id}", ['status' => 'inactive'])->assertOk();
        }
    }

    // ================================================================== capability, plans, navigation

    public function test_the_education_capability_is_in_no_seeded_plan_and_is_sold_through_plan_management(): void
    {
        $this->assertSame(0, Plan::query()->whereHas('capabilities', fn ($q) => $q->where('slug', 'industry_education'))->count(), 'no seeded plan bundles Education');

        $super = $this->superAdmin();
        $this->actingAs($super)->postJson('/api/admin/plans-management', [
            'slug' => 'school_pack', 'label' => 'School Pack', 'price' => 1499, 'duration_days' => 30, 'engine_type' => 'meta', 'billing_model' => 'flat_quota',
            'total_allocated_messages' => 1000, 'capabilities' => ['whatsapp_send', 'crm', 'industry_education'],
        ])->assertSuccessful();
        $this->assertContains('industry_education', Plan::where('slug', 'school_pack')->first()->capabilities->pluck('slug')->all());

        // …or by an entitlement grant on one account.
        $account = Account::factory()->create();
        $this->actingAs($super)->postJson("/api/admin/accounts/{$account->id}/entitlements", ['capability' => 'industry_education'])->assertSuccessful();
        $this->assertTrue(app(\App\Services\Access\AccessControlService::class)->canTenant($account, 'industry_education'));
    }

    public function test_education_navigation_keys_appear_only_when_every_condition_holds(): void
    {
        [$account, $admin] = $this->ready();

        $keys = $this->actingAs($admin)->getJson('/api/auth/me')->json('user.industry_modules');
        $this->assertEqualsCanonicalizing(['education.students', 'education.batches', 'education.attendance'], $keys);

        // Fees also needs the generic billing_collections capability, which this account does not hold.
        $this->assertNotContains('education.fees', $keys);

        Role::firstOrCreate(['name' => 'edu_none2', 'guard_name' => 'web'])->syncPermissions(['view-analytics']);
        $this->assertSame([], $this->actingAs($this->userOf($account, 'edu_none2'))->getJson('/api/auth/me')->json('user.industry_modules'));

        AccountEntitlement::where('account_id', $account->id)->update(['revoked_at' => now()]);
        $this->assertSame([], $this->actingAs($admin)->getJson('/api/auth/me')->json('user.industry_modules'));
        AccountEntitlement::where('account_id', $account->id)->update(['revoked_at' => null]);

        AccountIndustry::where('account_id', $account->id)->delete();
        $this->assertSame([], $this->actingAs($admin)->getJson('/api/auth/me')->json('user.industry_modules'));
    }

    public function test_the_registry_and_permissions_for_education_are_consistent(): void
    {
        $education = config('industries.industries.education');

        $this->assertSame(['school', 'coaching', 'institute'], array_keys($education['verticals']));
        foreach ($education['verticals'] as $key => $_) {
            $this->assertContains($education['vertical_config'][$key]['group_kind'], ['class', 'batch']);
        }
        foreach (['students', 'batches', 'attendance'] as $module) {
            $this->assertTrue($education['modules'][$module]['available']);
            $this->assertSame('view-education', $education['modules'][$module]['permission']);
            $this->assertSame('manage-education', $education['modules'][$module]['write_permission']);
            $this->assertSame('contact', $education['modules'][$module]['crm_anchor']);
        }
        $this->assertTrue($education['modules']['fees']['available']);
        $this->assertSame('billing_collections', $education['modules']['fees']['requires_capability'], 'fees rides on the generic core');
        foreach (['view-education', 'manage-education'] as $permission) {
            $this->assertTrue(\Spatie\Permission\Models\Permission::where('name', $permission)->exists());
        }
        $this->assertTrue(Role::findByName('admin')->hasPermissionTo('manage-education'));
        $this->assertFalse(Role::findByName('user')->hasPermissionTo('view-education'), 'ordinary staff do not get Education by default');
    }

    public function test_existing_crm_and_other_industries_are_unaffected(): void
    {
        [$account, $admin] = $this->ready();
        $this->grant($account, 'crm');
        $admin->givePermissionTo('manage-crm');
        $this->student($account);

        $this->actingAs($admin)->getJson('/api/crm/leads')->assertOk();
        $this->actingAs($admin)->getJson('/api/crm/contacts')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson('/api/industry/context')->assertOk()->assertJsonPath('data.0.industry', 'education');

        // A client with no Education assignment is untouched by any of it.
        $plain = Account::factory()->create();
        Subscription::factory()->create(['account_id' => $plain->id]);
        $this->grant($plain, 'crm');
        $plainAdmin = $this->userOf($plain);
        $plainAdmin->givePermissionTo('manage-crm');
        $this->actingAs($plainAdmin)->getJson('/api/crm/leads')->assertOk();
        $this->actingAs($plainAdmin)->getJson(self::BASE.'/students')->assertForbidden();
    }
}
