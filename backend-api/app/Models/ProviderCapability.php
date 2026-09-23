<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class ProviderCapability extends Model
{
    use LogsActivity;

    protected $fillable = ['provider_id', 'capability_id', 'supported', 'reason'];

    protected function casts(): array
    {
        return ['supported' => 'boolean'];
    }

    /**
     * Invalidates ProviderCapabilityService's cache on write, mirroring
     * Account::booted()'s own cache-invalidation-on-save pattern.
     */
    protected static function booted(): void
    {
        $forget = function (ProviderCapability $row) {
            $providerSlug = $row->provider?->slug ?? Provider::find($row->provider_id)?->slug;
            $capabilitySlug = $row->capability?->slug ?? Capability::find($row->capability_id)?->slug;

            if ($providerSlug && $capabilitySlug) {
                Cache::forget("provider_capability:{$providerSlug}:{$capabilitySlug}");
            }
        };

        static::saved($forget);
        static::deleted($forget);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function capability(): BelongsTo
    {
        return $this->belongsTo(Capability::class);
    }
}
