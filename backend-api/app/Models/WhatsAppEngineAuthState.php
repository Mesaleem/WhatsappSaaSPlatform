<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Baileys auth-state file for a WhatsApp NUMBER SLOT (creds.json, a signal
 * key, an app-state key, ...). Keyed by whatsapp_numbers.id, not by account:
 * an account can own several numbers, each with its own login. Written and read
 * only by qr-engine-service through the internal API. Never exposed to a browser.
 */
class WhatsAppEngineAuthState extends Model
{
    protected $table = 'whatsapp_engine_auth_states';

    protected $fillable = [
        'whatsapp_number_id',
        'name',
        'value',
    ];

    /**
     * The value is credential material: encrypted at rest with APP_KEY, the same
     * way other stored secrets are. Hidden from any serialisation so it can never
     * leak through a response.
     */
    protected $casts = [
        'value' => 'encrypted',
    ];

    protected $hidden = [
        'value',
    ];

    public function numberSlot(): BelongsTo
    {
        return $this->belongsTo(WhatsAppNumber::class, 'whatsapp_number_id');
    }
}
