<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use App\Traits\LogsActivity;

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
    use LogsActivity;

    /** IMPLEMENT: Dynamic Route Master with Super-Admin Bypass & Global Audit Tracking — module label shown in the Activity Logs UI. */
    protected string $auditModuleName = 'Contact Groups';
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
        // Developer API: unique (per-account), human-readable lookup key for
        // POST /api/v1/send-message (recipient_type: "group") -- see the
        // add_group_code_to_contact_groups_table migration and
        // generateGroupCode() below.
        'group_code',
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

    /**
     * Developer API -- auto-generate a unique, human-readable group_code
     * from a group's name, the same UPPER_SNAKE_CASE slug shape
     * MessageTemplate::generateTemplateCode() uses for template_code.
     *
     * Deliberately scoped PER ACCOUNT, not globally unique like
     * template_code: a ContactGroup always belongs to exactly one tenant
     * (there is no "global group" concept the way a template can have a
     * null account_id), so two unrelated tenants both naming a group
     * "VIP" is normal and must not collide. TemplateMessageController::
     * sendMessage() resolves group_code scoped to the caller's own
     * account_id, so per-account uniqueness is already sufficient to
     * make that lookup unambiguous.
     *
     * $ignoreId excludes a row from the uniqueness check -- pass the
     * group's own id when regenerating a code for an existing group.
     */
    public static function generateGroupCode(int $accountId, string $name, ?int $ignoreId = null): string
    {
        $base = strtoupper((string) Str::of($name)
            ->ascii()
            ->replaceMatches('/[^A-Za-z0-9]+/', '_')
            ->trim('_'));

        if ($base === '') {
            $base = 'GRP_'.((int) static::where('account_id', $accountId)->max('id') + 1);
        }

        $code = $base;
        $suffix = 2;

        while (
            static::query()
                ->where('account_id', $accountId)
                ->where('group_code', $code)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $code = $base.'_'.$suffix;
            $suffix++;
        }

        return $code;
    }
}
