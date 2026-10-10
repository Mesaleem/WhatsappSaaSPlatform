<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\WhatsAppNumber;
use App\Services\WhatsApp\WhatsAppNumberException;
use App\Services\WhatsApp\WhatsAppNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The tenant's WhatsApp number slots (included number + paid add-ons).
 * Routes sit under module.guard:whatsapp_setup. The account is resolved from the
 * authenticated user, never from the body.
 */
class WhatsAppNumberController extends Controller
{
    use ResolvesTenantAccount;

    public function __construct(private readonly WhatsAppNumberService $numbers)
    {
    }

    /** GET /api/whatsapp/numbers */
    public function index(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client account first (pass ?account_id=).');

        return response()->json([
            'data' => $this->presentAll($account),
            'locked' => $this->numbers->isLocked($account),
        ]);
    }

    /** POST /api/whatsapp/numbers  body: { phone_number } */
    public function store(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client account first (pass ?account_id=).');
        $data = $request->validate(['phone_number' => ['required', 'string', 'max:25']]);

        try {
            $number = $this->numbers->add($account, $data['phone_number']);
        } catch (WhatsAppNumberException $e) {
            return $this->error($e);
        }

        return response()->json(['message' => 'Number added.', 'data' => $this->present($number)], 201);
    }

    /**
     * PUT /api/whatsapp/numbers/{id}  body: { phone_number }
     * Self-service: changes the number on a slot that is not currently linked. No
     * approval required (see WhatsAppNumberService::changeNumber()'s docblock).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client account first (pass ?account_id=).');
        $data = $request->validate(['phone_number' => ['required', 'string', 'max:25']]);

        try {
            $number = $this->numbers->changeNumber($account, $id, $data['phone_number']);
        } catch (WhatsAppNumberException $e) {
            return $this->error($e);
        }

        return response()->json(['message' => 'Number updated.', 'data' => $this->present($number)]);
    }

    /** PUT /api/whatsapp/numbers/{id}/default */
    public function setDefault(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client account first (pass ?account_id=).');

        try {
            $number = $this->numbers->setDefault($account, $id);
        } catch (WhatsAppNumberException $e) {
            return $this->error($e);
        }

        return response()->json(['message' => 'Default number updated.', 'data' => $this->presentAll($account)]);
    }

    /** DELETE /api/whatsapp/numbers/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client account first (pass ?account_id=).');

        try {
            $this->numbers->remove($account, $id);
        } catch (WhatsAppNumberException $e) {
            return $this->error($e);
        }

        return response()->json(['message' => 'Number removed.']);
    }

    /** @return array<int, array<string, mixed>> */
    private function presentAll(\App\Models\Account $account): array
    {
        $eligible = $this->numbers->defaultEligibility($account);

        return array_map(
            fn (WhatsAppNumber $n) => $this->present($n, $eligible[$n->id] ?? false),
            $this->numbers->listFor($account),
        );
    }

    private function present(WhatsAppNumber $number, bool $canSetDefault = false): array
    {
        return [
            'id' => $number->id,
            'phone_number' => $number->phone_number,
            // True for a default slot auto-created with no number typed yet (see
            // WhatsAppNumber::PENDING_PLACEHOLDER_PREFIX's own docblock) -- the
            // frontend must not show phone_number as-is, nor treat it as a real
            // number to enforce a QR/pairing-code match against.
            'has_pending_number' => $number->hasPendingPlaceholderNumber(),
            'is_included' => $number->is_included,
            'is_default' => $number->is_default,
            'status' => $number->status,
            'locked' => $number->isLocked(),
            // Server-side rule: linked, paid, all numbers linked, subscription active.
            'can_set_default' => $canSetDefault,
            'term_ends_at' => $number->term_ends_at?->toIso8601String(),
        ];
    }

    private function error(WhatsAppNumberException $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage(), 'error_code' => $e->errorCode], $e->status);
    }
}
