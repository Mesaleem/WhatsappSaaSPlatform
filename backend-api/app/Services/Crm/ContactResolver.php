<?php

namespace App\Services\Crm;

use App\Models\Contact;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * Phase 6 — CRM, Task 2. The one place a CRM Contact is found or
 * created. Every future lead source (manual, whatsapp, meta_ad, api,
 * journey) goes through this, so "the same person" means the same thing
 * however the lead arrived.
 *
 * WHY A SERVICE AND NOT A MODEL METHOD: resolution is a two-step
 * decision (normalize, then find-or-create, then conditionally fill a
 * missing name) with a concurrency case to handle. Task 2's brief asks
 * for it as a reusable service, and the controller must not be the only
 * caller — Task 3+ will resolve contacts from webhook and journey
 * contexts that have no request at all.
 *
 * NORMALIZATION IS NOT REIMPLEMENTED HERE. PhoneNumberNormalizer is the
 * platform's single normalizer (ContactGroupController::addContacts(),
 * MetaLeadWebhookHandler and WhatsAppJourneyEngine::upsertLead() all
 * already use it), and Contact's own saving hook applies it again on the
 * way into the database. Both calls are required and neither is
 * redundant: this one produces the string we SEARCH by, the model's
 * produces the string that is STORED. normalize() is idempotent, so
 * applying it twice cannot drift.
 */
class ContactResolver
{
    /**
     * Find this account's contact for a phone number, or create it.
     *
     * Tenant safety: account_id is a required parameter, never read from
     * a request, and every query here is filtered by it. A caller cannot
     * reach another tenant's contact through this service even by
     * guessing a phone number — a phone that exists under Account B
     * simply produces a NEW contact under Account A, which is the
     * correct outcome (unique(account_id, phone_number) is per-account
     * on purpose: two tenants sharing a customer is normal).
     */
    public function resolve(int $accountId, string $phoneNumber, ?string $name = null, ?string $email = null): Contact
    {
        $normalized = PhoneNumberNormalizer::normalize($phoneNumber);

        /*
         * normalize() strips every non-digit, so "n/a" or "+++" reduce
         * to an empty string. Storing that would create a contact that
         * can never be messaged and would collide with every other
         * unusable number under the same account. Rejected as a
         * validation error with the same field key the request used, so
         * it renders as an ordinary 422 rather than a 500.
         */
        if ($normalized === '') {
            throw ValidationException::withMessages([
                'phone_number' => ['Enter a valid phone number.'],
            ]);
        }

        $existing = $this->find($accountId, $normalized);

        if ($existing) {
            return $this->fillMissingDetails($existing, $name, $email);
        }

        try {
            return Contact::create([
                'account_id' => $accountId,
                'phone_number' => $normalized,
                'name' => $this->cleanName($name),
                'email' => $this->cleanEmail($email),
            ]);
        } catch (QueryException $e) {
            /*
             * Two concurrent requests for the same new number both miss
             * the lookup above; one wins the unique(account_id,
             * phone_number) index and the other lands here. Re-reading
             * is correct and cheap — the winner's row is exactly the row
             * this call wanted. Rethrown if the failure was something
             * else, so a real schema/connection error is never
             * swallowed as a duplicate.
             */
            $contact = $this->find($accountId, $normalized);

            if (! $contact) {
                throw $e;
            }

            return $this->fillMissingDetails($contact, $name, $email);
        }
    }

    /**
     * Fill in a name and/or email only when the contact has none.
     *
     * Deliberately never overwrites an existing non-empty name. A name
     * arriving on a lead is whatever that source happened to capture —
     * a Meta form field, a journey variable, a hurried typist — while
     * the stored name may have been corrected by hand. Silently
     * replacing it would make every new lead a destructive write to
     * data the tenant curated. The brief asks for exactly this, and
     * nothing in this codebase establishes a last-write-wins convention
     * for contact names that would justify otherwise. An explicit
     * rename is a separate, intentional action — see
     * CrmLeadService::update() and the Contacts API.
     *
     * Phase 6 Hardening (Issue 3): email follows the identical rule, and
     * that is the ONLY way capture-lead email data ever reaches a
     * contact. The brief forbids a blanket backfill of Meta/Journey
     * emails; filling a blank field on a contact this very call is
     * resolving is a deliberate, disclosed part of the integration, and
     * it can never overwrite an address the tenant already has.
     */
    private function fillMissingDetails(Contact $contact, ?string $name, ?string $email = null): Contact
    {
        $dirty = false;

        $cleanName = $this->cleanName($name);

        if ($cleanName !== null && blank($contact->name)) {
            $contact->name = $cleanName;
            $dirty = true;
        }

        $cleanEmail = $this->cleanEmail($email);

        if ($cleanEmail !== null && blank($contact->email)) {
            $contact->email = $cleanEmail;
            $dirty = true;
        }

        if ($dirty) {
            $contact->save();
        }

        return $contact;
    }

    private function find(int $accountId, string $normalizedPhone): ?Contact
    {
        return Contact::query()
            ->forAccount($accountId)
            ->where('phone_number', $normalizedPhone)
            ->first();
    }

    /** A whitespace-only name is no name; it must not defeat the blank() check above. */
    private function cleanName(?string $name): ?string
    {
        $name = $name === null ? null : trim($name);

        return $name === '' ? null : $name;
    }

    /**
     * Trimmed only. Deliberately NOT lower-cased: nothing in this
     * codebase normalizes email casing (users.email, mail_logs
     * .recipient_email and leads.lead_email are all stored as supplied),
     * and inventing a CRM-only casing rule would make contacts.email
     * behave unlike every other email column here.
     */
    private function cleanEmail(?string $email): ?string
    {
        $email = $email === null ? null : trim($email);

        return $email === '' ? null : $email;
    }
}
