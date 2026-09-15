<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\LogsActivity;

/**
 * Group Messaging — Step 1. A single phone number inside one
 * ContactGroup. No account_id here by design — see this table's
 * creating migration for why; tenant scoping goes through
 * group()->account_id.
 */
class ContactGroupMember extends Model
{
    use LogsActivity;

    /** IMPLEMENT: Dynamic Route Master with Super-Admin Bypass & Global Audit Tracking — module label shown in the Activity Logs UI. */
    protected string $auditModuleName = 'Contact Groups';
    protected $fillable = [
        'group_id',
        'phone_number',
        'name',
    ];

    protected function casts(): array
    {
        return [
            'group_id' => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ContactGroup::class, 'group_id');
    }
}
