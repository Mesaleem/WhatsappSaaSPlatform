<?php

namespace App\Console\Commands;

use App\Models\AiOperation;
use App\Services\Ai\Billing\MeteredAiService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Phase 8 Task 5 — completes AI billing that could not finish in-line.
 * Every step is idempotent (CreditService consume:{r} / release:{r} keys,
 * conditional status updates), so an overlapping or repeated run is
 * harmless.
 *
 *   succeeded (answer delivered, charge recorded, ledger write failed)
 *       → settle: consume exactly credits_charged from the hold → settled
 *   failed with a still-open hold
 *       → release the hold (no charge)
 *   running for longer than ai.credits.stale_after_seconds (the process
 *   died mid-call; the outcome is unknown)
 *       → abandoned + release the hold (no charge — never charge an
 *         operation whose usage was never reported)
 */
class SettleAiOperations extends Command
{
    protected $signature = 'ai:settle-operations {--limit=200 : Maximum operations per category per run}';

    protected $description = 'Settle AI usage charges that could not be written in-line, and release abandoned AI credit holds.';

    public function handle(MeteredAiService $metered): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $settled = $released = $abandoned = $errors = 0;

        foreach (AiOperation::query()->where('status', AiOperation::STATUS_SUCCEEDED)->orderBy('id')->limit($limit)->get() as $operation) {
            try {
                $metered->settle($operation) && $settled++;
            } catch (Throwable $e) {
                $errors++;
                $this->warn("ai_operations#{$operation->id}: settlement failed (".class_basename($e).').');
            }
        }

        foreach (AiOperation::query()->where('status', AiOperation::STATUS_FAILED)->whereNotNull('reservation_id')->orderBy('id')->limit($limit)->get() as $operation) {
            try {
                $before = $operation->reservation?->status;
                $metered->releaseHold($operation);
                $before === 'reserved' && $released++;
            } catch (Throwable $e) {
                $errors++;
                $this->warn("ai_operations#{$operation->id}: release failed (".class_basename($e).').');
            }
        }

        $cutoff = now()->subSeconds((int) config('ai.credits.stale_after_seconds', 900));

        foreach (AiOperation::query()->where('status', AiOperation::STATUS_RUNNING)->where('updated_at', '<=', $cutoff)->orderBy('id')->limit($limit)->get() as $operation) {
            try {
                // Phase 8 Task 7 — the same guarded abandon + release, shared with MeteredAiService.
                $metered->abandonIfStale($operation) && $abandoned++;
            } catch (Throwable $e) {
                $errors++;
                $this->warn("ai_operations#{$operation->id}: release of an abandoned hold failed (".class_basename($e).').');
            }
        }

        $this->info("Settled {$settled}, released {$released} failed hold(s), abandoned {$abandoned} stale operation(s); {$errors} error(s).");

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
