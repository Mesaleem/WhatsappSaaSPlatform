<?php

namespace App\Services\WhatsApp;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\InvoiceLineItem;
use App\Models\ManualPayment;
use App\Models\User;
use App\Models\WhatsAppNumber;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Paid extra WhatsApp numbers (agreed 2026-10-05).
 *
 *  - purchase(): one invoice for the chosen numbers, one line per number, a GST-
 *    inclusive total. The numbers are reserved as 'pending_payment' until paid.
 *  - recordManualPayment(): marks the invoice paid (Super Admin or Agent, while no
 *    gateway is configured). Each number then gets a one-month term from payment,
 *    independent of the plan's expiry, and is 'unlinked' so it can be connected.
 *  - enforceTerms(): pauses an add-on whose term has ended, and pauses the included
 *    number while the account has no active subscription. Nothing is removed.
 *
 * A purchase can be bought in the middle of a plan. It never changes the included
 * number or the default.
 */
class WhatsAppAddonService
{
    public function __construct(private readonly WhatsAppNumberService $numbers)
    {
    }

    public function price(): float
    {
        return (float) config('whatsapp_numbers.addon_price');
    }

    /**
     * @param  array<int, string>  $rawPhones
     */
    public function purchase(Account $account, array $rawPhones): Invoice
    {
        $max = (int) config('whatsapp_numbers.max_addons_per_purchase');

        $phones = [];
        foreach ($rawPhones as $raw) {
            $phones[] = $this->numbers->normalize((string) $raw);
        }
        $phones = array_values(array_unique($phones));

        if ($phones === []) {
            throw new WhatsAppNumberException('Choose at least one extra number.', 'no_numbers');
        }
        if (count($phones) > $max) {
            throw new WhatsAppNumberException("You can add at most {$max} extra numbers in one purchase.", 'too_many_numbers');
        }
        if (! WhatsAppNumber::query()->where('account_id', $account->id)->where('is_included', true)->exists()) {
            throw new WhatsAppNumberException('Your plan number must be set up before you add extra numbers.', 'no_included_number');
        }

        $unit = $this->price();
        $total = round($unit * count($phones), 2);

        try {
            return DB::transaction(function () use ($account, $phones, $unit, $total): Invoice {
                $invoice = Invoice::query()->create([
                    'account_id' => $account->id,
                    'invoice_number' => $this->invoiceNumber($account->id),
                    'plan_key' => (string) config('whatsapp_numbers.addon_plan_key'),
                    'plan_label' => 'WhatsApp extra number'.(count($phones) > 1 ? 's ('.count($phones).')' : ''),
                    // The total includes GST. Invoices show only this figure.
                    'amount' => $total,
                    'tax_amount' => 0,
                    'total_amount' => $total,
                    'currency' => 'INR',
                    'payment_gateway' => 'manual',
                    'status' => 'pending',
                ]);

                foreach ($phones as $phone) {
                    InvoiceLineItem::query()->create([
                        'invoice_id' => $invoice->id,
                        'description' => 'Extra WhatsApp number +'.$phone,
                        'quantity' => 1,
                        'unit_amount' => $unit,
                        'amount' => $unit,
                    ]);

                    WhatsAppNumber::query()->create([
                        'account_id' => $account->id,
                        'phone_number' => $phone,
                        'is_included' => false,
                        'is_default' => false,
                        'status' => WhatsAppNumber::STATUS_PENDING_PAYMENT,
                        'addon_invoice_id' => $invoice->id,
                    ]);
                }

                return $invoice->load('lineItems');
            });
        } catch (QueryException $e) {
            // The unique phone number is the authority: a number taken by another slot
            // (including a reservation) is refused, without naming who holds it.
            if (str_contains((string) $e->getMessage(), 'UNIQUE') || str_contains((string) $e->getMessage(), 'Duplicate entry')) {
                throw new WhatsAppNumberException(
                    'One of these WhatsApp numbers is already added. Each number can be used by only one WhatsApp slot.',
                    'number_already_used',
                    409,
                );
            }
            throw $e;
        }
    }

    /** Cancels an unpaid add-on purchase and releases its reserved numbers. */
    public function cancelPending(Account $account, int $invoiceId): void
    {
        $invoice = $this->findAddonInvoice($account, $invoiceId);

        if ($invoice->status !== 'pending') {
            throw new WhatsAppNumberException('Only an unpaid purchase can be cancelled.', 'invoice_not_pending', 409);
        }

        DB::transaction(function () use ($invoice): void {
            WhatsAppNumber::query()->where('addon_invoice_id', $invoice->id)->where('status', WhatsAppNumber::STATUS_PENDING_PAYMENT)->delete();
            $invoice->forceFill(['status' => 'failed'])->save();
        });
    }

    /**
     * Records a cash or manual payment for an add-on invoice. Idempotent: a second
     * call on a paid invoice is refused, so a double click cannot start two terms.
     */
    public function recordManualPayment(Invoice $invoice, User $actor, array $details): Invoice
    {
        if (! $this->isAddonInvoice($invoice)) {
            throw new WhatsAppNumberException('This invoice is not a WhatsApp add-on invoice.', 'not_addon_invoice', 422);
        }

        $role = $this->assertCanRecord($invoice, $actor);
        $details = $this->validatedDetails($invoice, $details);

        return DB::transaction(function () use ($invoice, $actor, $role, $details): Invoice {
            $locked = Invoice::query()->lockForUpdate()->whereKey($invoice->id)->firstOrFail();

            if ($locked->status !== 'pending') {
                throw new WhatsAppNumberException('This invoice is already '.$locked->status.'.', 'invoice_not_pending', 409);
            }

            if (ManualPayment::query()->where('transaction_id', $details['transaction_id'])->exists()) {
                throw new WhatsAppNumberException('This transaction ID is already recorded.', 'transaction_already_recorded', 409);
            }

            // The paid term starts on the chosen date (default: the date paid).
            $termStart = Carbon::parse($details['term_starts_on'])->startOfDay();

            $payment = ManualPayment::query()->create([
                'invoice_id' => $locked->id,
                'account_id' => $locked->account_id,
                'amount' => $details['amount'],
                'method' => $details['method'],
                'transaction_id' => $details['transaction_id'],
                'paid_on' => $details['paid_on'],
                'term_starts_on' => $details['term_starts_on'],
                'recorded_by_user_id' => $actor->id,
                'recorded_by_role' => $role,
                'note' => $details['note'],
            ]);

            $locked->forceFill([
                'status' => 'paid',
                'paid_at' => Carbon::parse($details['paid_on'])->startOfDay(),
                'gateway_payment_id' => $details['transaction_id'],
            ])->save();

            $this->activateSlots($locked, $termStart);

            Log::info('WhatsApp add-on paid manually', [
                'invoice_id' => $locked->id,
                'manual_payment_id' => $payment->id,
                'account_id' => $locked->account_id,
                'recorded_by' => $actor->id,
                'role' => $role,
            ]);

            return $locked;
        });
    }

    /**
     * Who may record a payment for this invoice's account:
     *  - a Super Admin: any account (including an agent's client when the agent does not respond);
     *  - an Agent: only a client the Agent created (accounts.agent_id is the Agent's account);
     *  - anyone else, and any self-signup account (no agent): refused for an Agent,
     *    so only a Super Admin can approve a self-signup payment.
     *
     * @return 'super_admin'|'agent'
     */
    public function assertCanRecord(Invoice $invoice, User $actor): string
    {
        if ($actor->hasRole('super_admin')) {
            return 'super_admin';
        }

        if (! $actor->hasRole('agent')) {
            throw new WhatsAppNumberException('Only a Super Admin or an Agent can record a payment.', 'not_allowed', 403);
        }

        $account = Account::query()->find($invoice->account_id);

        if ($account === null || $account->agent_id === null) {
            throw new WhatsAppNumberException(
                'This account signed up directly. Only a Super Admin can approve its payment.',
                'self_signup_needs_super_admin',
                403,
            );
        }

        if ((int) $account->agent_id !== (int) $actor->account_id) {
            throw new WhatsAppNumberException('This account is not one of your clients.', 'not_your_client', 403);
        }

        return 'agent';
    }

    /**
     * Full details are required and checked against the invoice: the amount must
     * equal the invoice total exactly, the method must be known, the transaction ID is
     * required, the date paid cannot be in the future, and the term start may be moved
     * by up to 30 days either way (default: the date paid).
     *
     * @return array{amount: float, method: string, transaction_id: string, paid_on: string, term_starts_on: string, note: ?string}
     */
    public function validatedDetails(Invoice $invoice, array $details): array
    {
        $amount = round((float) ($details['amount'] ?? 0), 2);
        $method = (string) ($details['method'] ?? '');
        $transactionId = trim((string) ($details['transaction_id'] ?? ''));
        $paidOn = Carbon::parse((string) ($details['paid_on'] ?? now()->toDateString()))->startOfDay();
        $termStart = isset($details['term_starts_on']) && $details['term_starts_on'] !== ''
            ? Carbon::parse((string) $details['term_starts_on'])->startOfDay()
            : $paidOn->copy();

        if ($transactionId === '') {
            throw new WhatsAppNumberException('The transaction ID or receipt number is required.', 'transaction_id_required');
        }
        if (! in_array($method, ManualPayment::METHODS, true)) {
            throw new WhatsAppNumberException('Choose how the payment was made.', 'invalid_method');
        }
        if (abs($amount - round((float) $invoice->total_amount, 2)) > 0.001) {
            throw new WhatsAppNumberException(
                'The amount must equal the invoice total of ₹'.number_format((float) $invoice->total_amount, 2).'.',
                'amount_mismatch',
            );
        }
        if ($paidOn->isFuture()) {
            throw new WhatsAppNumberException('The date paid cannot be in the future.', 'paid_on_in_future');
        }
        if (abs($termStart->diffInDays($paidOn, false)) > 30) {
            throw new WhatsAppNumberException('The term start can be moved by at most 30 days from the date paid.', 'term_start_out_of_range');
        }

        return [
            'amount' => $amount,
            'method' => $method,
            'transaction_id' => $transactionId,
            'paid_on' => $paidOn->toDateString(),
            'term_starts_on' => $termStart->toDateString(),
            'note' => isset($details['note']) && $details['note'] !== '' ? (string) $details['note'] : null,
        ];
    }

    /**
     * Applies the term rules. Returns how many numbers were paused.
     * Pausing a number also logs its session out of the engine, so a paused number
     * does not keep sending.
     */
    public function enforceTerms(): int
    {
        $now = now();
        $paused = 0;

        // An add-on whose one-month term has ended.
        WhatsAppNumber::query()
            ->where('is_included', false)
            ->whereNotNull('term_ends_at')
            ->where('term_ends_at', '<', $now)
            ->where('status', '!=', WhatsAppNumber::STATUS_PAUSED)
            ->get()
            ->each(function (WhatsAppNumber $slot) use (&$paused): void {
                $this->pause($slot);
                $paused++;
            });

        // The included number follows the plan: paused while the subscription is not active.
        WhatsAppNumber::query()
            ->where('is_included', true)
            ->where('status', '!=', WhatsAppNumber::STATUS_PAUSED)
            ->get()
            ->each(function (WhatsAppNumber $slot) use (&$paused): void {
                $account = Account::query()->find($slot->account_id);
                if ($account !== null && ! $account->hasActiveSubscription()) {
                    $this->pause($slot);
                    $paused++;
                }
            });

        return $paused;
    }

    private function pause(WhatsAppNumber $slot): void
    {
        $slot->forceFill(['status' => WhatsAppNumber::STATUS_PAUSED])->save();
        $this->disconnectEngine($slot);
    }

    private function disconnectEngine(WhatsAppNumber $slot): void
    {
        $baseUrl = rtrim((string) config('services.qr_engine.url'), '/');

        try {
            Http::withHeaders(['X-Internal-Secret' => config('services.qr_engine.internal_secret')])
                ->connectTimeout(5)
                ->timeout(10)
                ->post("{$baseUrl}/api/qr/logout", ['account_id' => $slot->account_id, 'session_id' => $slot->id]);
        } catch (\Throwable $e) {
            // The status is already paused here; a missed logout is logged and the
            // engine's own reconnect rules still apply.
            Log::warning('WhatsApp pause: engine logout failed', ['number_id' => $slot->id, 'error' => $e->getMessage()]);
        }
    }

    private function findAddonInvoice(Account $account, int $invoiceId): Invoice
    {
        $invoice = Invoice::query()->where('account_id', $account->id)->whereKey($invoiceId)->first();

        if ($invoice === null || ! $this->isAddonInvoice($invoice)) {
            throw new WhatsAppNumberException('Invoice not found.', 'not_found', 404);
        }

        return $invoice;
    }

    /**
     * Pending add-on invoices the actor may act on: a Super Admin sees every account;
     * an Agent sees only clients it created (accounts.agent_id). Anyone else is refused.
     *
     * @return \Illuminate\Support\Collection<int, Invoice>
     */
    public function pendingInvoicesFor(User $actor): \Illuminate\Support\Collection
    {
        $query = Invoice::query()
            ->with('account:id,company_name,agent_id')
            ->where('plan_key', (string) config('whatsapp_numbers.addon_plan_key'))
            ->where('status', 'pending')
            ->orderByDesc('created_at');

        if ($actor->hasRole('super_admin')) {
            return $query->get();
        }

        if (! $actor->hasRole('agent')) {
            throw new WhatsAppNumberException('Only a Super Admin or an Agent can see pending invoices.', 'not_allowed', 403);
        }

        return $query->whereHas('account', fn ($q) => $q->where('agent_id', $actor->account_id))->get();
    }

    /**
     * An add-on invoice is recognised by its plan key only. Its payment method can
     * change: created as manual, then paid online, or the other way round.
     */
    public function isAddonInvoice(Invoice $invoice): bool
    {
        return $invoice->plan_key === (string) config('whatsapp_numbers.addon_plan_key');
    }

    /**
     * An add-on invoice that the customer can still pay online: pending, and not
     * already paid, cancelled, or failed. Returns the invoice or throws.
     */
    public function payableInvoice(Account $account, int $invoiceId): Invoice
    {
        $invoice = $this->findAddonInvoice($account, $invoiceId);

        if ($invoice->status !== 'pending') {
            throw new WhatsAppNumberException('This invoice is already '.$invoice->status.'.', 'invoice_not_pending', 409);
        }

        return $invoice;
    }

    /**
     * Activation after an ONLINE payment confirmed by the gateway (verify or webhook).
     * Called from InvoiceCreditService::markPaidAndCreditQuota inside its transaction,
     * which already holds the invoice lock, so a payment can be applied only once.
     */
    public function activateOnlinePayment(Invoice $invoice, string $paymentId): void
    {
        $paidAt = now();

        $invoice->forceFill([
            'status' => 'paid',
            'paid_at' => $paidAt,
            'gateway_payment_id' => $paymentId,
        ])->save();

        $this->activateSlots($invoice, $paidAt->copy()->startOfDay());

        Log::info('WhatsApp add-on paid online', ['invoice_id' => $invoice->id, 'account_id' => $invoice->account_id]);
    }

    /**
     * The numbers of a paid invoice become 'unlinked' (ready to connect), locked, and
     * get their one-month term from $termStart.
     */
    private function activateSlots(Invoice $invoice, Carbon $termStart): void
    {
        $termEnd = $termStart->copy()->addMonths((int) config('whatsapp_numbers.addon_term_months'));

        WhatsAppNumber::query()
            ->where('addon_invoice_id', $invoice->id)
            ->where('status', WhatsAppNumber::STATUS_PENDING_PAYMENT)
            ->update([
                'status' => WhatsAppNumber::STATUS_UNLINKED,
                'locked_at' => now(),
                'term_ends_at' => $termEnd,
            ]);
    }

    private function invoiceNumber(int $accountId): string
    {
        return sprintf('INV-%d-%s-%s', $accountId, now()->format('Ymd'), strtoupper(bin2hex(random_bytes(3))));
    }
}
