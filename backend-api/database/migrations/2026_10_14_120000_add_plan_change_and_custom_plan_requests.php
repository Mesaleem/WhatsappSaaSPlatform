<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan purchase rules (owner, 2026-10-14):
 *
 * invoices.plan_change   what a plan invoice does when paid: `new` (no plan running),
 *                        `renew` (the same plan after it ended) or `upgrade` (a bigger
 *                        plan while one runs — the running plan ENDS when this is paid and
 *                        the new period starts then). NULL on older invoices = the old
 *                        behaviour (extend from the current expiry).
 * custom_plan_requests   "Contact sales" for a custom plan: the requirement form. The
 *                        customer and the Super Admins are emailed; Super Admins see it on
 *                        the dashboard and in notifications.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('plan_change', 10)->nullable()->after('plan_label');
        });

        Schema::create('custom_plan_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 191);
            $table->string('email', 191);
            $table->string('phone', 32)->nullable();
            $table->string('company', 191)->nullable();
            $table->unsignedInteger('expected_messages')->nullable();
            $table->unsignedSmallInteger('whatsapp_numbers')->nullable();
            $table->unsignedSmallInteger('team_users')->nullable();
            $table->text('requirement');
            $table->string('status', 12)->default('new');
            $table->text('admin_note')->nullable();
            $table->foreignId('handled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('contacted_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_plan_requests');
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('plan_change');
        });
    }
};
