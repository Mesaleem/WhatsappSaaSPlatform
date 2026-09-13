<?php

namespace App\Services\PaymentAlerts;

use App\Jobs\ProcessPaymentAlertJob;
use App\Models\Account;
use App\Models\PaymentAlert;
use Illuminate\Database\QueryException;

/**
 * Module 9 — extracted from PaymentAlertController::send() so the new
 * external Api\V1\ExternalAlertController can dispatch a payment alert
 * through the EXACT SAME dedup-then-create-then-queue logic as the
 * internal, session-authenticated endpoint, rather than a second,
 * independently-maintained copy that could silently drift (e.g. one path
 * forgetting the payment_ref dedup check). PaymentAlertController::send()
 * itself was refactored to call this too — its request/response shape is
 * unchanged, only the internals moved.
 */
class PaymentAlertDispatcher
{
    /**
     * True only for a 'qr'-engine account whose latest whatsapp_sessions
     * row isn't 'connected'. Deliberately scoped to the 'qr' engine only —
     * a 'meta'-engine account's whatsapp_sessions.status is never touched
     * by qr-engine-service's status webhook (it stays at its DB default
     * 'disconnected' forever), so applying this check unscoped would block
     * every Meta Cloud API account from ever sending. Meta-engine readiness
     * is validated separately, inside WhatsAppEngineFactory::makeMetaDriver().
     */
    public static function isWhatsAppDisconnected(Account $account): bool
    {
        return $account->currentSubscription?->engine_type === 'qr'
            && $account->whatsAppSession?->status !== 'connected';
    }

    /**
     * [New feature, disclosed]: $source/$apiKeyId are threaded through to
     * ProcessPaymentAlertJob purely so it can write the right
     * MessageDispatchLog row once the send resolves — this method's own
     * dedup/create/queue logic is completely unchanged.
     *
     * @param array{recipient_phone: string, customer_name: string, amount: float|string, payment_ref: string} $data
     * @return array{status: 'queued'|'duplicate'|'disconnected', alert?: PaymentAlert, payment_ref?: string}
     */
    public static function dispatch(int $accountId, array $data, string $source = 'web_ui', ?int $apiKeyId = null): array
    {
        $account = Account::with(['currentSubscription', 'whatsAppSession'])->find($accountId);

        if ($account && self::isWhatsAppDisconnected($account)) {
            return ['status' => 'disconnected'];
        }

        if (PaymentAlert::isDuplicate($accountId, $data['payment_ref'])) {
            return ['status' => 'duplicate', 'payment_ref' => $data['payment_ref']];
        }

        try {
            $alert = PaymentAlert::create([
                ...$data,
                'account_id' => $accountId,
                'status' => 'queued',
            ]);
        } catch (QueryException $e) {
            // SQLSTATE 23000 = integrity constraint violation — the
            // unique(['account_id','payment_ref']) index's concurrency
            // backstop for the isDuplicate() race, consistent across
            // SQLite (this project's local driver) and MySQL.
            if ($e->getCode() === '23000') {
                return ['status' => 'duplicate', 'payment_ref' => $data['payment_ref']];
            }

            throw $e;
        }

        ProcessPaymentAlertJob::dispatch($alert->id, $source, $apiKeyId);

        return ['status' => 'queued', 'alert' => $alert];
    }
}
