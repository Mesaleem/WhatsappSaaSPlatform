<?php

use App\Services\Mail\SystemEmailCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QA fixes (2026-10-14) — the master email templates added to the catalog
 * after 2026_10_14_150000 ran (account.alert, onboarding.client_created,
 * billing.invoice_issued, billing.payment_received_admin). Inserted only when
 * the slug is missing, exactly like that migration: an edited template is
 * never overwritten. SystemMailer falls back to the catalog either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('notification_templates', 'slug')) {
            return;
        }

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
        DB::table('notification_templates')
            ->whereIn('slug', ['account.alert', 'onboarding.client_created', 'billing.invoice_issued', 'billing.payment_received_admin'])
            ->delete();
    }
};
