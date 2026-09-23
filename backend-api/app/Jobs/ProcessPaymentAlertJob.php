<?php

namespace App\Jobs;

use App\Models\Account;
use App\Models\MessageDispatchLog;
use App\Models\PaymentAlert;
use App\Services\WhatsApp\WhatsAppEngineFactory;
use App\Services\Webhooks\WebhookDispatcher;
use App\Support\PhoneNumberNormalizer;
use App\Services\Messaging\MessageQuotaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Sends one payment_alerts row over WhatsApp via WhatsAppEngineFactory,
 * then records the outcome back onto that same row.
 *
 * Dispatched by PaymentAlertController AFTER the row is created at
 * status='queued' — the row's existence (not this job) is what the
 * payment_ref dedup guard checks, so a job that never runs (queue outage,
 * worker down) still correctly blocks a duplicate resubmission; it just
 * leaves that alert stuck at 'queued' until a worker processes it.
 */
class ProcessPaymentAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Exactly one attempt. WhatsApp sends are not idempotent on our side
     * (the driver call has already left the account's device/Meta by the
     * time a failure could be detected in some error modes), so a Laravel
     * automatic retry risks a duplicate real-world send. A failed alert is
     * recorded as 'failed' and left for a human/future resend flow, not
     * silently retried.
     */
    public int $tries = 1;

    /**
     * [New feature, disclosed]: $source/$apiKeyId exist only to label the
     * MessageDispatchLog row this job writes once the send resolves —
     * every other line of this job's logic is unchanged.
     */
    public function __construct(
        public readonly int $paymentAlertId,
        public readonly string $source = 'web_ui',
        public readonly ?int $apiKeyId = null,
    ) {
    }

    public function handle(): void
    {
        $alert = PaymentAlert::find($this->paymentAlertId);

        if (! $alert) {
            Log::warning("ProcessPaymentAlertJob: payment_alerts#{$this->paymentAlertId} no longer exists.");

            return;
        }

        // Already resolved (defensive — dispatch() should only ever be
        // called once per row, but this makes a duplicate dispatch a no-op
        // instead of a duplicate send).
        if (in_array($alert->status, ['sent', 'failed'], true)) {
            return;
        }

        try {
            $this->process($alert);
        } catch (Throwable $e) {
            Log::error('ProcessPaymentAlertJob failed unexpectedly.', [
                'payment_alert_id' => $alert->id,
                'exception' => $e->getMessage(),
            ]);

            // Module 9 — routed through fail() (rather than the previous
            // inline forceFill) so this path also fires 'message.failed';
            // otherwise an unexpected exception here would silently skip
            // the webhook notification every other failure path gets.
            $this->fail($alert, 'Internal error: '.$e->getMessage());
        }
    }

    private function process(PaymentAlert $alert): void
    {
        $account = Account::with('currentSubscription', 'whatsAppSession')->find($alert->account_id);

        if (! $account || ! $account->hasActiveSubscription()) {
            $this->fail($alert, 'Account has no active subscription.');

            return;
        }

        $subscription = $account->currentSubscription;

        // Phase 5 Task 3 -- this was a hand-inlined copy of
        // Subscription::computeStatus()'s own exhaustion condition.
        // hasQuotaFor(1) is exactly equivalent: remainingQuota() returns
        // null for 'unlimited' or an unset total_allocated_messages
        // (-> always true), otherwise max(0, total - used), so
        // "! hasQuotaFor(1)" is precisely "capped AND used >= total".
        // Advisory pre-send gate only -- unlocked, exactly as the
        // arithmetic it replaces was.
        if (! app(MessageQuotaService::class)->hasQuotaFor($subscription, 1)) {
            $this->fail($alert, 'Message quota exhausted for the current subscription period.');

            return;
        }

        // Anti-ban jitter: WhatsApp (especially the unofficial Baileys/'qr'
        // engine) flags accounts that send in a mechanical, fixed cadence.
        // This intentionally blocks THIS job's worker slot for the delay —
        // it is not a queue re-release. Deploy multiple queue workers
        // (`php artisan queue:work --queue=default -q &` x N, or Horizon)
        // if alert throughput needs to stay high under this delay; a single
        // worker processes at most one alert per ~3-8s by design.
        sleep(random_int(3, 8));

        try {
            $driver = WhatsAppEngineFactory::make($account);
        } catch (RuntimeException $e) {
            $this->fail($alert, $e->getMessage());

            return;
        }

        $message = sprintf(
            'Hi %s, we have received your payment of Rs. %s (Ref: %s). Thank you!',
            $alert->customer_name,
            number_format((float) $alert->amount, 2),
            $alert->payment_ref,
        );

        // Auto-format: strip spaces/symbols, add the '91' country code to a
        // bare 10-digit number. Applied here (not at intake) so it covers
        // every entry point — single send, CSV bulk-upload, external API —
        // from this one call site.
        $normalizedPhone = PhoneNumberNormalizer::normalize($alert->recipient_phone);

        if ($normalizedPhone === '') {
            $this->fail($alert, "Recipient phone number '{$alert->recipient_phone}' is not a valid number after normalization.", messagePreview: $message);

            return;
        }

        $result = $driver->sendMessage($normalizedPhone, $message, [
            'payment_ref' => $alert->payment_ref,
        ]);

        if (empty($result['success'])) {
            $this->fail($alert, $this->actionableErrorMessage($result['error'] ?? null), $result['raw'] ?? null, messagePreview: $message);

            return;
        }

        $costDeducted = $subscription->billing_model === 'per_message'
            ? (float) ($subscription->rate_per_message ?? 0)
            : 0.0000;

        // Atomic: increment used_messages and mark the alert sent together,
        // with a row lock on the subscription so two alerts processed by
        // two concurrent workers can't both read a stale used_messages
        // value and undercount.
        DB::transaction(function () use ($subscription, $alert, $costDeducted, $result) {
            // Phase 5 Task 3 -- consume() is called INSIDE this existing
            // transaction on purpose. It opens its own transaction, which
            // Laravel turns into a savepoint when one is already active,
            // so the row lock it takes is still held until THIS outer
            // transaction commits. The quota increment and the alert's
            // status write therefore remain a single atomic unit, exactly
            // as before -- only the arithmetic moved.
            app(MessageQuotaService::class)->consume($subscription, 1);

            $alert->forceFill([
                'status' => 'sent',
                'cost_deducted' => $costDeducted,
                'error_reason' => null,
                'sent_at' => now(),
                'raw_response' => $result['raw'] ?? null,
                'gateway_message_id' => $result['message_id'] ?? null,
            ])->save();
        });

        // [New feature, disclosed]: logged AFTER the same transaction
        // commits used_messages, same ordering guarantee as the webhook
        // fire below — a MessageDispatchLog row can never be observed
        // before the quota increment it reports on has actually landed.
        MessageDispatchLog::record(
            $alert->account_id,
            $this->source,
            $alert->recipient_phone,
            success: true,
            apiKeyId: $this->apiKeyId,
            referenceType: 'payment_alert',
            referenceId: $alert->id,
            messagePreview: $message,
        );

        // Module 9 — fired AFTER the transaction commits, so a webhook
        // receiver can never observe 'message.sent' before used_messages
        // has actually been incremented and the row is durably saved.
        WebhookDispatcher::fire($alert->account_id, 'message.sent', $this->webhookPayload($alert));
    }

    /**
     * PaymentAlertDispatcher already rejects a send at request time with a
     * 422 if the account's WhatsApp session isn't 'connected' — this is
     * defense-in-depth for the race where the session drops during the
     * job's own 3-8s anti-ban sleep, between that check and the actual
     * send. Translates qr-engine-service's raw signal into copy the tenant
     * can act on, instead of storing the bare string verbatim.
     */
    private function actionableErrorMessage(?string $rawError): string
    {
        if ($rawError === 'Session disconnected') {
            return 'WhatsApp session is disconnected. Please re-scan QR code in WhatsApp Setup.';
        }

        return $rawError ?? 'The WhatsApp engine rejected the message.';
    }

    /**
     * $messagePreview is null for the 3 failure branches reached BEFORE
     * the outgoing text is built (no active subscription, quota
     * exhausted, driver resolution error) — there is nothing to preview
     * yet at that point — and populated for the 2 branches reached after
     * (invalid phone, driver rejection), matching exactly what each call
     * site in process() above actually has in scope.
     */
    private function fail(PaymentAlert $alert, string $reason, ?array $raw = null, ?string $messagePreview = null): void
    {
        $alert->forceFill([
            'status' => 'failed',
            'cost_deducted' => 0.0000,
            'error_reason' => $reason,
            'sent_at' => null,
            'raw_response' => $raw,
        ])->save();

        // [New feature, disclosed]: same single call point as the webhook
        // fire below, so every failure reason (no subscription, quota
        // exhausted, driver error, outright rejection) is logged exactly
        // once, consistent with the 'sent' path in process() above.
        MessageDispatchLog::record(
            $alert->account_id,
            $this->source,
            $alert->recipient_phone,
            success: false,
            errorReason: $reason,
            apiKeyId: $this->apiKeyId,
            referenceType: 'payment_alert',
            referenceId: $alert->id,
            messagePreview: $messagePreview,
        );

        // Module 9 — every failure path (no active subscription, quota
        // exhausted, driver resolution error, or an outright send
        // rejection) funnels through here, so this single call point is
        // enough to guarantee 'message.failed' always fires exactly once
        // per failed alert, regardless of WHY it failed.
        WebhookDispatcher::fire($alert->account_id, 'message.failed', $this->webhookPayload($alert));
    }

    /**
     * @return array<string, mixed>
     */
    private function webhookPayload(PaymentAlert $alert): array
    {
        return [
            'id' => $alert->id,
            'account_id' => $alert->account_id,
            'recipient_phone' => $alert->recipient_phone,
            'customer_name' => $alert->customer_name,
            'amount' => (string) $alert->amount,
            'payment_ref' => $alert->payment_ref,
            'status' => $alert->status,
            'error_reason' => $alert->error_reason,
            'sent_at' => $alert->sent_at?->toIso8601String(),
        ];
    }
}
