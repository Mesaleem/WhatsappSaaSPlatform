<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 Foundation — promotes App\Support\PlanCatalog's 3 hardcoded
 * plans into a real table, exactly per PlanCatalog's own disclosed
 * promotion trigger ("If the platform later needs ... admin-editable
 * plans without a deploy, that's the trigger to promote this into a
 * real table"). PlanCatalog itself is NOT removed in Phase 1 — the live
 * checkout flow keeps reading it unchanged; PlanSeeder seeds this table
 * with value-identical rows so a future cutover has zero drift to
 * reconcile. See the Phase 1 plan, Step 5, Task 4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('label');
            $table->decimal('price', 10, 2);
            $table->unsignedInteger('duration_days');
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
