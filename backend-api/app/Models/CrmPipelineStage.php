<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * CRM Sales Phase 1 — one column of a tenant's deal pipeline. `kind` says what a deal
 * in it means: open (still being worked), won or lost. CrmDealService keeps a deal's
 * status in step with its stage's kind. The default set is created lazily per tenant
 * by CrmPipelineStageService::ensureDefaults().
 */
class CrmPipelineStage extends Model
{
    use LogsActivity;

    protected string $auditModuleName = 'CRM';

    protected $table = 'crm_pipeline_stages';

    public const KIND_OPEN = 'open';
    public const KIND_WON = 'won';
    public const KIND_LOST = 'lost';

    /** @var list<string> */
    public const KINDS = [self::KIND_OPEN, self::KIND_WON, self::KIND_LOST];

    /**
     * The set a tenant starts with: name, probability %, kind.
     *
     * @var list<array{0: string, 1: int, 2: string}>
     */
    public const DEFAULTS = [
        ['Qualification', 10, self::KIND_OPEN],
        ['Needs Analysis', 30, self::KIND_OPEN],
        ['Proposal', 50, self::KIND_OPEN],
        ['Negotiation', 75, self::KIND_OPEN],
        ['Won', 100, self::KIND_WON],
        ['Lost', 0, self::KIND_LOST],
    ];

    protected $fillable = ['account_id', 'name', 'position', 'probability', 'kind'];

    protected $attributes = ['kind' => self::KIND_OPEN, 'probability' => 0, 'position' => 0];

    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'position' => 'integer',
            'probability' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (CrmPipelineStage $stage): void {
            if ($stage->exists && $stage->isDirty('account_id')) {
                throw ValidationException::withMessages(['account_id' => ['A stage cannot be moved to another account.']]);
            }

            $stage->name = trim((string) $stage->name);
            if ($stage->name === '') {
                throw ValidationException::withMessages(['name' => ['The stage name is required.']]);
            }

            if (! in_array($stage->kind, self::KINDS, true)) {
                throw ValidationException::withMessages(['kind' => ['The selected kind is invalid.']]);
            }

            if ($stage->probability < 0 || $stage->probability > 100) {
                throw ValidationException::withMessages(['probability' => ['The probability must be between 0 and 100.']]);
            }
        });
    }

    public function deals(): HasMany
    {
        return $this->hasMany(CrmDeal::class, 'stage_id');
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }
}
