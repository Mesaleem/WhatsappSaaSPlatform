<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A platform-wide value changed by the Super Admin. A missing key means "use the default". */
class PlatformSetting extends Model
{
    public const WHATSAPP_ADDON_PRICE = 'whatsapp_addon_price';

    protected $table = 'platform_settings';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    public static function get(string $key): ?string
    {
        return static::query()->whereKey($key)->value('value');
    }

    public static function put(string $key, string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
