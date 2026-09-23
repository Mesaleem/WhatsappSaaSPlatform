<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\BulkAssignCrmLeadsRequest;
use App\Http\Requests\BulkChangeCrmLeadStatusRequest;
use App\Http\Requests\BulkCrmLeadTagRequest;
use App\Models\CrmTag;
use App\Services\Crm\CrmLeadService;
use App\Services\Crm\CrmTagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Phase 6 — CRM Task 9. Bulk lead operations — the four operations that
 * already exist individually, for up to CrmBulkLeadSelection::MAX_LEADS
 * leads at once:
 *
 *   POST /api/crm/leads/bulk/assignee      {lead_ids, assigned_user_id|null}
 *   POST /api/crm/leads/bulk/status        {lead_ids, status, not_converted_reason?}
 *   POST /api/crm/leads/bulk/tags/attach   {lead_ids, tag_id}
 *   POST /api/crm/leads/bulk/tags/detach   {lead_ids, tag_id}
 *
 * Same middleware chain as every CRM route (tenant.isolation,
 * subscription.guard, module.guard:lead_crm, permission:manage-crm,
 * capability.guard:crm) — no bulk-specific permission or capability.
 *
 * ALL OR NOTHING: one transaction per request; a foreign/missing lead id,
 * a foreign/missing tag, an ineligible assignee or a disallowed transition
 * rejects the whole batch with a 422 and zero mutations. No partial
 * success is ever reported.
 *
 * Response (200): {"message": "...", "data": {"operation", "requested",
 * "changed", "unchanged"}} — counts only, never other tenants' data.
 *
 * Not deletion, not contact reassignment, not merge: those stay individual
 * operations by design. Not exposed on /api/v1.
 */
class CrmLeadBulkController extends Controller
{
    use ResolvesTenantAccount;

    public function __construct(
        private readonly CrmLeadService $leads,
        private readonly CrmTagService $tags,
    ) {
    }

    public function assignee(BulkAssignCrmLeadsRequest $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        $userId = $request->validated()['assigned_user_id'];

        $summary = $this->leads->bulkChangeAssignee($account, $request->leadIds(), $userId === null ? null : (int) $userId);

        return $this->respond($summary, $userId === null ? 'Leads unassigned.' : 'Leads assigned.');
    }

    public function status(BulkChangeCrmLeadStatusRequest $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        $data = $request->validated();

        $summary = $this->leads->bulkChangeStatus(
            $account,
            $request->leadIds(),
            (string) $data['status'],
            $data['not_converted_reason'] ?? null,
        );

        return $this->respond($summary, 'Lead statuses updated.');
    }

    public function attachTag(BulkCrmLeadTagRequest $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        $summary = $this->tags->bulkAttach($account, $request->leadIds(), $this->tag($account->id, (int) $request->validated()['tag_id']));

        return $this->respond($summary, 'Tag added to leads.');
    }

    public function detachTag(BulkCrmLeadTagRequest $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        $summary = $this->tags->bulkDetach($account, $request->leadIds(), $this->tag($account->id, (int) $request->validated()['tag_id']));

        return $this->respond($summary, 'Tag removed from leads.');
    }

    /** A foreign and a missing tag are the same generic 422 — no enumeration. */
    private function tag(int $accountId, int $tagId): CrmTag
    {
        $tag = CrmTag::query()->forAccount($accountId)->find($tagId);

        if (! $tag) {
            throw ValidationException::withMessages([
                'tag_id' => ['The selected tag is not available.'],
            ]);
        }

        return $tag;
    }

    /**
     * @param array{operation: string, requested: int, changed: int, unchanged: int} $summary
     */
    private function respond(array $summary, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'data' => $summary]);
    }
}
