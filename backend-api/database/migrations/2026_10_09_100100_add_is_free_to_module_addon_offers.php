<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Super-Admin-wide "Free (no charge)" switch per module offer, independent of
 * price/tiers/`is_active` (owner instruction, 2026-10-07). While `is_free = true` for a
 * module, that module is unlimited/granted for every account with no purchase, request
 * or invoice -- `ModuleAddonService::isFree()`/`activeUnitLimit()` and
 * `NativeGroupEntitlement::allows()` check it. Price and tiers are left untouched
 * underneath, so flipping the switch back to false resumes charging at exactly what
 * was configured before.
 *
 * Schema-only, deliberately: defaults to false on every module (including
 * `contact_groups`), so this never silently changes existing behaviour or the paid-path
 * assumptions ~10 existing test files make about `contact_groups`. Switching
 * `contact_groups` ("Custom Contact Groups" on the Plans screen -- actually the offer
 * that prices Native WhatsApp Group slot counts, see CustomGroupAccessService's class
 * docblock) to free right now is a deliberate Super Admin action: Plans page -> that
 * offer's new "Free (no charge) right now" checkbox -> Save. Not done here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('module_addon_offers', 'is_free')) {
            Schema::table('module_addon_offers', function (Blueprint $table) {
                $table->boolean('is_free')->default(false)->after('is_active');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('module_addon_offers', 'is_free')) {
            Schema::table('module_addon_offers', function (Blueprint $table) {
                $table->dropColumn('is_free');
            });
        }
    }
};
