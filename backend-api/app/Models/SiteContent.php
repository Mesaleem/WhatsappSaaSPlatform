<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One editable section of the public website (see SiteContentDefaults for the keys). */
class SiteContent extends Model
{
    protected $fillable = ['key', 'value', 'updated_by_user_id'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
