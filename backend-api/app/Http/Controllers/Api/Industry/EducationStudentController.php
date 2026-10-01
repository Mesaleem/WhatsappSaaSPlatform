<?php

namespace App\Http\Controllers\Api\Industry;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Education\EducationStudent;
use App\Models\Education\EducationStudentGuardian;
use App\Services\Education\EducationStudentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 11 Task 2 — /api/industry/education/students.
 *
 * Authorization is NOT here: the route group is `industry.guard:education,students`, which runs the one
 * IndustryAuthorizer (industry assigned, module, capability, permission, subscription — writes need
 * `manage-education` and an active subscription). This class only resolves the TARGET account
 * (requireTargetAccount: a Super Admin must pass ?account_id=, no fallback) and scopes every query to it.
 * The request body's `account_id` is never read.
 */
class EducationStudentController extends Controller
{
    use ResolvesTenantAccount;

    private const PER_PAGE_MAX = 100;

    public function __construct(private readonly EducationStudentService $students)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $account = $this->requireTargetAccount($request);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(EducationStudent::STATUSES)],
            'group_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);
        $perPage = min((int) $request->integer('per_page', 20), self::PER_PAGE_MAX);

        $query = EducationStudent::query()->forAccount($account->id)
            ->join('contacts', function ($join) {
                $join->on('contacts.id', '=', 'education_students.contact_id')->on('contacts.account_id', '=', 'education_students.account_id');
            })
            ->select('education_students.*')
            ->with(['contact', 'guardians.contact', 'groups'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('education_students.status', $v))
            ->when($filters['group_id'] ?? null, fn ($q, $v) => $q->whereExists(function ($sub) use ($v) {
                $sub->selectRaw('1')->from('education_group_members')
                    ->whereColumn('education_group_members.student_id', 'education_students.id')
                    ->where('education_group_members.group_id', $v);
            }))
            ->when(isset($filters['search']) && trim($filters['search']) !== '', function ($q) use ($filters) {
                $term = '%'.addcslashes(trim($filters['search']), '%_\\').'%';
                $q->where(fn ($w) => $w->where('contacts.name', 'like', $term)
                    ->orWhere('contacts.phone_number', 'like', $term)
                    ->orWhere('contacts.email', 'like', $term)
                    ->orWhere('education_students.admission_number', 'like', $term));
            })
            ->orderBy('contacts.name')->orderBy('education_students.id');

        $page = $query->paginate($perPage);
        $page->getCollection()->transform(fn (EducationStudent $s) => $this->present($s));

        return response()->json($page);
    }

    public function store(Request $request): JsonResponse
    {
        $account = $this->requireTargetAccount($request);
        $student = $this->students->create($account, $this->validated($request, true));

        return response()->json(['message' => 'Student created.', 'data' => $this->present($student)], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $student = $this->find($request, $id);

        return response()->json(['data' => $this->present($student->load(['contact', 'guardians.contact', 'groups']))]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $student = $this->find($request, $id);
        $student = $this->students->update($student, $this->validated($request, false));

        return response()->json(['message' => 'Student updated.', 'data' => $this->present($student)]);
    }

    /** POST /students/{id}/guardians — link an existing CRM contact (or a phone number) as parent/guardian. */
    public function addGuardian(Request $request, int $id): JsonResponse
    {
        $student = $this->find($request, $id);
        $data = $request->validate($this->personRules('') + ['relationship' => ['required', Rule::in(EducationStudentGuardian::RELATIONSHIPS)]]);

        return response()->json(['message' => 'Guardian linked.', 'data' => $this->present($this->students->addGuardian($student, $data))], 201);
    }

    public function removeGuardian(Request $request, int $id, int $linkId): JsonResponse
    {
        $student = $this->find($request, $id);

        return response()->json(['message' => 'Guardian unlinked.', 'data' => $this->present($this->students->removeGuardian($student, $linkId))]);
    }

    /** PUT /students/{id}/groups — the student's complete set of classes/batches. */
    public function syncGroups(Request $request, int $id): JsonResponse
    {
        $student = $this->find($request, $id);
        $data = $request->validate(['group_ids' => ['present', 'array', 'max:50'], 'group_ids.*' => ['integer']]);

        return response()->json(['message' => 'Classes/batches updated.', 'data' => $this->present($this->students->syncGroups($student, $data['group_ids']))]);
    }

    // ------------------------------------------------------------------ helpers

    private function find(Request $request, int $id): EducationStudent
    {
        $account = $this->requireTargetAccount($request);
        $student = EducationStudent::query()->forAccount($account->id)->find($id);
        abort_if(! $student, 404, 'Student not found.');

        return $student;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $own = [
            'admission_number' => ['nullable', 'string', 'max:64'],
            'status' => [$creating ? 'nullable' : 'sometimes', Rule::in(EducationStudent::STATUSES)],
            'admission_date' => ['nullable', 'date_format:Y-m-d'],
            'metadata' => ['nullable', 'array', function (string $attr, mixed $value, \Closure $fail) {
                if (strlen((string) json_encode($value)) > 8000) {
                    $fail('The metadata is too large.');
                }
            }],
            'group_ids' => ['nullable', 'array', 'max:50'],
            'group_ids.*' => ['integer'],
        ];

        if (! $creating) {
            return $request->validate($own);
        }

        return $request->validate($own + $this->personRules('') + [
            'guardians' => ['nullable', 'array', 'max:10'],
            'guardians.*.relationship' => ['required', Rule::in(EducationStudentGuardian::RELATIONSHIPS)],
        ] + $this->personRules('guardians.*.'));
    }

    /** @return array<string, array<int, mixed>> */
    private function personRules(string $prefix): array
    {
        return [
            $prefix.'contact_id' => ['nullable', 'integer'],
            $prefix.'phone_number' => ['nullable', 'string', 'max:32'],
            $prefix.'name' => ['nullable', 'string', 'max:255'],
            $prefix.'email' => ['nullable', 'email', 'max:255'],
        ];
    }

    /** @return array<string, mixed> */
    private function present(EducationStudent $student): array
    {
        return [
            'id' => $student->id,
            'contact' => $this->contact($student->contact),
            'admission_number' => $student->admission_number,
            'status' => $student->status,
            'admission_date' => $student->admission_date?->toDateString(),
            'metadata' => $student->metadata,
            'guardians' => $student->guardians->map(fn (EducationStudentGuardian $g) => [
                'id' => $g->id,
                'relationship' => $g->relationship,
                'contact' => $this->contact($g->contact),
            ])->values(),
            'groups' => $student->groups->map(fn ($g) => ['id' => $g->id, 'name' => $g->name, 'kind' => $g->kind, 'academic_year' => $g->academic_year, 'status' => $g->status])->values(),
            'created_at' => $student->created_at,
            'updated_at' => $student->updated_at,
        ];
    }

    /** @return array<string, mixed>|null */
    private function contact(?Contact $contact): ?array
    {
        return $contact ? ['id' => $contact->id, 'name' => $contact->name, 'phone_number' => $contact->phone_number, 'email' => $contact->email] : null;
    }
}
