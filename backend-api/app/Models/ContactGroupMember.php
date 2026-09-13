<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Group Messaging — Step 1. A single phone number inside one
 * ContactGroup. No account_id here by design — see this table's
 * creating migration for why; tenant scoping goes through
 * group()->account_id.
 */
class ContactGroupMember extends Model
{
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
