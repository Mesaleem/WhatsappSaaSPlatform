<?php

use App\Services\Mail\SystemEmailCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Master (system) email templates — the automatic emails the platform
     * sends (signup code, welcome, password reset, plan / quota / add-on
     * requests, expiry reminders, ...) become editable rows of
     * notification_templates, marked is_system and found by `slug`.
     *
     * The default rows are inserted HERE, so `php artisan migrate` on a
     * server is enough — no seeder to remember. A row is inserted only when
     * its slug is missing: re-running never overwrites a template the Super
     * Admin has edited. (Newer catalog entries are added by a later
     * migration, or by `Reset to default` in the Mail Templates screen.)
     *
     * mail_logs.template_slug records which system template an automatic
     * email used, so those sends appear in Mail Logs next to broadcasts.
     */
    public function up(): void
    {
        Schema::table('notification_templates', function (Blueprint $table) {
            $table->string('slug', 100)->nullable()->unique()->after('id');
            $table->boolean('is_system')->default(false)->after('slug');
            $table->boolean('is_active')->default(true)->after('is_system');
            $table->string('description', 500)->nullable()->after('category');
            $table->json('variables')->nullable()->after('body');
        });

        Schema::table('mail_logs', function (Blueprint $table) {
            $table->string('template_slug', 100)->nullable()->after('broadcast_id');
            $table->index('template_slug');
        });

        $now = now();
        $existing = DB::table('notification_templates')->whereNotNull('slug')->pluck('slug')->all();

        foreach (SystemEmailCatalog::all() as $slug => $template) {
            if (in_array($slug, $existing, true)) {
                continue;
            }

            DB::table('notification_templates')->insert([
                'slug' => $slug,
                'is_system' => true,
                'is_active' => true,
                'name' => $template['name'],
                'type' => 'raw_html',
                'category' => $template['category'],
                'description' => $template['description'],
                'subject' => $template['subject'],
                'body' => $template['body'],
                'variables' => json_encode(SystemEmailCatalog::variablesFor($slug)),
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('notification_templates')->where('is_system', true)->delete();

        Schema::table('mail_logs', function (Blueprint $table) {
            $table->dropIndex(['template_slug']);
            $table->dropColumn('template_slug');
        });

        Schema::table('notification_templates', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'is_system', 'is_active', 'description', 'variables']);
        });
    }
};
