<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        // Platform-level Super Admin — no account_id, global system access.
        $superAdmin = User::firstOrCreate(
            ['email' => 'superadmin@wa-saas.local'],
            [
                'name' => 'Platform Super Admin',
                'password' => 'ChangeMe!123',
                'is_active' => true,
                'account_id' => null,
            ]
        );
        $superAdmin->assignRole('super_admin');

        // Demo tenant account with an Admin user + subscription, for local dev only.
        $demoAccount = Account::firstOrCreate(
            ['company_name' => 'Demo Account'],
            ['primary_phone' => '+91 90000 00000', 'status' => 'active']
        );

        Subscription::firstOrCreate(
            ['account_id' => $demoAccount->id],
            [
                'engine_type' => 'qr',
                'billing_model' => 'flat_quota',
                'rate_per_message' => null,
                'total_allocated_messages' => 5000,
                'used_messages' => 0,
                'price_paid' => 4999,
                'payment_mode' => 'razorpay',
                'starts_at' => now(),
                'expires_at' => now()->addYear(),
                'status' => 'active',
            ]
        );

        $demoAdmin = User::firstOrCreate(
            ['email' => 'admin@demo-account.local'],
            [
                'name' => 'Demo Account Admin',
                'password' => 'ChangeMe!123',
                'is_active' => true,
                'account_id' => $demoAccount->id,
            ]
        );
        $demoAdmin->assignRole('admin');
    }
}
