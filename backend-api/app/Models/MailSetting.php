<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use App\Traits\LogsActivity;

class MailSetting extends Model
{
    use LogsActivity;

    /** IMPLEMENT: Dynamic Route Master with Super-Admin Bypass & Global Audit Tracking — module label shown in the Activity Logs UI. */
    protected string $auditModuleName = 'Mail Settings';
    private const CACHE_KEY = 'mail_setting:current';

    /**
     * Runtime Query Caching (Performance & Database Optimization audit) —
     * this singleton row (there is at most one) is read on every
     * broadcast email send (NotificationBroadcastController::store()) and
     * every Mail Settings page load, previously a fresh MailSetting::first()
     * query each time. 60-minute TTL, invalidated the moment the row is
     * actually saved/deleted so an updated SMTP config is never stale.
     */
    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    /** Cached MailSetting::first() — null when no settings row has been saved yet. */
    public static function currentCached(): ?self
    {
        return Cache::remember(self::CACHE_KEY, 3600, fn () => self::first());
    }
    protected $fillable = [
        'mailer',
        'host',
        'port',
        'username',
        'password',
        'encryption',
        'from_address',
        'from_name',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            // Same Crypt::encryptString/decryptString transparent cast
            // used by PaymentGatewaySetting's secret fields.
            'password' => 'encrypted',
        ];
    }

    public function isConfigured(): bool
    {
        return (bool) ($this->host && $this->port && $this->from_address);
    }

    /**
     * The runtime mail.mailers.<name> config array this row represents —
     * shared by the "save" path (config isn't actually swapped on save,
     * only persisted) and MailSettingsController::sendTest(), which DOES
     * apply it via Config::set() for one outgoing message. Kept on the
     * model so both call sites can never drift apart.
     *
     * @return array<string, mixed>
     */
    public function toMailerConfig(): array
    {
        return [
            'transport' => $this->mailer ?: 'smtp',
            'host' => $this->host,
            'port' => $this->port,
            'encryption' => $this->encryption ?: null,
            'username' => $this->username,
            'password' => $this->password,
            'timeout' => null,
        ];
    }
}
