<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Provider extends Model
{
    use LogsActivity;

    protected $fillable = ['slug', 'label', 'driver_class'];

    public function capabilities(): BelongsToMany
    {
        return $this->belongsToMany(Capability::class, 'provider_capabilities')
            ->withPivot(['supported', 'reason'])
            ->withTimestamps();
    }

    public function providerCapabilities(): HasMany
    {
        return $this->hasMany(ProviderCapability::class);
    }
}
