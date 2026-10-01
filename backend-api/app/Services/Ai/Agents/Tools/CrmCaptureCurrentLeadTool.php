<?php

namespace App\Services\Ai\Agents\Tools;

use App\Models\CrmLead;
use App\Services\Crm\CrmLeadService;

/**
 * Phase 8 Task 11 — side effect: make sure the current conversation's
 * customer has an open CRM lead. An existing open lead is returned
 * (created=false) instead of creating a duplicate; otherwise one is created
 * through the existing CrmLeadService (source `journey`, status `new`; the
 * contact is resolved/created by ContactResolver, whose never-overwrite
 * rule applies to the optional name/email). ToolExecutor records the call in
 * the same transaction, so a retried journey step never creates it twice.
 */
final class CrmCaptureCurrentLeadTool extends CrmConversationTool
{
    public const NAME = 'crm.lead.capture_current';

    public function __construct(private readonly CrmLeadService $leads)
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function label(): string
    {
        return 'Capture the customer as a CRM lead';
    }

    public function description(): string
    {
        return 'Create a CRM lead for the customer in this conversation, or return their existing open lead. Optionally pass the customer\'s name and email if they gave them.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
                'email' => ['type' => 'string', 'maxLength' => 191, 'format' => 'email'],
            ],
            'required' => [],
        ];
    }

    public function sideEffect(): bool
    {
        return true;
    }

    public function execute(ToolContext $context, array $arguments): array
    {
        $contact = $this->contact($context);
        $existing = $contact === null ? null : $this->openLead($context, $contact);

        if ($existing !== null) {
            return ['created' => false] + $this->view($existing);
        }

        $lead = $this->leads->create($context->account, [
            'phone_number' => (string) $context->contactPhone(),
            'name' => isset($arguments['name']) ? trim((string) $arguments['name']) : null,
            'email' => $arguments['email'] ?? null,
            'status' => CrmLead::STATUS_NEW,
            'source' => CrmLead::SOURCE_JOURNEY,
        ]);

        return ['created' => true] + $this->view($lead);
    }
}
