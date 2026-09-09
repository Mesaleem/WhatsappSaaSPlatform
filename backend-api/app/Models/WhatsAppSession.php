<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppSession extends Model
{
    // Eloquent's naming convention would derive 'whats_app_sessions'
    // (Str::snake('WhatsAppSessions')) from this class name, but the
    // migration (2026_09_08_100530_create_whatsapp_sessions_table) creates
    // 'whatsapp_sessions' (one word). Pin it explicitly so Eloquent targets
    // the table that actually exists.
    protected $table = 'whatsapp_sessions';

    protected $fillable = [
        'account_id',
        'status',
        'last_connected_at',
        'meta_phone_number_id',
        'meta_waba_id',
        'meta_access_token',
        'meta_webhook_verify_token',
    ];

    /**
     * Defense in depth: even if some future code path returns this model
     * directly (instead of MetaConfigController's masked response array),
     * the raw token never serializes into JSON.
     *
     * @var list<string>
     */
    protected $hidden = [
        'meta_access_token',
    ];

    protected function casts(): array
    {
        return [
            'last_connected_at' => 'datetime',
            // Laravel's built-in encrypted cast — Crypt::encryptString() on
            // write, Crypt::decryptString() on read, transparently.
            'meta_access_token' => 'encrypted',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function hasMetaConfigured(): bool
    {
        return (bool) ($this->meta_phone_number_id && $this->meta_access_token);
    }
}
