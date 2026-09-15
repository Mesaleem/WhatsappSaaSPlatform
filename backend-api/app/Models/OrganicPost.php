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
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
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
        ])->save();
    }

    public function markFailed(string $errorMessage): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
        ])->save();
    }
}
