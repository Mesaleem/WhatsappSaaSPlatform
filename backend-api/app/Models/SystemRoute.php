<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use App\Traits\LogsActivity;

/**
 * BUILD: Fully Dynamic Categorized Route Master & Nested Permission
 * Matrix UI — one checkbox row inside a RouteCategory box. See this
 * migration's docblock for why `permission_key` reuses the existing
 * Account::MODULES slug space.
 */
class SystemRoute extends Model
{
    use LogsActivity;

    /** IMPLEMENT: Dynamic Route Master with Super-Admin Bypass & Global Audit Tracking — module label shown in the Activity Logs UI. */
    protected string $auditModuleName = 'Route Master - Routes';
    use HasFactory;

    /**
     * Account::effectiveModules() caches the dynamic active-permission-key
     * list under this key and the booted() hooks below (plus
     * RouteCategory's own) bust it on every save/delete — mirrors
     * Account::findCached()'s cache-and-invalidate-on-save convention
     * exactly, so a Super Admin toggling a route/category's is_active
     * flag is never masked by a stale cache.
     */
    public const ACTIVE_KEYS_CACHE_KEY = 'system_routes.active_permission_keys';

    protected $fillable = [
        'category_id',
        'route_title',
        'route_path',
        'permission_key',
        'is_agent_assignable',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_agent_assignable' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::ACTIVE_KEYS_CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::ACTIVE_KEYS_CACHE_KEY));
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(RouteCategory::class, 'category_id');
    }

    /**
     * Every permission_key whose route AND parent category are both
     * currently active. Account::effectiveModules() intersects
     * allowed_modules against this list, so deactivating a route (or its
     * whole category) here dynamically disables that module platform-wide
     * without touching any account's own allowed_modules column.
     *
     * Returns null (sentinel meaning "no restriction, behave exactly as
     * before this feature") when the table is empty — i.e. before
     * RouteMasterSeeder has run, or in any environment that hasn't
     * migrated this feature yet — so shipping this migration can never
     * itself lock every account out of every module. Same
     * null-means-unrestricted convention Account::MODULES/allowed_modules
     * already use.
     *
     * @return list<string>|null
     */
    public static function activePermissionKeys(): ?array
    {
        return Cache::remember(self::ACTIVE_KEYS_CACHE_KEY, 3600, function () {
            if (self::query()->doesntExist()) {
                return null;
            }

            return self::query()
                ->where('is_active', true)
                ->whereHas('category', fn ($q) => $q->where('is_active', true))
                ->pluck('permission_key')
                ->unique()
                ->values()
                ->all();
        });
    }
}
