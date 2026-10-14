<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One page opened during a website visit, with the seconds it was visible. */
class SitePageView extends Model
{
    protected $fillable = ['site_visit_id', 'view_id', 'path', 'title', 'duration_seconds', 'entered_at'];

    protected function casts(): array
    {
        return ['duration_seconds' => 'integer', 'entered_at' => 'datetime'];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(SiteVisit::class, 'site_visit_id');
    }
}
