<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One browsing session on the public website (see the create_site_visits_tables migration). */
class SiteVisit extends Model
{
    protected $fillable = [
        'session_id', 'visitor_id', 'is_returning', 'ip', 'country', 'user_agent', 'device', 'browser', 'os',
        'referrer', 'source', 'utm_source', 'utm_medium', 'utm_campaign', 'landing_path', 'exit_path',
        'page_count', 'duration_seconds', 'signed_up', 'started_at', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'is_returning' => 'boolean',
            'signed_up' => 'boolean',
            'page_count' => 'integer',
            'duration_seconds' => 'integer',
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function pageViews(): HasMany
    {
        return $this->hasMany(SitePageView::class)->orderBy('entered_at')->orderBy('id');
    }
}
