<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named, tenant-scoped contact list. Two kinds, distinguished by
 * `group_type` (see the "Native WhatsApp Group Re-Architecture" migration
 * for the full column-level rationale):
 *
 * - GROUP_TYPE_INTERNAL ('internal_segment', the original and still the
 *   default): a pure DB broadcast segment — sending to it fans out to
 *   every member's own individual WhatsApp chat (ProcessGroupDispatchJob).
 *   This is what every ContactGroup row was before this re-architecture.
 * - GROUP_TYPE_NATIVE ('native_wa_group'): backed by a real WhatsApp
 *   group chat on the tenant's connected 'qr' (Baileys) device — sending
 *   to it delivers ONE message into that actual group chat, not N
 *   individual DMs. wa_group_jid/invite_link/sync_status/sync_error
 *   track that live group's identity and sync state.
 *
 * AccountController::store() auto-creates one is_default=true row (always
 * GROUP_TYPE_INTERNAL — a native WhatsApp group cannot exist before a
 * tenant has even connected a device) per new account.
 */
class ContactGroup extends Model
{
    public const GROUP_TYPE_INTERNAL = 'internal_segment';
    public const GROUP_TYPE_NATIVE = 'native_wa_group';

    /** @var list<string> */
    public const GROUP_TYPES = [
        self::GROUP_TYPE_INTERNAL,
        self::GROUP_TYPE_NATIVE,
    ];

    public const SYNC_STATUS_PENDING = 'pending';
    public const SYNC_STATUS_SYNCED = 'synced';
    public const SYNC_STATUS_FAILED = 'failed';

    protected $fillable = [
        'account_id',
        'name',
        'is_default',
        'group_type',
        'wa_group_jid',
        'invite_link',
        'sync_status',
        'sync_error',
    ];

    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(ContactGroupMember::class, 'group_id');
    }

    public function isNative(): bool
    {
        return $this->group_type === self::GROUP_TYPE_NATIVE;
    }

    /** True only once a native group's live WhatsApp JID is known and usable as a send target. */
    public function isSyncedNativeGroup(): bool
    {
        return $this->isNative()
            && $this->sync_status === self::SYNC_STATUS_SYNCED
            && filled($this->wa_group_jid);
    }
}
