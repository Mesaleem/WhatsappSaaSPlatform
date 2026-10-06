<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A plan-expiry reminder already sent for one term (see SubscriptionExpiryReminderService). */
class SubscriptionReminder extends Model
{
    protected $fillable = ['account_id', 'kind', 'expires_at', 'sent_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
