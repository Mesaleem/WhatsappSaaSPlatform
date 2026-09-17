<?php

namespace App\Jobs;

use App\Services\Templates\TemplateMessageDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Anti-Spam Bulk Dispatch -- the async half of
 * MessageTemplateController::sendBulk(). One instance == one recipient:
 * the controller enqueues one of these per parsed phone number with a
 * pre-computed ->delay() (see sendBulk()'s docblock for the cumulative
 * randomized-delay + batch-pause math), rather than looping and calling
 * TemplateMessageDispatcher::dispatch() N times inline in the HTTP
 * request -- that would either block the request for minutes (N times a
 * few seconds, plus a 1-2 minute pause every 10 -- see
 * BulkMessageDispatcher for the exact numbers) or, if fired in parallel, send a burst of
 * near-simultaneous messages that is exactly the mechanical, fixed-
 * cadence pattern anti-ban jitter exists to avoid (see
 * ProcessPaymentAlertJob's own docblock on this).
 *
 * Deliberately dispatched with ->onConnection('database')->onQueue(
 * 'whatsapp-bulk') from the controller, NOT the app's default queue
 * connection (QUEUE_CONNECTION=sync in this environment) -- every other
 * existing Job in this codebase (ProcessPaymentAlertJob,
 * CreateNativeWhatsAppGroupJob, DispatchWebhookJob, etc.) is dispatched
 * with no explicit connection and keeps running synchronously/inline as
 * before; only this bulk path is routed onto the 'database' connection,
 * which already has a ready-to-use `jobs` table (0001_01_01_000002_
 * create_jobs_table.php) and needs no new migration or default-
 * connection/.env change. See routes/console.php for the scheduled
 * `queue:work database --queue=whatsapp-bulk` burst command that
 * actually drains this queue (this environment has no long-running
 * queue:work daemon process defined anywhere in this repo -- confirmed
 * by searching for Procfile/supervisor/k8s manifests before choosing
 * this design -- so a persistent worker was never a safe assumption).
 *
 * Reuses TemplateMessageDispatcher::dispatch() verbatim -- the exact
 * same account/quota/approval/variable/WhatsApp-connection checks and
 * MessageDispatchLog audit trail as the single-recipient send path
 * (MessageTemplateController::send()) and the external API. Per-
 * recipient outcomes are therefore already visible on the existing
 * Message Logs page (GET /api/alerts/logs) once each job runs -- no new
 * results UI was needed for this feature.
 */
class SendWhatsAppTemplateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Exactly one attempt -- same reasoning as ProcessPaymentAlertJob:
     * a WhatsApp send is not idempotent on our side, so an automatic
     * Laravel retry risks a duplicate real-world message. A failed
     * recipient is logged (MessageDispatchLog, written inside
     * TemplateMessageDispatcher::dispatch() itself) and left for the
     * tenant to see on Message Logs and resend manually, not silently
     * retried.
     */
    public int $tries = 1;

    public function __construct(
        public readonly int $accountId,
        public readonly int $templateId,
        public readonly string $recipientPhone,
        public readonly array $variables,
        public readonly ?string $mediaUrl = null,
    ) {
    }

    public function handle(): void
    {
        $result = TemplateMessageDispatcher::dispatch(
            $this->accountId,
            $this->templateId,
            $this->recipientPhone,
            $this->variables,
            source: 'web_template_bulk',
            mediaUrl: $this->mediaUrl,
        );

        if ($result['status'] !== 'sent') {
            // Not re-thrown -- TemplateMessageDispatcher::dispatch()
            // already wrote a failed MessageDispatchLog row for every
            // non-'sent' status (quota_exhausted, not_found, disconnected,
            // missing_variables, failed), so the tenant can already see
            // and act on this via Message Logs. This is an operational
            // breadcrumb for whoever runs the queue worker, not the
            // tenant-facing record of the failure.
            Log::warning('SendWhatsAppTemplateJob: bulk recipient did not send.', [
                'account_id' => $this->accountId,
                'template_id' => $this->templateId,
                'recipient_phone' => $this->recipientPhone,
                'status' => $result['status'],
                'message' => $result['message'] ?? null,
            ]);
        }
    }
}
