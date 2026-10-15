<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Product tours (owner, 2026-10-15): the tours this user has finished or
     * skipped ("welcome", "page:/crm/leads", ...), so a first-login / first-visit
     * tour is shown once per user. NULL = none yet (a new user sees the
     * welcome tour). Returned with /api/auth/me; written by POST /api/auth/tours.
     *
     * Tours are for NEW users: everyone who already exists gets every tour
     * marked done (they can replay any tour from the header's ? button).
     * Keep EXISTING_USER_TOURS in line with frontend-app/src/tours/tourKeys.ts.
     */
    private const EXISTING_USER_TOURS = [
        'welcome', 'journey:nodes',
        'page:/settings/whatsapp', 'page:/alerts/send', 'page:/contact-groups', 'page:/admin/templates',
        'page:/chatbot', 'page:/chatbot/journeys', 'page:/crm/leads', 'page:/crm/pipeline',
        'page:/social/accounts', 'page:/social/inbox', 'page:/social/ads', 'page:/billing',
        'page:/users', 'page:/admin/accounts', 'page:/admin/plans', 'page:/developer',
    ];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('completed_tours')->nullable();
        });

        DB::table('users')->update(['completed_tours' => json_encode(self::EXISTING_USER_TOURS)]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('completed_tours');
        });
    }
};
