<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppNumber;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/internal/whatsapp-numbers/{id} — qr-engine-service asks which account
 * owns a number slot, before it shows that slot's QR or status to a browser.
 * Behind internal.secret. Returns only the owner and the number's status.
 */
class WhatsAppNumberLookupController extends Controller
{
    public function show(int $id): JsonResponse
    {
        $slot = WhatsAppNumber::query()->whereKey($id)->first();

        if ($slot === null) {
            return response()->json(['message' => 'WhatsApp number not found.'], 404);
        }

        return response()->json([
            'account_id' => $slot->account_id,
            'phone_number' => $slot->phone_number,
            'is_default' => $slot->is_default,
            'status' => $slot->status,
        ]);
    }
}
