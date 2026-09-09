<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Module 3 splits the old accounts.subscription_status/expires_at pair
     * (Module 2) into a dedicated `subscriptions` table. This migration:
     *  1. Renames accounts.name -> accounts.company_name (data preserved).
     *  2. Adds accounts.primary_phone and accounts.status (admin-level
     *     active/suspended/expired, distinct from billing status).
     *  3. Backfills one subscriptions row per existing account from its
     *     old subscription_status/expires_at values, so accounts created
     *     under Module 2 keep a working subscription after the drop below.
     *  4. Drops the now-superseded subscription_status/expires_at columns.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->renameColumn('name', 'company_name');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->string('primary_phone')->nullable()->after('company_name');
            $table->string('status')->default('active')->after('primary_phone');
        });

        $now = now();
        $legacyAccounts = DB::table('accounts')->get(['id', 'subscription_status', 'expires_at', 'created_at']);

        foreach ($legacyAccounts as $account) {
            $alreadyHasSubscription = DB::table('subscriptions')
                ->where('account_id', $account->id)
                ->exists();

            if ($alreadyHasSubscription) {
                continue;
            }

            DB::table('subscriptions')->insert([
                'account_id' => $account->id,
                'engine_type' => 'qr',
                'billing_model' => 'unlimited',
                'rate_per_message' => null,
                'total_allocated_messages' => null,
                'used_messages' => 0,
                'price_paid' => 0,
                'payment_mode' => 'cash',
                'starts_at' => $account->created_at ?? $now,
                // Legacy 'trial' accounts get a 30-day grace window; other
                // legacy statuses keep their original expires_at as-is.
                'expires_at' => $account->expires_at ?? $now->copy()->addDays(30),
                'status' => in_array($account->subscription_status, ['active', 'trial'], true)
                    ? 'active'
                    : 'expired',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['subscription_status', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('subscription_status')->default('trial');
            $table->timestamp('expires_at')->nullable();
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['primary_phone', 'status']);
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->renameColumn('company_name', 'name');
        });
    }
};
