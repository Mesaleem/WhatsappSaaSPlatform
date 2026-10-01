<?php

namespace App\Services\Ai\Agents\Tools;

use App\Models\CrmLead;

/**
 * Phase 8 Task 11 — read-only: does the current conversation's customer
 * already have a CRM lead, and in which status? Returns the most recent
 * open lead (else the most recent lead), minimal fields only.
 */
final class CrmFindCurrentLeadTool extends CrmConversationTool
{
    public const NAME = 'crm.lead.find_current';

    public function name(): string
    {
        return self::NAME;
    }

    public function label(): string
    {
        return 'Find the customer\'s CRM lead';
    }

    public function description(): string
    {
        return 'Look up the CRM lead of the customer in this conversation. Returns found=false when the customer has no lead. Takes no arguments.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }

    public function sideEffect(): bool
    {
        return false;
    }

    public function execute(ToolContext $context, array $arguments): array
    {
        $contact = $this->contact($context);

        if ($contact === null) {
            return ['found' => false];
        }

        $lead = $this->openLead($context, $contact)
            ?? CrmLead::query()->forAccount((int) $context->account->id)->where('contact_id', $contact->id)->orderByDesc('id')->first();

        return $lead === null ? ['found' => false] : ['found' => true] + $this->view($lead);
    }
}
