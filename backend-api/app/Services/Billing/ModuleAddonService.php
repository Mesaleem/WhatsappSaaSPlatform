<?php

namespace App\Services\Billing;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\InAppNotification;
use App\Models\Invoice;
use App\Models\ModuleAddonOffer;
use App\Models\ModuleAddonTier;
use App\Models\InvoiceLineItem;
use App\Models\ManualPayment;
use App\Models\ModuleAddonRequest;
use App\Models\User;
use App\Models\WhatsAppNumber;
use App\Services\Access\AccessControlService;
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
    /** The reason a renewal invoice is created with, so a new choice can replace an unpaid one. */
    public const RENEWAL_REASON = 'Renewal of an ended term';

    /** The entitlement source written for a capability granted by a paid add-on. */
    public const ADDON_SOURCE = 'addon:module_addon';

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

    public function request(Account $account, User $actor, string $module, ?string $reason, ?int $units = null): ModuleAddonRequest
    {
        $this->offer($module);

        if ($this->isFree($module)) {
            throw new WhatsAppNumberException('This is currently free -- nothing to request.', 'module_is_free', 422);
        }

        // Add-ons are bought while the plan is active. A lapsed plan renews first.
        if (! $account->hasActiveSubscription()) {
            throw new WhatsAppNumberException('Your plan is not active. Renew it first, then you can add this.', 'plan_not_active', 422);
        }

        // A tiered offer needs the units; the tier that holds them must exist.
        if ($this->tiers($module)->isNotEmpty()) {
            if ($units === null || $units < 1) {
                throw new WhatsAppNumberException('Tell us how many units you need.', 'units_required', 422);
            }
            $this->priceFor($module, $units);
        }

        // A tiered module with a term running: a bigger tier is an upgrade (allowed at any time); a
        // smaller or equal one waits for the running term to end. Otherwise the module is already on.
        $runningUnits = $units !== null && $this->hasTiers($module)
            ? ModuleAddonRequest::query()
                ->where('account_id', $account->id)
                ->where('module', $module)
                ->where('status', ModuleAddonRequest::PAID)
                ->where('term_ends_at', '>', now())
                ->max('units')
            : null;

        if ($runningUnits !== null) {
            if ($units <= (int) $runningUnits) {
                throw new WhatsAppNumberException(
                    "Your current term includes {$runningUnits} groups. A smaller plan can start when this term ends.",
                    'downgrade_after_term',
                    422,
                );
            }
        } elseif ($this->alreadyIncluded($account, $module)) {
            throw new WhatsAppNumberException('This module is already included in your account.', 'already_enabled', 422);
        }

        $open = ModuleAddonRequest::query()
            ->where('account_id', $account->id)
            ->where('module', $module)
            ->whereIn('status', [ModuleAddonRequest::REQUESTED, ModuleAddonRequest::INVOICED])
            ->get();

        // An unpaid renewal is replaced by the client's new choice (for example a smaller tier). Any
        // other open request still blocks: it is waiting for approval or payment.
        if ($open->isNotEmpty() && $open->every(fn (ModuleAddonRequest $r) => $r->reason === self::RENEWAL_REASON)) {
            foreach ($open as $renewal) {
                if ($renewal->invoice_id !== null) {
                    Invoice::query()->whereKey($renewal->invoice_id)->where('status', 'pending')->update(['status' => 'failed']);
                }
                $renewal->forceFill(['status' => ModuleAddonRequest::REJECTED, 'decision_note' => 'Replaced by a new choice.'])->save();
            }
            $open = collect();
        }

        if ($open->isNotEmpty()) {
            throw new WhatsAppNumberException('A request for this module is already waiting for approval or payment.', 'request_pending', 409);
        }

        return ModuleAddonRequest::query()->create([
            'account_id' => $account->id,
            'module' => $module,
            'status' => ModuleAddonRequest::REQUESTED,
            'reason' => $reason,
            'requested_by_user_id' => $actor->id,
            'units' => $units,
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

    /**
     * The history the caller may see, newest first: every add-on request (requested, approved,
     * paid, rejected, term) and every paid WhatsApp number purchase. A Super Admin sees all
     * clients, an agent its own clients, and any other user only its own account, whatever
     * account it names. $account narrows the result to one client.
     *
     * @return array{requests: array<int, array<string, mixed>>, number_purchases: array<int, array<string, mixed>>}
     */
    public function historyFor(User $actor, ?Account $account = null): array
    {
        $visible = $this->visibleAccountIds($actor);

        if ($account !== null) {
            if ($visible !== null && ! in_array($account->id, $visible, true)) {
                throw new WhatsAppNumberException('Client not found.', 'not_found', 404);
            }
            $visible = [$account->id];
        }

        $offerLabels = ModuleAddonOffer::query()->pluck('label', 'module');

        $requests = ModuleAddonRequest::query()
            ->with(['account:id,company_name', 'invoice:id,invoice_number,total_amount,paid_at'])
            ->when($visible !== null, fn ($q) => $q->whereIn('account_id', $visible))
            ->orderByDesc('id')
            ->get()
            ->map(fn (ModuleAddonRequest $r) => [
                'id' => $r->id,
                'client' => $r->account?->company_name,
                'module' => $r->module,
                'label' => $offerLabels[$r->module] ?? $r->module,
                'status' => $r->status,
                'units' => $r->units,
                'reason' => $r->reason,
                'decision_note' => $r->decision_note,
                'requested_at' => $r->created_at?->toIso8601String(),
                'decided_at' => $r->decided_at?->toIso8601String(),
                'paid_at' => $r->invoice?->paid_at?->toIso8601String(),
                'term_starts_at' => $r->term_starts_at?->toIso8601String(),
                'term_ends_at' => $r->term_ends_at?->toIso8601String(),
                'invoice_number' => $r->invoice?->invoice_number,
                'total_amount' => $r->invoice ? (float) $r->invoice->total_amount : null,
            ])
            ->values()
            ->all();

        // One WhatsApp add-on purchase = one invoice with one line per number bought.
        $numbers = WhatsAppNumber::query()
            ->with('account:id,company_name')
            ->where('is_included', false)
            ->whereNotNull('addon_invoice_id')
            ->when($visible !== null, fn ($q) => $q->whereIn('account_id', $visible))
            ->orderByDesc('id')
            ->get();

        $invoices = Invoice::query()
            ->whereIn('id', $numbers->pluck('addon_invoice_id')->unique())
            ->get(['id', 'invoice_number', 'status', 'total_amount', 'created_at', 'paid_at'])
            ->keyBy('id');

        $purchases = $numbers->groupBy('addon_invoice_id')->map(function ($group, $invoiceId) use ($invoices) {
            $invoice = $invoices->get((int) $invoiceId);
            $first = $group->first();
            $termEnds = $group->pluck('term_ends_at')->filter()->map(fn ($d) => $d->timestamp)->max();

            return [
                'invoice_id' => $invoice?->id,
                'account_id' => $first->account_id,
                'invoice_number' => $invoice?->invoice_number,
                'client' => $first->account?->company_name,
                'number_count' => $group->count(),
                'numbers' => $group->map(fn (WhatsAppNumber $n) => ['phone_number' => $n->phone_number, 'status' => $n->status])->values()->all(),
                'status' => $invoice?->status,
                'total_amount' => $invoice ? (float) $invoice->total_amount : null,
                'bought_at' => ($invoice?->created_at ?? $first->created_at)?->toIso8601String(),
                'paid_at' => $invoice?->paid_at?->toIso8601String(),
                'term_months' => (int) config('whatsapp_numbers.addon_term_months'),
                'term_ends_at' => $termEnds === null ? null : Carbon::createFromTimestamp($termEnds)->toIso8601String(),
            ];
        })->values()->all();

        return ['requests' => $requests, 'number_purchases' => $purchases];
    }

    /** null = every account (Super Admin); otherwise the account ids the actor may see. */
    private function visibleAccountIds(User $actor): ?array
    {
        if ($actor->hasRole('super_admin')) {
            return null;
        }

        if ($actor->hasRole('agent')) {
            return Account::query()->where('agent_id', $actor->account_id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return $actor->account_id === null ? [] : [(int) $actor->account_id];
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
            $total = round($this->priceForRequest($request), 2);

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
                    .($request->units ? ' — up to '.$request->units.' '.$this->unitName($request->module) : '')
                    .' ('.$offer['term_months'].' month'.($offer['term_months'] === 1 ? '' : 's').')',
                'quantity' => 1,
                'unit_amount' => $total,
                'amount' => $total,
            ]);

            $request->forceFill([
                'status' => ModuleAddonRequest::INVOICED,
                'invoice_id' => $invoice->id,
                'decided_by_user_id' => $decidedBy,
                'decided_at' => now(),
            ])->save();

            // A free tier (price 0) has nothing to pay: the invoice is recorded as paid and the
            // add-on starts now, so the request does not wait for a payment that cannot be made.
            if ($total <= 0) {
                $invoice->forceFill(['status' => 'paid', 'paid_at' => now(), 'payment_gateway' => 'free'])->save();
                $this->activate($request->refresh(), now());

                return $request->refresh();
            }

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
            'decided_at' => now(),
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
            if ($account !== null && $request->module === 'contact_groups') {
                // The term ended: a smaller term still running may mean the client has to choose again.
                app(CustomGroupAccessService::class)->applyAllowance($account, false);
            }
            if ($account !== null && ! $stillRunningNow) {
                // Renewal: a new invoice at the current price. The module comes back when it is paid.
                $renewal = ModuleAddonRequest::query()->create([
                    'account_id' => $request->account_id,
                    'module' => $request->module,
                    'status' => ModuleAddonRequest::REQUESTED,
                    'reason' => self::RENEWAL_REASON,
                    'units' => $request->units,
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
                    $this->switchOff($account, $request->module);
                }
            }
        }

        return $ended->count();
    }

    private function activate(ModuleAddonRequest $request, Carbon $termStart): ModuleAddonRequest
    {
        $offer = $this->offer($request->module);
        $account = $this->accountOf($request);

        $this->switchOn($account, $request->module);

        // A term is "fresh" when no other paid term for the module is running (an upgrade is not).
        $otherRunning = ModuleAddonRequest::query()
            ->where('account_id', $account->id)
            ->where('module', $request->module)
            ->where('status', ModuleAddonRequest::PAID)
            ->where('term_ends_at', '>', now())
            ->whereKeyNot($request->id)
            ->exists();

        $request->forceFill([
            'status' => ModuleAddonRequest::PAID,
            'term_starts_at' => $termStart,
            'term_ends_at' => $termStart->copy()->addMonthsNoOverflow($offer['term_months']),
        ])->save();

        if ($request->module === 'contact_groups') {
            app(CustomGroupAccessService::class)->applyAllowance($account, ! $otherRunning);
        }

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
            'link' => '/add-ons',
            'is_read' => false,
        ]);
    }

    /** The price tiers of a module, lowest first. Empty when the offer is a flat price. */
    public function tiers(string $module): \Illuminate\Support\Collection
    {
        return ModuleAddonTier::query()->where('module', $module)->orderBy('from_units')->get();
    }

    /** The price for a number of units, from the tier that holds it. */
    public function priceFor(string $module, int $units): float
    {
        $tiers = $this->tiers($module);

        if ($tiers->isEmpty()) {
            return (float) $this->offer($module)['price'];
        }

        $tier = $tiers->first(fn (ModuleAddonTier $t) => $units >= $t->from_units && ($t->to_units === null || $units <= $t->to_units));

        if ($tier === null) {
            throw new WhatsAppNumberException('No price is set for that number of units. Choose another amount.', 'no_tier', 422);
        }

        return (float) $tier->price;
    }

    /** The price a request is invoiced at: its units' tier, or the flat price. */
    private function priceForRequest(ModuleAddonRequest $request): float
    {
        return $request->units !== null ? $this->priceFor($request->module, (int) $request->units) : (float) $this->offer($request->module)['price'];
    }

    private function unitName(string $module): string
    {
        return $module === 'contact_groups' ? 'group chats' : 'units';
    }

    /** True when the account already has what the offer sells: the module, or the capability for a capability offer. */
    private function hasTiers(string $module): bool
    {
        return ModuleAddonTier::query()->where('module', $module)->exists();
    }

    private function alreadyIncluded(Account $account, string $module): bool
    {
        $offer = ModuleAddonOffer::query()->where('module', $module)->first();

        if ($offer !== null && $offer->kind === ModuleAddonOffer::KIND_CAPABILITY) {
            return app(AccessControlService::class)->canTenant($account, (string) $offer->capability_slug);
        }

        // [Re-scoped 2026-10-07, disclosed]: 'contact_groups' now prices Native WhatsApp Group COUNT
        // only -- internal_segment groups are free and always on, so hasModuleEnabled('contact_groups')
        // is always true and would otherwise make this module permanently "already included", refusing
        // every request to buy native-group units. A native-group allowance is always something
        // bought in units (see CustomGroupAccessService), never "included for free" by a module flag,
        // so this never short-circuits a request for it.
        if ($module === 'contact_groups') {
            return false;
        }

        return $account->hasModuleEnabled($module);
    }

    /** Switches the offer on: adds the module, or grants the capability (unless the account already holds it). */
    private function switchOn(Account $account, string $module): void
    {
        $offer = ModuleAddonOffer::query()->where('module', $module)->first();

        if ($offer !== null && $offer->kind === ModuleAddonOffer::KIND_CAPABILITY) {
            $capability = Capability::query()->where('slug', $offer->capability_slug)->first();
            if ($capability === null) {
                throw new WhatsAppNumberException('This capability is not set up on the platform.', 'capability_missing', 422);
            }

            // A grant the plan or the Super Admin already holds is left alone: only an add-on grant is ours to end.
            $held = AccountEntitlement::query()
                ->where('account_id', $account->id)
                ->where('capability_id', $capability->id)
                ->whereNull('revoked_at')
                ->exists();

            if (! $held) {
                AccountEntitlement::query()->updateOrCreate(
                    ['account_id' => $account->id, 'capability_id' => $capability->id],
                    ['source' => self::ADDON_SOURCE, 'revoked_at' => null],
                );
            }

            return;
        }

        $modules = array_values(array_unique(array_merge((array) $account->allowed_modules, [$module])));
        $account->forceFill(['allowed_modules' => $modules])->save();
    }

    /** Switches the offer off at the end of its last term. A capability is revoked only when this add-on granted it. */
    private function switchOff(Account $account, string $module): void
    {
        $offer = ModuleAddonOffer::query()->where('module', $module)->first();

        if ($offer !== null && $offer->kind === ModuleAddonOffer::KIND_CAPABILITY) {
            $capability = Capability::query()->where('slug', $offer->capability_slug)->first();
            if ($capability !== null) {
                AccountEntitlement::query()
                    ->where('account_id', $account->id)
                    ->where('capability_id', $capability->id)
                    ->where('source', self::ADDON_SOURCE)
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => now()]);
            }

            return;
        }

        $modules = array_values(array_diff((array) $account->allowed_modules, [$module]));
        $account->forceFill(['allowed_modules' => $modules])->save();
    }

    /**
     * The number of groups an account may have while a paid term of the module is running
     * (the offer's units_included). Null when no paid term is running, so no add-on limit applies.
     */
    /**
     * True while a Super Admin has switched this module to free (owner instruction,
     * 2026-10-07): unlimited/granted for every account, no purchase needed. Independent
     * of `is_active` and of price/tiers, which stay stored underneath for when the
     * switch is turned back off.
     */
    public function isFree(string $module): bool
    {
        return (bool) (ModuleAddonOffer::query()->where('module', $module)->value('is_free') ?? false);
    }

    public function activeUnitLimit(Account $account, string $module): ?int
    {
        if ($this->isFree($module)) {
            return null;
        }

        $running = ModuleAddonRequest::query()
            ->where('account_id', $account->id)
            ->where('module', $module)
            ->where('status', ModuleAddonRequest::PAID)
            ->where('term_ends_at', '>', now())
            ->exists();

        if (! $running) {
            return null;
        }

        $paidUnits = ModuleAddonRequest::query()
            ->where('account_id', $account->id)
            ->where('module', $module)
            ->where('status', ModuleAddonRequest::PAID)
            ->where('term_ends_at', '>', now())
            ->max('units');

        if ($paidUnits !== null) {
            return (int) $paidUnits;
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
