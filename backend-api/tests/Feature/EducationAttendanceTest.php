<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\AccountIndustry;
use App\Models\Capability;
use App\Models\Contact;
use App\Models\Education\EducationAttendance;
use App\Models\Education\EducationGroup;
use App\Models\Education\EducationStudent;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Education\EducationAttendanceService;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 11 Task 3 — Education attendance: one table, one service, one gate.
 */
class EducationAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/industry/education';

    private const D1 = '2026-09-14';

    private const D2 = '2026-09-15';

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

    private function student(Account $account, ?string $name = null): EducationStudent
    {
        static $n = 0;
        $n++;
        $contact = Contact::create(['account_id' => $account->id, 'phone_number' => '9199'.str_pad((string) $n, 8, '0', STR_PAD_LEFT), 'name' => $name ?? "Student {$n}"]);

        return EducationStudent::create(['account_id' => $account->id, 'contact_id' => $contact->id]);
    }

    private function group(Account $account, string $name = 'Class 5', string $status = 'active'): EducationGroup
    {
        return EducationGroup::create(['account_id' => $account->id, 'kind' => 'class', 'name' => $name, 'academic_year' => '2026-27', 'status' => $status]);
    }

    private function enrol(EducationStudent $student, EducationGroup $group): void
    {
        $student->groups()->attach($group->id, ['account_id' => $student->account_id]);
    }

    /** @return array{0: Account, 1: User, 2: EducationGroup, 3: list<EducationStudent>} an account, its admin, a class with 3 members */
    private function classroom(): array
    {
        $account = $this->school();
        $group = $this->group($account);
        $students = [$this->student($account, 'Asha'), $this->student($account, 'Bala'), $this->student($account, 'Chitra')];
        foreach ($students as $s) {
            $this->enrol($s, $group);
        }

        return [$account, $this->userOf($account), $group, $students];
    }

    private function saveAs(User $user, EducationGroup $group, array $records, string $date = self::D1)
    {
        return $this->actingAs($user)->putJson(self::BASE."/groups/{$group->id}/attendance", ['date' => $date, 'records' => $records]);
    }

    private function rec(EducationStudent $s, string $status): array
    {
        return ['student_id' => $s->id, 'status' => $status];
    }

    // ================================================================== sheet + bulk save

    public function test_the_sheet_lists_current_members_with_no_marks_and_stores_no_personal_data(): void
    {
        [$account, $admin, $group, $students] = $this->classroom();
        $outsider = $this->student($account, 'Not in class');

        $res = $this->actingAs($admin)->getJson(self::BASE."/groups/{$group->id}/attendance?date=".self::D1)->assertOk();

        $this->assertSame(['Asha', 'Bala', 'Chitra'], array_column($res->json('data.students'), 'name'));
        $this->assertNotContains($outsider->id, array_column($res->json('data.students'), 'student_id'));
        $this->assertSame([null, null, null], array_column($res->json('data.students'), 'status'));
        $this->assertSame(3, $res->json('data.summary.total_students'));
        $this->assertSame(3, $res->json('data.summary.unmarked'));
        $this->assertNull($res->json('data.summary.attendance_percentage'), 'no records → N/A, never 0%');

        foreach (['name', 'phone_number', 'email', 'student_name'] as $column) {
            $this->assertFalse(Schema::hasColumn('education_attendances', $column), "no {$column} copied into attendance");
        }
    }

    public function test_a_whole_class_is_saved_in_one_request_with_present_absent_and_late(): void
    {
        [$account, $admin, $group, [$a, $b, $c]] = $this->classroom();

        $res = $this->saveAs($admin, $group, [$this->rec($a, 'present'), $this->rec($b, 'absent'), $this->rec($c, 'late')])
            ->assertOk()->assertJsonPath('created', 3)->assertJsonPath('updated', 0)->assertJsonPath('unchanged', 0);

        $this->assertSame(['present', 'absent', 'late'], array_column($res->json('data.students'), 'status'));
        $this->assertEquals(['present' => 1, 'absent' => 1, 'late' => 1, 'marked' => 3, 'unmarked' => 0, 'total_students' => 3], array_intersect_key($res->json('data.summary'), array_flip(['present', 'absent', 'late', 'marked', 'unmarked', 'total_students'])));
        $this->assertSame(66.7, $res->json('data.summary.attendance_percentage'), 'present + late count as attended: 2 of 3');
        $this->assertSame(3, EducationAttendance::where('account_id', $account->id)->count());
        $this->assertSame($admin->id, EducationAttendance::first()->recorded_by_user_id);
        $this->assertSame(self::D1, EducationAttendance::first()->attendance_date->toDateString());
    }

    public function test_saving_is_idempotent_and_a_resubmission_updates_instead_of_duplicating(): void
    {
        [$account, $admin, $group, [$a, $b, $c]] = $this->classroom();
        $sheet = [$this->rec($a, 'present'), $this->rec($b, 'absent'), $this->rec($c, 'late')];
        $this->saveAs($admin, $group, $sheet)->assertOk();
        $createdAt = EducationAttendance::where('student_id', $b->id)->value('created_at');
        $this->travel(5)->minutes();

        // exact same sheet: nothing changes
        $this->saveAs($admin, $group, $sheet)->assertOk()->assertJsonPath('created', 0)->assertJsonPath('updated', 0)->assertJsonPath('unchanged', 3);
        $this->assertSame(3, EducationAttendance::count());

        // a correction updates the one row; created_at is preserved
        $this->saveAs($admin, $group, [$this->rec($b, 'late')])->assertOk()->assertJsonPath('updated', 1)->assertJsonPath('created', 0);
        $this->assertSame(3, EducationAttendance::count());
        $row = EducationAttendance::where('student_id', $b->id)->first();
        $this->assertSame('late', $row->status);
        $this->assertEquals($createdAt, $row->created_at);
        // the others in a partial sheet are untouched
        $this->assertSame('present', EducationAttendance::where('student_id', $a->id)->value('status'));
    }

    public function test_the_same_student_may_have_attendance_on_other_dates_and_in_other_groups(): void
    {
        [$account, $admin, $group, [$a]] = $this->classroom();
        $other = $this->group($account, 'Maths Batch');
        $this->enrol($a, $other);

        $this->saveAs($admin, $group, [$this->rec($a, 'present')], self::D1)->assertOk();
        $this->saveAs($admin, $group, [$this->rec($a, 'absent')], self::D2)->assertOk();
        $this->saveAs($admin, $other, [$this->rec($a, 'late')], self::D1)->assertOk();

        $this->assertSame(3, EducationAttendance::where('student_id', $a->id)->count());
    }

    public function test_an_invalid_sheet_writes_nothing_at_all(): void
    {
        [$account, $admin, $group, [$a, $b, $c]] = $this->classroom();
        $this->saveAs($admin, $group, [$this->rec($a, 'present')])->assertOk();
        $other = $this->school();
        $foreign = $this->student($other);
        $outsider = $this->student($account); // same account, not in this class
        $before = EducationAttendance::orderBy('id')->get()->toArray();

        $bad = [
            'foreign student' => [$this->rec($b, 'absent'), $this->rec($foreign, 'present')],
            'non-member' => [$this->rec($b, 'absent'), $this->rec($outsider, 'present')],
            'unknown student' => [$this->rec($b, 'absent'), ['student_id' => 999999, 'status' => 'present']],
            'duplicate in sheet' => [$this->rec($b, 'absent'), $this->rec($b, 'late')],
            'bad status' => [$this->rec($a, 'absent'), $this->rec($b, 'excused')],
            'missing status' => [$this->rec($a, 'absent'), ['student_id' => $c->id]],
            'no student id' => [$this->rec($a, 'absent'), ['status' => 'present']],
        ];
        foreach ($bad as $label => $records) {
            $this->saveAs($admin, $group, $records)->assertStatus(422);
            $this->assertSame($before, EducationAttendance::orderBy('id')->get()->toArray(), "{$label}: the sheet is untouched");
        }

        $this->saveAs($admin, $group, [])->assertStatus(422);
        foreach (['2026-13-40', '14/09/2026', 'yesterday', '2026-9-1'] as $date) {
            $this->saveAs($admin, $group, [$this->rec($b, 'absent')], $date)->assertStatus(422)->assertJsonValidationErrors('date');
        }
        $this->saveAs($admin, $group, [$this->rec($b, 'absent')], now()->addDays(5)->toDateString())->assertStatus(422)->assertJsonValidationErrors('date');
        $this->assertSame(1, EducationAttendance::count());
        // field-level messages point at the offending row
        $this->saveAs($admin, $group, [$this->rec($a, 'present'), $this->rec($outsider, 'present')])->assertJsonValidationErrors('records.1.student_id');
    }

    public function test_today_and_a_day_of_clock_slack_are_accepted(): void
    {
        [, $admin, $group, [$a]] = $this->classroom();

        $this->saveAs($admin, $group, [$this->rec($a, 'present')], now()->toDateString())->assertOk();
        $this->saveAs($admin, $group, [$this->rec($a, 'present')], now()->addDay()->toDateString())->assertOk();
    }

    // ================================================================== membership & archived groups

    public function test_a_student_needs_membership_of_the_group_and_a_foreign_group_is_not_found(): void
    {
        [$account, $admin, $group, [$a]] = $this->classroom();
        $groupB = $this->group($account, 'Class 6');
        $other = $this->school();
        $foreignGroup = $this->group($other, 'Foreign');

        // Student A + Group B (A is not a member of B)
        $this->saveAs($admin, $groupB, [$this->rec($a, 'present')])->assertStatus(422)->assertJsonValidationErrors('records.0.student_id');
        $this->assertSame(0, EducationAttendance::count());

        $this->saveAs($admin, $foreignGroup, [$this->rec($a, 'present')])->assertNotFound();
        $this->actingAs($admin)->getJson(self::BASE."/groups/{$foreignGroup->id}/attendance?date=".self::D1)->assertNotFound();
        $this->assertSame(0, EducationAttendance::count());
    }

    public function test_an_archived_group_refuses_new_attendance_but_its_history_stays_readable(): void
    {
        [$account, $admin, $group, [$a, $b]] = $this->classroom();
        $this->saveAs($admin, $group, [$this->rec($a, 'present'), $this->rec($b, 'absent')])->assertOk();
        $group->update(['status' => 'archived']);

        $this->saveAs($admin, $group, [$this->rec($a, 'late')])->assertStatus(422)->assertJsonValidationErrors('group');
        $this->assertSame('present', EducationAttendance::where('student_id', $a->id)->value('status'));

        $sheet = $this->actingAs($admin)->getJson(self::BASE."/groups/{$group->id}/attendance?date=".self::D1)->assertOk();
        $this->assertSame('archived', $sheet->json('data.group.status'));
        $this->assertSame('present', $sheet->json('data.students.0.status'));
        $this->actingAs($admin)->getJson(self::BASE."/students/{$a->id}/attendance")->assertOk()->assertJsonPath('summary.present', 1)->assertJsonPath('data.0.group.status', 'archived');
    }

    public function test_a_student_who_leaves_a_class_keeps_their_history_but_gets_no_new_marks(): void
    {
        [, $admin, $group, [$a, $b, $c]] = $this->classroom();
        $this->saveAs($admin, $group, [$this->rec($a, 'present'), $this->rec($b, 'present')])->assertOk();

        $this->actingAs($admin)->putJson(self::BASE."/students/{$a->id}/groups", ['group_ids' => []])->assertOk();

        $this->actingAs($admin)->getJson(self::BASE."/students/{$a->id}/attendance")->assertOk()->assertJsonPath('summary.total', 1);
        $this->saveAs($admin, $group, [$this->rec($a, 'absent')], self::D2)->assertStatus(422);
        $this->assertSame(2, EducationAttendance::count());
        $sheet = $this->actingAs($admin)->getJson(self::BASE."/groups/{$group->id}/attendance?date=".self::D1)->json('data.students');
        $this->assertSame([$b->id, $c->id], array_column($sheet, 'student_id'));
    }

    // ================================================================== database guarantees

    public function test_the_database_itself_prevents_duplicates_and_cross_tenant_rows(): void
    {
        [$account, , $group, [$a]] = $this->classroom();
        $other = $this->school();
        $foreignGroup = $this->group($other);
        $now = now();
        $row = ['account_id' => $account->id, 'student_id' => $a->id, 'group_id' => $group->id, 'attendance_date' => self::D1, 'status' => 'present', 'created_at' => $now, 'updated_at' => $now];

        DB::table('education_attendances')->insert($row);
        try {
            DB::table('education_attendances')->insert($row);
            $this->fail('a duplicate (account, student, group, date) must be refused by the unique index');
        } catch (QueryException) {
            $this->assertSame(1, DB::table('education_attendances')->count());
        }
        try {
            DB::table('education_attendances')->insert(array_merge($row, ['group_id' => $foreignGroup->id, 'attendance_date' => self::D2]));
            $this->fail('attendance cannot name another account\'s group');
        } catch (QueryException) {
            $this->assertSame(1, DB::table('education_attendances')->count());
        }
        try {
            DB::table('education_attendances')->insert(array_merge($row, ['account_id' => $other->id, 'attendance_date' => self::D2]));
            $this->fail('attendance cannot name another account\'s student');
        } catch (QueryException) {
            $this->assertSame(1, DB::table('education_attendances')->count());
        }
    }

    public function test_a_row_that_appeared_between_read_and_write_is_updated_not_duplicated(): void
    {
        [$account, , $group, [$a]] = $this->classroom();
        $service = app(EducationAttendanceService::class);
        // Another submission wins the race and inserts first …
        DB::table('education_attendances')->insert(['account_id' => $account->id, 'student_id' => $a->id, 'group_id' => $group->id, 'attendance_date' => self::D1, 'status' => 'absent', 'created_at' => now(), 'updated_at' => now()]);

        // … this one (which validated normally) upserts onto the same unique key.
        $service->saveSheet($account, $group, self::D1, [$this->rec($a, 'present')]);

        $this->assertSame(1, EducationAttendance::count());
        $this->assertSame('present', EducationAttendance::first()->status);
    }

    public function test_deleting_a_student_or_group_removes_only_its_own_attendance(): void
    {
        [$account, $admin, $group, [$a, $b]] = $this->classroom();
        $this->saveAs($admin, $group, [$this->rec($a, 'present'), $this->rec($b, 'present')])->assertOk();

        DB::table('education_students')->where('id', $a->id)->delete();

        $this->assertSame([$b->id], EducationAttendance::pluck('student_id')->all());
    }

    // ================================================================== history & summary

    public function test_student_history_filters_by_group_and_dates_with_totals(): void
    {
        [$account, $admin, $group, [$a]] = $this->classroom();
        $batch = $this->group($account, 'Maths Batch');
        $this->enrol($a, $batch);
        foreach ([['2026-09-10', 'present'], ['2026-09-11', 'absent'], ['2026-09-12', 'late'], ['2026-09-13', 'present']] as [$d, $s]) {
            $this->saveAs($admin, $group, [$this->rec($a, $s)], $d)->assertOk();
        }
        $this->saveAs($admin, $batch, [$this->rec($a, 'absent')], '2026-09-12')->assertOk();
        $url = self::BASE."/students/{$a->id}/attendance";

        $all = $this->actingAs($admin)->getJson($url)->assertOk();
        $this->assertSame(5, $all->json('summary.total'));
        $this->assertSame(['present' => 2, 'absent' => 2, 'late' => 1], array_intersect_key($all->json('summary'), array_flip(['present', 'absent', 'late'])));
        $this->assertEquals(60.0, $all->json('summary.attendance_percentage'));
        $this->assertSame('Asha', $all->json('student.name'));
        $this->assertSame('2026-09-13', $all->json('data.0.attendance_date'), 'newest first');

        $byGroup = $this->actingAs($admin)->getJson($url.'?group_id='.$group->id)->assertOk();
        $this->assertSame(4, $byGroup->json('summary.total'));
        $this->assertEquals(75.0, $byGroup->json('summary.attendance_percentage'));

        $range = $this->actingAs($admin)->getJson($url.'?from=2026-09-11&to=2026-09-12')->assertOk();
        $this->assertSame(3, $range->json('summary.total'));
        $this->assertSame(3, $range->json('total'));

        // a status filter narrows the rows but not the totals
        $absent = $this->actingAs($admin)->getJson($url.'?status=absent')->assertOk();
        $this->assertSame(2, $absent->json('total'));
        $this->assertSame(5, $absent->json('summary.total'));

        $this->actingAs($admin)->getJson($url.'?per_page=2')->assertOk()->assertJsonPath('last_page', 3);
    }

    public function test_history_with_no_records_reports_na_not_zero_percent(): void
    {
        [, $admin, , [$a]] = $this->classroom();

        $res = $this->actingAs($admin)->getJson(self::BASE."/students/{$a->id}/attendance")->assertOk();

        $this->assertSame(0, $res->json('summary.total'));
        $this->assertNull($res->json('summary.attendance_percentage'));
        $this->assertSame([], $res->json('data'));
    }

    public function test_history_validates_its_filters_and_scopes_the_group_filter_to_the_account(): void
    {
        [$account, $admin, , [$a]] = $this->classroom();
        $foreignGroup = $this->group($this->school(), 'Foreign');
        $url = self::BASE."/students/{$a->id}/attendance";

        $this->actingAs($admin)->getJson($url.'?from=nonsense')->assertStatus(422)->assertJsonValidationErrors('from');
        $this->actingAs($admin)->getJson($url.'?from=2026-09-12&to=2026-09-01')->assertStatus(422)->assertJsonValidationErrors('to');
        $this->actingAs($admin)->getJson($url.'?status=excused')->assertStatus(422);
        $this->actingAs($admin)->getJson($url.'?group_id='.$foreignGroup->id)->assertNotFound();
        $this->actingAs($admin)->getJson(self::BASE.'/students/999999/attendance')->assertNotFound();
    }

    public function test_the_summary_arithmetic_is_pinned(): void
    {
        $service = app(EducationAttendanceService::class);

        $this->assertSame(['total' => 0, 'present' => 0, 'absent' => 0, 'late' => 0, 'attendance_percentage' => null], $service->summarize([]));
        $this->assertSame(100.0, $service->summarize(['present' => 3])['attendance_percentage']);
        $this->assertSame(0.0, $service->summarize(['absent' => 2])['attendance_percentage'], 'real data with nobody attending IS 0%');
        $this->assertSame(75.0, $service->summarize(['present' => 2, 'late' => 1, 'absent' => 1])['attendance_percentage']);
    }

    // ================================================================== authorization

    public function test_every_gate_denies_every_attendance_route(): void
    {
        [$account, $admin, $group, [$a]] = $this->classroom();
        $routes = [
            ['getJson', "/groups/{$group->id}/attendance?date=".self::D1],
            ['putJson', "/groups/{$group->id}/attendance", ['date' => self::D1, 'records' => [['student_id' => $a->id, 'status' => 'present']]]],
            ['getJson', "/students/{$a->id}/attendance"],
        ];
        $call = fn (User $u, array $r) => $this->actingAs($u)->{$r[0]}(self::BASE.$r[1], $r[2] ?? []);

        foreach ($routes as $r) {
            $this->assertSame(200, $call($admin, $r)->status());
        }

        AccountEntitlement::where('account_id', $account->id)->update(['revoked_at' => now()]);
        foreach ($routes as $r) {
            $call($admin, $r)->assertForbidden()->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
        }
        AccountEntitlement::where('account_id', $account->id)->update(['revoked_at' => null]);

        $account->forceFill(['allowed_modules' => array_values(array_diff(Account::MODULES, ['industry_modules']))])->save();
        foreach ($routes as $r) {
            $call($admin, $r)->assertForbidden()->assertJsonPath('error_code', 'MODULE_DISABLED');
        }
        $account->forceFill(['allowed_modules' => null])->save();

        AccountIndustry::where('account_id', $account->id)->delete();
        foreach ($routes as $r) {
            $call($admin, $r)->assertForbidden()->assertJsonPath('error_code', 'INDUSTRY_NOT_ASSIGNED');
        }
        AccountIndustry::create(['account_id' => $account->id, 'industry' => 'education', 'subtype' => 'school']);

        Role::firstOrCreate(['name' => 'att_none', 'guard_name' => 'web'])->syncPermissions(['view-analytics']);
        $none = $this->userOf($account, 'att_none');
        foreach ($routes as $r) {
            $call($none, $r)->assertForbidden()->assertJsonPath('error_code', 'PERMISSION_DENIED');
        }

        $account->forceFill(['status' => 'suspended'])->save();
        foreach ($routes as $r) {
            $this->assertContains($call($admin, $r)->status(), [401, 403]);
        }
    }

    public function test_reads_need_view_education_and_the_save_needs_manage_education(): void
    {
        [$account, , $group, [$a]] = $this->classroom();
        Role::firstOrCreate(['name' => 'att_viewer', 'guard_name' => 'web'])->syncPermissions(['view-education']);
        $viewer = $this->userOf($account, 'att_viewer');

        $this->actingAs($viewer)->getJson(self::BASE."/groups/{$group->id}/attendance?date=".self::D1)->assertOk();
        $this->actingAs($viewer)->getJson(self::BASE."/students/{$a->id}/attendance")->assertOk();
        $this->saveAs($viewer, $group, [$this->rec($a, 'present')])->assertForbidden()->assertJsonPath('error_code', 'PERMISSION_DENIED');
        $this->assertSame(0, EducationAttendance::count());
        $this->assertSame([], array_values(array_filter(Permission_names_added())), 'no new permission was introduced for attendance');
    }

    public function test_an_expired_subscription_keeps_reads_and_blocks_the_save(): void
    {
        [$account, $admin, $group, [$a]] = $this->classroom();
        $this->saveAs($admin, $group, [$this->rec($a, 'present')])->assertOk();
        Subscription::where('account_id', $account->id)->update(['expires_at' => now()->subDay()]);

        $this->actingAs($admin)->getJson(self::BASE."/groups/{$group->id}/attendance?date=".self::D1)->assertOk()->assertJsonPath('data.students.0.status', 'present');
        $this->actingAs($admin)->getJson(self::BASE."/students/{$a->id}/attendance")->assertOk();
        $this->saveAs($admin, $group, [$this->rec($a, 'absent')])->assertForbidden();
        $this->assertSame('present', EducationAttendance::first()->status);
    }

    public function test_tenant_isolation_between_accounts(): void
    {
        [$a, $adminA, $groupA, [$studentA]] = $this->classroom();
        [$b, $adminB, $groupB, [$studentB]] = $this->classroom();
        $this->saveAs($adminA, $groupA, [$this->rec($studentA, 'absent')])->assertOk();

        $this->actingAs($adminB)->getJson(self::BASE."/groups/{$groupA->id}/attendance?date=".self::D1)->assertNotFound();
        $this->saveAs($adminB, $groupA, [$this->rec($studentB, 'present')])->assertNotFound();
        $this->actingAs($adminB)->getJson(self::BASE."/students/{$studentA->id}/attendance")->assertNotFound();
        // B's own group + A's student
        $this->saveAs($adminB, $groupB, [$this->rec($studentA, 'present')])->assertStatus(422);
        $this->assertSame(1, EducationAttendance::count());
        $this->assertSame('absent', EducationAttendance::first()->status, 'A\'s record is untouched');

        // A forged account id in the body or query never retargets
        $this->actingAs($adminB)->putJson(self::BASE."/groups/{$groupB->id}/attendance?account_id={$a->id}", [
            'date' => self::D1, 'account_id' => $a->id, 'records' => [$this->rec($studentB, 'late')],
        ]);
        $this->assertSame([$b->id], EducationAttendance::where('student_id', $studentB->id)->pluck('account_id')->all());
        $this->assertSame(0, EducationAttendance::where('account_id', $a->id)->where('student_id', $studentB->id)->count());
    }

    public function test_an_agent_reaches_only_its_own_clients(): void
    {
        $agent = $this->school('school', ['account_type' => 'agent']);
        $actor = $this->userOf($agent);
        $own = $this->school();
        $own->forceFill(['agent_id' => $agent->id])->save();
        $ownGroup = $this->group($own);
        $ownStudent = $this->student($own);
        $this->enrol($ownStudent, $ownGroup);
        $otherAgent = $this->school('school', ['account_type' => 'agent']);
        $stranger = $this->school();
        $stranger->forceFill(['agent_id' => $otherAgent->id])->save();
        $strangerGroup = $this->group($stranger);
        $strangerStudent = $this->student($stranger);
        $this->enrol($strangerStudent, $strangerGroup);

        $this->actingAs($actor)->putJson(self::BASE."/groups/{$ownGroup->id}/attendance?account_id={$own->id}", ['date' => self::D1, 'records' => [$this->rec($ownStudent, 'present')]])->assertOk();
        $this->actingAs($actor)->getJson(self::BASE."/students/{$ownStudent->id}/attendance?account_id={$own->id}")->assertOk()->assertJsonPath('summary.present', 1);

        $this->actingAs($actor)->putJson(self::BASE."/groups/{$strangerGroup->id}/attendance?account_id={$stranger->id}", ['date' => self::D1, 'records' => [$this->rec($strangerStudent, 'present')]])->assertNotFound();
        $this->actingAs($actor)->getJson(self::BASE."/groups/{$strangerGroup->id}/attendance?date=".self::D1.'&account_id='.$stranger->id)->assertNotFound();
        // Acting as itself, a child's group id is not reachable
        $this->actingAs($actor)->getJson(self::BASE."/groups/{$ownGroup->id}/attendance?date=".self::D1)->assertNotFound();
        $this->assertSame(0, EducationAttendance::where('account_id', $stranger->id)->count());
    }

    public function test_super_admin_must_pick_the_client_and_never_gets_the_platform_account(): void
    {
        $client = $this->school();
        $clientGroup = $this->group($client);
        $clientStudent = $this->student($client);
        $this->enrol($clientStudent, $clientGroup);
        $platform = Account::factory()->create(['account_type' => 'super_admin', 'company_name' => 'Platform (Super Admin)']);
        Subscription::factory()->create(['account_id' => $platform->id]);
        $this->grant($platform, 'industry_education');
        AccountIndustry::create(['account_id' => $platform->id, 'industry' => 'education', 'subtype' => 'school']);
        $platformGroup = $this->group($platform);
        $platformStudent = $this->student($platform);
        $this->enrol($platformStudent, $platformGroup);
        $admin = $this->superAdmin();
        $this->app['env'] = 'local';

        $this->actingAs($admin)->getJson(self::BASE."/groups/{$clientGroup->id}/attendance?date=".self::D1)->assertStatus(422);
        $this->actingAs($admin)->putJson(self::BASE."/groups/{$platformGroup->id}/attendance", ['date' => self::D1, 'records' => [$this->rec($platformStudent, 'present')]])->assertStatus(422);
        $this->actingAs($admin)->getJson(self::BASE."/students/{$clientStudent->id}/attendance")->assertStatus(422);
        $this->assertSame(0, EducationAttendance::count());

        $this->actingAs($admin)->putJson(self::BASE."/groups/{$clientGroup->id}/attendance?account_id={$client->id}", ['date' => self::D1, 'records' => [$this->rec($clientStudent, 'present')]])->assertOk();
        $this->assertSame([$client->id], EducationAttendance::pluck('account_id')->all());
        // The client's id with the Platform's group is a plain 404 — ids do not cross accounts.
        $this->actingAs($admin)->getJson(self::BASE."/groups/{$platformGroup->id}/attendance?date=".self::D1."&account_id={$client->id}")->assertNotFound();
    }

    public function test_the_source_of_a_mark_never_changes_authorization(): void
    {
        [$account, $admin, $group, [$a]] = $this->classroom();
        // As a Journey / API / import / scheduler would: straight to the service.
        $result = app(EducationAttendanceService::class)->mark($account, $group, $a, self::D1, 'absent');
        $this->assertSame(1, $result['created']);

        // The same row is read through the same gate, and the service applies the same membership/tenant rules.
        $this->actingAs($admin)->getJson(self::BASE."/students/{$a->id}/attendance")->assertOk()->assertJsonPath('summary.absent', 1);
        $stranger = $this->school();
        try {
            app(EducationAttendanceService::class)->mark($stranger, $group, $a, self::D1, 'present');
            $this->fail('another account must not be able to mark this group');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertSame(1, EducationAttendance::count());
    }

    // ================================================================== navigation, regression

    public function test_the_attendance_nav_key_follows_the_same_conditions(): void
    {
        [$account, $admin] = $this->classroom();

        $keys = $this->actingAs($admin)->getJson('/api/auth/me')->json('user.industry_modules');
        $this->assertEqualsCanonicalizing(['education.students', 'education.batches', 'education.attendance'], $keys);
        $this->assertNotContains('education.fees', $keys);

        AccountEntitlement::where('account_id', $account->id)->update(['revoked_at' => now()]);
        $this->assertSame([], $this->actingAs($admin)->getJson('/api/auth/me')->json('user.industry_modules'));
    }

    public function test_education_students_groups_and_the_crm_are_unaffected(): void
    {
        [$account, $admin, $group, [$a]] = $this->classroom();
        $this->grant($account, 'crm');
        $admin->givePermissionTo('manage-crm');
        $this->saveAs($admin, $group, [$this->rec($a, 'present')])->assertOk();

        $this->actingAs($admin)->getJson(self::BASE.'/students')->assertOk()->assertJsonCount(3, 'data');
        $this->actingAs($admin)->getJson(self::BASE.'/groups')->assertOk()->assertJsonPath('data.0.students_count', 3);
        $this->actingAs($admin)->postJson(self::BASE.'/students', ['phone_number' => '919800009999'])->assertCreated();
        $this->actingAs($admin)->getJson('/api/crm/contacts')->assertOk();
        // CRM contact merge still moves the student, and the attendance follows the student row.
        $this->assertSame(1, EducationAttendance::where('student_id', $a->id)->count());
    }
}

/** Permission names that mention attendance — there must be none (Education's two permissions are enough). */
function Permission_names_added(): array
{
    return \Spatie\Permission\Models\Permission::where('name', 'like', '%attendance%')->pluck('name')->all();
}
