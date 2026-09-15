<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUILD: Fully Dynamic Categorized Route Master & Nested Permission
 * Matrix UI — one row per category box rendered in "Module & Feature
 * Access" (e.g. "WhatsApp Messaging Suite"), replacing the hardcoded
 * CORE_COMMON_MODULES/WHATSAPP_SUITE_MODULES/SOCIAL_SUITE_MODULES
 * constant arrays with a database-driven equivalent. See
 * RouteMasterSeeder for the one-time backfill from those constants, and
 * this feature's summary doc (Claude outputs/) for why this ships as
 * ADDITIVE infrastructure alongside — not a replacement of —
 * accounts.allowed_modules / Account::effectiveModules().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_categories', function (Blueprint $table) {
            $table->id();
            $table->string('category_name');
            // Stable machine key (e.g. 'whatsapp_suite') independent of
            // the human-editable display name, so a Super Admin can
            // rename a category without breaking anything that might
            // reference it by code in the future.
            $table->string('category_code')->unique();
            // lucide-react icon component name (e.g. 'MessageSquare'),
            // resolved client-side the same way AppLayout.tsx's NAV_ITEMS
            // already resolve icons — see ModulePermissionMatrix.tsx.
            $table->string('icon_name')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_categories');
    }
};
