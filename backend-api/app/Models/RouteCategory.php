<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use App\Traits\LogsActivity;

/**
 * BUILD: Fully Dynamic Categorized Route Master & Nested Permission
 * Matrix UI — one styled category box (e.g. "WhatsApp Messaging Suite")
 * in the dynamic "Module & Feature Access" UI, replacing the hardcoded
 * WHATSAPP_SUITE_MODULES-style constant arrays.
 */
class RouteCategory extends Model
{
    use LogsActivity;

    /** IMPLEMENT: Dynamic Route Master with Super-Admin Bypass & Global Audit Tracking — module label shown in the Activity Logs UI. */
    protected string $auditModuleName = 'Route Master - Categories';
    use HasFactory;

    protected $fillable = [
        'category_name',
        'category_code',
        'icon_name',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Deactivating a whole category must dynamically disable every
     * permission_key under it (SystemRoute::activePermissionKeys() joins
     * against this table's is_active too), so this table's own
     * save/delete also busts that cache — not just SystemRoute's.
     */
    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(SystemRoute::ACTIVE_KEYS_CACHE_KEY));
        static::deleted(fn () => Cache::forget(SystemRoute::ACTIVE_KEYS_CACHE_KEY));
    }

    public function routes(): HasMany
    {
        return $this->hasMany(SystemRoute::class, 'category_id');
    }
}
