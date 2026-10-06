<?php

namespace App\Services\Billing;

use App\Models\Account;
use App\Models\InAppNotification;
use App\Models\Invoice;
use App\Models\ModuleAddonOffer;
use App\Models\InvoiceLineItem;
use App\Models\ManualPayment;
use App\Models\ModuleAddonRequest;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppAddonService;
use App\Services\WhatsApp\WhatsAppNumberException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Paid module add-ons (for example Custom Contact Groups).
 *
 *  - request():  a client asks for a module its plan does not include.
 *  - approve():  a Super Admin, or an agent for its own client, creates the invoice.
 *  - recordManualPayment() / activateOnlinePayment(): the invoice is paid; the module
 *    is switched on for one term from the payment.
 *  - enforce():  when a term ends, the module is switched off (unless another paid
 *    term for the same module is still running).
 *
 * The amount, date and transaction rules are the same ones the WhatsApp add-ons use.
 */
class ModuleAddonService
{
    public function __construct(private readonly WhatsAppAddonService $addons)
    {
    }

    /**
     * The current offer for a module, from the editable offers table. Refused when the
     * module has no offer or the offer is switched off.
     *
     * @return array{label: string, price: float, term_months: int, units_included: int}
     */
    public function offer(string $module): array
    {
        $row = ModuleAddonOffer::query()->where('module', $module)->where('is_active', true)->first();

        if ($row === null) {
            throw new WhatsAppNumberException('This module is not available as an add-on.', 'unknown_module', 404);
        }

        return [
            'label' => (string) $row->label,
            'price' => (float) $row->price,
            'term_months' => (int) $row->term_months,
            'units_included' => (int) $row->units_included,
        ];
    }

    /** Every offer on sale, for the client and the Super Admin screens. */
    public function offers(): \Illuminate\Support\Collection
    {
        return ModuleAddonOffer::query()->where('is_active', true)->orderBy('id')->get();
    }

    public function request(Account $account, User $actor, string $module, ?string $reason): ModuleAddonRequest
    {
        $this->offer($module);

        if ($account->hasModuleEnabled($module)) {
            throw new WhatsAppNumberException('This module is already included in your account.', 'already_enabled', 422);
        }

        $open = ModuleAddonRequest::query()
            ->where('account_id', $account->id)
            ->where('module', $module)
            ->whereIn('status', [ModuleAddonRequest::REQUESTED, ModuleAddonRequest::INVOICED])
            ->exists();

        if ($open) {
            throw new WhatsAppNumberException('A request for this module is already waiting for approval or payment.', 'request_pending', 409);
        }

        return ModuleAddonRequest::query()->create([
            'account_id' => $account->id,
            'module' => $module,
            'status' => ModuleAddonRequest::REQUESTED,
            'reason' => $reason,
            'requested_by_user_id' => $actor->id,
        ]);
    }

    /** Requests the actor may act on: all for a Super Admin, the agent's own clients for an Agent. */
    public function pendingFor(User $actor): \Illuminate\Support\Collection
    {
        $query = ModuleAddonRequest::query()
            ->with('account:id,company_name,agent_id')
            ->whereIn('status', [ModuleAddonRequest::REQUESTED, ModuleAddonRequest::INVOICED])
            ->orderByDesc('created_at');

        if ($actor->hasRole('super_admin')) {
            return $query->get();
        }

        if (! $actor->hasRole('agent')) {
            throw new WhatsAppNumberException('Only a Super Admin or an Agent can see these requests.', 'not_allowed', 403);
        }

        return $query->whereHas('account', fn ($q) => $q->where('agent_id', $actor->account_id))->get();
    }

    public function approve(ModuleAddonRequest $request, User $actor): ModuleAddonRequest
    {
        $account = $this->accountOf($request);
        $this->assertCanManage($account, $actor);

        if ($request->status !== ModuleAddonRequest::REQUESTED) {
            throw new WhatsAppNumberException('Only a new request can be approved.', 'not_requested', 409);
        }

        return $this->issueInvoice($request, $account, $actor->id);
    }

    /** Creates the invoice for a request that is due one: an approval, or a renewal when a term ends. */
    public function issueInvoice(ModuleAddonRequest $request, Account $account, ?int $decidedBy): ModuleAddonRequest
    {
        $offer = $this->offer($request->module);

        return DB::transaction(function () use ($request, $account, $decidedBy, $offer): ModuleAddonRequest {
            $total = round($offer['price'], 2);

            $invoice = Invoice::query()->create([
                'account_id' => $account->id,
                'invoice_number' => sprintf('INV-%d-%s-%s', $account->id, now()->format('Ymd'), strtoupper(bin2hex(random_bytes(3)))),
                'plan_key' => $this->planKey($request->module),
                'plan_label' => $offer['label'],
                // The total includes GST. Invoices show only this figure.
                'amount' => $total,
                'tax_amount' => 0,
                'total_amount' => $total,
                'currency' => 'INR',
                'payment_gateway' => 'manual',
                'status' => 'pending',
            ]);

            InvoiceLineItem::query()->create([
                'invoice_id' => $invoice->id,
                'description' => $offer['label']
                    .($offer['units_included'] > 0 ? ' — includes '.$offer['units_included'].' units' : '')
                    .' ('.$offer['term_months'].' month'.($offer['term_months'] === 1 ? '' : 's').')',
                'quantity' => 1,
                'unit_amount' => $total,
                'amount' => $total,
            ]);

            $request->forceFill([
                'status' => ModuleAddonRequest::INVOICED,
                'invoice_id' => $invoice->id,
                'decided_by_user_id' => $decidedBy,
            ])->save();

            $this->notifyRequester($request, 'Invoice ready: '.$offer['label'], 'Your invoice '.$invoice->invoice_number.' for ₹'.number_format($total, 2).' (GST included) is ready. Pay it on the Billing page.');

            return $request->refresh();
        });
    }

    public function reject(ModuleAddonRequest $request, User $actor, ?string $note): ModuleAddonRequest
    {
        $this->assertCanManage($this->accountOf($request), $actor);

        if ($request->status !== ModuleAddonRequest::REQUESTED) {
            throw new WhatsAppNumberException('Only a new request can be rejected.', 'not_requested', 409);
        }

        $request->forceFill([
            'status' => ModuleAddonRequest::REJECTED,
            'decided_by_user_id' => $actor->id,
            'decision_note' => $note,
        ])->save();

        $this->notifyRequester($request, 'Request not approved', 'Your request for '.$this->label($request->module).' was not approved.'.($note ? ' Note: '.$note : ''));

        return $request->refresh();
    }

    /** Records a cash or manual payment for the request's invoice. Same details and rules as the WhatsApp add-ons. */
    public function recordManualPayment(ModuleAddonRequest $request, User $actor, array $details): ModuleAddonRequest
    {
        $account = $this->accountOf($request);
        $role = $this->assertCanManage($account, $actor);

        $invoice = $request->invoice;
        if ($invoice === null || $request->status !== ModuleAddonRequest::INVOICED) {
            throw new WhatsAppNumberException('Approve the request before recording its payment.', 'not_invoiced', 409);
        }

        $details = $this->addons->validatedDetails($invoice, $details);

        return DB::transaction(function () use ($request, $invoice, $actor, $role, $details): ModuleAddonRequest {
            $locked = Invoice::query()->lockForUpdate()->whereKey($invoice->id)->firstOrFail();

            if ($locked->status !== 'pending') {
                throw new WhatsAppNumberException('This invoice is already '.$locked->status.'.', 'invoice_not_pending', 409);
            }

            if (ManualPayment::query()->where('transaction_id', $details['transaction_id'])->exists()) {
                throw new WhatsAppNumberException('This transaction ID is already recorded.', 'transaction_already_recorded', 409);
            }

            ManualPayment::query()->create([
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

            return $this->activate($request, Carbon::parse($details['term_starts_on'])->startOfDay());
        });
    }

    /** Activation after an ONLINE payment confirmed by the gateway. Called inside the payment transaction. */
    public function activateOnlinePayment(Invoice $invoice, string $paymentId): void
    {
        $request = ModuleAddonRequest::query()->where('invoice_id', $invoice->id)->first();

        $invoice->forceFill([
            'status' => 'paid',
            'paid_at' => now(),
            'gateway_payment_id' => $paymentId,
        ])->save();

        if ($request !== null) {
            $this->activate($request, now()->startOfDay());
        }
    }

    /** Switches the module off for every paid request whose term has ended. Returns how many. */
    public function enforce(): int
    {
        $ended = ModuleAddonRequest::query()
            ->where('status', ModuleAddonRequest::PAID)
            ->where('term_ends_at', '<', now())
            ->get();

        foreach ($ended as $request) {
            $request->forceFill(['status' => ModuleAddonRequest::EXPIRED])->save();

            $stillRunningNow = ModuleAddonRequest::query()
                ->where('account_id', $request->account_id)
                ->where('module', $request->module)
                ->where('status', ModuleAddonRequest::PAID)
                ->where('term_ends_at', '>', now())
                ->exists();

            $account = Account::query()->find($request->account_id);
            if ($account !== null && ! $stillRunningNow) {
                // Renewal: a new invoice at the current price. The module comes back when it is paid.
                $renewal = ModuleAddonRequest::query()->create([
                    'account_id' => $request->account_id,
                    'module' => $request->module,
                    'status' => ModuleAddonRequest::REQUESTED,
                    'reason' => 'Renewal of an ended term',
                    'requested_by_user_id' => $request->requested_by_user_id,
                ]);
                $this->issueInvoice($renewal, $account, null);
                $this->notifyRequester($request, $this->label($request->module).' has ended', 'Your term for '.$this->label($request->module).' has ended. Your renewal invoice is ready. Pay it on the Billing page to continue.');
            } else {
                $this->notifyRequester($request, $this->label($request->module).' has ended', 'Your term for '.$this->label($request->module).' has ended.');
            }

            // Keep the module on if another paid term for it is still running.
            $stillRunning = ModuleAddonRequest::query()
                ->where('account_id', $request->account_id)
                ->where('module', $request->module)
                ->where('status', ModuleAddonRequest::PAID)
                ->where('term_ends_at', '>', now())
                ->exists();

            if (! $stillRunning) {
                $account = Account::query()->find($request->account_id);
                if ($account !== null) {
                    $modules = array_values(array_diff((array) $account->allowed_modules, [$request->module]));
                    $account->forceFill(['allowed_modules' => $modules])->save();
                }
            }
        }

        return $ended->count();
    }

    private function activate(ModuleAddonRequest $request, Carbon $termStart): ModuleAddonRequest
    {
        $offer = $this->offer($request->module);
        $account = $this->accountOf($request);

        $modules = array_values(array_unique(array_merge((array) $account->allowed_modules, [$request->module])));
        $account->forceFill(['allowed_modules' => $modules])->save();

        $request->forceFill([
            'status' => ModuleAddonRequest::PAID,
            'term_starts_at' => $termStart,
            'term_ends_at' => $termStart->copy()->addMonths($offer['term_months']),
        ])->save();

        $this->notifyRequester($request, $offer['label'].' is active', $offer['label'].' is active until '.$request->term_ends_at->toDateString().'.');

        return $request->refresh();
    }

    /**
     * Same rule as the WhatsApp add-ons: a Super Admin acts on any account; an Agent only
     * on a client it created; a self-signup account only through a Super Admin.
     *
     * @return 'super_admin'|'agent'
     */
    private function assertCanManage(Account $account, User $actor): string
    {
        if ($actor->hasRole('super_admin')) {
            return 'super_admin';
        }

        if (! $actor->hasRole('agent')) {
            throw new WhatsAppNumberException('Only a Super Admin or an Agent can manage this request.', 'not_allowed', 403);
        }

        if ($account->agent_id === null) {
            throw new WhatsAppNumberException('This account signed up directly. Only a Super Admin can approve it.', 'self_signup_needs_super_admin', 403);
        }

        if ((int) $account->agent_id !== (int) $actor->account_id) {
            throw new WhatsAppNumberException('This account is not one of your clients.', 'not_your_client', 403);
        }

        return 'agent';
    }

    /** Tells the person who asked (or the account's first user if that person is gone). */
    private function notifyRequester(ModuleAddonRequest $request, string $title, string $body): void
    {
        $userId = $request->requested_by_user_id
            ?? User::query()->where('account_id', $request->account_id)->value('id');

        if ($userId === null) {
            return;
        }

        InAppNotification::query()->create([
            'user_id' => $userId,
            'title' => $title,
            'body' => $body,
            'category' => 'billing',
            'link' => '/billing',
            'is_read' => false,
        ]);
    }

    /**
     * The number of groups an account may have while a paid term of the module is running
     * (the offer's units_included). Null when no paid term is running, so no add-on limit applies.
     */
    public function activeUnitLimit(Account $account, string $module): ?int
    {
        $running = ModuleAddonRequest::query()
            ->where('account_id', $account->id)
            ->where('module', $module)
            ->where('status', ModuleAddonRequest::PAID)
            ->where('term_ends_at', '>', now())
            ->exists();

        if (! $running) {
            return null;
        }

        return (int) (ModuleAddonOffer::query()->where('module', $module)->value('units_included') ?? 0);
    }

    private function label(string $module): string
    {
        return (string) (ModuleAddonOffer::query()->where('module', $module)->value('label') ?? $module);
    }

    private function accountOf(ModuleAddonRequest $request): Account
    {
        $account = Account::query()->find($request->account_id);

        if ($account === null) {
            throw new WhatsAppNumberException('Account not found.', 'not_found', 404);
        }

        return $account;
    }

    /** The invoice's plan key. Module invoices are recognised by this prefix. */
    public function planKey(string $module): string
    {
        return 'module_addon:'.$module;
    }

    public function isModuleInvoice(Invoice $invoice): bool
    {
        return str_starts_with((string) $invoice->plan_key, 'module_addon:');
    }
}
