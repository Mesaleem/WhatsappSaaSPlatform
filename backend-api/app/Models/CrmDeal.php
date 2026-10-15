<?php

namespace App\Models;

use App\Services\Crm\CrmTenantLinks;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * CRM Sales Phase 1 — one specific sale (opportunity), separate from the lead it may
 * have come from and from the customer (contact / company) it is for.
 *
 * STATUS follows the stage's kind (open / won / lost) and is written only by
 * CrmDealService. The invariants are enforced here so every writer is checked:
 *   open  won_at NULL, lost_at NULL, lost_reason NULL
 *   won   won_at SET,  lost_at NULL, lost_reason NULL
 *   lost  lost_at SET, won_at NULL  (reason optional)
 * and every link (lead, contact, company, stage) must belong to the same tenant
 * (CrmTenantLinks: one message for "missing" and "not yours").
 *
 * value is decimal(14,2), cast to a string so no float ever touches money.
 */
class CrmDeal extends Model
{
    use LogsActivity;

    protected string $auditModuleName = 'CRM';

    /** @var list<string> */
    protected array $auditIdentity = ['id', 'number'];

    protected $table = 'crm_deals';

    public const STATUS_OPEN = 'open';
    public const STATUS_WON = 'won';
    public const STATUS_LOST = 'lost';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_OPEN, self::STATUS_WON, self::STATUS_LOST];

    protected $fillable = [
        'account_id', 'sequence', 'number', 'name', 'crm_lead_id', 'contact_id', 'crm_company_id', 'stage_id',
        'value', 'currency', 'expected_close_date', 'owner_user_id', 'status', 'source', 'won_at', 'lost_at', 'lost_reason',
    ];

    protected $attributes = ['status' => self::STATUS_OPEN, 'currency' => 'INR', 'value' => '0.00'];

    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'sequence' => 'integer',
            'crm_lead_id' => 'integer',
            'contact_id' => 'integer',
            'crm_company_id' => 'integer',
            'stage_id' => 'integer',
            'owner_user_id' => 'integer',
            'value' => 'decimal:2',
            'expected_close_date' => 'date',
            'won_at' => 'datetime',
            'lost_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // QA fixes (2026-10-14): developer webhook crm.deal.won.
        static::saved(function (CrmDeal $deal): void {
            if ($deal->status === self::STATUS_WON && ($deal->wasRecentlyCreated || $deal->wasChanged('status'))) {
                $payload = [
                    'id' => $deal->id, 'account_id' => $deal->account_id, 'name' => $deal->name,
                    'value' => (string) $deal->value, 'currency' => $deal->currency, 'crm_lead_id' => $deal->crm_lead_id ?? null,
                    'won_at' => $deal->won_at?->toIso8601String(),
                ];
                \Illuminate\Support\Facades\DB::afterCommit(fn () => \App\Services\Webhooks\WebhookDispatcher::fire((int) $deal->account_id, 'crm.deal.won', $payload));

                // QA fixes (2026-10-14): a won deal from an ad-attributed lead gives the ad its
                // conversion value (only when none was entered by hand), so ROAS has a figure.
                $leadId = $deal->crm_lead_id ?? null;
                if ($leadId && (float) $deal->value > 0) {
                    \Illuminate\Support\Facades\DB::afterCommit(function () use ($leadId, $deal): void {
                        $lead = \App\Models\CrmLead::query()->find($leadId);
                        $hasValue = \App\Models\AdAttribution::query()->where('crm_lead_id', $leadId)->whereNotNull('conversion_value')->exists();
                        if ($lead !== null && ! $hasValue) {
                            try {
                                app(\App\Services\Ads\AdAttributionService::class)->recordConversionValue($lead, number_format((float) $deal->value, 2, '.', ''), strtoupper((string) ($deal->currency ?: 'INR')));
                            } catch (\Throwable) {
                                // Not attributed / not converted: nothing to record.
                            }
                        }
                    });
                }
            }
        });

        static::saving(function (CrmDeal $deal): void {
            if ($deal->exists && $deal->isDirty('account_id')) {
                throw ValidationException::withMessages(['account_id' => ['A deal cannot be moved to another account.']]);
            }

            $deal->name = trim((string) $deal->name);
            if ($deal->name === '') {
                throw ValidationException::withMessages(['name' => ['The deal name is required.']]);
            }

            if (! in_array($deal->status, self::STATUSES, true)) {
                throw ValidationException::withMessages(['status' => ['The selected status is invalid.']]);
            }

            if (bccomp((string) $deal->value, '0', 2) < 0) {
                throw ValidationException::withMessages(['value' => ['The deal value cannot be negative.']]);
            }

            $links = [];
            foreach (['crm_lead_id', 'contact_id', 'crm_company_id', 'stage_id'] as $field) {
                if ($deal->isDirty([$field, 'account_id'])) {
                    $links[$field] = $deal->{$field};
                }
            }
            CrmTenantLinks::assertBelong((int) $deal->account_id, $links);

            if ($deal->owner_user_id !== null && $deal->isDirty(['owner_user_id', 'account_id'])
                && ! CrmLead::assigneeIsEligibleById((int) $deal->owner_user_id, (int) $deal->account_id)) {
                throw ValidationException::withMessages(['owner_user_id' => ['The selected owner is not available.']]);
            }

            $deal->assertOutcomeInvariants();
        });

        // Activities that pointed at a deleted deal keep their history, unlinked.
        static::deleted(function (CrmDeal $deal): void {
            CrmActivity::query()->where('account_id', $deal->account_id)->where('crm_deal_id', $deal->id)->update(['crm_deal_id' => null]);
        });
    }

    private function assertOutcomeInvariants(): void
    {
        $won = $this->status === self::STATUS_WON;
        $lost = $this->status === self::STATUS_LOST;

        if ($won !== ($this->won_at !== null)) {
            throw ValidationException::withMessages(['won_at' => ['Only a won deal records when it was won, and it must.']]);
        }

        if ($lost !== ($this->lost_at !== null)) {
            throw ValidationException::withMessages(['lost_at' => ['Only a lost deal records when it was lost, and it must.']]);
        }

        if (! $lost && $this->lost_reason !== null) {
            throw ValidationException::withMessages(['lost_reason' => ['Only a lost deal may record a reason.']]);
        }
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(CrmPipelineStage::class, 'stage_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'crm_lead_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(CrmCompany::class, 'crm_company_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(CrmActivity::class, 'crm_deal_id');
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    /** Own records only for a CRM user without view-all-crm (QA fixes, 2026-10-14). */
    public function scopeVisibleTo(Builder $query, ?\App\Models\User $user): Builder
    {
        return \App\Support\CrmVisibility::apply($query, $user, ['owner_user_id']);
    }
}
