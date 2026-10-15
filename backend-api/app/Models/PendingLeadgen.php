<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A Meta lead-ad lead whose answers could not be fetched yet (QA fixes, 2026-10-14). */
class PendingLeadgen extends Model
{
    protected $fillable = ['account_id', 'social_account_id', 'leadgen_id', 'payload', 'attempts', 'last_error', 'next_attempt_at', 'resolved_at'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
