<?php

namespace App\Http\Controllers\Api\Industry;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\Education\EducationAttendance;
use App\Models\Education\EducationGroup;
use App\Models\Education\EducationStudent;
use App\Services\Education\EducationAttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 11 Task 3 — attendance under /api/industry/education.
 *
 *   GET /groups/{id}/attendance?date=YYYY-MM-DD   the sheet (current members + that day's marks + summary)
 *   PUT /groups/{id}/attendance                   save a whole sheet atomically/idempotently
 *   GET /students/{id}/attendance                 history (group / date filters) + totals
 *
 * Authorization is `industry.guard:education,attendance` on the route (view-education for GET,
 * manage-education + an active subscription for PUT). This controller resolves the TARGET account
 * (Super Admin must pass ?account_id=), looks the group/student up INSIDE it (a foreign id is a 404), and
 * hands everything to EducationAttendanceService — the same entry point automation will use.
 */
class EducationAttendanceController extends Controller
{
    use ResolvesTenantAccount;

    private const PER_PAGE_MAX = 100;

    public function __construct(private readonly EducationAttendanceService $attendance)
    {
    }

    public function sheet(Request $request, int $id): JsonResponse
    {
        $account = $this->requireTargetAccount($request);
        $data = $request->validate(['date' => ['required', 'string']]);
        $group = $this->group($account->id, $id);

        return response()->json(['data' => $this->attendance->sheet($account, $group, $data['date'])]);
    }

    public function save(Request $request, int $id): JsonResponse
    {
        $account = $this->requireTargetAccount($request);
        $data = $request->validate([
            'date' => ['required', 'string'],
            'records' => ['required', 'array', 'min:1', 'max:500'],
            'records.*.student_id' => ['required', 'integer'],
            'records.*.status' => ['required', 'string', Rule::in(EducationAttendance::STATUSES)],
        ]);
        $group = $this->group($account->id, $id);

        $result = $this->attendance->saveSheet($account, $group, $data['date'], $data['records'], $request->user()?->id);

        return response()->json([
            'message' => 'Attendance saved.',
            'created' => $result['created'], 'updated' => $result['updated'], 'unchanged' => $result['unchanged'],
            'data' => $result['sheet'],
        ]);
    }

    public function studentHistory(Request $request, int $id): JsonResponse
    {
        $account = $this->requireTargetAccount($request);
        $filters = $request->validate([
            'group_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'string'],
            'to' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(EducationAttendance::STATUSES)],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);
        $student = EducationStudent::query()->forAccount($account->id)->with('contact')->find($id);
        abort_if(! $student, 404, 'Student not found.');
        if (! empty($filters['group_id'])) {
            $this->group($account->id, (int) $filters['group_id']); // a foreign group filter is a 404, not an empty list
        }

        $result = $this->attendance->history($account, $student, $filters, min((int) $request->integer('per_page', 31), self::PER_PAGE_MAX));
        $page = $result['page'];

        return response()->json([
            'student' => ['id' => $student->id, 'name' => $student->contact?->name, 'phone_number' => $student->contact?->phone_number, 'admission_number' => $student->admission_number],
            'summary' => $result['summary'],
            'data' => $page->getCollection()->map(fn (EducationAttendance $a) => [
                'id' => $a->id,
                'attendance_date' => $a->attendance_date->toDateString(),
                'status' => $a->status,
                'group' => $a->group ? ['id' => $a->group->id, 'name' => $a->group->name, 'kind' => $a->group->kind, 'academic_year' => $a->group->academic_year, 'status' => $a->group->status] : null,
            ])->values(),
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
        ]);
    }

    private function group(int $accountId, int $id): EducationGroup
    {
        $group = EducationGroup::query()->forAccount($accountId)->find($id);
        abort_if(! $group, 404, 'Class/batch not found.');

        return $group;
    }
}
