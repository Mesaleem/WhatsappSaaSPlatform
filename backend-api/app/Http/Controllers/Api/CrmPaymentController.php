<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\PresentsCrmDocuments;
use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\CrmPayment;
use App\Models\CrmPaymentReminder;
use App\Services\Crm\Sales\CrmPaymentService;
use App\Services\Crm\Sales\CrmReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRM Sales Phases 3, 5 — /api/crm/payments (record, allocate, reverse) and
 * /api/crm/reminders (approve / skip). All rules live in CrmPaymentService /
 * CrmReminderService. Gates: the CRM group + manage-crm-payments.
 */
class CrmPaymentController extends Controller
{
    use PresentsCrmDocuments;
    use ResolvesTenantAccount;

    public function __construct(private readonly CrmPaymentService $payments)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        $f = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'contact_id' => ['nullable', 'integer'],
            'crm_company_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in([CrmPayment::STATUS_SUCCESSFUL, CrmPayment::STATUS_REVERSED])],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = CrmPayment::query()->forAccount($account->id)->with(['contact', 'company', 'recordedBy', 'allocations.document'])
            ->when($f['contact_id'] ?? null, fn ($q, $v) => $q->where('contact_id', $v))
            ->when($f['crm_company_id'] ?? null, fn ($q, $v) => $q->where('crm_company_id', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('number', 'like', "%{$s}%")->orWhere('reference', 'like', "%{$s}%")))
            ->orderByDesc('paid_on')->orderByDesc('id')
            ->paginate(min((int) ($f['per_page'] ?? 20), 100));
        $page->getCollection()->transform(fn (CrmPayment $p) => $this->presentPayment($p));

        return response()->json($page);
    }

    public function store(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        $data = $request->validate([
            'amount' => ['required', 'string', 'max:20'],
            'method' => ['required', Rule::in(array_values(array_diff(CrmPayment::METHODS, ['gateway'])))],
            'paid_on' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'contact_id' => ['nullable', 'integer'],
            'crm_company_id' => ['nullable', 'integer'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'allocations' => ['sometimes', 'array', 'max:50'],
            'allocations.*.crm_document_id' => ['required', 'integer'],
            'allocations.*.amount' => ['required', 'string', 'max:20'],
        ]);

        $payment = $this->payments->record($account, $data + ['source' => 'manual'], $request->user()?->id);

        return response()->json(['message' => "Payment {$payment->number} recorded.", 'data' => $this->presentPayment($payment->load(['contact', 'company', 'recordedBy', 'allocations.document']))], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);

        return response()->json(['data' => $this->presentPayment($this->findOrFail($account->id, $id))]);
    }

    /** POST {id}/allocate {allocations: [{crm_document_id, amount}]} — use the payment's unallocated credit. */
    public function allocate(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        $data = $request->validate([
            'allocations' => ['required', 'array', 'min:1', 'max:50'],
            'allocations.*.crm_document_id' => ['required', 'integer'],
            'allocations.*.amount' => ['required', 'string', 'max:20'],
        ]);
        $payment = $this->payments->allocate($this->findOrFail($account->id, $id), $data['allocations'], $request->user()?->id);

        return response()->json(['message' => 'Payment applied.', 'data' => $this->presentPayment($payment->load(['contact', 'company', 'recordedBy', 'allocations.document']))]);
    }

    /** POST {id}/reverse {reason} — never deletes; releases the allocations and recomputes the invoices. */
    public function reverse(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $payment = $this->payments->reverse($this->findOrFail($account->id, $id), $data['reason'], $request->user()?->id);

        return response()->json(['message' => 'Payment reversed.', 'data' => $this->presentPayment($payment->load(['contact', 'company', 'recordedBy', 'allocations.document']))]);
    }

    /** GET {id}/receipt — the payment receipt PDF (from the payment and its allocations). */
    public function receipt(Request $request, int $id, \App\Services\Crm\Sales\CrmReceiptService $receipts): \Illuminate\Http\Response
    {
        $account = $this->requireAccount($request);
        $payment = $this->findOrFail($account->id, $id);
        $bytes = $receipts->pdf($payment);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="receipt-'.preg_replace('/[^A-Za-z0-9-]/', '-', $payment->number).'.pdf"',
            'Content-Length' => (string) strlen($bytes),
        ]);
    }

    /** POST {id}/send-receipt {channel: whatsapp|email, recipient?} — tell the customer the payment was received. */
    public function sendReceipt(Request $request, int $id, \App\Services\Crm\Sales\CrmReceiptService $receipts): JsonResponse
    {
        $account = $this->requireAccount($request);
        $data = $request->validate(['channel' => ['required', Rule::in(['whatsapp', 'email'])], 'recipient' => ['nullable', 'string', 'max:191']]);
        $error = $receipts->send($this->findOrFail($account->id, $id), $data['channel'], $data['recipient'] ?? null);

        return $error === null
            ? response()->json(['message' => 'Receipt sent.'])
            : response()->json(['message' => 'The receipt was not sent: '.$error], 422);
    }

    /** GET /api/crm/reminders?status= — the reminder queue (default: waiting for approval). */
    public function reminders(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        $f = $request->validate(['status' => ['nullable', Rule::in(['pending', 'awaiting_approval', 'sent', 'skipped', 'failed'])]]);

        $page = CrmPaymentReminder::query()->forAccount($account->id)->with('document')
            ->where('status', $f['status'] ?? CrmPaymentReminder::STATUS_AWAITING_APPROVAL)
            ->orderByDesc('scheduled_for')->orderByDesc('id')->paginate(50);
        $page->getCollection()->transform(fn (CrmPaymentReminder $r) => $this->presentReminder($r));

        return response()->json($page);
    }

    public function approveReminder(Request $request, int $id, CrmReminderService $reminders): JsonResponse
    {
        $account = $this->requireAccount($request);
        $outcome = $reminders->approve($this->findReminder($account->id, $id), $request->user()?->id);

        return response()->json(['message' => match ($outcome) {
            'sent' => 'Reminder sent.',
            'skipped' => 'Skipped: the invoice is already settled.',
            default => 'The reminder could not be sent; it will be retried.',
        }]);
    }

    public function skipReminder(Request $request, int $id, CrmReminderService $reminders): JsonResponse
    {
        $account = $this->requireAccount($request);
        $reminders->skip($this->findReminder($account->id, $id));

        return response()->json(['message' => 'Reminder skipped.']);
    }

    private function findOrFail(int $accountId, int $id): CrmPayment
    {
        $p = CrmPayment::query()->forAccount($accountId)->with(['contact', 'company', 'recordedBy', 'allocations.document'])->find($id);
        abort_if(! $p, 404, 'Payment not found.');

        return $p;
    }

    private function findReminder(int $accountId, int $id): CrmPaymentReminder
    {
        $r = CrmPaymentReminder::query()->forAccount($accountId)->find($id);
        abort_if(! $r, 404, 'Reminder not found.');

        return $r;
    }
}
