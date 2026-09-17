<?php

namespace App\Services\Templates;

use App\Jobs\SendWhatsAppTemplateJob;
use Illuminate\Support\Facades\Log;

/**
 * Anti-Spam Bulk Dispatch — the enqueue-time batching/scheduling logic
 * behind MessageTemplateController::sendBulk(), pulled out of that
 * controller into its own dispatcher class to match this codebase's
 * established *Dispatcher convention (TemplateMessageDispatcher,
 * GroupMessageDispatcher, PaymentAlertDispatcher) rather than living
 * inline in a controller method.
 *
 * Strict batching rule: recipients are split into batches of EXACTLY
 * BATCH_SIZE (the last batch may be smaller — never larger). Within a
 * batch, each recipient after the first gets a short randomized
 * per-message delay stacked onto the running cumulative total, so sends
 * inside one batch don't fire in a mechanical burst. Crossing into a new
 * batch adds one extra randomized pause on top of that running total —
 * once per batch boundary, never per message.
 *
 * IMPORTANT — "pausing the queue worker" here is a SCHEDULE computed
 * once, not a live pause: every recipient's SendWhatsAppTemplateJob is
 * enqueued immediately, right in this one method call, with its own
 * precomputed ->delay(), so the `jobs` table ends up with staggered
 * available_at timestamps for the whole batch up front. Nothing in this
 * method blocks the calling HTTP request or literally puts a worker
 * process to sleep — see SendWhatsAppTemplateJob's own docblock for why
 * (no persistent `queue:work` daemon exists in this environment;
 * routes/console.php's scheduled burst command is what actually drains
 * this queue over time). The Log::info() call below fires at enqueue
 * time and describes that computed schedule — "pausing for Xs" means
 * "the next batch's jobs won't become due for another Xs", not that
 * this request (or a worker) is actually sleeping for that long.
 */
class BulkMessageDispatcher
{
    /** Strictly 10 recipients per batch — not a default, not configurable per call. */
    public const BATCH_SIZE = 10;

    /** Randomized pause added once per batch boundary (1-2 minutes). */
    public const MIN_BATCH_PAUSE_SECONDS = 60;

    public const MAX_BATCH_PAUSE_SECONDS = 120;

    /** Randomized per-message jitter within a batch, to avoid burst firing. */
    public const MIN_PER_MESSAGE_DELAY_SECONDS = 2;

    public const MAX_PER_MESSAGE_DELAY_SECONDS = 4;

    /**
     * @param  list<string>  $recipientPhones
     * @param  array<string, string>  $variables
     * @return array{queued_count: int, batch_count: int, estimated_duration_seconds: int}
     */
    public static function dispatch(
        int $accountId,
        int $templateId,
        array $recipientPhones,
        array $variables,
        ?string $mediaUrl = null,
    ): array {
        $recipientPhones = array_values($recipientPhones);
        $cumulativeDelay = 0;
        $batchNumber = 1;

        foreach ($recipientPhones as $index => $phone) {
            $positionInBatch = $index % self::BATCH_SIZE;

            if ($index > 0) {
                if ($positionInBatch === 0) {
                    // Crossed a batch boundary — pause BEFORE the next
                    // batch's first message (not after the previous
                    // batch's last), so $pauseSeconds is exactly what
                    // separates the previous batch's final send from
                    // this new batch's first.
                    $pauseSeconds = random_int(self::MIN_BATCH_PAUSE_SECONDS, self::MAX_BATCH_PAUSE_SECONDS);
                    $cumulativeDelay += $pauseSeconds;
                    $batchNumber++;

                    Log::info(
                        'Anti-Spam Bulk Dispatch: batch of '.self::BATCH_SIZE." dispatched. Pausing queue worker for {$pauseSeconds} seconds before batch {$batchNumber}...",
                        [
                            'account_id' => $accountId,
                            'template_id' => $templateId,
                            'total_recipients' => count($recipientPhones),
                            'next_batch_number' => $batchNumber,
                            'pause_seconds' => $pauseSeconds,
                        ],
                    );
                } else {
                    $cumulativeDelay += random_int(self::MIN_PER_MESSAGE_DELAY_SECONDS, self::MAX_PER_MESSAGE_DELAY_SECONDS);
                }
            }

            SendWhatsAppTemplateJob::dispatch($accountId, $templateId, $phone, $variables, $mediaUrl)
                // Deliberately NOT the app's default queue connection
                // (QUEUE_CONNECTION=sync) — see SendWhatsAppTemplateJob's
                // own docblock for why this is scoped to its own
                // connection/queue, leaving every other existing Job's
                // synchronous/inline behavior completely unchanged.
                ->onConnection('database')
                ->onQueue('whatsapp-bulk')
                ->delay(now()->addSeconds($cumulativeDelay));
        }

        $queuedCount = count($recipientPhones);

        Log::info(
            "Anti-Spam Bulk Dispatch: {$queuedCount} message(s) queued across {$batchNumber} batch(es).",
            [
                'account_id' => $accountId,
                'template_id' => $templateId,
                'queued_count' => $queuedCount,
                'batch_count' => $batchNumber,
                'estimated_duration_seconds' => $cumulativeDelay,
            ],
        );

        return [
            'queued_count' => $queuedCount,
            'batch_count' => $batchNumber,
            'estimated_duration_seconds' => $cumulativeDelay,
        ];
    }
}
