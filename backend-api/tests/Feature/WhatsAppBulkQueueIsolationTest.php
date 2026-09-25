<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppTemplateJob;
use App\Services\Templates\BulkMessageDispatcher;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Confirms bulk sends stay on their own connection and queue and carry
 * only their own account id. Uses Queue::fake(), so it touches no
 * database and sends no WhatsApp message.
 */
class WhatsAppBulkQueueIsolationTest extends TestCase
{
    public function test_bulk_jobs_are_routed_to_database_whatsapp_bulk_queue_only(): void
    {
        Queue::fake();

        $result = BulkMessageDispatcher::dispatch(
            accountId: 42,
            templateId: 7,
            recipientPhones: ['919000000001', '919000000002', '919000000003'],
            variables: ['name' => 'X'],
        );

        $this->assertSame(3, $result['queued_count']);

        Queue::assertPushed(SendWhatsAppTemplateJob::class, 3);
        Queue::assertPushed(SendWhatsAppTemplateJob::class, function (SendWhatsAppTemplateJob $job) {
            return $job->connection === 'database'
                && $job->queue === 'whatsapp-bulk'
                && $job->accountId === 42
                && $job->tries === 1;
        });
        Queue::assertNotPushed(SendWhatsAppTemplateJob::class, fn (SendWhatsAppTemplateJob $job) => $job->queue !== 'whatsapp-bulk');
    }

    public function test_job_payload_holds_no_user_or_session_state(): void
    {
        $props = array_map(
            fn (\ReflectionProperty $p) => $p->getName(),
            (new \ReflectionClass(SendWhatsAppTemplateJob::class))->getProperties(\ReflectionProperty::IS_PUBLIC),
        );

        foreach (['accountId', 'templateId', 'recipientPhone', 'variables', 'mediaUrl'] as $expected) {
            $this->assertContains($expected, $props);
        }
        foreach ($props as $name) {
            $this->assertDoesNotMatchRegularExpression('/user|session|token|selected/i', $name, "Bulk job must not carry request-scoped state ({$name}).");
        }
    }
}
