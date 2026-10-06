<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Notifications saved before they carried a link opened nothing when clicked. A notification
 * with no link gets the page its category belongs to. Rows that already have a link are not
 * touched. Data only; the down step does nothing.
 */
return new class extends Migration
{
    private const LINKS = [
        'billing' => '/add-ons',
        'template_review' => '/admin/templates',
    ];

    public function up(): void
    {
        foreach (self::LINKS as $category => $link) {
            DB::table('in_app_notifications')
                ->where('category', $category)
                ->whereNull('link')
                ->update(['link' => $link]);
        }
    }

    public function down(): void
    {
        // Intentionally empty: links that were filled in are correct as they are.
    }
};
