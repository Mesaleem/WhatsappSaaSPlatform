<?php

namespace App\Services\Ai;

use App\Events\AiOperationPerformed;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 8 AI foundation — safe operation logging. One structured log line
 * (and one AiOperationPerformed event) per AI operation with: account id,
 * actor id, operation, source, provider, model, success, latency, error
 * code/category and vendor-reported token counts.
 *
 * NEVER logged: prompts, system prompts, response text or data, API keys,
 * headers, vendor response bodies, exception messages from the vendor.
 * Logging failures never break the AI call.
 */
class AiOperationLogger
{
    public function record(AiOperationPerformed $event): void
    {
        try {
            $context = [
                'account_id' => $event->accountId,
                'actor_user_id' => $event->actorUserId,
                'operation' => $event->operation,
                'source' => $event->source,
                'provider' => $event->provider,
                'model' => $event->model,
                'success' => $event->success,
                'latency_ms' => $event->latencyMs,
                'error_code' => $event->errorCode,
                'error_category' => $event->errorCategory,
                'input_tokens' => $event->inputTokens,
                'output_tokens' => $event->outputTokens,
                'operation_id' => $event->operationId,
            ];

            $logger = config('ai.log_channel') ? Log::channel(config('ai.log_channel')) : Log::getFacadeRoot();
            $event->success
                ? $logger->info('AI operation completed.', $context)
                : $logger->warning('AI operation failed.', $context);

            event($event);
        } catch (Throwable $e) {
            Log::warning('AiOperationLogger: could not record an AI operation.', ['exception' => class_basename($e)]);
        }
    }
}
