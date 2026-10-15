<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\LogsActivity;

class WebhookSubscription extends Model
{
    use LogsActivity;

    /** IMPLEMENT: Dynamic Route Master with Super-Admin Bypass & Global Audit Tracking — module label shown in the Activity Logs UI. */
    protected string $auditModuleName = 'Developer Webhooks';
    /**
     * The events this module supports. QA fixes (2026-10-14): CRM events too, so a
     * client can push new leads and won deals to its own system.
     */
    public const SUPPORTED_EVENTS = ['message.sent', 'message.failed', 'message.delivered', 'crm.lead.created', 'crm.lead.status_changed', 'crm.deal.won'];

    protected static function booted(): void
    {
        // WebhookDispatcher remembers for a minute whether an account has webhooks.
        $forget = fn (self $s) => \Illuminate\Support\Facades\Cache::forget("webhooks:has:{$s->account_id}");
        static::saved($forget);
        static::deleted($forget);
    }

    protected $fillable = [
        'account_id',
        'url',
        'secret',
        'events',
        'is_active',
    ];

    /**
     * `secret` is never re-exposed in an index/show response after
     * creation (see WebhookSubscriptionController::present()) — hidden
     * here too as a defensive backstop against a raw `$model->toArray()`
     * or `toJson()` call bypassing that explicit masking.
     */
    protected $hidden = [
        'secret',
    ];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'is_active' => 'boolean',
            // Same Crypt::encryptString/decryptString transparent cast as
            // PaymentGatewaySetting's key/secret columns (Module 8) —
            // DispatchWebhookJob needs the plaintext back to compute each
            // delivery's HMAC-SHA256 signature, so this is encrypted
            // at-rest, not hashed.
            'secret' => 'encrypted',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function listensTo(string $event): bool
    {
        return in_array($event, $this->events ?? [], true);
    }
}
