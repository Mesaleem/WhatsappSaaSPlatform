<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\LogsActivity;

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 2.
 * One Meta Lead Ads submission, processed by MetaLeadWebhookHandler.
 */
class Lead extends Model
{
    use LogsActivity;

    /** IMPLEMENT: Dynamic Route Master with Super-Admin Bypass & Global Audit Tracking — module label shown in the Activity Logs UI. */
    protected string $auditModuleName = 'Instant Lead CRM';
    protected $fillable = [
        'account_id',
        'social_account_id',
        'provider',
        'provider_lead_id',
        'form_id',
        'ad_id',
        'lead_name',
        'lead_phone',
        'lead_email',
        'raw_field_data',
        'tenant_notified_at',
        'lead_welcomed_at',
        'tenant_notify_error',
        'lead_welcome_error',
    ];

    protected function casts(): array
    {
        return [
            'raw_field_data' => 'array',
            'tenant_notified_at' => 'datetime',
            'lead_welcomed_at' => 'datetime',
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

    /**
     * The business dedup guard described in the creating migration's
     * docblock: has THIS account already received a lead from this exact
     * phone number in the last 24 hours? Deliberately excludes
     * provider_lead_id (a redelivery of the SAME event is a different,
     * unique-index-level guard — see MetaLeadWebhookHandler).
     */
    public static function isDuplicatePhoneWithin24Hours(int $accountId, string $normalizedPhone): bool
    {
        return self::query()
            ->forAccount($accountId)
            ->where('lead_phone', $normalizedPhone)
            ->where('created_at', '>=', now()->subDay())
            ->exists();
    }
}
