<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\LogsActivity;

/**
 * Group Messaging — Step 1. A single phone number inside one
 * ContactGroup. No account_id here by design — see this table's
 * creating migration for why; tenant scoping goes through
 * group()->account_id.
 */
class ContactGroupMember extends Model
{
    use LogsActivity;

    /** IMPLEMENT: Dynamic Route Master with Super-Admin Bypass & Global Audit Tracking — module label shown in the Activity Logs UI. */
    protected string $auditModuleName = 'Contact Groups';
    protected $fillable = [
        'group_id',
        // Round 2, Limitation 2 — denormalized from the owning group so
        // the two composite, tenant-proving foreign keys below can exist
        // at all. NOT NULL; the (group_id, account_id) key makes it
        // impossible for it to drift from the group that owns the row.
        'account_id',
        // Phase 6 Hardening (Issue 8) — the universal CRM Contact this
        // membership row refers to, once reconciled. Nullable and
        // purely additive: phone_number below remains the column every
        // dispatch path actually sends to, so broadcast behaviour is
        // identical whether this is populated or not.
        'contact_id',
        'phone_number',
        'name',
    ];

    protected function casts(): array
    {
        return [
            'group_id' => 'integer',
            'account_id' => 'integer',
            'contact_id' => 'integer',
        ];
    }

    /**
     * Round 2 — keep the denormalized account_id correct without making
     * every caller remember it.
     *
     * account_id became NOT NULL so the two composite, tenant-proving
     * foreign keys could exist. Rather than push that requirement out to
     * every place a membership is built — which would mean editing call
     * sites and fixtures that have nothing to do with the CRM — the
     * value is derived here from the row's own group whenever it is
     * missing. One query, and only when it is actually absent.
     *
     * The three BULK writers (ContactGroupController::addContacts,
     * NativeGroupCreationService::create/importExisting) use
     * ContactGroupMember::upsert(), which bypasses Eloquent events, so
     * they set account_id explicitly. This hook covers every ordinary
     * model write, including existing tests and any future caller.
     *
     * It can only ever produce the group's OWN account: the composite
     * (group_id, account_id) foreign key would refuse anything else.
     */
    protected static function booted(): void
    {
        static::saving(function (ContactGroupMember $member): void {
            if ($member->account_id === null && $member->group_id !== null) {
                $member->account_id = ContactGroup::whereKey($member->group_id)->value('account_id');
            }
        });
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ContactGroup::class, 'group_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** Phase 6 Hardening (Issue 8) — null until this membership has been reconciled to a CRM Contact. */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
