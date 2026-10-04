<?php

namespace App\Logging;

use Illuminate\Log\LogManager;
use Illuminate\Log\Logger;

/**
 * Phase 12 Task 3 (redaction fix) — the shared logging boundary.
 *
 * Laravel builds every named channel (default, stack members, json, daily, slack, deprecations …) through
 * LogManager::tap(), and builds ad-hoc stacks and the emergency logger separately. This subclass installs
 * RedactingProcessor on all three, so every record is redacted before ANY handler writes it — without touching a
 * single Log::… call site, and without requiring LOG_CHANNEL=json.
 *
 * Monolog runs the most recently pushed processor first: the framework pushes its Context processor after tap(),
 * so Context (request_id, account_id, job …) is already in `extra` when RedactingProcessor runs and is covered too.
 */
class RedactingLogManager extends LogManager
{
    protected function tap($name, Logger $logger)
    {
        return $this->redact(parent::tap($name, $logger));
    }

    public function stack(array $channels, $channel = null)
    {
        return $this->redact(parent::stack($channels, $channel));
    }

    protected function createEmergencyLogger()
    {
        return $this->redact(parent::createEmergencyLogger());
    }

    private function redact(Logger $logger): Logger
    {
        if (method_exists($logger->getLogger(), 'pushProcessor')) {
            $logger->pushProcessor(new RedactingProcessor());
        }

        return $logger;
    }
}
