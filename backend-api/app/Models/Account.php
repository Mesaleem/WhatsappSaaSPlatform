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
     *
     * Also lazily provisions a never-expiring 'qr'-engine, 'unlimited'
     * subscription the first time this account is created — both
     * WhatsAppEngineFactory::make() and PaymentAlertDispatcher::
     * isWhatsAppDisconnected() key off currentSubscription->engine_type,
     * and this row has no real plan/billing behind it (price_paid stays
     * 0, it is never shown on any billing screen, and test() never
     * deducts from used_messages — a test-fire must never consume a real
     * quota). 'qr' specifically, not 'meta' — the "WhatsApp scan option"
     * this exists for is a Baileys QR pairing, the same flow
     * QRScannerModal already drives for every tenant.
     */
    public static function platformDevice(): self
    {
        $account = static::withoutGlobalScope('exclude_platform_device')
            ->firstOrCreate(
                ['is_platform_device' => true],
                ['company_name' => 'Super Admin — WhatsApp Test Device', 'status' => 'active'],
            );

        if ($account->subscriptions()->doesntExist()) {
            $account->subscriptions()->create([
                'engine_type' => 'qr',
                'billing_model' => 'unlimited',
                'rate_per_message' => null,
                'total_allocated_messages' => null,
                'used_messages' => 0,
                'price_paid' => 0,
                'payment_mode' => 'cash',
                'starts_at' => now(),
                'expires_at' => null,
                'status' => 'active',
            ]);
        }

        return $account;
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
    /**
     * Super Admin Client Provisioning refactor — the client's coarse
     * business-category, distinct from the granular per-feature
     * `allowed_modules` checklist above: this drives which WIDGET SET
     * DashboardPage.tsx renders (WhatsApp-only / Social-only / hybrid
     * "both"), while `allowed_modules` continues to drive the sidebar's
     * per-feature checklist. Deliberately two separate fields rather
     * than deriving one from the other — conflating "which dashboard
     * layout" with "which of 15 individual features" would make either
     * concept impossible to reason about on its own. See this
     * refactor's audit report.
     *
     * @var list<string>
     */
    public const MODULE_ASSIGNMENTS = ['whatsapp_messaging', 'social_media', 'both'];

    public const MODULES = [
        'dashboard',
        'whatsapp_setup',
        'send_alert',
        'analytics',
        'chatbot',
        'billing',
        'developer_api',
        'team_management',
        'notifications',
        // Client Management, User Creation, Multi-Role Permissions &
        // Feature Module Checklists refactor — six new checklist rows.
        // The spec's 8-item checklist also lists "WhatsApp Chatbot &
        // Rules" and "Team Users": deliberately NOT new slugs here —
        // "Team Users" already exists above as 'team_management'
        // (enforced both client- and server-side already), and
        // "WhatsApp Chatbot & Rules" is rendered in the checklist UI as
        // one combined checkbox over the two EXISTING 'whatsapp_setup'
        // + 'chatbot' slugs rather than a new, parallel, unenforced
        // slug that would duplicate what already works. See
        // CreateAccountModal.tsx's module-checklist section and this
        // refactor's audit report for the full slug-to-checklist-row
        // mapping and the reasoning above.
        'meta_ads',
        'social_inbox',
        'lead_crm',
        'comment_automation',
        'reports',
        'templates',
        // "Module & Feature Access" modal reorganization (3 categories +
        // Master Category checkboxes + Quick Plan presets) — two new
        // slugs. 'device_settings' maps to a superAdminOnly nav item
        // with no requiresModule (same disclosed no-op precedent as
        // 'templates'); 'social_accounts' closes a previously-disclosed
        // gap by gaining a real `requiresModule` gate on its nav item.
        // See frontend-app's types/account.ts CORE_COMMON_MODULES /
        // WHATSAPP_SUITE_MODULES / SOCIAL_SUITE_MODULES and this
        // refactor's audit report.
        'device_settings',
        'social_accounts',
        // Message Logs Governance Fix — previously piggybacked on the
        // generic 'analytics' slug (a Core Common module), so a Super
        // Admin could not gate it independently of the Analytics page.
        // Re-homed under the WhatsApp Messaging Suite as its own slug.
        // See routes/api.php's module.guard wrap and frontend-app's
        // types/account.ts WHATSAPP_SUITE_MODULES for the other half.
        'message_logs',
        // Group Messaging Step 1 — Custom Contact Groups, a paid
        // addon. See ContactGroup/ContactGroupMember models and their
        // creating migrations; also under WHATSAPP_SUITE_MODULES in
        // frontend-app's types/account.ts.
        'contact_groups',
    ];

    protected $fillable = [
        'company_name',
        'primary_phone',
        'status',
        // Module 9 — requests/minute allowed on the external Developer API.
        'api_rate_limit_per_minute',
        // Absolute Super Admin Control — per-client module toggles.
        'allowed_modules',
        // Super Admin Client Provisioning, Client Admin Mapping, User
        // Limits, Granular Permission Matrix & Adaptive Dashboards
        // refactor — see the creating migration's docblock and
        // MODULE_ASSIGNMENTS above.
        'max_users_limit',
        'module_assignment',
        // Super Admin WhatsApp Device Integration — see platformDevice().
        'is_platform_device',
        // Social Media Marketing & Meta Ads Automation Expansion (Phase 1).
        // Per-platform toggles; see the creating migration's docblock for
        // why these default false rather than following allowed_modules'
        // "null = everything enabled" precedent.
        'allow_facebook',
        'allow_instagram',
        'allow_linkedin',
        'allow_youtube',
        // Social Media Marketing & Meta Ads Automation Expansion (Final
        // Phase) — White-Label Automated PDF Reporting. See the creating
        // migration's docblock: Super-Admin-set only, same tier as
        // company_name/primary_phone above (no tenant self-service
        // profile endpoint exists for any of these).
        'logo_url',
        'brand_accent_color',
        // Social/Ads Launcher Overhaul — Step 1 (Gemini Pro Engine).
        // Genuinely per-tenant AI provider key override — see the
        // creating migration's docblock for the full 3-tier resolution
        // order (tenant -> platform social_provider_configs -> env).
        // Super-Admin-set via the same AccountController::update()
        // endpoint as logo_url/brand_accent_color above.
        'gemini_api_key',
    ];

    /**
     * gemini_api_key is a secret (a tenant's own AI provider credential)
     * and must never round-trip into a JSON response the way every other
     * $fillable Account attribute does by default — this app had no
     * $hidden array on Account at all before this, because nothing
     * secret lived on this model until now. Mirrors SocialProviderConfig
     * ::$hidden's treatment of its own client_secret/webhook_verify_token.
     *
     * @var list<string>
     */
    protected $hidden = [
        'gemini_api_key',
    ];

    protected function casts(): array
    {
        return [
            'allowed_modules' => 'array',
            'max_users_limit' => 'integer',
            'is_platform_device' => 'boolean',
            'allow_facebook' => 'boolean',
            'allow_instagram' => 'boolean',
            'allow_linkedin' => 'boolean',
            'allow_youtube' => 'boolean',
            // Same Crypt::encryptString/decryptString transparent cast
            // used for SocialProviderConfig's secret columns.
            'gemini_api_key' => 'encrypted',
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

    /** Group Messaging Step 1 — every named contact list this account owns, including the AccountController::store()-created default. */
    public function contactGroups(): HasMany
    {
        return $this->hasMany(ContactGroup::class);
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

    /**
     * Super Admin Client Provisioning refactor — User Limits.
     * max_users_limit === null means unlimited (the default for every
     * account until a Super Admin sets a cap — same zero-regression
     * convention as hasModuleEnabled() above). Counts EVERY user on the
     * account, including the primary Admin/owner, since the limit is a
     * total-headcount cap on the account, not a "team members besides
     * the owner" cap.
     */
    public function hasReachedUserLimit(): bool
    {
        if ($this->max_users_limit === null) {
            return false;
        }

        return $this->users()->count() >= $this->max_users_limit;
    }

    /**
     * Social Media Marketing & Meta Ads Automation Expansion (Phase 1).
     * Maps a SocialAccount::ASSET_TYPES value to the allow_* column that
     * gates it. 'meta_ad_account' is deliberately bundled under
     * allow_facebook (the spec names four PLATFORM toggles — facebook,
     * instagram, linkedin, youtube — not a separate "ads" toggle; a Meta
     * Ad Account is part of the same Meta/Facebook Business grant as
     * Pages) — a disclosed interpretation, easy to split into its own
     * column later if Super Admin wants Ads gated independently of Pages.
     * Unknown asset types fail closed (false), not open.
     */
    public function hasSocialPlatformEnabled(string $assetType): bool
    {
        return match ($assetType) {
            'facebook_page', 'meta_ad_account' => (bool) $this->allow_facebook,
            'instagram' => (bool) $this->allow_instagram,
            'linkedin_page' => (bool) $this->allow_linkedin,
            'youtube_channel' => (bool) $this->allow_youtube,
            default => false,
        };
    }
}
