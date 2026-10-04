<?php

namespace App\Logging;

use Monolog\Formatter\NormalizerFormatter;
use Monolog\LogRecord;

/**
 * Phase 12 Task 3 — one JSON object per line, for production log shippers.
 *
 * Fixed top-level fields (always present when known): timestamp, level, channel, message, request_id,
 * account_id, user_id, job{class,queue,connection,attempt,uuid}, exception{class,message,code,file,line,trace?}.
 * Everything else the caller passed lands under `context` (and `extra` for processor output), after Redactor.
 *
 * Context is read from the record's `extra` (Laravel copies its log Context there). For API-key requests
 * (/api/v1) the tenant is read lazily from the request attributes — attributes only, never request input.
 */
class StructuredLogFormatter extends NormalizerFormatter
{
    private const HOISTED = ['request_id', 'account_id', 'user_id', 'job'];

    public function __construct(private readonly bool $includeTrace = false)
    {
        parent::__construct(\DateTimeInterface::RFC3339_EXTENDED);
    }

    public function format(LogRecord $record): string
    {
        $extra = $record->extra;
        $context = $record->context;

        $exception = null;
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $exception = $this->describeException($context['exception']);
            unset($context['exception']);
        } elseif (isset($context['exception']['class'], $context['exception']['message']) && is_array($context['exception'])) {
            // already described (and redacted) by RedactingProcessor
            $exception = $context['exception'];
            if (! $this->includeTrace) {
                unset($exception['trace']);
            }
            unset($context['exception']);
        }

        $out = [
            'timestamp' => $record->datetime->format('Y-m-d\TH:i:s.vP'),
            'level' => strtolower($record->level->getName()),
            'channel' => $record->channel,
            'message' => Redactor::scrubText($record->message),
            'request_id' => $extra['request_id'] ?? $context['request_id'] ?? null,
            'account_id' => $extra['account_id'] ?? $context['account_id'] ?? $this->requestAccountId(),
            'user_id' => $extra['user_id'] ?? $context['user_id'] ?? null,
        ];

        if (isset($extra['job']) && is_array($extra['job'])) {
            $out['job'] = Redactor::redact($extra['job']);
        }
        if ($exception !== null) {
            $out['exception'] = $exception;
        }

        $out = array_filter($out, fn ($v) => $v !== null);

        $rest = array_diff_key($context, array_flip(self::HOISTED));
        if ($rest !== []) {
            $out['context'] = Redactor::redact($this->normalize($rest));
        }
        $restExtra = array_diff_key($extra, array_flip(self::HOISTED));
        if ($restExtra !== []) {
            $out['extra'] = Redactor::redact($this->normalize($restExtra));
        }

        return (json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}')."\n";
    }

    /** @return array<string, mixed> */
    private function describeException(\Throwable $e): array
    {
        return Redactor::describeThrowable($e, $this->includeTrace);
    }

    private function requestAccountId(): ?int
    {
        if (! function_exists('app') || ! app()->bound('request')) {
            return null;
        }
        $attrs = app('request')->attributes;
        $id = $attrs->get('api_account_id') ?? $attrs->get('account_id');

        return is_int($id) ? $id : null;
    }
}
