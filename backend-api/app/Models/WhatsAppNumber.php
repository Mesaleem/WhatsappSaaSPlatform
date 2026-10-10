<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A WhatsApp number an account owns. See the whatsapp_numbers migration for the
 * uniqueness and lock rules. Tenant-scoped by the caller (always filtered by
 * account_id in WhatsAppNumberService), not by a global scope.
 */
class WhatsAppNumber extends Model
{
    public const STATUS_PENDING_PAYMENT = 'pending_payment';
    public const STATUS_UNLINKED = 'unlinked';
    public const STATUS_LINKED = 'linked';
    public const STATUS_PAUSED = 'paused';

    /**
     * [Owner instruction, disclosed]: the account's DEFAULT (included, free) slot no
     * longer needs its number typed in before connecting — unlike an add-on slot,
     * which still must be added (WhatsAppNumberService::add()) with a real, verified
     * number before it can be paid for. A default slot with no real number yet is
     * auto-created (WhatsAppController::ensureDefaultSlot()) with phone_number set to
     * this prefix + the account id. hasPendingPlaceholderNumber() below is what
     * actually decides "placeholder or real" though — it checks the SHAPE of
     * phone_number (every real one matches /^[1-9][0-9]{7,14}$/, digits only), not
     * this exact prefix, deliberately: it also recognises an earlier placeholder
     * convention this exact prefix replaced ('platform'.$accountId, no dash, used
     * before this instruction generalised the fix from the platform device to every
     * account's default slot) without needing a backfill migration for whichever
     * account already got one of those. WhatsAppStatusController::update() calls it
     * to decide whether to adopt whatever number actually connects instead of
     * refusing it as a "different number" mismatch.
     */
    public const PENDING_PLACEHOLDER_PREFIX = 'pending-';

    protected $table = 'whatsapp_numbers';

    protected $fillable = [
        'account_id',
        'phone_number',
        'is_included',
        'is_default',
        'status',
        'locked_at',
        'addon_invoice_id',
        'term_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'is_included' => 'boolean',
            'is_default' => 'boolean',
            'locked_at' => 'datetime',
            'term_ends_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /**
     * True while this slot has never actually been connected to a real number yet.
     * Checked by SHAPE (does phone_number look like a real one at all), not by this
     * class's own current placeholder prefix — see that constant's own docblock for
     * why: it also recognises an earlier placeholder convention without a backfill.
     */
    public function hasPendingPlaceholderNumber(): bool
    {
        return ! preg_match('/^[1-9][0-9]{7,14}$/', (string) $this->phone_number);
    }
}
