<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Plan extends Model
{
    use LogsActivity;

    /** A plan edit shows on the public pricing page at once (see PublicSiteController's cache). */
    protected static function booted(): void
    {
        static::saved(fn () => \App\Http\Controllers\Api\PublicSiteController::forgetCache());
        static::deleted(fn () => \App\Http\Controllers\Api\PublicSiteController::forgetCache());
    }

    protected $fillable = [
        'slug',
        'label',
        'price',
        'duration_days',
        'description',
        // Phase 5 Task 11 — the billing dimensions checkout used to read
        // off PlanCatalog. The `plans` table is now the runtime source.
        'engine_type',
        'billing_model',
        'rate_per_message',
        'total_allocated_messages',
        // Phase 8 Task 2 — AI credits allocated per purchased period (0 = none).
        'included_credits',
        'is_active',
        // Plan restructure (2026-10-15) — per-plan limits, NULL = unlimited. See LIMITS.
        'max_users',
        'max_meta_templates',
        'max_flows',
        'max_broadcasts_per_month',
        'marketing_message_limit',
        // false = not listed on the website / Billing page; account_id = a custom plan for one client.
        'is_public',
        'account_id',
    ];

    /**
     * The hidden website-signup trial plan (QA fixes, 2026-10-14): never sold,
     * never listed; signup grants it so a trial's menu matches what works.
     */
    public const TRIAL_SLUG = 'trial';

    /**
     * The plan limits (column => what it caps). Captured on the invoice at
     * order time (Invoice::capturePlanTerms) and copied to the subscription
     * at payment, where PlanLimits enforces them. NULL = unlimited.
     */
    public const LIMITS = [
        'max_users' => 'Team users',
        'max_meta_templates' => 'Meta message templates',
        'max_flows' => 'Journeys / WhatsApp Flows',
        'max_broadcasts_per_month' => 'Broadcasts per month',
        'marketing_message_limit' => 'Marketing messages per month',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'duration_days' => 'integer',
            'rate_per_message' => 'decimal:4',
            'total_allocated_messages' => 'integer',
            'included_credits' => 'integer',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'account_id' => 'integer',
            'max_users' => 'integer',
            'max_meta_templates' => 'integer',
            'max_flows' => 'integer',
            'max_broadcasts_per_month' => 'integer',
            'marketing_message_limit' => 'integer',
        ];
    }

    /** Purchasable right now. See PlanRepository for the single definition. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function account(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** A custom plan built for one client (null for the public line-up). */
    public function isCustom(): bool
    {
        return $this->account_id !== null;
    }

    /** @return array<string, ?int> column => limit */
    public function limits(): array
    {
        return collect(array_keys(self::LIMITS))->mapWithKeys(fn (string $c) => [$c => $this->{$c}])->all();
    }

    public function capabilities(): BelongsToMany
    {
        return $this->belongsToMany(Capability::class, 'plan_entitlements')
            ->withPivot('usage_limit')
            ->withTimestamps();
    }
}
