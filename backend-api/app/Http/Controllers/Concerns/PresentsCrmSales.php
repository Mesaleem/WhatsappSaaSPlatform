<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Contact;
use App\Models\CrmActivity;
use App\Models\CrmCompany;
use App\Models\CrmDeal;
use App\Models\CrmPipelineStage;
use App\Models\User;

/**
 * CRM Sales Phase 1 — the JSON shapes of companies, stages, deals and activities, in
 * one place so every endpoint returns the same fields. Money is a string ("1500.00"),
 * never a float.
 */
trait PresentsCrmSales
{
    protected function presentCompany(CrmCompany $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'industry' => $company->industry,
            'website' => $company->website,
            'gstin' => $company->gstin,
            'phone' => $company->phone,
            'email' => $company->email,
            'address_line1' => $company->address_line1,
            'address_line2' => $company->address_line2,
            'city' => $company->city,
            'state' => $company->state,
            'pincode' => $company->pincode,
            'country' => $company->country,
            'type' => $company->type,
            'status' => $company->status,
            'owner' => $this->presentUser($company->relationLoaded('owner') ? $company->owner : null),
            'owner_user_id' => $company->owner_user_id,
            'contacts_count' => $company->contacts_count ?? null,
            'deals_count' => $company->deals_count ?? null,
            'created_at' => $company->created_at?->toIso8601String(),
            'updated_at' => $company->updated_at?->toIso8601String(),
        ];
    }

    protected function presentStage(CrmPipelineStage $stage): array
    {
        return [
            'id' => $stage->id,
            'name' => $stage->name,
            'position' => $stage->position,
            'probability' => $stage->probability,
            'kind' => $stage->kind,
        ];
    }

    protected function presentDeal(CrmDeal $deal): array
    {
        return [
            'id' => $deal->id,
            'number' => $deal->number,
            'name' => $deal->name,
            'value' => (string) $deal->value,
            'currency' => $deal->currency,
            'status' => $deal->status,
            'stage_id' => $deal->stage_id,
            'stage' => $deal->relationLoaded('stage') && $deal->stage ? $this->presentStage($deal->stage) : null,
            'expected_close_date' => $deal->expected_close_date?->toDateString(),
            'source' => $deal->source,
            'won_at' => $deal->won_at?->toIso8601String(),
            'lost_at' => $deal->lost_at?->toIso8601String(),
            'lost_reason' => $deal->lost_reason,
            'crm_lead_id' => $deal->crm_lead_id,
            'contact' => $this->presentContactRef($deal->relationLoaded('contact') ? $deal->contact : null),
            'company' => $deal->relationLoaded('company') && $deal->company ? ['id' => $deal->company->id, 'name' => $deal->company->name] : null,
            'owner' => $this->presentUser($deal->relationLoaded('owner') ? $deal->owner : null),
            'owner_user_id' => $deal->owner_user_id,
            'created_at' => $deal->created_at?->toIso8601String(),
            'updated_at' => $deal->updated_at?->toIso8601String(),
        ];
    }

    protected function presentActivity(CrmActivity $activity): array
    {
        return [
            'id' => $activity->id,
            'type' => $activity->type,
            'subject' => $activity->subject,
            'body' => $activity->body,
            'status' => $activity->status,
            'priority' => $activity->priority,
            'outcome' => $activity->outcome,
            'due_at' => $activity->due_at?->toIso8601String(),
            'completed_at' => $activity->completed_at?->toIso8601String(),
            'is_overdue' => $activity->status === CrmActivity::STATUS_PENDING && $activity->due_at !== null && $activity->due_at->isPast(),
            'crm_lead_id' => $activity->crm_lead_id,
            'contact_id' => $activity->contact_id,
            'crm_company_id' => $activity->crm_company_id,
            'crm_deal_id' => $activity->crm_deal_id,
            'contact' => $this->presentContactRef($activity->relationLoaded('contact') ? $activity->contact : null),
            'company' => $activity->relationLoaded('company') && $activity->company ? ['id' => $activity->company->id, 'name' => $activity->company->name] : null,
            'deal' => $activity->relationLoaded('deal') && $activity->deal ? ['id' => $activity->deal->id, 'number' => $activity->deal->number, 'name' => $activity->deal->name] : null,
            'assigned_user' => $this->presentUser($activity->relationLoaded('assignedUser') ? $activity->assignedUser : null),
            'assigned_user_id' => $activity->assigned_user_id,
            'created_by' => $this->presentUser($activity->relationLoaded('createdBy') ? $activity->createdBy : null),
            'created_at' => $activity->created_at?->toIso8601String(),
        ];
    }

    /** @return list<string> */
    protected function dealRelations(): array
    {
        return ['stage', 'contact', 'company', 'owner'];
    }

    /** @return list<string> */
    protected function activityRelations(): array
    {
        return ['contact', 'company', 'deal', 'assignedUser', 'createdBy'];
    }

    private function presentUser(?User $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name] : null;
    }

    private function presentContactRef(?Contact $contact): ?array
    {
        return $contact ? ['id' => $contact->id, 'name' => $contact->name, 'phone_number' => $contact->phone_number] : null;
    }
}
