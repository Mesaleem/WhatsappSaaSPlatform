<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\ManualPayment;
use App\Models\ModuleAddonOffer;
use App\Models\ModuleAddonRequest;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppAddonService;
use App\Services\WhatsApp\WhatsAppNumberException;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Records a manual payment (cash, bank transfer, UPI, cheque) for ANY invoice: a plan, a
 * WhatsApp number add-on, or a module add-on. Who may record it, and the amount, date and
 * transaction rules, are the same for all three (WhatsAppAddonService::assertCanRecord and
 * validatedDetails). Each kind then switches on what it bought, from the chosen start date.
 */
class ManualPaymentService
{
    public function __construct(
        private readonly WhatsAppAddonService $whatsapp,
        private readonly ModuleAddonService $moduleAddons,
        private readonly InvoiceCreditService $credits,
    ) {
    }

    /** 'plan' | 'whatsapp_addon' | 'module_addon' */
    public function kindOf(Invoice $invoice): string
    {
        if ($this->moduleAddons->isModuleInvoice($invoice)) {
            return 'module_addon';
        }

        return $this->whatsapp->isAddonInvoice($invoice) ? 'whatsapp_addon' : 'plan';
    }

    public function record(Invoice $invoice, User $actor, array $details): Invoice
    {
        return match ($this->kindOf($invoice)) {
            'whatsapp_addon' => $this->whatsapp->recordManualPayment($invoice, $actor, $details),
            'module_addon' => $this->recordModuleAddon($invoice, $actor, $details),
            default => $this->recordPlan($invoice, $actor, $details),
        };
    }

    /**
     * Pending invoices the caller may act on: a Super Admin sees every client, an Agent only
     * its own clients. A direct (self-signup) client is never in an Agent's list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pendingFor(User $actor): array
    {
        $query = Invoice::query()
            ->with('account:id,company_name,agent_id')
            ->where('status', 'pending')
            ->orderByDesc('created_at');

        if (! $actor->hasRole('super_admin')) {
            if (! $actor->hasRole('agent')) {
                throw new WhatsAppNumberException('Only a Super Admin or an Agent can see pending invoices.', 'not_allowed', 403);
            }

            $query->whereHas('account', fn ($q) => $q->where('agent_id', $actor->account_id));
        }

        return $query->get()->map(fn (Invoice $invoice) => [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'account_id' => $invoice->account_id,
            'plan_key' => $invoice->plan_key,
            'client' => $invoice->account?->company_name,
            'kind' => $this->kindOf($invoice),
            'label' => $invoice->plan_label,
            'total_amount' => (float) $invoice->total_amount,
            'payment_gateway' => $invoice->payment_gateway,
            'created_at' => $invoice->created_at?->toIso8601String(),
            'term' => $this->termFor($invoice),
        ])->values()->all();
    }

    /**
     * How long the purchase runs, so the form can show the end date: a plan by its days, an
     * add-on by its months. Null when the invoice has no fixed term.
     *
     * @return array{days: int}|array{months: int}|null
     */
    public function termFor(Invoice $invoice): ?array
    {
        return match ($this->kindOf($invoice)) {
            'plan' => $invoice->plan_duration_days ? ['days' => (int) $invoice->plan_duration_days] : null,
            'whatsapp_addon' => ['months' => (int) config('whatsapp_numbers.addon_term_months')],
            'module_addon' => ['months' => (int) (ModuleAddonOffer::query()
                ->where('module', (string) ModuleAddonRequest::query()->where('invoice_id', $invoice->id)->value('module'))
                ->value('term_months') ?? 1)],
            default => null,
        };
    }

    /** Gateways that are fully configured right now: the only ones an online payment can use. */
    public function readyGateways(): array
    {
        return \App\Models\PaymentGatewaySetting::enabledGatewaysCached()
            ->filter(fn (\App\Models\PaymentGatewaySetting $s) => $s->isFullyConfigured())
            ->pluck('gateway')
            ->all();
    }

    private function recordModuleAddon(Invoice $invoice, User $actor, array $details): Invoice
    {
        $request = ModuleAddonRequest::query()->where('invoice_id', $invoice->id)->first();

        if ($request === null) {
            throw new WhatsAppNumberException('This invoice has no add-on request.', 'not_addon_invoice', 422);
        }

        $this->moduleAddons->recordManualPayment($request, $actor, $details);

        return $invoice->refresh();
    }

    /**
     * A plan invoice: the payment is recorded, then the plan is credited from the chosen
     * start date (the same crediting as an online payment, with the start date applied).
     */
    private function recordPlan(Invoice $invoice, User $actor, array $details): Invoice
    {
        $role = $this->whatsapp->assertCanRecord($invoice, $actor);
        $details = $this->whatsapp->validatedDetails($invoice, $details);

        return DB::transaction(function () use ($invoice, $actor, $role, $details): Invoice {
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

            $credited = $this->credits->markPaidAndCreditQuota(
                $locked->id,
                $details['transaction_id'],
                null,
                Carbon::parse($details['term_starts_on'])->startOfDay(),
            );

            // The plan's terms could not be resolved: refuse, and roll the payment back with it.
            if (! $credited) {
                throw new WhatsAppNumberException('This plan can no longer be activated. Check the plan with your Super Admin.', 'plan_unavailable', 422);
            }

            $locked->refresh()->forceFill([
                'payment_gateway' => 'manual',
                'paid_at' => Carbon::parse($details['paid_on'])->startOfDay(),
            ])->save();

            return $locked->refresh();
        });
    }
}
