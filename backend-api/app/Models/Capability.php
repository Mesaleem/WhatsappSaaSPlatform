<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Capability extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = ['slug', 'label', 'description', 'category'];

    public function providers(): BelongsToMany
    {
        return $this->belongsToMany(Provider::class, 'provider_capabilities')
            ->withPivot(['supported', 'reason'])
            ->withTimestamps();
    }

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'plan_entitlements')
            ->withPivot('usage_limit')
            ->withTimestamps();
    }
}
