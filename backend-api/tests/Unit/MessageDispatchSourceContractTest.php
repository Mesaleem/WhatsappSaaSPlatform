<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\MessageDispatchLogController;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Regression test for the 2026-09-25 "Analytics / Message Logs go blank
 * after running the whatsapp-bulk worker" incident.
 *
 * Root cause: SendWhatsAppTemplateJob writes message_dispatch_logs rows
 * with source = 'web_template_bulk', a value that neither
 * MessageDispatchLogController::VALID_SOURCES nor the frontend's
 * MessageDispatchSource union knew about. The frontend looked it up in a
 * Record<MessageDispatchSource, ...> badge map, got undefined, and threw
 * during render.
 *
 * DB-free on purpose: phpunit.xml points at the real MySQL database
 * (the sqlite lines are commented out), so this test only scans source
 * files and never boots Eloquent.
 */
class MessageDispatchSourceContractTest extends TestCase
{
    /** @return list<string> every literal `source` value written to message_dispatch_logs under app/ */
    private function sourcesWrittenByBackend(): array
    {
        $appDir = dirname(__DIR__, 2).'/app';
        $patterns = [
            "/\\bsource:\\s*'([a-z_]+)'/",                                  // named arg: source: 'x'
            "/\\\$source\\s*=\\s*'([a-z_]+)'/",                             // param default: $source = 'x'
            "/MessageDispatchLog::record\\([^,]+,\\s*'([a-z_]+)'/",        // positional 2nd arg
        ];

        $found = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appDir));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = file_get_contents($file->getPathname());
            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $code, $m)) {
                    foreach ($m[1] as $source) {
                        $found[$source] = true;
                    }
                }
            }
        }

        return array_keys($found);
    }

    public function test_bulk_job_source_is_whitelisted(): void
    {
        $this->assertContains('web_template_bulk', MessageDispatchLogController::VALID_SOURCES);
    }

    public function test_every_backend_written_source_is_filterable(): void
    {
        $written = $this->sourcesWrittenByBackend();

        $this->assertNotEmpty($written, 'Source scan found nothing; the regex patterns are stale.');
        $this->assertContains('web_template_bulk', $written, 'Scan must see SendWhatsAppTemplateJob.');

        foreach ($written as $source) {
            $this->assertContains(
                $source,
                MessageDispatchLogController::VALID_SOURCES,
                "message_dispatch_logs.source '{$source}' is written by the backend but missing from MessageDispatchLogController::VALID_SOURCES.",
            );
        }
    }

    public function test_every_backend_written_source_is_known_to_the_frontend(): void
    {
        $typeFile = dirname(__DIR__, 3).'/frontend-app/src/types/messageLog.ts';
        if (! is_file($typeFile)) {
            $this->markTestSkipped('frontend-app not present next to backend-api.');
        }

        $ts = file_get_contents($typeFile);
        $this->assertSame(1, preg_match('/export type MessageDispatchSource\s*=\s*([^;]+);/', $ts, $m));
        preg_match_all("/'([a-z_]+)'/", $m[1], $union);

        foreach ($this->sourcesWrittenByBackend() as $source) {
            $this->assertContains(
                $source,
                $union[1],
                "message_dispatch_logs.source '{$source}' is missing from the frontend MessageDispatchSource union (types/messageLog.ts); pages keyed by it will render undefined.",
            );
        }
    }
}
