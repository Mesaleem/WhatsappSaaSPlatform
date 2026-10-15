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

            $mismatch = $data['status'] === 'connected' && ! empty($data['phone_number']) && $data['phone_number'] !== $slot->phone_number;

            if ($mismatch) {
                // [Owner instruction, disclosed]: a DEFAULT (included, free) slot never
                // has a number typed in before connecting -- WhatsAppController::
                // ensureDefaultSlot()/platformDeviceSlot() auto-create it with a
                // placeholder phone_number, so there is nothing real to mismatch
                // against. The first (and every) real connection simply adopts
                // whatever number signs in for that slot, for ANY account (super
                // admin, admin, agent). An ADD-ON slot is always added with a real,
                // pre-verified number (WhatsAppNumberService::add()), so it never has
                // a pending placeholder and this still refuses a different number.
                if ($slot->acceptsAnyNumber()) {
                    // [Owner instruction, disclosed]: "duplicate na ho" -- the same real
                    // WhatsApp number must not end up on two slots. phone_number is
                    // globally UNIQUE (see the whatsapp_numbers migration), so adopting
                    // one already held by another slot would otherwise throw a raw SQL
                    // unique-constraint exception instead of a clean refusal.
                    $takenByAnotherSlot = WhatsAppNumber::query()
                        ->where('phone_number', $data['phone_number'])
                        ->whereKeyNot($slot->id)
                        ->exists();

                    if ($takenByAnotherSlot) {
                        $slot->forceFill(['status' => WhatsAppNumber::STATUS_UNLINKED])->save();

                        return response()->json([
                            'message' => 'That WhatsApp number is already connected to another account or number slot.',
                            'error_code' => 'number_already_used',
                        ], 422);
                    }

                    $slot->phone_number = $data['phone_number'];
                } else {
                    $slot->forceFill(['status' => WhatsAppNumber::STATUS_UNLINKED])->save();

                    return response()->json([
                        'message' => 'That WhatsApp account is a different number from this slot. Link the number you added.',
                        'error_code' => 'number_mismatch',
                    ], 422);
                }
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

        $this->tellTheClient((int) $data['account_id'], $data['status'], $slot);

        return response()->json(['message' => 'Status updated.', 'session' => $session ?? $slot?->fresh()]);
    }

    /**
     * QA fixes (2026-10-14) — a disconnect is no longer silent: the client's
     * admins get an in-app notice and the owner an email (once per number per
     * 6 hours). A reconnect clears it, and Send Notification batches that were
     * paused because a sending number disconnected carry on by themselves.
     */
    private function tellTheClient(int $accountId, string $status, ?WhatsAppNumber $slot): void
    {
        $account = \App\Models\Account::query()->find($accountId);
        if ($account === null || $account->isPlatformAccount()) {
            return;
        }
        $alerts = app(\App\Services\Notifications\AccountAlerts::class);
        $key = 'wa-disconnected-'.($slot?->id ?? 'default');

        if ($status === 'disconnected') {
            // A paused number was logged out on purpose (term ended): its own notice says so.
            if ($slot !== null && $slot->status === WhatsAppNumber::STATUS_PAUSED) {
                return;
            }
            $number = $slot && ! $slot->hasPendingPlaceholderNumber() ? $slot->phone_number : 'your WhatsApp number';
            $alerts->notify(
                $account,
                $key,
                'WhatsApp disconnected',
                "{$number} was disconnected from WhatsApp. Messages, the chatbot and journeys cannot send until you reconnect it in WhatsApp Setup.",
                'whatsapp',
                '/settings/whatsapp',
                'Reconnect WhatsApp',
            );

            return;
        }

        if ($status === 'connected') {
            $alerts->reset($account, $key);
            \App\Models\MessageBatch::query()
                ->where('account_id', $accountId)
                ->where('status', \App\Models\MessageBatch::PAUSED)
                ->where('stop_reason', 'Paused: a sending WhatsApp number is disconnected.')
                ->pluck('id')
                ->each(function (int $batchId) use ($account): void {
                    try {
                        app(\App\Services\Batches\MessageBatchService::class)->resume($account, $batchId);
                    } catch (\Throwable) {
                        // Left paused; the user can resume it from the page.
                    }
                });
        }
    }
}
