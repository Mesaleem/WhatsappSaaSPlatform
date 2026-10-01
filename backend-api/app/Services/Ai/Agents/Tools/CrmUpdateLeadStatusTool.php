<?php

namespace App\Services\Ai\Agents\Tools;

use App\Models\CrmLead;
use App\Services\Crm\CrmLeadService;
use Illuminate\Validation\ValidationException;

/**
 * Phase 8 Task 11 — side effect: move one lead of the CURRENT conversation's
 * customer to another status, through the existing CrmLeadService lifecycle
 * (transition matrix, terminal-outcome stamping, LogsActivity audit). The
 * lead id must belong to the session's account AND to this conversation's
 * contact — any other id (another customer, another account) is "not found".
 */
final class CrmUpdateLeadStatusTool extends CrmConversationTool
{
    public const NAME = 'crm.lead.update_status';

    public function __construct(private readonly CrmLeadService $leads)
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function label(): string
    {
        return 'Update the customer\'s lead status';
    }

    public function description(): string
    {
        return 'Change the status of a CRM lead of the customer in this conversation (use crm.lead.find_current first to get its lead_id). A reason may be given when the status is not_converted.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'lead_id' => ['type' => 'integer', 'minimum' => 1],
                'status' => ['type' => 'string', 'maxLength' => 32, 'enum' => CrmLead::STATUSES],
                'not_converted_reason' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
            ],
            'required' => ['lead_id', 'status'],
        ];
    }

    public function sideEffect(): bool
    {
        return true;
    }

    public function execute(ToolContext $context, array $arguments): array
    {
        $contact = $this->contact($context);
        $lead = $contact === null ? null : CrmLead::query()->forAccount((int) $context->account->id)
            ->where('contact_id', $contact->id)->find((int) $arguments['lead_id']);

        if ($lead === null) {
            throw new ToolError('not_found', 'No lead with this id belongs to the customer in this conversation.');
        }

        $previous = (string) $lead->status;

        try {
            $lead = $this->leads->changeStatus($lead, (string) $arguments['status'], $arguments['not_converted_reason'] ?? null);
        } catch (ValidationException $e) {
            throw new ToolError('invalid_transition', mb_substr((string) collect($e->errors())->flatten()->first(), 0, 200));
        }

        return ['updated' => $previous !== (string) $lead->status, 'previous_status' => $previous] + $this->view($lead);
    }
}
