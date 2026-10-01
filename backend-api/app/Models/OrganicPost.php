<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\LogsActivity;

/**
 * Social/Ads Launcher Overhaul — Step 3 (Organic Multi-Channel Publishing
 * Engine). One row per organic post attempt to Facebook Page Feed,
 * Instagram, or LinkedIn via OrganicPublishService. See the creating
 * migration's docblock for why this table logs failures too (unlike
 * AdCampaign, which only persists on confirmed external success).
 */
class OrganicPost extends Model
{
    use LogsActivity;

    /** IMPLEMENT: Dynamic Route Master with Super-Admin Bypass & Global Audit Tracking — module label shown in the Activity Logs UI. */
    protected string $auditModuleName = 'Social Organic Posts';
    public const PLATFORMS = ['facebook', 'instagram', 'linkedin'];

    public const STATUS_PENDING = 'pending';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_FAILED = 'failed';

    /** Phase 9 Task 3 — waiting for its scheduled_at (or a retry back-off). */
    public const STATUS_SCHEDULED = 'scheduled';

    /** Phase 9 Task 3 — claimed by one worker/request that is calling the provider. */
    public const STATUS_PUBLISHING = 'publishing';

    /** Phase 9 Task 3 — the connection is expired/revoked; runs again only after a retry once reconnected. */
    public const STATUS_RECONNECT_REQUIRED = 'reconnect_required';

    public const STATUS_CANCELLED = 'cancelled';

    public const ORIGIN_MANUAL = 'manual';

    public const ORIGIN_SCHEDULED = 'scheduled';

    /**
     * Phase 9 Task 3 — the only allowed moves (OrganicPublishService
     * applies each one as a conditional UPDATE on the current status).
     * `pending` keeps its original meaning: the provider is still
     * processing (Instagram video).
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_SCHEDULED => [self::STATUS_PUBLISHING, self::STATUS_CANCELLED, self::STATUS_FAILED],
        self::STATUS_PUBLISHING => [self::STATUS_PUBLISHED, self::STATUS_PENDING, self::STATUS_FAILED, self::STATUS_RECONNECT_REQUIRED, self::STATUS_SCHEDULED],
        self::STATUS_PENDING => [self::STATUS_PUBLISHED, self::STATUS_FAILED, self::STATUS_RECONNECT_REQUIRED],
        self::STATUS_FAILED => [self::STATUS_SCHEDULED],
        self::STATUS_RECONNECT_REQUIRED => [self::STATUS_SCHEDULED, self::STATUS_CANCELLED],
        self::STATUS_PUBLISHED => [],
        self::STATUS_CANCELLED => [],
    ];

    protected $fillable = [
        'account_id',
        'social_account_id',
        'provider',
        'platform',
        'caption',
        'media_url',
        'media_type',
        'status',
        'external_post_id',
        'error_message',
        'published_at',
        // Phase 9 Task 3 — publishing lifecycle (see the 2026_09_30_110000 migration).
        'scheduled_at',
        'origin',
        'idempotency_key',
        'created_by_user_id',
        'attempts',
        'next_attempt_at',
        'claim_token',
        'claimed_at',
        'provider_called_at',
        'failure_code',
        'cancelled_at',
        'metadata',
    ];

    /** The claim token is an internal lock value, never serialized. */
    protected $hidden = ['claim_token'];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'claimed_at' => 'datetime',
            'provider_called_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'attempts' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    /** Phase 9 Task 4 — latest insights snapshot (organic_post_insights). */
    public function insight(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(OrganicPostInsight::class, 'organic_post_id');
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    public function markPublished(string $externalPostId): void
    {
        $this->forceFill([
            'status' => self::STATUS_PUBLISHED,
            'external_post_id' => $externalPostId,
            'published_at' => now(),
            'error_message' => null,
            'failure_code' => null,
            'claim_token' => null,
        ])->save();
    }

    public function markFailed(string $errorMessage, ?string $failureCode = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
            'failure_code' => $failureCode,
            'claim_token' => null,
        ])->save();
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** Phase 9 Task 3 — a user may cancel a post that has not started publishing. */
    public function isCancellable(): bool
    {
        return in_array($this->status, [self::STATUS_SCHEDULED, self::STATUS_RECONNECT_REQUIRED], true);
    }

    /** Phase 9 Task 3 — a user may retry a post that ended without being published. */
    public function isRetryable(): bool
    {
        return in_array($this->status, [self::STATUS_FAILED, self::STATUS_RECONNECT_REQUIRED], true);
    }
}
