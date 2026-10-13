<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\PresentsCrmSales;
use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\CrmDeal;
use App\Models\CrmPipelineStage;
use App\Services\Crm\CrmDealService;
use App\Services\Crm\CrmPipelineStageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRM Sales Phase 1 — deals and their pipeline stages, inside the CRM route group
 * (same gates as leads). Stage and status change only through PATCH {id}/stage
 * (CrmDealService::moveToStage), never through the general update, so the won / lost
 * bookkeeping and the timeline event cannot be skipped.
 */
class CrmDealController extends Controller
{
    use PresentsCrmSales;
    use ResolvesTenantAccount;

    public function __construct(
        private readonly CrmDealService $deals,
        private readonly CrmPipelineStageService $stages,
    ) {
    }

    /** GET /api/crm/deals?status=&stage_id=&owner_user_id=&crm_company_id=&contact_id=&search= */
    public function index(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        $filters = $request->validate($this->filterRules());

        $deals = $this->filtered($account->id, $filters)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 20), 100));

        $deals->getCollection()->transform(fn (CrmDeal $d) => $this->presentDeal($d));

        return response()->json($deals);
    }

    /**
     * GET /api/crm/deals/board — the Kanban: every stage with its deals (up to 100 per
     * stage, newest first) and the stage's total value.
     */
    public function board(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        $filters = $request->validate($this->filterRules());
        unset($filters['stage_id'], $filters['status']);

        $columns = $this->stages->forAccount($account)->map(function (CrmPipelineStage $stage) use ($account, $filters) {
            $query = $this->filtered($account->id, $filters)->where('stage_id', $stage->id);
            $total = (clone $query)->count();
            // decimal arithmetic only: the database sum is a decimal string.
            $sum = bcadd((string) ((clone $query)->sum('value') ?: '0'), '0', 2);

            return $this->presentStage($stage) + [
                'deals_count' => $total,
                'total_value' => $sum,
                'deals' => $query->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get()->map(fn (CrmDeal $d) => $this->presentDeal($d))->values(),
            ];
        })->values();

        return response()->json(['data' => $columns]);
    }

    public function store(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        $data = $request->validate($this->rules(true) + [
            'stage_id' => ['sometimes', 'nullable', 'integer'],
            'crm_lead_id' => ['sometimes', 'nullable', 'integer'],
            'lost_reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $deal = $this->deals->create($account, $data, $request->user()?->id);

        return response()->json(['message' => 'Deal created.', 'data' => $this->presentDeal($deal->load($this->dealRelations()))], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);

        return response()->json(['data' => $this->presentDeal($this->findOrFail($account->id, $id))]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        $deal = $this->deals->update($this->findOrFail($account->id, $id), $request->validate($this->rules(false)));

        return response()->json(['message' => 'Deal updated.', 'data' => $this->presentDeal($deal->load($this->dealRelations()))]);
    }

    /** PATCH /api/crm/deals/{id}/stage  {stage_id, lost_reason?} — the one way to move, win or lose a deal. */
    public function stage(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        $data = $request->validate([
            'stage_id' => ['required', 'integer'],
            'lost_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $deal = $this->deals->moveToStage($this->findOrFail($account->id, $id), (int) $data['stage_id'], $data['lost_reason'] ?? null, $request->user()?->id);

        return response()->json(['message' => 'Deal moved.', 'data' => $this->presentDeal($deal->load($this->dealRelations()))]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        $this->deals->delete($this->findOrFail($account->id, $id));

        return response()->json(['message' => 'Deal deleted.']);
    }

    /** @param array<string, mixed> $filters */
    private function filtered(int $accountId, array $filters)
    {
        return CrmDeal::query()
            ->forAccount($accountId)
            ->with($this->dealRelations())
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['stage_id'] ?? null, fn ($q, $v) => $q->where('stage_id', $v))
            ->when($filters['owner_user_id'] ?? null, fn ($q, $v) => $v === 'none' ? $q->whereNull('owner_user_id') : $q->where('owner_user_id', (int) $v))
            ->when($filters['crm_company_id'] ?? null, fn ($q, $v) => $q->where('crm_company_id', $v))
            ->when($filters['contact_id'] ?? null, fn ($q, $v) => $q->where('contact_id', $v))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('number', 'like', "%{$s}%")));
    }

    /** @return array<string, mixed> */
    private function filterRules(): array
    {
        return [
            'status' => ['nullable', Rule::in(CrmDeal::STATUSES)],
            'stage_id' => ['nullable', 'integer'],
            'owner_user_id' => ['nullable', 'string', 'regex:/^(none|[1-9][0-9]*)$/'],
            'crm_company_id' => ['nullable', 'integer'],
            'contact_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:191'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, mixed> */
    private function rules(bool $creating): array
    {
        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:191'],
            'value' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999999', 'decimal:0,2'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'expected_close_date' => ['sometimes', 'nullable', 'date'],
            'owner_user_id' => ['sometimes', 'nullable', 'integer'],
            'contact_id' => ['sometimes', 'nullable', 'integer'],
            'crm_company_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    private function findOrFail(int $accountId, int $id): CrmDeal
    {
        $deal = CrmDeal::query()->forAccount($accountId)->with($this->dealRelations())->find($id);
        abort_if(! $deal, 404, 'Deal not found.');

        return $deal;
    }
}
