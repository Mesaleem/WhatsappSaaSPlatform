<?php

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Phase 12 Task 3 (redaction fix) — applies Redactor to EVERY record before any handler/formatter sees it.
 * Installed on every channel by RedactingLogManager, so the guarantee does not depend on which channel is selected.
 *
 * Message text and context/extra are scrubbed; a Throwable in the context is replaced by a safe description
 * (class, code, scrubbed message, file basename, line, frame list), so exception information survives but a secret
 * inside an exception message does not. Idempotent: running it twice (e.g. again inside the JSON formatter) is a no-op.
 */
final class RedactingProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        try {
            return $record->with(
                message: (string) Redactor::scrubText($record->message),
                context: Redactor::redact($record->context),
                extra: Redactor::redact($record->extra),
            );
        } catch (\Throwable) {
            // Fail closed: if redaction itself breaks, drop the structured data rather than emit it unredacted.
            return $record->with(message: (string) Redactor::scrubText($record->message), context: ['redaction_failed' => true], extra: []);
        }
    }
}
