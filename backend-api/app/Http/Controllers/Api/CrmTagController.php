<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCrmTagRequest;
use App\Http\Requests\UpdateCrmTagRequest;
use App\Models\CrmTag;
use App\Services\Crm\CrmTagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 6 — CRM Task 7. Tenant-scoped CRM tag management.
 *
 *   GET          /api/crm/tags          list (?search=, ?per_page=)
 *   POST         /api/crm/tags          create {"name": "Hot"}
 *   GET          /api/crm/tags/{id}     show
 *   PUT|PATCH    /api/crm/tags/{id}     rename {"name": "Very Hot"}
 *   DELETE       /api/crm/tags/{id}     delete (assignments go, leads stay)
 *
 * Same middleware stack as every CRM route (auth:sanctum ->
 * tenant.isolation -> subscription.guard -> module.guard:lead_crm ->
 * permission:manage-crm -> capability.guard:crm). No tag-specific
 * permission or capability: tagging is part of the CRM.
 *
 * Same security model as CrmLeadController: the account comes only from
 * TenantIsolationMiddleware, and every lookup is forAccount()->find(), so
 * a foreign id and a missing id return byte-identical 404s.
 */
class CrmTagController extends Controller
{
    use ResolvesTenantAccount;

    private const PER_PAGE_MAX = 100;

    private const PER_PAGE_DEFAULT = 50;

    public function __construct(private readonly CrmTagService $tags)
    {
    }

    /**
     * GET /api/crm/tags — this account's tags, ordered by name, each with
     * lead_count (CrmTag::scopeWithLeadCount(): a correlated COUNT inside
     * the same single SELECT — not one query per tag).
     *
     * ?search= is a case-insensitive PREFIX match on the normalized name
     * ("fol" finds "Follow Up"), served by the
     * unique(account_id, normalized_name) index. Not a substring search:
     * a leading wildcard could not use that index. LIKE metacharacters in
     * the input are escaped, so "%" matches a literal percent sign.
     */
    public function index(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:'.CrmTag::NAME_MAX],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $perPage = min((int) $request->integer('per_page', self::PER_PAGE_DEFAULT), self::PER_PAGE_MAX);

        $tags = CrmTag::query()
            ->forAccount($account->id)
            ->when(isset($filters['search']), function ($q) use ($filters) {
                $prefix = addcslashes(CrmTag::normalize((string) $filters['search']), '\\%_');
                $q->whereRaw('normalized_name LIKE ? ESCAPE ?', [$prefix.'%', '\\']);
            })
            ->withLeadCount()
            ->orderedByName()
            ->paginate($perPage);

        $tags->getCollection()->transform(fn (CrmTag $tag) => $this->presentTag($tag));

        return response()->json($tags);
    }

    /** POST /api/crm/tags */
    public function store(StoreCrmTagRequest $request): JsonResponse
    {
        $account = $this->requireAccount($request);

        $tag = $this->tags->create($account, (string) $request->validated()['name']);

        return response()->json([
            'message' => 'Tag created.',
            'data' => $this->presentTag($this->findOrFail($tag->account_id, $tag->id)),
        ], 201);
    }

    /** GET /api/crm/tags/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);

        return response()->json(['data' => $this->presentTag($this->findOrFail($account->id, $id))]);
    }

    /** PUT|PATCH /api/crm/tags/{id} — rename. Assignments are unaffected. */
    public function update(UpdateCrmTagRequest $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);

        $tag = $this->tags->rename(
            $this->findOrFail($account->id, $id),
            (string) $request->validated()['name'],
        );

        return response()->json([
            'message' => 'Tag updated.',
            'data' => $this->presentTag($this->findOrFail($tag->account_id, $tag->id)),
        ]);
    }

    /**
     * DELETE /api/crm/tags/{id} — removes the tag and its assignments.
     * Never deletes a lead, changes a status, or touches a contact or a
     * capture row.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);

        $this->tags->delete($this->findOrFail($account->id, $id));

        return response()->json(['message' => 'Tag deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentTag(CrmTag $tag): array
    {
        return [
            'id' => $tag->id,
            'name' => $tag->name,
            'lead_count' => (int) ($tag->lead_count ?? 0),
            'created_at' => $tag->created_at?->toIso8601String(),
            'updated_at' => $tag->updated_at?->toIso8601String(),
        ];
    }

    private function findOrFail(int $accountId, int $id): CrmTag
    {
        $tag = CrmTag::query()
            ->forAccount($accountId)
            ->withLeadCount()
            ->find($id);

        abort_if(! $tag, 404, 'Tag not found.');

        return $tag;
    }
}
