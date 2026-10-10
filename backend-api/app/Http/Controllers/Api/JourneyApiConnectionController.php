<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\JourneyApiConnection;
use App\Support\JourneyApiConnectionSecrets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Phase 8 Task 15 — "Manage All APIs" for the Journey Builder
 * (JourneyBuilderPage.tsx's ManageApiConnectionsModal). CRUD for a named,
 * reusable API connection (base URL + headers + query) an `api` node can
 * reference via `data.apiConnectionId` instead of re-entering the same
 * endpoint/credentials per node — see JourneyApiConnection's docblock.
 *
 * Same route group/permission tier as the Journey Builder itself
 * (module.guard:chatbot + capability.guard:journey_automation +
 * permission:manage-chatbot|whatsapp.*, see routes/api.php): managing a
 * journey's own reusable API configuration is not a distinct capability
 * from authoring the journey.
 *
 * A header/query value is NEVER returned in plaintext — every response
 * runs through JourneyApiConnectionSecrets::mask() first, the exact same
 * guarantee JourneySecrets already gives an `api` node's own fields. An
 * update may send back JourneySecrets::MASK for a value it wants kept;
 * protect() resolves that against the row's OWN already-stored pairs
 * (never against another connection's).
 */
class JourneyApiConnectionController extends Controller
{
    use ResolvesTenantAccount;

    private const MAX_PAIRS = 30;

    public function index(Request $request): JsonResponse
    {
        $account = $this->account($request);

        $connections = JourneyApiConnection::query()->forAccount($account->id)->orderBy('name')->get()
            ->map(fn (JourneyApiConnection $c) => $this->present($c));

        return response()->json(['data' => $connections]);
    }

    public function store(Request $request): JsonResponse
    {
        $account = $this->account($request);
        $data = $this->validatePayload($request);

        try {
            $connection = JourneyApiConnection::create([
                'account_id' => $account->id,
                'name' => $data['name'],
                'base_url' => $data['base_url'] ?? null,
                'headers' => JourneyApiConnectionSecrets::protect($data['headers'] ?? [], null),
                'query' => JourneyApiConnectionSecrets::protect($data['query'] ?? [], null),
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['headers' => [$e->getMessage()]]);
        }

        return response()->json(['message' => 'API connection created.', 'data' => $this->present($connection)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $account = $this->account($request);
        $connection = JourneyApiConnection::query()->forAccount($account->id)->find($id);

        abort_if($connection === null, 404, 'API connection not found.');

        $data = $this->validatePayload($request, $connection->id);

        try {
            $connection->fill([
                'name' => $data['name'],
                'base_url' => $data['base_url'] ?? null,
                'headers' => JourneyApiConnectionSecrets::protect($data['headers'] ?? [], $connection->headers),
                'query' => JourneyApiConnectionSecrets::protect($data['query'] ?? [], $connection->query),
                'notes' => $data['notes'] ?? null,
            ])->save();
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['headers' => [$e->getMessage()]]);
        }

        return response()->json(['message' => 'API connection updated.', 'data' => $this->present($connection->fresh())]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $account = $this->account($request);
        $connection = JourneyApiConnection::query()->forAccount($account->id)->find($id);

        abort_if($connection === null, 404, 'API connection not found.');

        // Deliberately NOT blocked on in-use journeys: the `api` node
        // isn't runtime-executable yet (JourneyNodeCatalog::
        // RUNTIME_EXECUTABLE_TYPES), so there is no running journey this
        // could break today; a journey referencing a deleted connection
        // simply fails assertApiConnectionsOwned() the next time it is
        // saved, exactly like a deleted knowledge base or AI agent does.
        $connection->delete();

        return response()->json(['message' => 'API connection deleted.']);
    }

    /** @return array{name: string, base_url: ?string, headers: array<int, array<string, mixed>>, query: array<int, array<string, mixed>>, notes: ?string} */
    private function validatePayload(Request $request, ?int $ignoreId = null): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'base_url' => ['nullable', 'string', 'max:2048'],
            'headers' => ['sometimes', 'array', 'max:'.self::MAX_PAIRS],
            'headers.*.key' => ['required_with:headers', 'string', 'max:191'],
            'headers.*.value' => ['nullable'],
            'query' => ['sometimes', 'array', 'max:'.self::MAX_PAIRS],
            'query.*.key' => ['required_with:query', 'string', 'max:191'],
            'query.*.value' => ['nullable'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];

        $data = $request->validate($rules);

        $account = $this->account($request);
        $duplicate = JourneyApiConnection::query()->forAccount($account->id)->where('name', $data['name']);

        if ($ignoreId !== null) {
            $duplicate->whereKeyNot($ignoreId);
        }

        if ($duplicate->exists()) {
            throw ValidationException::withMessages(['name' => ['An API connection with this name already exists.']]);
        }

        return $data;
    }

    private function present(JourneyApiConnection $connection): array
    {
        return [
            'id' => $connection->id,
            'name' => $connection->name,
            'base_url' => $connection->base_url,
            'headers' => JourneyApiConnectionSecrets::mask($connection->headers),
            'query' => JourneyApiConnectionSecrets::mask($connection->query),
            'notes' => $connection->notes,
            'updated_at' => $connection->updated_at?->toIso8601String(),
        ];
    }

    private function account(Request $request)
    {
        return $this->requireAccount(
            $request,
            'Select a client from the header to manage their API connections.'
        );
    }
}
