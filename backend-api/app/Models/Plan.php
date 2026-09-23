<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Plan extends Model
{
    use LogsActivity;

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
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'duration_days' => 'integer',
            'rate_per_message' => 'decimal:4',
            'total_allocated_messages' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** Purchasable right now. See PlanRepository for the single definition. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function capabilities(): BelongsToMany
    {
        return $this->belongsToMany(Capability::class, 'plan_entitlements')
            ->withPivot('usage_limit')
            ->withTimestamps();
    }
}
