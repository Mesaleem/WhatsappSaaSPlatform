<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\PaymentAlerts\PaymentAlertDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Module 9, requirement 2 — the public external Developer API. Every
 * route in this namespace is authenticated by AuthenticateApiKey (Bearer
 * API key, NOT Sanctum/session) and rate-limited per-account by
 * `throttle:external-api` (see routes/api.php and
 * AppServiceProvider::boot()). There is no Laravel "user" on these
 * requests — the caller is an external system, scoped strictly to the
 * account that owns the presented API key via
 * $request->attributes->get('api_account_id'), which is NEVER accepted
 * from the request body/headers.
 */
class ExternalAlertController extends Controller
{
    /** POST /api/v1/messages/send-payment-alert */
    public function sendPaymentAlert(Request $request): JsonResponse
    {
        $accountId = $request->attributes->get('api_account_id');
        // Defensive only — AuthenticateApiKey guarantees this is set for
        // any request that reaches this controller; abort rather than
        // silently trust an absent value if the middleware is ever
        // misconfigured on a future route.
        abort_if(! $accountId, 401, 'Unauthenticated.');

        $account = Account::find($accountId);
        abort_if(! $account, 401, 'Unauthenticated.');

        // Requirement: "checks subscription quota limits". Fails fast
        // with a clear 403 for an external integrator instead of a
        // deceptive 202 that silently dies later inside
        // ProcessPaymentAlertJob — which still re-checks this itself as
        // defense-in-depth (the account's state can change in the window
        // between this check and the queue worker actually processing it).
        if (! $account->hasActiveSubscription()) {
            return response()->json([
                'message' => 'This account has no active subscription, or its message quota is exhausted.',
            ], 403);
        }

        $data = $request->validate([
            'recipient_phone' => ['required', 'string', 'max:20'],
            'customer_name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_ref' => ['required', 'string', 'max:255'],
        ]);

        // Same dedup-then-create-then-queue path as the internal
        // /api/alerts/send endpoint (PaymentAlertController) — see
        // PaymentAlertDispatcher's docblock for why this is shared rather
        // than duplicated.
        $result = PaymentAlertDispatcher::dispatch($accountId, $data);

        if ($result['status'] === 'disconnected') {
            return response()->json([
                'message' => 'WhatsApp account is disconnected. Please connect your device first.',
                'error_code' => 'WHATSAPP_DISCONNECTED',
            ], 422);
        }

        if ($result['status'] === 'duplicate') {
            return response()->json([
                'message' => "An alert for payment_ref '{$result['payment_ref']}' has already been sent for this account.",
            ], 409);
        }

        $alert = $result['alert'];

        return response()->json([
            'message' => 'Payment alert queued.',
            'alert' => [
                'id' => $alert->id,
                'status' => $alert->status,
                'recipient_phone' => $alert->recipient_phone,
                'payment_ref' => $alert->payment_ref,
            ],
        ], 202);
    }
}
