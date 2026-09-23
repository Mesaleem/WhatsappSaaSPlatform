<?php

namespace App\Services\Crm;

use App\Models\Account;
use App\Models\Contact;
use App\Models\ContactGroupMember;
use App\Models\CrmLead;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 6 — CRM Hardening, Issue 2. The explicit, user-driven half of
 * contact management, alongside ContactResolver's automatic half.
 *
 * THE TWO ARE DELIBERATELY DIFFERENT and that difference is the whole
 * design:
 *  - ContactResolver runs as a SIDE EFFECT of capturing a lead. It fills
 *    a blank name or email and never overwrites one, because whatever a
 *    Meta form or a journey variable happened to contain must not clobber
 *    data a tenant curated.
 *  - ContactService runs because a person asked for this contact to
 *    change. Here overwriting IS the request, so name, email and even the
 *    phone number are replaced as instructed.
 * Creation still goes through ContactResolver, so the API cannot produce
 * a duplicate the capture flows would then have to reconcile.
 */
class ContactService
{
    public function __construct(private readonly ContactResolver $contacts)
    {
    }

    /**
     * Create a contact, or hand back the one this account already has
     * for that number.
     *
     * The brief requires that a repeat phone number must not create a
     * duplicate and must instead return/use the existing Contact. The
     * `created` flag is what lets the controller answer 201 for a new
     * row and 200 for an existing one — the ordinary REST distinction,
     * and the only honest way for a caller to tell the two apart.
     *
     * @param array{phone_number: string, name?: string|null, email?: string|null} $data
     * @return array{contact: Contact, created: bool}
     */
    public function create(Account $account, array $data): array
    {
        $normalized = PhoneNumberNormalizer::normalize((string) $data['phone_number']);

        $existedBefore = $normalized !== '' && Contact::query()
            ->forAccount($account->id)
            ->where('phone_number', $normalized)
            ->exists();

        $contact = $this->contacts->resolve(
            $account->id,
            (string) $data['phone_number'],
            $data['name'] ?? null,
            $data['email'] ?? null,
        );

        return ['contact' => $contact, 'created' => ! $existedBefore];
    }

    /**
     * Explicitly edit a contact.
     *
     * account_id is absent from the accepted keys and is never written,
     * so a contact cannot be moved between tenants through this path —
     * which also means crm_leads' composite (contact_id, account_id)
     * foreign key can never be invalidated from here.
     *
     * A phone change is normalized through the same
     * PhoneNumberNormalizer every other path uses (no second
     * implementation) and then checked against unique(account_id,
     * phone_number), EXCLUDING this contact's own row so that resaving
     * an unchanged number is not an error. Two contacts merging is a
     * genuine product decision — which leads, group memberships and
     * capture rows survive — and is not something an edit form should
     * do implicitly, so it is refused rather than guessed at.
     *
     * @param array{name?: string|null, email?: string|null, phone_number?: string} $data
     */
    public function update(Contact $contact, array $data): Contact
    {
        return DB::transaction(function () use ($contact, $data): Contact {
            if (array_key_exists('phone_number', $data)) {
                $normalized = PhoneNumberNormalizer::normalize((string) $data['phone_number']);

                if ($normalized === '') {
                    throw ValidationException::withMessages([
                        'phone_number' => ['Enter a valid phone number.'],
                    ]);
                }

                $taken = Contact::query()
                    ->forAccount((int) $contact->account_id)
                    ->where('phone_number', $normalized)
                    ->whereKeyNot($contact->getKey())
                    ->exists();

                if ($taken) {
                    throw ValidationException::withMessages([
                        'phone_number' => ['Another contact in this account already uses this phone number.'],
                    ]);
                }

                $contact->phone_number = $normalized;
            }

            if (array_key_exists('name', $data)) {
                $contact->name = $this->blankToNull($data['name']);
            }

            if (array_key_exists('email', $data)) {
                $contact->email = $this->blankToNull($data['email']);
            }

            $contact->save();

            return $contact->fresh();
        });
    }

    /**
     * Round 2, Limitation 6 — merge two duplicate contacts.
     *
     * THE TARGET SURVIVES. The source is removed only after every
     * dependent row has been moved onto the target, inside one
     * transaction, so there is no window in which a CRM lead or a
     * broadcast-list membership points at a contact that is going away.
     *
     * WHAT MOVES:
     *   crm_leads.contact_id            -> target
     *   contact_group_members.contact_id -> target
     * Nothing is deleted: not a CRM lead, not a capture row, not a group
     * membership. Capture leads need no handling of their own — since
     * Round 2 a capture is linked through crm_leads.capture_lead_id, so
     * moving the CRM lead carries its capture origin with it
     * automatically, and there is no second link that could be left
     * pointing at the dead contact.
     *
     * Rows are moved one model at a time rather than with a mass
     * ->update(): a query-builder update bypasses Eloquent events, and
     * this codebase's audit trail (LogsActivity) is built on those
     * events. Chunked, so a contact with a long history does not load
     * entirely into memory.
     *
     * FIELD RULES — deterministic, and never destructive to curated data:
     *   name   target's wins when non-empty; otherwise the source's fills it
     *   email  same rule
     *   phone  the TARGET's is authoritative and is never changed
     * The source's phone number is therefore DISCARDED, and that is the
     * one genuinely lossy part of a merge. Because unique(account_id,
     * phone_number) means two contacts in one account can never share a
     * number, every merge discards a real number — so this is not an
     * edge case to warn about, it is the operation itself. $confirmed
     * makes the caller say so explicitly; without it the merge is
     * refused rather than silently losing a way to reach that person.
     *
     * @throws ValidationException when the contacts are the same, belong
     *         to different accounts, or the phone loss is unconfirmed
     */
    public function merge(Contact $source, Contact $target, bool $confirmed = false): Contact
    {
        if ($source->getKey() === $target->getKey()) {
            throw ValidationException::withMessages([
                'target' => ['A contact cannot be merged into itself.'],
            ]);
        }

        // Belt and braces: the controller already resolves both contacts
        // through forAccount(), so a cross-tenant pair cannot reach here.
        // This makes the rule true for any future caller too.
        if ((int) $source->account_id !== (int) $target->account_id) {
            throw ValidationException::withMessages([
                'target' => ['The selected contact is not available.'],
            ]);
        }

        if (! $confirmed && $source->phone_number !== $target->phone_number) {
            throw ValidationException::withMessages([
                'confirm_phone_discard' => ["Merging discards {$source->phone_number}; the surviving contact keeps {$target->phone_number}. Confirm to proceed."],
            ]);
        }

        return DB::transaction(function () use ($source, $target): Contact {
            CrmLead::query()
                ->where('contact_id', $source->getKey())
                ->chunkById(200, function ($leads) use ($target): void {
                    foreach ($leads as $lead) {
                        $lead->forceFill(['contact_id' => $target->getKey()])->save();
                    }
                });

            ContactGroupMember::query()
                ->where('contact_id', $source->getKey())
                ->chunkById(200, function ($members) use ($target): void {
                    foreach ($members as $member) {
                        $member->forceFill(['contact_id' => $target->getKey()])->save();
                    }
                });

            if (blank($target->name) && filled($source->name)) {
                $target->name = $source->name;
            }

            if (blank($target->email) && filled($source->email)) {
                $target->email = $source->email;
            }

            $target->save();

            // Safe by then: everything that referenced the source has
            // been moved, so this cannot cascade away customer history.
            $source->delete();

            return $target->fresh();
        });
    }

    private function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
