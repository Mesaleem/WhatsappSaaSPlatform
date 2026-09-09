<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WhatsAppStatusController extends Controller
{
    /**
     * POST /api/internal/whatsapp-status — webhook called by qr-engine-service
     * (never by a browser) whenever a Baileys connection opens, closes, or
     * starts pairing. Gated by VerifyInternalSecret, not auth:sanctum.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'status' => ['required', Rule::in(['connecting', 'connected', 'disconnected'])],
        ]);

        $session = WhatsAppSession::firstOrNew(['account_id' => $data['account_id']]);
        $session->status = $data['status'];

        if ($data['status'] === 'connected') {
            $session->last_connected_at = now();
        }

        $session->save();

        return response()->json(['message' => 'Status updated.', 'session' => $session]);
    }
}
