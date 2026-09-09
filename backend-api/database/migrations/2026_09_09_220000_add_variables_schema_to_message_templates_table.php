<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dynamic Template Engine — Variable Type & Validation Controls.
     * variables_schema stores, per {{token}} in template_body, the
     * Variable Configurator Panel's metadata:
     *   [{"key": "patient_name", "label": "Patient Name",
     *     "type": "string"|"number"|"date"|"select", "required": bool,
     *     "options"?: string[] (only for type "select")}, ...]
     *
     * Nullable, not backfilled: every template created before this
     * migration (and any created without ever opening the Configurator
     * Panel) simply has no row here. MessageTemplate::effectiveVariablesSchema()
     * is the single place that reconciles this — it returns the stored
     * schema when present, otherwise auto-derives one straight from the
     * template's {{tokens}} (type "string", required true for each) — so
     * every template, old or new, always validates through exactly one
     * code path; there is no separate "legacy" branch anywhere else in
     * the codebase.
     */
    public function up(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->json('variables_schema')->nullable()->after('template_body');
        });
    }

    public function down(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->dropColumn('variables_schema');
        });
    }
};
