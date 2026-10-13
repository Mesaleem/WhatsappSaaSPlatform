<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Template awaiting your review" notifications were created without a link,
 * so clicking them in the bell did nothing. New ones carry '/admin/templates'
 * (TemplateService::notifyAwaitingReview); this gives the existing ones the
 * same link. Only rows that have no link are touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('in_app_notifications')
            ->where('category', 'template_review')
            ->whereNull('link')
            ->update(['link' => '/admin/templates']);
    }

    public function down(): void
    {
        // Not reversed: an empty link was the bug, not a state worth restoring.
    }
};
