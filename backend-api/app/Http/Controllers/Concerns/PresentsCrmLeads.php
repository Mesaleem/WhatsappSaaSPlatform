<?php

namespace App\Http\Controllers\Concerns;

use App\Models\CrmLead;

/**
 * Phase 6 — CRM Task 3. One serialization of a CRM lead, shared by every
 * endpoint that returns one.
 *
 * Extracted rather than duplicated because Task 3 adds a second
 * controller that returns CRM leads (CrmContactController::leads()), and
 * two copies of a presenter drift: the moment one of them starts
 * exposing a field the other does not, a frontend written against one
 * endpoint breaks against the other.
 *
 * THE CONTACT BLOCK IS DELIBERATELY LIGHTWEIGHT — id, name, normalized
 * phone, email. That is what the brief asks a lead detail to carry, and
 * it is what a UI needs to render "who is this lead about" without a
 * second request. It is NOT the whole Contact object: no timestamps, no
 * lead counts, no account_id. account_id in particular is always the
 * caller's own, so printing it adds nothing and only widens what a
 * future bug could leak.
 *
 * THE THREE RECORDS STAY SEPARATE, and this shape is where that is most
 * visible:
 *   Contact      the tenant's current CRM identity for a person
 *   CrmLead      one opportunity's lifecycle — status, source, owner
 *   capture Lead the original, immutable provider submission
 * A lead carries no name or email of its own; it points at the Contact
 * that does. That is why editing a Contact can never rewrite lead data,
 * and why reassigning a lead to another Contact changes exactly one
 * column.
 */
trait PresentsCrmLeads
{
    /**
     * @return array<string, mixed>
     */
    protected function presentCrmLead(CrmLead $lead): array
    {
        return [
            'id' => $lead->id,
            'status' => $lead->status,
            'source' => $lead->source,
            'assigned_user_id' => $lead->assigned_user_id,
            'assigned_user' => $lead->assignedUser ? [
                'id' => $lead->assignedUser->id,
                'name' => $lead->assignedUser->name,
            ] : null,
            'contact' => $lead->contact ? [
                'id' => $lead->contact->id,
                'name' => $lead->contact->name,
                'phone_number' => $lead->contact->phone_number,
                'email' => $lead->contact->email,
            ] : null,
            /*
             * The capture origin, when this lead was promoted from one.
             * Identifiers only — provider, the provider's own id, and
             * when it arrived. Deliberately NOT raw_field_data: that is
             * the provider's verbatim payload and has no business being
             * re-served through the CRM.
             */
            'capture_lead' => $lead->capture_lead_id === null ? null : [
                'id' => $lead->capture_lead_id,
                'provider' => $lead->captureLead?->provider,
                'provider_lead_id' => $lead->captureLead?->provider_lead_id,
                'captured_at' => $lead->captureLead?->created_at?->toIso8601String(),
            ],
            /*
             * Task 7 — the lead's tags, id + name only, ordered by name.
             * Metadata independent of `status`. Read from the eager-loaded
             * relation (crmLeadRelations() below), so a page of leads
             * costs one tag query, not one per lead.
             */
            'tags' => $lead->tags
                ->map(fn ($tag): array => ['id' => $tag->id, 'name' => $tag->name])
                ->values()
                ->all(),
            'converted_at' => $lead->converted_at?->toIso8601String(),
            'not_converted_at' => $lead->not_converted_at?->toIso8601String(),
            'not_converted_reason' => $lead->not_converted_reason,
            'created_at' => $lead->created_at?->toIso8601String(),
            'updated_at' => $lead->updated_at?->toIso8601String(),
        ];
    }

    /** The relations presentCrmLead() reads, for eager loading. */
    protected function crmLeadRelations(): array
    {
        return ['contact', 'assignedUser', 'captureLead', 'tags'];
    }
}
