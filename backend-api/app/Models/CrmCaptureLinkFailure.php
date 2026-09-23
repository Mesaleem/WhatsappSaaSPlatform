<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 6 — CRM Hardening Round 2, Limitation 5. One row per capture
 * submission that could not be promoted into the CRM.
 *
 * This is an OPERATIONAL record, not a copy of the lead. It holds the
 * identifiers needed to find the original and the reason promotion
 * failed; the capture row in `leads` still holds all the data, so a
 * retry reads from there.
 *
 * Deliberately never holds a provider access token, a webhook payload,
 * or a customer's contact details.
 */
class CrmCaptureLinkFailure extends Model
{
    use LogsActivity;

    /** Dynamic Route Master / Global Audit Tracking — module label shown in the Activity Logs UI. */
    protected string $auditModuleName = 'CRM';

    /** The capture row carried no phone number that normalizes to digits. */
    public const REASON_UNRESOLVABLE_PHONE = 'unresolvable_phone';

    /** Something threw: a validation guard, a constraint, a connection. */
    public const REASON_EXCEPTION = 'exception';

    /**
     * Phase 6 Task 10. The account is not entitled to the CRM (no active
     * `crm` capability, or the `lead_crm` module is off) at capture time.
     * Recorded rather than silently dropped so CaptureLeadLinker::retry()
     * can promote the capture once the account is entitled.
     */
    public const REASON_NOT_ENTITLED = 'not_entitled';

    /** @var list<string> */
    public const REASONS = [
        self::REASON_UNRESOLVABLE_PHONE,
        self::REASON_EXCEPTION,
        self::REASON_NOT_ENTITLED,
    ];

    protected $fillable = [
        'account_id',
        'lead_id',
        'provider',
        'provider_lead_id',
        'reason',
        'message',
        'attempts',
        'resolved_at',
        'resolved_crm_lead_id',
    ];

    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'lead_id' => 'integer',
            'attempts' => 'integer',
            'resolved_at' => 'datetime',
            'resolved_crm_lead_id' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function resolvedCrmLead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'resolved_crm_lead_id');
    }

    /** The operator's working set: failures nobody has fixed yet. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }
}
