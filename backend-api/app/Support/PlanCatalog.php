<?php

namespace App\Support;

/**
 * Static plan catalog for the self-service checkout flow (Module 8).
 *
 * NOT a database table: the module spec's migration list names only
 * `invoices`, so no `plans` table was added — introducing one would have
 * been an uninstructed schema addition. This mirrors how
 * RolePermissionSeeder defines its permission catalog as PHP constants
 * rather than a config-driven table. If the platform later needs
 * per-tenant custom pricing, promotional plans, or admin-editable plans
 * without a deploy, that's the trigger to promote this into a real table
 * — a deliberate, disclosed trade-off, not an oversight.
 *
 * `duration_days` is a renewal period (added to the current
 * expires_at — see InvoiceCreditService), not a hard "plan expires on
 * this date" concept.
 */
class PlanCatalog
{
    /**
     * @var array<string, array{
     *   label: string,
     *   engine_type: string,
     *   billing_model: string,
     *   rate_per_message: float|null,
     *   total_allocated_messages: int|null,
     *   price: float,
     *   duration_days: int,
     *   description: string,
     * }>
     */
    private const PLANS = [
        'starter' => [
            'label' => 'Starter',
            'engine_type' => 'qr',
            'billing_model' => 'flat_quota',
            'rate_per_message' => null,
            'total_allocated_messages' => 500,
            'price' => 499.00,
            'duration_days' => 30,
            'description' => '500 messages/month over the QR (Baileys) engine.',
        ],
        'growth' => [
            'label' => 'Growth',
            'engine_type' => 'qr',
            'billing_model' => 'flat_quota',
            'rate_per_message' => null,
            'total_allocated_messages' => 2500,
            'price' => 1999.00,
            'duration_days' => 30,
            'description' => '2,500 messages/month over the QR (Baileys) engine.',
        ],
        'business' => [
            'label' => 'Business',
            'engine_type' => 'meta',
            'billing_model' => 'flat_quota',
            'rate_per_message' => null,
            'total_allocated_messages' => 10000,
            'price' => 7999.00,
            'duration_days' => 30,
            'description' => '10,000 messages/month over the official Meta Cloud API.',
        ],
    ];

    /**
     * @return array<string, array>
     */
    public static function all(): array
    {
        return self::PLANS;
    }

    public static function find(string $key): ?array
    {
        return self::PLANS[$key] ?? null;
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::PLANS);
    }
}
