<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\WhatsAppNumber;
use App\Models\WhatsAppNumberChangeRequest;
use App\Services\WhatsApp\WhatsAppNumberChangeService;
use App\Services\WhatsApp\WhatsAppNumberException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "This number was entered wrongly": a client asks for a slot to hold a different number. The
 * client's own list is under module.guard:whatsapp_setup; the decision is for a Super Admin or
 * the agent for its own client, and is scoped by WhatsAppNumberChangeService.
 */
class WhatsAppNumberChangeController extends Controller
{
    use ResolvesTenantAccount;

    public function __construct(private readonly WhatsAppNumberChangeService $changes)
    {
    }

    /** GET /api/whatsapp/numbers/change-requests — this account's requests. */
    public function index(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client account first (pass ?account_id=).');

        return response()->json([
            'data' => WhatsAppNumberChangeRequest::query()
                ->where('account_id', $account->id)
                ->orderByDesc('id')
                ->get()
                ->map(fn (WhatsAppNumberChangeRequest $r) => $this->present($r))
                ->values(),
        ]);
    }

    /** POST /api/whatsapp/numbers/{id}/change-requests  body: { new_phone, reason } */
    public function store(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client account first (pass ?account_id=).');
        $data = $request->validate([
            'new_phone' => ['required', 'string', 'max:25'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        try {
            $row = $this->changes->request($account, $request->user(), $id, $data['new_phone'], $data['reason']);
        } catch (WhatsAppNumberException $e) {
            return $this->error($e);
        }

        return response()->json([
            'message' => 'Request sent. Your Super Admin or agent will check the number and approve the change.',
            'data' => $this->present($row),
        ], 201);
    }

    /** GET /api/admin/whatsapp/number-change-requests — the requests the caller may decide. */
    public function pending(Request $request): JsonResponse
    {
        try {
            $rows = $this->changes->pendingFor($request->user());
        } catch (WhatsAppNumberException $e) {
            return $this->error($e);
        }

        return response()->json([
            'data' => $rows->map(fn (WhatsAppNumberChangeRequest $r) => array_merge($this->present($r), [
                'account' => ['id' => $r->account_id, 'name' => $r->account?->company_name],
            ]))->values(),
        ]);
    }

    /** POST /api/admin/whatsapp/number-change-requests/{id}/approve */
    public function approve(Request $request, int $id): JsonResponse
    {
        try {
            $row = $this->changes->approve($this->findRequest($id), $request->user());
        } catch (WhatsAppNumberException $e) {
            return $this->error($e);
        }

        return response()->json(['message' => 'Approved. The number is changed; the slot must be connected again.', 'data' => $this->present($row)]);
    }

    /** POST /api/admin/whatsapp/number-change-requests/{id}/reject  body: { note? } */
    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        try {
            $row = $this->changes->reject($this->findRequest($id), $request->user(), $data['note'] ?? null);
        } catch (WhatsAppNumberException $e) {
            return $this->error($e);
        }

        return response()->json(['message' => 'Request rejected.', 'data' => $this->present($row)]);
    }

    private function findRequest(int $id): WhatsAppNumberChangeRequest
    {
        $row = WhatsAppNumberChangeRequest::query()->whereKey($id)->first();
        if ($row === null) {
            throw new WhatsAppNumberException('Request not found.', 'not_found', 404);
        }

        return $row;
    }

    private function present(WhatsAppNumberChangeRequest $row): array
    {
        return [
            'id' => $row->id,
            'whatsapp_number_id' => $row->whatsapp_number_id,
            'old_phone' => $row->old_phone,
            'new_phone' => $row->new_phone,
            'reason' => $row->reason,
            'status' => $row->status,
            'decision_note' => $row->decision_note,
            'created_at' => $row->created_at?->toIso8601String(),
            'decided_at' => $row->decided_at?->toIso8601String(),
        ];
    }

    private function error(WhatsAppNumberException $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage(), 'error_code' => $e->errorCode], $e->status);
    }
}
