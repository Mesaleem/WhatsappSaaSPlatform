<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\LogsActivity;

class NotificationTemplate extends Model
{
    use LogsActivity;

    /** IMPLEMENT: Dynamic Route Master with Super-Admin Bypass & Global Audit Tracking — module label shown in the Activity Logs UI. */
    protected string $auditModuleName = 'Notification Templates';
    protected $fillable = [
        'slug',
        'is_system',
        'is_active',
        'name',
        'type',
        'category',
        'description',
        'subject',
        'body',
        'variables',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'variables' => 'array',
        ];
    }

    /**
     * A master (system) template: the content of an automatic email, found
     * by slug (see App\Services\Mail\SystemEmailCatalog). Only the Super
     * Admin may see or change these; they cannot be deleted.
     */
    public function scopeSystem($query, bool $system = true)
    {
        return $query->where('is_system', $system);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
