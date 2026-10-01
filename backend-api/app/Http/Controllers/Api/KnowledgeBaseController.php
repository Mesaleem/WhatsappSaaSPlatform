<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeDocument;
use App\Services\Ai\AiAuthorization;
use App\Services\Ai\AiAuthorizer;
use App\Services\Ai\Retrieval\KnowledgeRetriever;
use App\Services\Ai\Retrieval\RetrievalQuery;
use App\Services\Ai\Retrieval\RetrievedChunk;
use App\Services\Knowledge\KnowledgeBaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Phase 8 Task 9 — minimal Knowledge Base API (/api/knowledge-bases).
 *
 * AUTHORIZATION (no new model): the route requires permission:manage-chatbot
 * (auth:sanctum + tenant.isolation + subscription.guard from its group), and
 * EVERY action first runs AiAuthorizer::forRequest($request, 'chatbot',
 * 'manage-chatbot') — target account as TenantIsolationMiddleware resolved it
 * (tenant: own; agent: own/sub-client; Super Admin: a selected client is
 * REQUIRED and gets no entitlement bypass), account active, subscription
 * current, the chatbot module, the permission, and the `ai` capability on
 * the TARGET account. Everything below is scoped to that account; a
 * knowledge-base / document id from the URL is only resolved inside it
 * (another account's id → 404, indistinguishable from a missing one).
 *
 * BILLING: ingestion (embeddings, in the job) and search (the query
 * embedding) are charged to the target account through
 * MeteredAiService::embed. Listing, creating, deleting cost nothing.
 */
class KnowledgeBaseController extends Controller
{
    public const MODULE = 'chatbot';

    public const PERMISSION = 'manage-chatbot';

    public function __construct(private readonly AiAuthorizer $authorizer, private readonly KnowledgeBaseService $knowledge)
    {
    }

    private function authorizeTarget(Request $request): AiAuthorization
    {
        return $this->authorizer->forRequest($request, self::MODULE, self::PERMISSION, 'manual');
    }

    public function index(Request $request): JsonResponse
    {
        $account = $this->authorizeTarget($request)->account;

        $bases = \App\Models\KnowledgeBase::query()->forAccount((int) $account->id)
            ->withCount(['documents'])
            ->orderBy('name')->limit(200)->get();

        return response()->json(['data' => $bases]);
    }

    public function store(Request $request): JsonResponse
    {
        $authorization = $this->authorizeTarget($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $base = $this->knowledge->createKnowledgeBase($authorization->account, $data['name'], $data['description'] ?? null, (int) $request->user()->id);

        return response()->json(['data' => $base], 201);
    }

    public function show(Request $request, string $knowledgeBase): JsonResponse
    {
        $base = $this->knowledge->findKnowledgeBase($this->authorizeTarget($request)->account, $knowledgeBase);

        $statuses = KnowledgeDocument::query()->forAccount((int) $base->account_id)->where('knowledge_base_id', $base->id)
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n);

        return response()->json(['data' => $base->toArray() + ['documents_by_status' => $statuses]]);
    }

    public function destroy(Request $request, string $knowledgeBase): JsonResponse
    {
        $this->knowledge->deleteKnowledgeBase($this->knowledge->findKnowledgeBase($this->authorizeTarget($request)->account, $knowledgeBase));

        return response()->json(['message' => 'Knowledge base deleted.']);
    }

    public function documents(Request $request, string $knowledgeBase): JsonResponse
    {
        $base = $this->knowledge->findKnowledgeBase($this->authorizeTarget($request)->account, $knowledgeBase);

        $documents = KnowledgeDocument::query()->forAccount((int) $base->account_id)->where('knowledge_base_id', $base->id)
            ->orderByDesc('id')->limit(500)->get();

        return response()->json(['data' => $documents]);
    }

    public function storeDocument(Request $request, string $knowledgeBase): JsonResponse
    {
        $authorization = $this->authorizeTarget($request);
        $base = $this->knowledge->findKnowledgeBase($authorization->account, $knowledgeBase);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'content' => ['required', 'string'],
            'source_key' => ['nullable', 'string', 'max:191', 'regex:/^[A-Za-z0-9._:\/\-]+$/'],
        ]);

        $result = $this->knowledge->submitDocument($base, $data['title'], $data['content'], $data['source_key'] ?? null, KnowledgeDocument::SOURCE_TEXT, (int) $request->user()->id);

        return response()->json(['data' => $result['document']->fresh(), 'created' => $result['created'], 'changed' => $result['changed']], $result['changed'] ? 202 : 200);
    }

    public function showDocument(Request $request, string $knowledgeBase, string $document): JsonResponse
    {
        $base = $this->knowledge->findKnowledgeBase($this->authorizeTarget($request)->account, $knowledgeBase);

        return response()->json(['data' => $this->knowledge->findDocument($base, $document)]);
    }

    public function reprocessDocument(Request $request, string $knowledgeBase, string $document): JsonResponse
    {
        $base = $this->knowledge->findKnowledgeBase($this->authorizeTarget($request)->account, $knowledgeBase);

        return response()->json(['data' => $this->knowledge->reprocess($this->knowledge->findDocument($base, $document))], 202);
    }

    public function destroyDocument(Request $request, string $knowledgeBase, string $document): JsonResponse
    {
        $base = $this->knowledge->findKnowledgeBase($this->authorizeTarget($request)->account, $knowledgeBase);
        $this->knowledge->deleteDocument($this->knowledge->findDocument($base, $document));

        return response()->json(['message' => 'Document deleted.']);
    }

    /**
     * POST /api/knowledge-bases/{id}/search — retrieval only (no generation).
     * One charge per Idempotency-Key when the header is sent.
     */
    public function search(Request $request, string $knowledgeBase, KnowledgeRetriever $retriever): JsonResponse
    {
        $authorization = $this->authorizeTarget($request);
        $data = $request->validate([
            'query' => ['required', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.max(1, (int) config('ai.knowledge.max_results', 20))],
            'min_score' => ['nullable', 'numeric', 'between:-1,1'],
        ]);

        $result = $retriever->retrieve(new RetrievalQuery(
            $authorization->account,
            $authorization,
            $knowledgeBase,
            $data['query'],
            (int) ($data['limit'] ?? 5),
            isset($data['min_score']) ? (float) $data['min_score'] : null,
            $this->operationKey($request),
        ));

        return response()->json(['data' => [
            'knowledge_base_id' => $result->knowledgeBaseId,
            'results' => array_map(fn (RetrievedChunk $c) => $c->toArray(), $result->chunks),
            'credits_charged' => $result->creditsCharged,
        ]]);
    }

    private function operationKey(Request $request): ?string
    {
        $header = trim((string) $request->header('Idempotency-Key', ''));

        if ($header === '') {
            return null;
        }

        if (mb_strlen($header) > 100 || preg_match('/^[A-Za-z0-9._:\-]+$/', $header) !== 1) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'The Idempotency-Key header must be 1-100 letters, digits, ".", "_", ":" or "-".']);
        }

        return 'kbsearch:u'.(int) $request->user()->id.':'.$header;
    }
}
