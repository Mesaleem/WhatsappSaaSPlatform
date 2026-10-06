<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\ModuleAddonOffer;
use App\Models\ModuleAddonRequest;
use App\Models\ModuleAddonTier;
use Illuminate\Support\Facades\DB;
use App\Models\PaymentGatewaySetting;
use App\Services\Payment\PaymentGatewayFactory;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use App\Services\Billing\ModuleAddonService;
use App\Services\WhatsApp\WhatsAppNumberException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Paid module add-ons: a client requests one, a Super Admin or an agent approves it,
 * and the payment (manual, or online once a gateway exists) switches it on for a term.
 */
class ModuleAddonController extends Controller
{
    use ResolvesTenantAccount;

    public function __construct(private readonly ModuleAddonService $service)
    {
    }

    /** GET /api/module-addons — the client's own requests and their status. */
    public function index(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client account first (pass ?account_id=).');

        $rows = ModuleAddonRequest::query()
            ->where('account_id', $account->id)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (ModuleAddonRequest $r) => $this->present($r))->values(),
            'offers' => $this->service->offers()->map(fn (ModuleAddonOffer $o) => $this->presentOffer($o))->values(),
            'gateway_ready' => $this->gatewayReady(),
        ]);
    }

    /**
     * GET /api/module-addons/history[?account_id=] — every add-on request and paid WhatsApp
     * number purchase the caller may see (a client: its own account; an agent: its clients;
     * a Super Admin: all, or one client when account_id is given).
     */
    public function history(Request $request): JsonResponse
    {
        $account = null;
        if ($request->filled('account_id')) {
            $account = Account::query()->find((int) $request->query('account_id'));
            if ($account === null) {
                return response()->json(['message' => 'Client not found.', 'error_code' => 'not_found'], 404);
            }
        }

        try {
            return response()->json($this->service->historyFor($request->user(), $account) + ['gateways' => $this->readyGateways()]);
        } catch (WhatsAppNumberException $e) {
            return $this->error($e);
        }
    }

    /** POST /api/module-addons/request  body: { module, reason? } */
    public function request(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client account first (pass ?account_id=).');
        $data = $request->validate([
            'module' => ['required', 'string', Rule::in($this->service->offers()->pluck('module')->all())],
            'reason' => ['nullable', 'string', 'max:500'],
            'units' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        try {
            $row = $this->service->request($account, $request->user(), $data['module'], $data['reason'] ?? null, $data['units'] ?? null);
        } catch (WhatsAppNumberException $e) {
            return $this->error($e);
        }

        return response()->json(['message' => 'Request sent. Your Super Admin or agent will approve it and send an invoice.', 'data' => $this->present($row)], 201);
    }

    /** GET /api/admin/module-addons/pending — requests the caller may act on. */
    public function pending(Request $request): JsonResponse
    {
        try {
            $rows = $this->service->pendingFor($request->user());
        } catch (WhatsAppNumberException $e) {
            return $this->error($e);
        }

        return response()->json([
            'data' => $rows->map(fn (ModuleAddonRequest $r) => array_merge($this->present($r), [
                'account' => ['id' => $r->account_id, 'name' => $r->account?->company_name],
            ]))->values(),
        ]);
    }

    /** POST /api/admin/module-addons/{id}/approve — creates the invoice. */
    public function approve(Request $request, int $id): JsonResponse
    {
        try {
            $row = $this->service->approve($this->findRequest($id), $request->user());
        } catch (WhatsAppNumberException $e) {
            return $this->error($e);
        }

        return response()->json(['message' => 'Approved. The invoice is ready for payment.', 'data' => $this->present($row)]);
    }

    /** POST /api/admin/module-addons/{id}/reject  body: { note? } */
    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        try {
            $row = $this->service->reject($this->findRequest($id), $request->user(), $data['note'] ?? null);
        } catch (WhatsAppNumberException $e) {
            return $this->error($e);
        }

        return response()->json(['message' => 'Request rejected.', 'data' => $this->present($row)]);
    }

    /** POST /api/admin/module-addons/{id}/record-payment — same details as the WhatsApp add-on payments. */
    public function recordPayment(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'method' => ['required', 'string', 'in:'.implode(',', \App\Models\ManualPayment::METHODS)],
            'transaction_id' => ['required', 'string', 'max:120'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'term_starts_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $row = $this->service->recordManualPayment($this->findRequest($id), $request->user(), $data);
        } catch (WhatsAppNumberException $e) {
            return $this->error($e);
        }

        return response()->json(['message' => 'Payment recorded. The module is switched on for its term.', 'data' => $this->present($row)]);
    }

    /** GET /api/admin/module-offers — the offers, for the Super Admin and agents to see. */
    public function offers(): JsonResponse
    {
        return response()->json([
            'data' => ModuleAddonOffer::query()->orderBy('id')->get()->map(fn (ModuleAddonOffer $o) => $this->presentOffer($o))->values(),
        ]);
    }

    /** PUT /api/admin/module-offers/{module} — Super Admin only (route). Issued invoices keep their price. */
    public function updateOffer(Request $request, string $module): JsonResponse
    {
        $offer = ModuleAddonOffer::query()->where('module', $module)->first();
        if ($offer === null) {
            return response()->json(['message' => 'Offer not found.', 'error_code' => 'not_found'], 404);
        }

        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'price' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'term_months' => ['required', 'integer', 'min:1', 'max:12'],
            'units_included' => ['required', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['required', 'boolean'],
        ]);

        $offer->forceFill($data)->save();
        Log::info('Module add-on offer changed', ['module' => $module, 'by' => $request->user()->id, 'price' => $data['price']]);

        return response()->json([
            'message' => 'Offer updated. New requests use the new price; issued invoices keep theirs.',
            'data' => $this->presentOffer($offer->refresh()),
        ]);
    }

    /**
     * PUT /api/admin/module-offers/{module}/tiers  body: { tiers: [{from_units, to_units|null, price}] }
     * Super Admin only. The tiers must start at 1, not overlap, and the last may be open-ended.
     */
    public function updateTiers(Request $request, string $module): JsonResponse
    {
        $data = $request->validate([
            'tiers' => ['required', 'array', 'min:1', 'max:20'],
            'tiers.*.from_units' => ['required', 'integer', 'min:1', 'max:100000'],
            'tiers.*.to_units' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'tiers.*.price' => ['required', 'numeric', 'min:0', 'max:1000000'],
        ]);

        $tiers = collect($data['tiers'])->sortBy('from_units')->values();

        foreach ($tiers as $i => $tier) {
            $to = $tier['to_units'] ?? null;
            if ($to !== null && $to < $tier['from_units']) {
                return response()->json(['message' => 'A tier must end at or after the units it starts from.', 'error_code' => 'invalid_tier'], 422);
            }
            $next = $tiers[$i + 1] ?? null;
            if ($next !== null && ($to === null || $to >= $next['from_units'])) {
                return response()->json(['message' => 'Tiers must not overlap.', 'error_code' => 'overlapping_tiers'], 422);
            }
        }

        DB::transaction(function () use ($module, $tiers): void {
            ModuleAddonTier::query()->where('module', $module)->delete();
            foreach ($tiers as $tier) {
                ModuleAddonTier::query()->create([
                    'module' => $module,
                    'from_units' => $tier['from_units'],
                    'to_units' => $tier['to_units'] ?? null,
                    'price' => $tier['price'],
                ]);
            }
        });

        Log::info('Module add-on tiers changed', ['module' => $module, 'by' => $request->user()->id]);

        return response()->json([
            'message' => 'Prices updated. New requests use them; issued invoices keep their price.',
            'data' => ModuleAddonOffer::query()->where('module', $module)->first() ? $this->presentOffer(ModuleAddonOffer::query()->where('module', $module)->first()) : null,
        ]);
    }

    /**
     * POST /api/module-addons/invoices/{id}/pay  body: { gateway }
     * Online payment for a module add-on invoice. Refused until a gateway is configured.
     */
    public function payInvoice(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client account first (pass ?account_id=).');
        $data = $request->validate(['gateway' => ['required', Rule::in(PaymentGatewayFactory::SUPPORTED_GATEWAYS)]]);

        $invoice = Invoice::query()->where('account_id', $account->id)->whereKey($id)->first();
        if ($invoice === null || ! $this->service->isModuleInvoice($invoice)) {
            return response()->json(['message' => 'Invoice not found.', 'error_code' => 'not_found'], 404);
        }
        if ($invoice->status !== 'pending') {
            return response()->json(['message' => 'This invoice is already '.$invoice->status.'.', 'error_code' => 'invoice_not_pending'], 409);
        }

        if (! in_array($data['gateway'], $this->readyGateways(), true)) {
            return response()->json([
                'message' => 'Online payment is not available yet: no payment gateway has been configured.',
                'error_code' => 'gateway_not_configured',
            ], 422);
        }

        try {
            $driver = PaymentGatewayFactory::make($data['gateway']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'error_code' => 'gateway_unavailable'], 422);
        }

        $amount = (int) round((float) $invoice->total_amount * 100);
        $result = $driver->createOrder($amount, 'INR', $invoice->invoice_number, [
            'invoice_id' => (string) $invoice->id,
            'account_id' => (string) $account->id,
            'plan_key' => $invoice->plan_key,
        ]);

        if (empty($result['success'])) {
            return response()->json(['message' => $result['error'] ?? 'Could not create the payment order.', 'error_code' => 'order_failed'], 502);
        }

        $invoice->forceFill([
            'payment_gateway' => $data['gateway'],
            'gateway_order_id' => $result['order_id'],
            'gateway_raw_response' => $result['raw'] ?? null,
        ])->save();

        $settings = PaymentGatewayFactory::settingsFor($data['gateway']);

        return response()->json([
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'gateway' => $data['gateway'],
            'order_id' => $result['order_id'],
            'client_secret' => $result['client_secret'] ?? null,
            'key_id' => $settings?->activeKeyId(),
            'amount' => $amount,
            'currency' => 'INR',
            'plan' => ['key' => $invoice->plan_key, 'label' => $invoice->plan_label],
        ]);
    }

    /** @return array<int, string> the gateways the Super Admin has fully configured */
    private function readyGateways(): array
    {
        return PaymentGatewaySetting::enabledGatewaysCached()
            ->filter(fn (PaymentGatewaySetting $s) => $s->isFullyConfigured())
            ->pluck('gateway')
            ->all();
    }

    private function gatewayReady(): bool
    {
        return $this->readyGateways() !== [];
    }

    /** @return array<string, mixed> */
    private function presentOffer(ModuleAddonOffer $o): array
    {
        return [
            'tiers' => ModuleAddonTier::query()->where('module', $o->module)->orderBy('from_units')->get()
                ->map(fn (ModuleAddonTier $t) => ['from_units' => (int) $t->from_units, 'to_units' => $t->to_units, 'price' => (float) $t->price])
                ->values(),
            'module' => $o->module,
            'label' => $o->label,
            'price' => (float) $o->price,
            'term_months' => (int) $o->term_months,
            'units_included' => (int) $o->units_included,
            'is_active' => (bool) $o->is_active,
        ];
    }

    private function findRequest(int $id): ModuleAddonRequest
    {
        $row = ModuleAddonRequest::query()->whereKey($id)->first();

        if ($row === null) {
            throw new WhatsAppNumberException('Request not found.', 'not_found', 404);
        }

        return $row;
    }

    private function present(ModuleAddonRequest $row): array
    {
        return [
            'id' => $row->id,
            'module' => $row->module,
            'label' => ModuleAddonOffer::query()->where('module', $row->module)->value('label'),
            'status' => $row->status,
            'reason' => $row->reason,
            'invoice_id' => $row->invoice_id,
            'units' => $row->units,
            'total_amount' => $row->invoice ? (float) $row->invoice->total_amount : null,
            'term_starts_at' => $row->term_starts_at?->toIso8601String(),
            'term_ends_at' => $row->term_ends_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }

    private function error(WhatsAppNumberException $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage(), 'error_code' => $e->errorCode], $e->status);
    }
}
