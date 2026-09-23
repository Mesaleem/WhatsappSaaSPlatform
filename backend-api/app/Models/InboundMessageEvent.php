<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 7 Task 3 — one processed inbound message (append-only claim
 * ledger). See the 2026_09_24_140000 migration and InboundEventGate.
 */
class InboundMessageEvent extends Model
{
    public const PROVIDER_META = 'meta';

    public const PROVIDER_QR = 'qr';

    public const UPDATED_AT = null;

    protected $table = 'inbound_message_events';

    protected $fillable = ['account_id', 'provider', 'event_key', 'phone_number', 'processed_at'];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }
}
