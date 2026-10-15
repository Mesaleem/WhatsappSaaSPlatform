<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One message in a WhatsApp inbox conversation (QA fixes, 2026-10-14). */
class WaMessage extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'wa_conversation_id', 'account_id', 'direction', 'message_type', 'body',
        'external_id', 'status', 'source', 'sent_by_user_id', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
