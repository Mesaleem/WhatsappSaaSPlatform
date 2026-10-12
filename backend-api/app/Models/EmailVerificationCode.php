<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A pending website-signup email code (see the create migration). */
class EmailVerificationCode extends Model
{
    protected $fillable = ['email', 'code_hash', 'attempts', 'expires_at'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'attempts' => 'integer'];
    }
}
