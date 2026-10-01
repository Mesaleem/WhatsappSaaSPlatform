<?php

namespace App\Http\Controllers\Api\Industry;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\Education\EducationGroup;
use App\Services\Education\EducationGroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 11 Task 2 — /api/industry/education/groups (classes and batches). Same contract as the student
 * controller: authorization is `industry.guard:education,batches` at the route; this resolves the target
 * account and scopes to it. No delete — a group is archived (status), keeping its history for later
 * attendance/fees features.
 */
class EducationGroupController extends Controller
{
    use ResolvesTenantAccount;

    private const PER_PAGE_MAX = 100;

    public function __construct(private readonly EducationGroupService $groups)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $account = $this->requireTargetAccount($request);
        $filters = $request->validate([
            'kind' => ['nullable', Rule::in(EducationGroup::KINDS)],
            'status' => ['nullable', Rule::in(EducationGroup::STATUSES)],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = EducationGroup::query()->forAccount($account->id)
            ->withCount('students')
            ->when($filters['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when(isset($filters['search']) && trim($filters['search']) !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes(trim($filters['search']), '%_\\').'%'))
            ->orderBy('name')->orderBy('id')
            ->paginate(min((int) $request->integer('per_page', 20), self::PER_PAGE_MAX));
        $page->getCollection()->transform(fn (EducationGroup $g) => $this->present($g));

        return response()->json($page);
    }

    public function store(Request $request): JsonResponse
    {
        $account = $this->requireTargetAccount($request);
        $data = $request->validate($this->rules(true));
        $group = $this->groups->create($account, $data)->loadCount('students');

        return response()->json(['message' => 'Created.', 'data' => $this->present($group)], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $group = $this->find($request, $id)->loadCount('students');
        $data = $this->present($group);
        $data['students'] = $group->students()->with('contact')->orderBy('education_students.id')->limit(500)->get()
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->contact?->name, 'phone_number' => $s->contact?->phone_number, 'admission_number' => $s->admission_number, 'status' => $s->status])->values();

        return response()->json(['data' => $data]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $group = $this->find($request, $id);
        $data = $request->validate($this->rules(false));
        $group = $this->groups->update($group, $data)->loadCount('students');

        return response()->json(['message' => 'Updated.', 'data' => $this->present($group)]);
    }

    private function find(Request $request, int $id): EducationGroup
    {
        $account = $this->requireTargetAccount($request);
        $group = EducationGroup::query()->forAccount($account->id)->find($id);
        abort_if(! $group, 404, 'Class/batch not found.');

        return $group;
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'min:1', 'max:120'],
            'kind' => $creating ? ['nullable', Rule::in(EducationGroup::KINDS)] : ['prohibited'],
            'academic_year' => ['nullable', 'string', 'max:20'],
            'status' => [$creating ? 'nullable' : 'sometimes', Rule::in(EducationGroup::STATUSES)],
            'metadata' => ['nullable', 'array'],
        ];
    }

    /** @return array<string, mixed> */
    private function present(EducationGroup $group): array
    {
        return [
            'id' => $group->id,
            'kind' => $group->kind,
            'name' => $group->name,
            'academic_year' => $group->academic_year,
            'status' => $group->status,
            'metadata' => $group->metadata,
            'students_count' => (int) ($group->students_count ?? 0),
            'created_at' => $group->created_at,
            'updated_at' => $group->updated_at,
        ];
    }
}
