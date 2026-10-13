<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\PresentsCrmDocuments;
use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\CrmDocument;
use App\Models\CrmPaymentReminder;
use App\Services\Crm\Sales\CrmDeliveryService;
use App\Services\Crm\Sales\CrmDocumentPdf;
use App\Services\Crm\Sales\CrmDocumentService;
use App\Services\Crm\Sales\CrmReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * CRM Sales Phases 2, 4, 5 — /api/crm/documents: proposals, quotations, customer POs,
 * sales orders, invoices and credit notes. Every rule (editability, numbering, totals,
 * transitions, credits) lives in CrmDocumentService; this resolves the tenant, finds
 * the document tenant-scoped (a foreign id = the same 404 as a missing one) and shapes
 * the response. Gates: the CRM group + manage-crm-documents.
 */
class CrmDocumentController extends Controller
{
    use PresentsCrmDocuments;
    use ResolvesTenantAccount;

    public function __construct(
        private readonly CrmDocumentService $documents,
        private readonly CrmDeliveryService $delivery,
    ) {
    }

    /** GET /api/crm/documents?type=&status=&search=&contact_id=&crm_company_id=&crm_deal_id=&overdue=1 */
    public function index(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        $f = $request->validate([
            'type' => ['nullable', Rule::in(CrmDocument::TYPES)],
            'status' => ['nullable', 'string', 'max:20'],
            'search' => ['nullable', 'string', 'max:191'],
            'contact_id' => ['nullable', 'integer'],
            'crm_company_id' => ['nullable', 'integer'],
            'crm_deal_id' => ['nullable', 'integer'],
            'overdue' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = CrmDocument::query()->forAccount($account->id)->with(['contact', 'company'])
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['contact_id'] ?? null, fn ($q, $v) => $q->where('contact_id', $v))
            ->when($f['crm_company_id'] ?? null, fn ($q, $v) => $q->where('crm_company_id', $v))
            ->when($f['crm_deal_id'] ?? null, fn ($q, $v) => $q->where('crm_deal_id', $v))
            ->when(! empty($f['overdue']), fn ($q) => $q->where('type', CrmDocument::TYPE_INVOICE)->whereIn('status', ['issued', 'partially_paid'])->whereDate('due_date', '<', now()->toDateString()))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('number', 'like', "%{$s}%")->orWhere('title', 'like', "%{$s}%")->orWhere('external_reference', 'like', "%{$s}%")))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(min((int) ($f['per_page'] ?? 20), 100));
        $page->getCollection()->transform(fn (CrmDocument $d) => $this->presentDocument($d));

        return response()->json($page);
    }

    public function store(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        $data = $request->validate(['type' => ['required', Rule::in(CrmDocument::TYPES)]] + $this->rules());

        $doc = $this->documents->create($account, $data['type'], $data, $request->user()?->id);

        return response()->json(['message' => 'Draft created.', 'data' => $this->presentDocument($doc, true)], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);

        return response()->json(['data' => $this->presentDocument($this->findOrFail($account->id, $id), true)]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        $doc = $this->documents->update($this->findOrFail($account->id, $id), $request->validate($this->rules()), $request->user()?->id);

        return response()->json(['message' => 'Draft saved.', 'data' => $this->presentDocument($doc, true)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        $this->documents->delete($this->findOrFail($account->id, $id));

        return response()->json(['message' => 'Draft deleted.']);
    }

    /** POST {id}/issue — number, freeze, send-ready. */
    public function issue(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        $doc = $this->documents->issue($this->findOrFail($account->id, $id), $request->user()?->id);

        return response()->json(['message' => 'Issued as '.$doc->number.'.', 'data' => $this->presentDocument($doc, true)]);
    }

    /** POST {id}/status {status, reason?} — accept, reject, cancel, approve, fulfil, void. */
    public function status(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        $data = $request->validate(['status' => ['required', 'string', 'max:20'], 'reason' => ['nullable', 'string', 'max:255']]);
        $doc = $this->documents->transition($this->findOrFail($account->id, $id), $data['status'], $data['reason'] ?? null, $request->user()?->id);

        return response()->json(['message' => 'Status updated.', 'data' => $this->presentDocument($doc, true)]);
    }

    /** POST {id}/convert {type} — a new draft of the next document type with the same lines. */
    public function convert(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        $data = $request->validate(['type' => ['required', Rule::in(CrmDocument::TYPES)]]);
        $doc = $this->documents->convert($this->findOrFail($account->id, $id), $data['type'], $request->user()?->id);

        return response()->json(['message' => 'Draft created from this document.', 'data' => $this->presentDocument($doc, true)], 201);
    }

    /** POST {id}/credit-note {items?, reason?} — a draft credit note against an issued invoice. */
    public function creditNote(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']] + array_intersect_key($this->rules(), array_flip(['items', 'items.*.description', 'items.*.quantity', 'items.*.unit_price', 'items.*.discount_amount', 'items.*.tax_rate', 'items.*.hsn_sac', 'items.*.unit'])));
        $doc = $this->documents->createCreditNote($this->findOrFail($account->id, $id), $data['items'] ?? null, $data['reason'] ?? null, $request->user()?->id);

        return response()->json(['message' => 'Credit note draft created.', 'data' => $this->presentDocument($doc, true)], 201);
    }

    /** GET {id}/pdf — staff download (also works for drafts, marked DRAFT). */
    public function pdf(Request $request, int $id, CrmDocumentPdf $pdf): Response
    {
        $account = $this->requireAccount($request);
        $doc = $this->findOrFail($account->id, $id);
        $bytes = $pdf->render($doc);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf->filename($doc).'"',
            'Content-Length' => (string) strlen($bytes),
        ]);
    }

    /** POST {id}/send {channel: whatsapp|email|manual, recipient?, subject?, message?, how?} */
    public function send(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        $data = $request->validate([
            'channel' => ['required', Rule::in(['whatsapp', 'email', 'manual'])],
            'recipient' => ['nullable', 'string', 'max:191', 'required_if:channel,manual'],
            'subject' => ['nullable', 'string', 'max:191'],
            'message' => ['nullable', 'string', 'max:2000'],
            'how' => ['nullable', 'string', 'max:50'],
        ]);
        $doc = $this->findOrFail($account->id, $id);
        $actor = $request->user()?->id;

        $delivery = match ($data['channel']) {
            'whatsapp' => $this->delivery->sendWhatsApp($doc, $data['recipient'] ?? null, $data['message'] ?? null, $actor),
            'email' => $this->delivery->sendEmail($doc, $data['recipient'] ?? null, $data['subject'] ?? null, $data['message'] ?? null, $actor),
            default => $this->delivery->recordManual($doc, $data['how'] ?? 'Sent manually', (string) $data['recipient'], $actor),
        };

        $message = match ($delivery->status) {
            'sent' => 'Sent.',
            'recorded' => 'Recorded as sent manually.',
            default => 'Not sent: '.$delivery->error,
        };

        return response()->json(['message' => $message, 'data' => $this->presentDelivery($delivery)], $delivery->status === 'failed' ? 422 : 200);
    }

    /** POST {id}/remind {channel?} — manual payment reminder, even with automation off. */
    public function remind(Request $request, int $id, CrmReminderService $reminders): JsonResponse
    {
        $account = $this->requireAccount($request);
        $data = $request->validate(['channel' => ['nullable', Rule::in(['whatsapp', 'email', 'both'])]]);
        $deliveries = $reminders->sendNow($this->findOrFail($account->id, $id), $data['channel'] ?? null, $request->user()?->id);
        $sent = collect($deliveries)->contains(fn ($d) => $d->status === 'sent');

        return response()->json([
            'message' => $sent ? 'Reminder sent.' : 'The reminder was not sent: '.collect($deliveries)->pluck('error')->filter()->implode(' | '),
            'data' => collect($deliveries)->map(fn ($d) => $this->presentDelivery($d))->values(),
        ], $sent ? 200 : 422);
    }

    /**
     * POST {id}/attachment (multipart "file": PDF / JPG / PNG, max 5 MB) — e.g. the customer's
     * PO. Stored on the PRIVATE disk under the tenant's folder (never a public URL); replaces
     * an earlier file. Not allowed on a cancelled / void document.
     */
    public function uploadAttachment(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        $doc = $this->findOrFail($account->id, $id);
        abort_if(in_array($doc->status, ['cancelled', 'void'], true), 422, 'This document is closed.');
        $file = $request->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120']])['file'];

        $old = $doc->attachment_path;
        $path = $file->storeAs("crm/{$account->id}/attachments", $doc->id.'-'.\Illuminate\Support\Str::random(16).'.'.$file->extension(), 'local');
        $doc->forceFill(['attachment_path' => $path, 'attachment_name' => mb_substr($file->getClientOriginalName(), 0, 191)])->save();
        if ($old && $old !== $path) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($old);
        }

        return response()->json(['message' => 'File attached.', 'data' => $this->presentDocument($doc->fresh(['items', 'contact', 'company']), true)]);
    }

    /** GET {id}/attachment — download the attached file (staff only, tenant-scoped). */
    public function downloadAttachment(Request $request, int $id): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $account = $this->requireAccount($request);
        $doc = $this->findOrFail($account->id, $id);
        abort_if(! $doc->attachment_path || ! \Illuminate\Support\Facades\Storage::disk('local')->exists($doc->attachment_path), 404, 'No file attached.');

        return \Illuminate\Support\Facades\Storage::disk('local')->download($doc->attachment_path, $doc->attachment_name ?? basename($doc->attachment_path));
    }

    /** GET {id}/reminders — this invoice's reminder occurrences. */
    public function reminders(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        $doc = $this->findOrFail($account->id, $id);

        return response()->json(['data' => CrmPaymentReminder::query()->where('crm_document_id', $doc->id)->orderBy('scheduled_for')->get()->map(fn ($r) => $this->presentReminder($r))->values()]);
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:191'],
            'contact_id' => ['sometimes', 'nullable', 'integer'],
            'crm_company_id' => ['sometimes', 'nullable', 'integer'],
            'crm_deal_id' => ['sometimes', 'nullable', 'integer'],
            'issue_date' => ['sometimes', 'nullable', 'date'],
            'valid_until' => ['sometimes', 'nullable', 'date'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'place_of_supply' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9]{2}$/'],
            'body' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'terms' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'external_reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'items' => ['sometimes', 'array', 'max:100'],
            'items.*.crm_product_id' => ['nullable', 'integer'],
            'items.*.description' => ['nullable', 'string', 'max:500'],
            'items.*.quantity' => ['required_with:items', 'string', 'max:20'],
            'items.*.unit_price' => ['nullable', 'string', 'max:20'],
            'items.*.discount_amount' => ['nullable', 'string', 'max:20'],
            'items.*.tax_rate' => ['nullable', 'string', 'max:6'],
            'items.*.hsn_sac' => ['nullable', 'string', 'max:20'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
        ];
    }

    private function findOrFail(int $accountId, int $id): CrmDocument
    {
        $doc = CrmDocument::query()->forAccount($accountId)->with(['items', 'contact', 'company'])->find($id);
        abort_if(! $doc, 404, 'Document not found.');

        return $doc;
    }
}
