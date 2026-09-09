<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Cache;

class Account extends Model
{
    use HasFactory;

    /**
     * Runtime Query Caching (Performance & Database Optimization audit)
     * — findCached() is used everywhere this app resolves "the tenant
     * account for this request" (ResolvesTenantAccount::resolveAccount(),
     * called on nearly every tenant-scoped controller action), which was
     * previously a fresh Account::findOrFail() on every single request.
     * 60-minute TTL matches the spec; the booted() hooks below flush the
     * entry the moment the row actually changes (save OR delete) so a
     * status/allowed_modules update is never masked by a stale cache —
     * this is deliberately a model-level cache-and-invalidate pair
     * rather than scattered manual Cache::forget() calls in every
     * controller that can mutate an Account, so a future update path
     * can't forget to invalidate it.
     */
    protected static function booted(): void
    {
        static::saved(fn (Account $account) => Cache::forget(self::cacheKey($account->id)));
        static::deleted(fn (Account $account) => Cache::forget(self::cacheKey($account->id)));

        // Super Admin WhatsApp Device Integration — the one reserved
        // is_platform_device row (see platformDevice() below) is not a
        // real client: excluded from every ordinary query (client lists,
        // analytics counts, the device-overview table, billing's account
        // dropdown, tenant resolution via findCached()/find()) by
        // default, so no controller has to remember to filter it out by
        // hand. Only platformDevice() reaches it, via
        // withoutGlobalScope().
        static::addGlobalScope('exclude_platform_device', function (Builder $query) {
            $query->where('is_platform_device', false);
        });
    }

    /**
     * Super Admin WhatsApp Device Integration — the Super Admin's own
     * WhatsApp test connection, used by MessageTemplateController::test()
     * to fire a real WhatsApp send for ANY approved-or-pending template
     * regardless of which client it belongs to (or whether it belongs to
     * one at all). Lazily created on first use — no seeder, no manual
     * provisioning step, same "get or create a singleton row" pattern as
     * MailSetting::currentCached() elsewhere in this codebase — rather
     * than requiring you to run a seeder for a single reserved row.
     */
    public static function platformDevice(): self
    {
        return static::withoutGlobalScope('exclude_platform_device')
            ->firstOrCreate(
                ['is_platform_device' => true],
                ['company_name' => 'Super Admin — WhatsApp Test Device', 'status' => 'active'],
            );
    }

    public static function cacheKey(int $id): string
    {
        return "account:{$id}";
    }

    /** Cached Account::find($id) — returns null (and caches the miss briefly) exactly like find() would. */
    public static function findCached(int $id): ?self
    {
        return Cache::remember(self::cacheKey($id), 3600, fn () => self::find($id));
    }

    /**
     * Absolute Super Admin Control — canonical catalog of feature/module
     * slugs a Super Admin can start/stop per client via
     * AccountController::updatePermissions(). Mirrors the permission-gated
     * items in frontend-app's AppLayout NAV_ITEMS; keep both lists in sync
     * by hand (there is no shared source of truth across the two apps).
     * Only 'team_management' is actually enforced server-side today (see
     * TeamController::store()) — the rest are stored/toggleable and hidden
     * client-side when disabled, per the scope of this feature's spec.
     *
     * @var list<string>
     */
    public const MODULES = [
        'dashboard',
        'whatsapp_setup',
        'send_alert',
        'analytics',
        'chatbot',
        'billing',
        'developer_api',
        'team_management',
    ];

    protected $fillable = [
        'company_name',
        'primary_phone',
        'status',
        // Module 9 — requests/minute allowed on the external Developer API.
        'api_rate_limit_per_minute',
        // Absolute Super Admin Control — per-client module toggles.
        'allowed_modules',
        // Super Admin WhatsApp Device Integration — see platformDevice().
        'is_platform_device',
    ];

    protected function casts(): array
    {
        return [
            'allowed_modules' => 'array',
            'is_platform_device' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * The account's primary Admin user (created alongside the account in
     * AccountController::store). Oldest Admin wins if more than one exists.
     */
    public function owner(): HasOne
    {
        return $this->hasOne(User::class)
            ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
            ->oldest();
    }

    /**
     * Full subscription history for this account. AccountController::updateSubscription
     * intentionally extends the *current* row in place (matches "extend validity"
     * in the spec) rather than inserting a new row on every change; a new row is
     * only created by AccountController::store.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * The most recently started subscription row. Determined by max(starts_at)
     * rather than a mutable is_current flag, so there is no risk of two rows
     * both being "current" at once.
     */
    public function currentSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->ofMany('starts_at', 'max');
    }

    /**
     * Module 4 — this account's Baileys QR-engine connection record
     * (status kept in sync by qr-engine-service's internal webhook).
     */
    public function whatsAppSession(): HasOne
    {
        return $this->hasOne(WhatsAppSession::class);
    }

    /**
     * Account-level admin gate: Super Admin can suspend an account outright,
     * independent of whether its subscription/billing is otherwise fine.
     */
    public function isAdministrativelyActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Used by SubscriptionGuardMiddleware. True only if the account itself
     * isn't suspended/expired AND its current subscription is 'active'.
     */
    public function hasActiveSubscription(): bool
    {
        if (! $this->isAdministrativelyActive()) {
            return false;
        }

        $subscription = $this->currentSubscription;

        return $subscription !== null && $subscription->isActive();
    }

    /**
     * Absolute Super Admin Control — whether this client currently has the
     * given module/feature enabled. NULL allowed_modules (the default) is
     * treated as "every module enabled" so existing accounts see zero
     * regression until a Super Admin explicitly narrows them.
     */
    public function hasModuleEnabled(string $module): bool
    {
        if ($this->allowed_modules === null) {
            return true;
        }

        return in_array($module, $this->allowed_modules, true);
    }
}
