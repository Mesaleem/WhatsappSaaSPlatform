<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\LogsActivity;

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 4.
 * One tenant-configured keyword trigger for the Ad Comment Auto-Responder
 * (CommentRulesPage's CRUD UI, enforced by CommentAutomationService).
 */
class CommentAutomationRule extends Model
{
    use LogsActivity;

    /** IMPLEMENT: Dynamic Route Master with Super-Admin Bypass & Global Audit Tracking — module label shown in the Activity Logs UI. */
    protected string $auditModuleName = 'Comment Automation';
    public const WILDCARD_KEYWORD = '*';

    protected $fillable = [
        'account_id',
        'keyword',
        'public_reply_template',
        'private_dm_template',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isWildcard(): bool
    {
        return $this->keyword === self::WILDCARD_KEYWORD;
    }
}
