<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only. `context` holds safe identifiers only - the writer (ApiKeyBindingService::record) allow-lists its keys. */
class ApiKeySecurityEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['account_id', 'api_key_id', 'binding_id', 'actor_user_id', 'event', 'ip', 'context', 'created_at'];

    protected function casts(): array
    {
        return ['context' => 'array', 'created_at' => 'datetime'];
    }
}
