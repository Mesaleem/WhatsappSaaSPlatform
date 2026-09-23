<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Contact;
use App\Models\CrmLead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Phase 6 — CRM, Task 2.
 *
 * THE ONE THING THIS FACTORY MUST GET RIGHT: a lead's contact has to
 * belong to the lead's own account. Declaring `'contact_id' =>
 * Contact::factory()` in definition() would create a contact under a
 * SECOND, unrelated account, and CrmLead's tenant guard would reject
 * every row — so the contact is created in an afterMaking hook, once
 * account_id is known and still before the insert. Callers who pass
 * their own contact via forContact() bypass it entirely.
 *
 * @extends Factory<CrmLead>
 */
class CrmLeadFactory extends Factory
{
    protected $model = CrmLead::class;

    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'contact_id' => null,
            'status' => CrmLead::STATUS_NEW,
            'source' => CrmLead::SOURCE_MANUAL,
            'assigned_user_id' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (CrmLead $lead): void {
            if ($lead->contact_id === null) {
                $lead->contact_id = Contact::factory()
                    ->forAccount((int) $lead->account_id)
                    ->create()
                    ->id;
            }
        });
    }

    /** Use an existing contact — and, necessarily, its account. */
    public function forContact(Contact $contact): static
    {
        return $this->state(fn () => [
            'account_id' => $contact->account_id,
            'contact_id' => $contact->id,
        ]);
    }

    /** Assign to an existing account member. The caller is responsible for the user being in the same account. */
    public function assignedTo(User $user): static
    {
        return $this->state(fn () => ['assigned_user_id' => $user->id]);
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function source(string $source): static
    {
        return $this->state(fn () => ['source' => $source]);
    }
}
