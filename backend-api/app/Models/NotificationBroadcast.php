<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationBroadcast extends Model
{
    protected $fillable = [
        'template_id',
        'account_id',
        'subject',
        'body',
        'category',
        'channels',
        'target_type',
        'target_summary',
        'recipient_count',
        'email_sent_count',
        'email_failed_count',
        'sent_by',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'template_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function inAppNotifications(): HasMany
    {
        return $this->hasMany(InAppNotification::class, 'broadcast_id');
    }
}
