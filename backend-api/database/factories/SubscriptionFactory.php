<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Phase 1 Foundation — first factory for Subscription. Defaults to an
 * active QR/flat_quota subscription (the same engine_type/billing_model
 * combination PlanCatalog's own 'starter' plan uses), started now and
 * expiring in 30 days, so quota/billing-adjacent tests don't need to
 * fill in every column by hand.
 *
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'engine_type' => 'qr',
            'billing_model' => 'flat_quota',
            'rate_per_message' => null,
            'total_allocated_messages' => 500,
            'used_messages' => 0,
            'price_paid' => 499.00,
            'payment_mode' => 'cash',
            'starts_at' => now(),
            'expires_at' => now()->addDays(30),
            'status' => 'active',
        ];
    }

    public function meta(): static
    {
        return $this->state(fn () => ['engine_type' => 'meta']);
    }

    public function perMessage(float $rate = 0.2): static
    {
        return $this->state(fn () => [
            'billing_model' => 'per_message',
            'rate_per_message' => $rate,
            'total_allocated_messages' => null,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'starts_at' => now()->subDays(60),
            'expires_at' => now()->subDays(30),
            'status' => 'expired',
        ]);
    }
}
