<?php

namespace App\Services\WhatsApp;

use App\Models\JourneyExecutionEvent;
use App\Models\WhatsAppFlowSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 7 Task 7 — writes Journey execution events (vocabulary on
 * App\Models\JourneyExecutionEvent). Observability only:
 *
 *   - one INSERT per event, no reads (except inbound_deduplicated, which
 *     looks up the original event's session by an indexed id);
 *   - never throws — a failed write is logged and execution carries on
 *     exactly as it would have without instrumentation;
 *   - bounded rows: message bodies, graph JSON, context variables, provider
 *     responses and credentials are never stored. error_message is the same
 *     human-readable text the session's last_error already carries (≤ 500
 *     chars); `details` keeps at most MAX_DETAILS whitelisted scalar keys.
 */
final class JourneyExecutionRecorder
{
    /** Normalised failure categories (error_category). */
    public const CATEGORIES = [
        'invalid_configuration', // a node's config / condition could never run
        'missing_node',          // an edge (or checkpoint) points at a node the pinned version lacks
        'unsupported_node',      // a palette node the engine has no execution path for
        'provider_failure',      // the WhatsApp engine did not accept the message
        'quota_failure',         // subscription inactive or message quota exhausted
        'crm_failure',           // the CRM write of a captured lead failed
        'entitlement_blocked',   // module/capability/journey entitlement denied
        'execution_limit',       // MAX_ADVANCE_STEPS reached in one run
        'flow_unavailable',      // the journey was deactivated/removed, or the account is gone
        'cancelled',             // an operator cancelled the session
        'internal_error',        // anything else (unexpected exception, integrity guard)
    ];

    public const SOURCES = ['inbound', 'test', 'scheduler', 'api', 'system'];

    private const DETAIL_KEYS = ['dispatch_log_id', 'lead_id', 'crm_lead_id', 'next_node_id', 'provider', 'restored_to'];

    private const MAX_DETAILS = 6;

    /**
     * @param  array{source?: string, inbound_event_id?: int|null, node_id?: string|null, node_type?: string|null,
     *               result?: string|null, attempt?: int|null, error_category?: string|null, error_message?: string|null,
     *               scheduled_for?: \DateTimeInterface|null, details?: array<string, mixed>}  $attributes
     */
    public function record(WhatsAppFlowSession $session, string $event, array $attributes = []): void
    {
        $this->insert([
            'account_id' => $session->account_id,
            'flow_id' => $session->flow_id,
            'flow_version_id' => $session->flow_version_id,
            'session_id' => $session->id,
            'node_id' => array_key_exists('node_id', $attributes) ? $attributes['node_id'] : $session->current_node_id,
            'attempt' => array_key_exists('attempt', $attributes) ? $attributes['attempt'] : (int) $session->attempts,
        ] + $attributes, $event);
    }

    /**
     * A redelivered inbound message the gate skipped. Recorded only when the
     * ORIGINAL message was handled by a Journey (its claim id appears on a
     * journey event) — duplicates of plain chatbot traffic are not Journey
     * history. One indexed lookup, one insert.
     */
    public function recordDuplicate(int $accountId, ?int $originalInboundEventId, string $provider): void
    {
        if ($originalInboundEventId === null) {
            return;
        }

        try {
            $original = JourneyExecutionEvent::query()
                ->where('inbound_event_id', $originalInboundEventId)
                ->where('account_id', $accountId)
                ->orderBy('id')
                ->first(['flow_id', 'flow_version_id', 'session_id', 'node_id']);
        } catch (Throwable $e) {
            Log::warning('JourneyExecutionRecorder: duplicate lookup failed.', ['error' => $e->getMessage()]);

            return;
        }

        if (! $original) {
            return;
        }

        $this->insert([
            'account_id' => $accountId,
            'flow_id' => $original->flow_id,
            'flow_version_id' => $original->flow_version_id,
            'session_id' => $original->session_id,
            'node_id' => $original->node_id,
            'source' => 'inbound',
            'inbound_event_id' => $originalInboundEventId,
            'details' => ['provider' => $provider],
        ], JourneyExecutionEvent::INBOUND_DEDUPLICATED);
    }

    /** The category of a step failure (JourneyStepFailed carries its own). */
    public static function categoryOf(Throwable $e): string
    {
        return $e instanceof JourneyStepFailed ? $e->category : 'internal_error';
    }

    /** @param array<string, mixed> $row */
    private function insert(array $row, string $event): void
    {
        try {
            $details = array_slice(array_filter(
                array_intersect_key($row['details'] ?? [], array_flip(self::DETAIL_KEYS)),
                fn ($v) => is_int($v) || (is_string($v) && $v !== ''),
            ), 0, self::MAX_DETAILS, true);

            DB::table('journey_execution_events')->insert([
                'account_id' => $row['account_id'],
                'flow_id' => $row['flow_id'] ?? null,
                'flow_version_id' => $row['flow_version_id'] ?? null,
                'session_id' => $row['session_id'] ?? null,
                'inbound_event_id' => $row['inbound_event_id'] ?? null,
                'source' => in_array($row['source'] ?? null, self::SOURCES, true) ? $row['source'] : 'system',
                'event' => $event,
                'node_id' => self::cut($row['node_id'] ?? null, 64),
                'node_type' => self::cut($row['node_type'] ?? null, 32),
                'result' => self::cut($row['result'] ?? null, 64),
                'attempt' => isset($row['attempt']) ? max(0, min(65535, (int) $row['attempt'])) : null,
                'error_category' => in_array($row['error_category'] ?? null, self::CATEGORIES, true) ? $row['error_category'] : (isset($row['error_category']) ? 'internal_error' : null),
                'error_message' => self::cut($row['error_message'] ?? null, 500),
                'scheduled_for' => $row['scheduled_for'] ?? null,
                'details' => $details === [] ? null : json_encode(array_map(fn ($v) => is_string($v) ? mb_substr($v, 0, 191) : $v, $details)),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('JourneyExecutionRecorder: could not record a journey execution event.', [
                'event' => $event,
                'session_id' => $row['session_id'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function cut(mixed $value, int $length): ?string
    {
        return ($value === null || $value === '') ? null : mb_substr((string) $value, 0, $length);
    }
}
