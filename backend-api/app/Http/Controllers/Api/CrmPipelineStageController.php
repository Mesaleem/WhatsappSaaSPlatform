<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\PresentsCrmSales;
use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\CrmPipelineStage;
use App\Services\Crm\CrmPipelineStageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** CRM Sales Phase 1 — /api/crm/stages: the tenant's deal pipeline columns. */
class CrmPipelineStageController extends Controller
{
    use PresentsCrmSales;
    use ResolvesTenantAccount;

    public function __construct(private readonly CrmPipelineStageService $stages)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);

        return response()->json(['data' => $this->stages->forAccount($account)->map(fn ($s) => $this->presentStage($s))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        $stage = $this->stages->create($account, $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'probability' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'kind' => ['sometimes', Rule::in(CrmPipelineStage::KINDS)],
        ]));

        return response()->json(['message' => 'Stage created.', 'data' => $this->presentStage($stage)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        $stage = $this->stages->update($this->findOrFail($account->id, $id), $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'probability' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'kind' => ['sometimes', Rule::in(CrmPipelineStage::KINDS)],
        ]));

        return response()->json(['message' => 'Stage updated.', 'data' => $this->presentStage($stage)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        $this->stages->delete($this->findOrFail($account->id, $id));

        return response()->json(['message' => 'Stage deleted.']);
    }

    /** POST /api/crm/stages/reorder  {ids: [every stage id, in order]} */
    public function reorder(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer']]);
        $this->stages->reorder($account, $data['ids']);

        return response()->json(['message' => 'Stages reordered.', 'data' => $this->stages->forAccount($account)->map(fn ($s) => $this->presentStage($s))->values()]);
    }

    private function findOrFail(int $accountId, int $id): CrmPipelineStage
    {
        $stage = CrmPipelineStage::query()->forAccount($accountId)->find($id);
        abort_if(! $stage, 404, 'Stage not found.');

        return $stage;
    }
}
