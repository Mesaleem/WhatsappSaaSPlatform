<?php

namespace App\Models;

use App\Support\PhoneNumberNormalizer;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Phase 6 — CRM, Task 1. A person, owned by exactly one tenant.
 *
 * This is the platform's first Contact entity — see the creating
 * migration for why neither contact_group_members (a phone number inside
 * one broadcast list: no account_id, cascade-deleted with its group,
 * duplicated per list, gated behind the paid contact_groups addon) nor
 * leads (an immutable Meta/journey capture event with a globally unique
 * provider_lead_id) could serve as one.
 *
 * A Contact is NOT a Lead. A Contact is who someone is; a CrmLead is one
 * business opportunity involving them. One contact can carry several
 * leads over time, which is exactly why the two are separate.
 *
 * Nothing outside the CRM reads or writes this model. Existing contact
 * groups, broadcast dispatch, QR/Baileys, Meta and the Journey engine are
 * untouched by its introduction.
 */
class Contact extends Model
{
    use HasFactory;
    use LogsActivity;

    /** Dynamic Route Master / Global Audit Tracking — module label shown in the Activity Logs UI. */
    protected string $auditModuleName = 'CRM';

    protected $fillable = [
        'account_id',
        'phone_number',
        'name',
        // Phase 6 Hardening (Issue 3) — nullable, not unique, stored as
        // supplied. See the adding migration for why.
        'email',
    ];

    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
        ];
    }

    /**
     * Normalize on the way in, always. unique(account_id, phone_number)
     * is the rule that makes this a person table rather than another
     * capture log, and that index is only as good as the string written
     * into it: "+91 98765 43210", "09876543210" and "9876543210" are the
     * same person and must collide. PhoneNumberNormalizer is the
     * established normalizer for exactly this — ContactGroupController::
     * addContacts() applies it before its own (group_id, phone_number)
     * upsert, and MetaLeadWebhookHandler applies it before its duplicate
     * check — so contacts land in the same digit form every messaging
     * path in this codebase already uses.
     */
    protected static function booted(): void
    {
        static::saving(function (Contact $contact): void {
            if ($contact->isDirty('phone_number') && $contact->phone_number !== null) {
                $contact->phone_number = PhoneNumberNormalizer::normalize((string) $contact->phone_number);
            }
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** Account -> Contacts -> CRM Leads: every opportunity recorded against this person. */
    public function crmLeads(): HasMany
    {
        return $this->hasMany(CrmLead::class);
    }

    /**
     * Phase 6 Hardening (Issue 8) — every broadcast-list membership row
     * that has been reconciled to this person. Not the inverse of a
     * required relationship: contact_group_members.contact_id is
     * nullable, and a membership works as a broadcast recipient with or
     * without it.
     */
    public function groupMemberships(): HasMany
    {
        return $this->hasMany(ContactGroupMember::class);
    }

    /**
     * The capture rows (Meta Lead Ads, Journey, click-to-WhatsApp) that
     * produced this person's CRM leads.
     *
     * SEMANTICS, pinned deliberately (Round 2, Limitation 7): this means
     * LINKED captures, not "every capture row that happens to share this
     * phone number". The product model is
     *     capture lead -> crm_lead.capture_lead_id -> crm lead -> contact
     * and this relationship walks exactly that path, so a capture that
     * was never promoted (no usable phone, or a promotion that failed —
     * see CrmCaptureLinkFailure) does NOT appear here. Widening it to
     * phone-matching would silently change what callers get and would
     * surface rows the CRM has deliberately not adopted. Tested in
     * CrmHardeningRound2Test.
     *
     * Reached THROUGH crm_leads rather than by a second column on
     * `leads`, so there is only one link to keep correct — and since
     * Round 2 that single link carries a composite, tenant-proving
     * foreign key, which is why another account's capture can never
     * appear here.
     */
    public function captureLeads(): HasManyThrough
    {
        return $this->hasManyThrough(
            Lead::class,
            CrmLead::class,
            'contact_id',        // crm_leads.contact_id -> contacts.id
            'id',                // leads.id
            'id',                // contacts.id
            'capture_lead_id',   // crm_leads.capture_lead_id -> leads.id
        );
    }

    /** Mirrors Lead::scopeForAccount() — the tenant-scoping idiom already used in this codebase. */
    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    /**
     * Phase 6 Hardening (Issue 10) — does anything depend on this
     * contact? Deletion is refused while it does, rather than cascading
     * through a customer's CRM history, their broadcast list membership
     * and their original capture records.
     *
     * @return array<string, int> non-zero dependency counts, keyed by kind
     */
    public function blockingDependencies(): array
    {
        return array_filter([
            'crm_leads' => $this->crmLeads()->count(),
            'group_memberships' => $this->groupMemberships()->count(),
        ]);
    }
}
