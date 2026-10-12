<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A message sent from the public website's contact form. */
class ContactMessage extends Model
{
    protected $fillable = ['name', 'email', 'phone', 'company', 'topic', 'message', 'ip', 'handled_at'];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }
}
