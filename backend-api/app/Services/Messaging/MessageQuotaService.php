<?php

namespace App\Services\Messaging;

use App\Models\Subscription;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5 Task 2 -- the one place message quota is moved.
 *
 * WHY THIS EXISTS (Phase 5 Task 1 audit, verbatim finding): five send
 * pipelines each hand-rolled their own quota handling, in two mutually
 * incompatible shapes --
 *
 *   reserve-ahead   GroupMessageDispatcher / GroupDirectMessageDispatcher:
 *                   lockForUpdate -> hasQuotaFor($n) -> increment($n),
 *                   all inside one transaction, BEFORE queueing.
 *   consume-after   TemplateMessageDispatcher / DirectMessageDispatcher /
 *                   ChatbotEngineService / WhatsAppJourneyEngine /
 *                   ProcessPaymentAlertJob: check, send, then
 *                   lockForUpdate -> increment(1).
 *
 * The consume-after shape has no re-check inside the lock, so two
 * concurrent sends can both pass the pre-send check and both increment
 * past the cap. The reserve-ahead shape does re-check, which is why it is
 * the correct one and why reserve() below is modelled on it.
 *
 * NOT a new billing model. Every limit decision still comes from
 * Subscription::hasQuotaFor() -> remainingQuota(), which is itself the
 * numeric form of computeStatus()'s own exhaustion condition. This class
 * adds locking and argument hygiene around that existing answer and
 * computes nothing about limits itself -- deliberately, so `unlimited`
 * plans, an unset total_allocated_messages, and expiry keep behaving
 * exactly as they already do.
 *
 * reserve() vs consume() is a real distinction, not two names for one
 * thing:
 *
 *   reserve()  is a GATE. It is called BEFORE the message goes out, it
 *              re-checks the cap inside the lock, and it REFUSES (false)
 *              when the cap cannot cover $amount. Nothing is incremented
 *              on a refusal.
 *   consume()  is ACCOUNTING for a send that has ALREADY happened. The
 *              message is on WhatsApp; refusing to record it would make
 *              the counter lie. So it never refuses on cap grounds -- it
 *              increments unconditionally, exactly as all five
 *              consume-after sites already do. It is still atomic
 *              (lockForUpdate), so concurrent sends cannot lose an
 *              increment to a read-modify-write race.
 *
 * refreshStatus() is called after every mutation so a subscription that
 * has just crossed its cap flips to 'exhausted' immediately -- matching
 * what every existing call site already does.
 *
 * The Subscription instance a caller passes in is deliberately NOT
 * refreshed from the locked row: no existing call site reads it after the
 * increment, and silently mutating a caller's model would be a behaviour
 * change this task does not want. Re-read it if you need the new value.
 */
class MessageQuotaService
{
    /**
     * Can this subscription cover $amount more messages right now?
     *
     * Advisory only -- NOT a reservation, and NOT locked. Two callers can
     * both get true for the same last credit. Use reserve() when the
     * answer has to hold.
     *
     * Returns false for a non-positive $amount: "may I send zero or minus
     * three messages" has no meaningful yes.
     */
    public function hasQuotaFor(Subscription $subscription, int $amount): bool
    {
        if (! $this->isValidAmount($amount)) {
            return false;
        }

        // Delegated, never recomputed -- see this class's docblock.
        return $subscription->hasQuotaFor($amount);
    }

    /**
     * Claim $amount of quota up front, atomically.
     *
     * The re-check happens INSIDE the same transaction and row lock as
     * the increment, so a concurrent reserve() for the same subscription
     * blocks until this one commits and then re-evaluates against the
     * already-incremented value. That is what makes overshoot impossible
     * here, and it is exactly what GroupMessageDispatcher has always
     * done.
     *
     * @return bool true when the quota was claimed; false when the amount
     *              was invalid, the subscription row no longer exists, or
     *              the cap cannot cover it. Nothing is written on false.
     */
    public function reserve(Subscription $subscription, int $amount): bool
    {
        if (! $this->isValidAmount($amount)) {
            return false;
        }

        return (bool) DB::transaction(function () use ($subscription, $amount) {
            $locked = $this->lock($subscription);

            if (! $locked) {
                return false;
            }

            // The re-check. Reading it from $locked (not from the caller's
            // possibly-stale instance) is the whole point.
            if (! $locked->hasQuotaFor($amount)) {
                return false;
            }

            $locked->increment('used_messages', $amount);
            $locked->refreshStatus();

            return true;
        });
    }

    /**
     * Record $amount of usage that has already occurred.
     *
     * Does NOT refuse on cap grounds -- see this class's docblock for
     * why. The only false results are an invalid amount or a
     * subscription row that has since been deleted.
     */
    public function consume(Subscription $subscription, int $amount): bool
    {
        if (! $this->isValidAmount($amount)) {
            return false;
        }

        return (bool) DB::transaction(function () use ($subscription, $amount) {
            $locked = $this->lock($subscription);

            if (! $locked) {
                return false;
            }

            $locked->increment('used_messages', $amount);
            $locked->refreshStatus();

            return true;
        });
    }

    /**
     * Give back quota previously claimed by reserve() -- e.g. a batch
     * that was reserved for N recipients but reached fewer.
     *
     * used_messages counts UP toward the cap, so "negative quota" means
     * used_messages dropping below zero. Floored at 0 rather than
     * decremented blindly: a double release, or a release larger than
     * what was ever reserved, must not manufacture credits the plan
     * never had. Computed inside the lock, so the floor cannot be raced.
     *
     * Deliberately void: there is no failure a caller can act on. An
     * invalid amount and a vanished subscription are both no-ops.
     *
     * No pipeline calls this yet (group reservations are still never
     * refunded -- a documented Task 1 finding). It exists now so Task 3
     * can fix that without also having to introduce the primitive.
     */
    public function release(Subscription $subscription, int $amount): void
    {
        if (! $this->isValidAmount($amount)) {
            return;
        }

        DB::transaction(function () use ($subscription, $amount) {
            $locked = $this->lock($subscription);

            if (! $locked) {
                return;
            }

            $released = max(0, (int) $locked->used_messages - $amount);

            // forceFill rather than decrement(): decrement() cannot express
            // the floor, and a plain decrement past zero is precisely the
            // invalid state this method must never create.
            $locked->forceFill(['used_messages' => $released])->save();

            // Releasing can take a subscription back UNDER its cap, so the
            // status must be recomputed here too -- 'exhausted' would
            // otherwise stick even though credits are available again.
            $locked->refreshStatus();
        });
    }

    /**
     * A quota movement is always a positive whole number of messages.
     * Zero is rejected rather than treated as a silent no-op so a caller
     * that computed its batch size wrongly finds out.
     */
    private function isValidAmount(int $amount): bool
    {
        return $amount > 0;
    }

    /**
     * Re-read the row under a write lock. Returns null when the
     * subscription has been deleted since the caller loaded it -- every
     * public method above treats that as "nothing to move", never as an
     * error worth throwing into a send path.
     */
    private function lock(Subscription $subscription): ?Subscription
    {
        return $subscription->newQuery()->lockForUpdate()->find($subscription->id);
    }
}
