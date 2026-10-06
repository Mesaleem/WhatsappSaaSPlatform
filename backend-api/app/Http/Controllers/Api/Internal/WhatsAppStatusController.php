<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppNumber;
use App\Models\WhatsAppSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WhatsAppStatusController extends Controller
{
    /**
     * POST /api/internal/whatsapp-status — webhook called by qr-engine-service
     * (never by a browser) whenever a Baileys connection opens, closes, or starts
     * pairing. Gated by VerifyInternalSecret, not auth:sanctum.
     *
     * Body: account_id, status, optional phone_number, and optional session_id (a
     * WhatsApp number slot). With a session_id the slot's status is recorded, and
     * a connection on a DIFFERENT number than the slot's is refused: a slot can only
     * be linked to the number it was added with.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'status' => ['required', Rule::in(['connecting', 'connected', 'disconnected'])],
            'phone_number' => ['nullable', 'string', 'regex:/^[1-9][0-9]{7,14}$/'],
            'session_id' => ['nullable', 'integer'],
        ]);

        $slot = null;
        if (! empty($data['session_id'])) {
            $slot = WhatsAppNumber::query()
                ->where('account_id', $data['account_id'])
                ->whereKey($data['session_id'])
                ->first();

            if ($slot === null) {
                return response()->json(['message' => 'WhatsApp number not found on this account.', 'error_code' => 'not_found'], 404);
            }

            if ($data['status'] === 'connected' && ! empty($data['phone_number']) && $data['phone_number'] !== $slot->phone_number) {
                $slot->forceFill(['status' => WhatsAppNumber::STATUS_UNLINKED])->save();

                return response()->json([
                    'message' => 'That WhatsApp account is a different number from this slot. Link the number you added.',
                    'error_code' => 'number_mismatch',
                ], 422);
            }

            $slot->forceFill([
                'status' => match ($data['status']) {
                    'connected' => WhatsAppNumber::STATUS_LINKED,
                    default => $slot->status === WhatsAppNumber::STATUS_PAUSED
                        ? WhatsAppNumber::STATUS_PAUSED
                        : WhatsAppNumber::STATUS_UNLINKED,
                },
            ])->save();
        }

        // The account-level row mirrors its DEFAULT slot, so the existing status
        // page keeps working. Without a slot (legacy call) it is updated as before.
        $session = null;
        if ($slot === null || $slot->is_default) {
            $session = WhatsAppSession::firstOrNew(['account_id' => $data['account_id']]);
            $session->status = $data['status'];

            if ($data['status'] === 'connected') {
                $session->last_connected_at = now();
                $session->connected_phone_number = $data['phone_number'] ?? $session->connected_phone_number;
            } else {
                // Not connected (connecting, disconnected): no number is linked.
                $session->connected_phone_number = null;
            }

            $session->save();
        }

        return response()->json(['message' => 'Status updated.', 'session' => $session ?? $slot?->fresh()]);
    }
}
