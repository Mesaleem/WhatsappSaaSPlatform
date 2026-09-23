<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Phase 6 — CRM, Task 2. Follows AccountFactory's conventions: a bare
 * Contact::factory()->create() produces an ordinary, usable row and
 * provisions its own Account, so a test that does not care about the
 * tenant does not have to build one.
 *
 * The phone number uses the same 91-prefixed 12-digit shape as
 * AccountFactory::primary_phone, and is unique() because
 * contacts.unique(account_id, phone_number) would otherwise collide the
 * moment two contacts land under the same generated account.
 *
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'phone_number' => fake()->unique()->numerify('91##########'),
            'name' => fake()->name(),
        ];
    }

    /** Pin this contact to an existing tenant instead of creating one. */
    public function forAccount(Account|int $account): static
    {
        return $this->state(fn () => [
            'account_id' => $account instanceof Account ? $account->id : $account,
        ]);
    }

    /** A contact captured with no name yet — the state ContactResolver is allowed to fill. */
    public function nameless(): static
    {
        return $this->state(fn () => ['name' => null]);
    }
}
