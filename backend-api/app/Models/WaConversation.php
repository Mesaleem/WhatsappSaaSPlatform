<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One customer's WhatsApp conversation with an account (QA fixes, 2026-10-14). */
class WaConversation extends Model
{
    protected $fillable = [
        'account_id', 'contact_phone', 'contact_name', 'whatsapp_number_id',
        'last_message_preview', 'last_direction', 'last_message_at', 'last_inbound_at', 'unread_count',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'unread_count' => 'integer',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WaMessage::class);
    }

    /** Meta allows a free-text reply only within 24 hours of the customer's last message. */
    public function withinServiceWindow(): bool
    {
        return $this->last_inbound_at !== null && $this->last_inbound_at->gt(now()->subHours(24));
    }
}
