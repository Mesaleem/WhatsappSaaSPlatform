<?php

namespace Database\Factories;

use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Phase 1 Foundation — first factory for Account, needed to write any
 * tenant-isolation/RBAC test at all (none existed before this). Defaults
 * to a plain direct client with every module enabled (allowed_modules =
 * null, matching Account::effectiveModules()'s own "null = everything"
 * convention) so a bare Account::factory()->create() behaves like an
 * ordinary, fully-entitled tenant unless a state narrows it.
 *
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        return [
            'account_type' => 'client',
            'agent_id' => null,
            'company_name' => fake()->unique()->company(),
            'primary_phone' => fake()->numerify('91##########'),
            'status' => 'active',
            'allowed_modules' => null,
            'is_platform_device' => false,
        ];
    }

    /**
     * A Reseller account. Per Account::MODULES' own docblock and
     * account_type's creating migration, an Agent is still a normal
     * `client`-shaped row for every column except account_type itself —
     * no schema field is agent-specific beyond that.
     */
    public function agent(): static
    {
        return $this->state(fn () => ['account_type' => 'agent']);
    }

    /**
     * A Client owned by the given Agent account (id or Account). Mirrors
     * AccountService::resolveDelegatedModules()'s expectation that a
     * delegated client's agent_id points at a real Agent-type Account.
     */
    public function client(Account|int $agent): static
    {
        return $this->state(fn () => ['agent_id' => $agent instanceof Account ? $agent->id : $agent]);
    }
}
