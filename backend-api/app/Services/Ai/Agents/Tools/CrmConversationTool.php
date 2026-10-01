<?php

namespace App\Services\Ai\Agents\Tools;

use App\Models\Account;
use App\Models\Contact;
use App\Models\CrmLead;

/**
 * Phase 8 Task 11 — shared base of the CRM tools. Every CRM tool acts ONLY
 * on the CURRENT conversation's customer (the Journey session's own phone
 * number, resolved inside the session's own account) — never on a phone
 * number, contact or lead the model names freely. A prompt-injected "look up
 * / change customer X" therefore cannot reach any other customer, let alone
 * another account.
 *
 * Authorization: `manage-crm` (checked against the user who granted the
 * tool, and against the acting user when there is one), the `lead_crm`
 * module and the `crm` capability (ToolExecutor), plus — here — the same
 * account/subscription rule every CRM write meets (P6-2: active account +
 * active subscription, read fresh).
 */
abstract class CrmConversationTool implements AgentTool
{
    public function permission(): ?string
    {
        return 'manage-crm';
    }

    public function module(): ?string
    {
        return 'lead_crm';
    }

    public function capability(): ?string
    {
        return 'crm';
    }

    public function authorize(ToolContext $context): ?string
    {
        if ($context->contactPhone() === null) {
            return 'no_conversation';
        }

        $fresh = Account::query()->with('currentSubscription')->find((int) $context->account->id);

        return $fresh !== null && $fresh->hasActiveSubscription() ? null : 'crm_unavailable';
    }

    /** The conversation's contact inside the context account, or null (never created here). */
    protected function contact(ToolContext $context): ?Contact
    {
        return Contact::query()->forAccount((int) $context->account->id)->where('phone_number', $context->contactPhone())->first();
    }

    /** The contact's most recent open (new / contacted) lead, or null. */
    protected function openLead(ToolContext $context, Contact $contact): ?CrmLead
    {
        return CrmLead::query()->forAccount((int) $context->account->id)->where('contact_id', $contact->id)
            ->whereIn('status', [CrmLead::STATUS_NEW, CrmLead::STATUS_CONTACTED])
            ->orderByDesc('id')->first();
    }

    /** @return array<string, mixed> the minimal lead view given to the model */
    protected function view(CrmLead $lead): array
    {
        return [
            'lead_id' => (int) $lead->id,
            'status' => (string) $lead->status,
            'source' => (string) $lead->source,
            'created_at' => $lead->created_at?->toIso8601String(),
        ];
    }
}
