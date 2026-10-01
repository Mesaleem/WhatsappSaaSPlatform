<?php

namespace App\Services\Education;

use App\Models\Account;
use App\Models\Education\EducationAttendance;
use App\Models\Education\EducationGroup;
use App\Models\Education\EducationStudent;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 11 Task 3 — the single attendance read/write path.
 *
 *   manual UI ─┐
 *   API ───────┤
 *   Journey ───┼──►  EducationAttendanceService  ──►  education_attendances
 *   import ────┤
 *   scheduler ─┘
 *
 * Callers pass an already-resolved target Account; authorization (industry, module, capability,
 * permission, subscription) happens at the edge and never depends on which caller this is. Everything
 * here is tenant-scoped by `$account->id` and verifies that the group, the students and the date belong
 * together BEFORE writing.
 *
 * saveSheet() is transactional and idempotent: it validates the whole sheet first, then UPSERTs on
 * unique(account, student, group, date) in one statement per chunk. Submitting the same sheet twice changes
 * nothing; two simultaneous submissions end with one row per student (the database arbitrates).
 */
class EducationAttendanceService
{
    private const MAX_RECORDS = 500;

    /** How far past "today" a sheet may be dated — a day of slack so a server clock behind the school's does not lock today out. */
    private const FUTURE_DAYS_ALLOWED = 1;

    /**
     * The attendance sheet of one group on one date: every CURRENT member, with that date's status or null.
     * Works for archived groups too (history stays readable).
     *
     * @return array{date: string, group: array<string, mixed>, students: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function sheet(Account $account, EducationGroup $group, string $date): array
    {
        $date = $this->parseDate($date, 'date');
        $this->assertGroupOwned($account, $group);

        $students = EducationStudent::query()->forAccount($account->id)
            ->join('education_group_members as m', function ($join) use ($group) {
                $join->on('m.student_id', '=', 'education_students.id')->on('m.account_id', '=', 'education_students.account_id')->where('m.group_id', $group->id);
            })
            ->join('contacts', function ($join) {
                $join->on('contacts.id', '=', 'education_students.contact_id')->on('contacts.account_id', '=', 'education_students.account_id');
            })
            ->select('education_students.*')
            ->with('contact')
            ->orderBy('contacts.name')->orderBy('education_students.id')
            ->get();

        $marks = EducationAttendance::query()->forAccount($account->id)
            ->where('group_id', $group->id)->whereDate('attendance_date', $date)
            ->pluck('status', 'student_id');

        $rows = $students->map(fn (EducationStudent $s) => [
            'student_id' => $s->id,
            'name' => $s->contact?->name,
            'phone_number' => $s->contact?->phone_number,
            'admission_number' => $s->admission_number,
            'student_status' => $s->status,
            'status' => $marks[$s->id] ?? null,
        ])->values()->all();

        return [
            'date' => $date,
            'group' => ['id' => $group->id, 'name' => $group->name, 'kind' => $group->kind, 'academic_year' => $group->academic_year, 'status' => $group->status],
            'students' => $rows,
            'summary' => $this->sheetSummary($rows),
        ];
    }

    /**
     * Save a whole sheet (or any subset of it) for one group and date.
     *
     * @param  list<array{student_id: int|string, status: string}>  $records
     * @return array{created: int, updated: int, unchanged: int, sheet: array<string, mixed>}
     */
    public function saveSheet(Account $account, EducationGroup $group, string $date, array $records, ?int $recordedByUserId = null): array
    {
        $date = $this->parseDate($date, 'date');
        $this->assertGroupOwned($account, $group);

        if ($group->status !== 'active') {
            throw ValidationException::withMessages(['group' => ['This class/batch is archived and no longer accepts attendance. Its history stays readable.']]);
        }
        if (Carbon::parse($date)->gt(now()->addDays(self::FUTURE_DAYS_ALLOWED)->startOfDay())) {
            throw ValidationException::withMessages(['date' => ['Attendance cannot be recorded for a future date.']]);
        }
        if ($records === []) {
            throw ValidationException::withMessages(['records' => ['Mark at least one student.']]);
        }
        if (count($records) > self::MAX_RECORDS) {
            throw ValidationException::withMessages(['records' => ['A sheet can hold at most '.self::MAX_RECORDS.' students.']]);
        }

        $clean = $this->validateRecords($account, $group, $records);

        $counts = DB::transaction(function () use ($account, $group, $date, $clean, $recordedByUserId): array {
            $existing = EducationAttendance::query()->forAccount($account->id)->where('group_id', $group->id)
                ->whereDate('attendance_date', $date)->whereIn('student_id', array_keys($clean))->pluck('status', 'student_id');

            $created = $updated = $unchanged = 0;
            $now = now();
            $rows = [];
            foreach ($clean as $studentId => $status) {
                if (! isset($existing[$studentId])) {
                    $created++;
                } elseif ($existing[$studentId] !== $status) {
                    $updated++;
                } else {
                    $unchanged++;
                }
                $rows[] = [
                    'account_id' => $account->id, 'student_id' => $studentId, 'group_id' => $group->id, 'attendance_date' => $date,
                    'status' => $status, 'recorded_by_user_id' => $recordedByUserId, 'created_at' => $now, 'updated_at' => $now,
                ];
            }

            // Atomic INSERT … ON CONFLICT/DUPLICATE KEY UPDATE on the unique key: never a duplicate row, even
            // when two submissions race. created_at is left alone on update.
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('education_attendances')->upsert($chunk, ['account_id', 'student_id', 'group_id', 'attendance_date'], ['status', 'recorded_by_user_id', 'updated_at']);
            }

            return compact('created', 'updated', 'unchanged');
        });

        return $counts + ['sheet' => $this->sheet($account, $group, $date)];
    }

    /** Convenience for a Journey/API caller marking one student. Same validation, same transaction. */
    public function mark(Account $account, EducationGroup $group, EducationStudent $student, string $date, string $status, ?int $recordedByUserId = null): array
    {
        return $this->saveSheet($account, $group, $date, [['student_id' => $student->id, 'status' => $status]], $recordedByUserId);
    }

    /**
     * One student's attendance history, newest first, with totals over the SAME filters (not just the page).
     *
     * @param  array{group_id?: int|null, from?: string|null, to?: string|null, status?: string|null}  $filters
     * @return array{page: LengthAwarePaginator, summary: array<string, mixed>}
     */
    public function history(Account $account, EducationStudent $student, array $filters, int $perPage): array
    {
        abort_if((int) $student->account_id !== (int) $account->id, 404);

        $from = isset($filters['from']) && $filters['from'] !== '' ? $this->parseDate($filters['from'], 'from') : null;
        $to = isset($filters['to']) && $filters['to'] !== '' ? $this->parseDate($filters['to'], 'to') : null;
        if ($from && $to && $from > $to) {
            throw ValidationException::withMessages(['to' => ['The end date must not be before the start date.']]);
        }

        $base = fn () => EducationAttendance::query()->forAccount($account->id)->where('student_id', $student->id)
            ->when($filters['group_id'] ?? null, fn ($q, $v) => $q->where('group_id', $v))
            ->when($from, fn ($q) => $q->whereDate('attendance_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('attendance_date', '<=', $to));

        $page = $base()->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->with('group:id,name,kind,academic_year,status')
            ->orderByDesc('attendance_date')->orderByDesc('id')->paginate($perPage);

        // Totals ignore the status filter on purpose: a filtered list still shows the student's whole picture.
        $counts = $base()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();

        return ['page' => $page, 'summary' => $this->summarize($counts)];
    }

    /** @param array<string, int> $counts status => count */
    public function summarize(array $counts): array
    {
        $out = ['total' => array_sum($counts)];
        foreach (EducationAttendance::STATUSES as $status) {
            $out[$status] = (int) ($counts[$status] ?? 0);
        }
        $attended = array_sum(array_map(fn ($s) => (int) ($counts[$s] ?? 0), EducationAttendance::ATTENDED));
        // Present + late count as attended. With NO records the percentage is unknown (null), never 0%.
        $out['attendance_percentage'] = $out['total'] === 0 ? null : round($attended / $out['total'] * 100, 1);

        return $out;
    }

    // ------------------------------------------------------------------ internals

    /** @param list<array<string, mixed>> $rows */
    private function sheetSummary(array $rows): array
    {
        $counts = [];
        foreach ($rows as $r) {
            if ($r['status'] !== null) {
                $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
            }
        }
        $summary = $this->summarize($counts);
        $summary['total_students'] = count($rows);
        $summary['marked'] = $summary['total'];
        $summary['unmarked'] = count($rows) - $summary['total'];
        unset($summary['total']);

        return $summary;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<int, string> student_id => status, every entry verified
     */
    private function validateRecords(Account $account, EducationGroup $group, array $records): array
    {
        $errors = [];
        $clean = [];
        foreach (array_values($records) as $i => $record) {
            $studentId = $record['student_id'] ?? null;
            $status = $record['status'] ?? null;

            if (! is_numeric($studentId) || (int) $studentId <= 0) {
                $errors["records.{$i}.student_id"] = ['Choose a student.'];

                continue;
            }
            $studentId = (int) $studentId;
            if (! in_array($status, EducationAttendance::STATUSES, true)) {
                $errors["records.{$i}.status"] = ['Status must be one of: '.implode(', ', EducationAttendance::STATUSES).'.'];
            }
            if (isset($clean[$studentId])) {
                $errors["records.{$i}.student_id"] = ['This student is listed twice.'];

                continue;
            }
            $clean[$studentId] = (string) $status;
        }

        if ($clean !== []) {
            // One query: which of these students are members of THIS group of THIS account? Anything else —
            // another account's student, an unknown id, a student of a different class — is refused.
            $members = DB::table('education_group_members')->where('account_id', $account->id)->where('group_id', $group->id)
                ->whereIn('student_id', array_keys($clean))->pluck('student_id')->map(fn ($v) => (int) $v)->all();
            foreach (array_values($records) as $i => $record) {
                $sid = (int) ($record['student_id'] ?? 0);
                if ($sid > 0 && ! in_array($sid, $members, true) && ! isset($errors["records.{$i}.student_id"])) {
                    $errors["records.{$i}.student_id"] = ['This student is not a member of the selected class/batch.'];
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    private function assertGroupOwned(Account $account, EducationGroup $group): void
    {
        abort_if((int) $group->account_id !== (int) $account->id, 404, 'Class/batch not found.');
    }

    private function parseDate(string $value, string $field): string
    {
        try {
            $parsed = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            $parsed = false;
        }
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages([$field => ['Use the date format YYYY-MM-DD.']]);
        }

        return $value;
    }
}
