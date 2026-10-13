<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CrmDocument;
use App\Services\Crm\Sales\CrmDocumentPdf;
use App\Services\Crm\Sales\CrmGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * CRM Sales Phases 3–4 — what the tenant's CUSTOMER opens from a WhatsApp / email link:
 *
 *   GET  /api/public/crm-documents/{token}          the document (customer-safe fields)
 *   GET  /api/public/crm-documents/{token}/pdf      its PDF (also what WhatsApp fetches)
 *   POST /api/public/crm-documents/{token}/pay      start an online payment of the balance
 *   POST /api/public/crm-documents/{token}/confirm  the gateway's signed checkout result
 *   POST /api/webhooks/crm-payments/{webhookToken}  the tenant gateway's webhook
 *
 * No login: the 48-character random token IS the access right (one document only,
 * never an id; drafts and cancelled / void documents are not served). Amounts and the
 * invoice always come from our rows, never from the request. Throttled in routes.
 */
class CrmPublicDocumentController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $doc = $this->find($token);

        return response()->json(['data' => [
            'type' => $doc->type,
            'number' => $doc->number,
            'status' => $doc->status,
            'issue_date' => $doc->issue_date?->toDateString(),
            'valid_until' => $doc->valid_until?->toDateString(),
            'due_date' => $doc->due_date?->toDateString(),
            'currency' => $doc->currency,
            'seller' => array_intersect_key($doc->seller_snapshot ?? [], array_flip(['name', 'gstin', 'address', 'phone', 'email', 'upi_id', 'bank_details'])),
            'customer' => array_intersect_key($doc->customer_snapshot ?? [], array_flip(['name', 'contact_name', 'gstin', 'address'])),
            'items' => $doc->items->map(fn ($i) => [
                'description' => $i->description, 'quantity' => (string) $i->quantity, 'unit' => $i->unit,
                'unit_price' => (string) $i->unit_price, 'tax_rate' => (string) $i->tax_rate, 'line_total' => (string) $i->line_total,
            ])->values(),
            'taxable_total' => (string) $doc->taxable_total,
            'tax_total' => (string) $doc->tax_total,
            'grand_total' => (string) $doc->grand_total,
            'amount_paid' => (string) $doc->amount_paid,
            'balance_due' => (string) $doc->balance_due,
            'payable_online' => $doc->isPayable() && $this->gatewayReady($doc),
            'body' => $doc->body,
            'terms' => $doc->terms,
        ]]);
    }

    public function pdf(string $token, CrmDocumentPdf $pdf): Response
    {
        $doc = $this->find($token);
        $bytes = $pdf->render($doc);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$pdf->filename($doc).'"',
            'Content-Length' => (string) strlen($bytes),
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    public function pay(string $token, CrmGatewayService $gateways): JsonResponse
    {
        return response()->json(['data' => $gateways->createOrder($this->find($token))]);
    }

    public function confirm(Request $request, string $token, CrmGatewayService $gateways): JsonResponse
    {
        $input = $request->validate([
            'razorpay_order_id' => ['nullable', 'string', 'max:120'],
            'razorpay_payment_id' => ['nullable', 'string', 'max:120'],
            'razorpay_signature' => ['nullable', 'string', 'max:255'],
            'payment_intent_id' => ['nullable', 'string', 'max:120'],
        ]);
        $payment = $gateways->confirmCheckout($this->find($token), $input);

        return response()->json(['message' => 'Payment received. Thank you!', 'data' => ['receipt' => $payment?->number]]);
    }

    public function webhook(Request $request, string $webhookToken, CrmGatewayService $gateways): JsonResponse
    {
        $signature = (string) ($request->header('X-Razorpay-Signature') ?? $request->header('Stripe-Signature') ?? '');
        try {
            $outcome = $gateways->handleWebhook($webhookToken, $request->getContent(), $signature, $request->header('X-Razorpay-Event-Id'));
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'invalid_signature') {
                Log::warning('CRM payment webhook: invalid signature; rejected.');

                return response()->json(['message' => 'Invalid signature.'], 400);
            }
            throw $e;
        }

        return response()->json(['received' => true, 'outcome' => $outcome]);
    }

    private function find(string $token): CrmDocument
    {
        $doc = strlen($token) >= 32
            ? CrmDocument::query()->where('public_token', $token)->with('items')->first()
            : null;
        abort_if(! $doc || $doc->number === null || $doc->isEditable() || in_array($doc->status, ['void', 'cancelled'], true), 404, 'Document not found.');

        return $doc;
    }

    private function gatewayReady(CrmDocument $doc): bool
    {
        return (bool) \App\Models\CrmGatewaySetting::query()->forAccount($doc->account_id)->first()?->isUsable();
    }
}
