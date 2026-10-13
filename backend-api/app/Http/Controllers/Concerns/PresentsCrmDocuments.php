<?php

namespace App\Http\Controllers\Concerns;

use App\Models\CrmDocument;
use App\Models\CrmDocumentDelivery;
use App\Models\CrmPayment;
use App\Models\CrmPaymentReminder;
use App\Models\CrmProduct;
use App\Services\Crm\Sales\CrmDeliveryService;
use App\Services\Crm\Sales\CrmPaymentService;

/**
 * CRM Sales Phases 2–5 — JSON shapes of products, documents, payments, deliveries and
 * reminders. Money is always a decimal string. The public token is exposed only as the
 * share links staff need, never as a bare field.
 */
trait PresentsCrmDocuments
{
    protected function presentProduct(CrmProduct $p): array
    {
        return [
            'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'description' => $p->description, 'unit' => $p->unit,
            'unit_price' => (string) $p->unit_price, 'tax_rate' => (string) $p->tax_rate, 'hsn_sac' => $p->hsn_sac,
            'billing_frequency' => $p->billing_frequency, 'is_active' => $p->is_active,
        ];
    }

    protected function presentDocument(CrmDocument $d, bool $full = false): array
    {
        $out = [
            'id' => $d->id,
            'type' => $d->type,
            'status' => $d->status,
            'number' => $d->number,
            'title' => $d->title,
            'issue_date' => $d->issue_date?->toDateString(),
            'valid_until' => $d->valid_until?->toDateString(),
            'due_date' => $d->due_date?->toDateString(),
            'is_overdue' => $d->isOverdue(),
            'currency' => $d->currency,
            'tax_mode' => $d->tax_mode,
            'grand_total' => (string) $d->grand_total,
            'amount_paid' => (string) $d->amount_paid,
            'amount_credited' => (string) $d->amount_credited,
            'balance_due' => (string) $d->balance_due,
            'parent_id' => $d->parent_id,
            'crm_deal_id' => $d->crm_deal_id,
            'contact_id' => $d->contact_id,
            'crm_company_id' => $d->crm_company_id,
            'customer_name' => $d->customer_snapshot['name'] ?? ($d->relationLoaded('company') && $d->company ? $d->company->name : null) ?? ($d->relationLoaded('contact') && $d->contact ? ($d->contact->name ?: $d->contact->phone_number) : null),
            'external_reference' => $d->external_reference,
            'editable' => $d->isEditable(),
            'created_at' => $d->created_at?->toIso8601String(),
        ];

        if (! $full) {
            return $out;
        }

        $delivery = app(CrmDeliveryService::class);

        return $out + [
            'place_of_supply' => $d->place_of_supply,
            'subtotal' => (string) $d->subtotal,
            'discount_total' => (string) $d->discount_total,
            'taxable_total' => (string) $d->taxable_total,
            'cgst_total' => (string) $d->cgst_total,
            'sgst_total' => (string) $d->sgst_total,
            'igst_total' => (string) $d->igst_total,
            'tax_total' => (string) $d->tax_total,
            'round_off' => (string) $d->round_off,
            'body' => $d->body,
            'terms' => $d->terms,
            'notes' => $d->notes,
            'customer_snapshot' => $d->customer_snapshot,
            'seller_snapshot' => $d->seller_snapshot,
            'issued_at' => $d->issued_at?->toIso8601String(),
            'cancel_reason' => $d->cancel_reason,
            'decided_at' => $d->decided_at?->toIso8601String(),
            'created_by' => $d->createdBy ? ['id' => $d->createdBy->id, 'name' => $d->createdBy->name] : null,
            'issued_by' => $d->issuedBy ? ['id' => $d->issuedBy->id, 'name' => $d->issuedBy->name] : null,
            'decided_by' => $d->decidedBy ? ['id' => $d->decidedBy->id, 'name' => $d->decidedBy->name] : null,
            'attachment' => $d->attachment_path ? ['name' => $d->attachment_name ?? 'attachment'] : null,
            'gateway_orders' => $d->gatewayOrders()->latest('id')->limit(20)->get()->map(fn ($o) => [
                'id' => $o->id, 'gateway' => $o->gateway, 'amount' => (string) $o->amount, 'status' => $o->status,
                'created_at' => $o->created_at?->toIso8601String(),
            ])->values(),
            'items' => $d->items->map(fn ($i) => [
                'id' => $i->id, 'crm_product_id' => $i->crm_product_id, 'description' => $i->description, 'hsn_sac' => $i->hsn_sac,
                'quantity' => (string) $i->quantity, 'unit' => $i->unit, 'unit_price' => (string) $i->unit_price,
                'discount_amount' => (string) $i->discount_amount, 'tax_rate' => (string) $i->tax_rate,
                'taxable_amount' => (string) $i->taxable_amount, 'cgst_amount' => (string) $i->cgst_amount,
                'sgst_amount' => (string) $i->sgst_amount, 'igst_amount' => (string) $i->igst_amount, 'line_total' => (string) $i->line_total,
            ])->values(),
            'children' => $d->children()->orderBy('id')->get()->map(fn (CrmDocument $c) => $this->presentDocument($c))->values(),
            'allocations' => $d->allocations()->with('payment')->orderBy('id')->get()->map(fn ($a) => [
                'id' => $a->id, 'amount' => (string) $a->amount, 'reversed' => $a->reversed_at !== null,
                'payment' => ['id' => $a->payment->id, 'number' => $a->payment->number, 'method' => $a->payment->method, 'paid_on' => $a->payment->paid_on?->toDateString()],
            ])->values(),
            'deliveries' => $d->deliveries()->with('sentBy')->latest('id')->limit(50)->get()->map(fn ($x) => $this->presentDelivery($x))->values(),
            'share' => $d->number !== null ? ['view_url' => $delivery->viewUrl($d), 'pdf_url' => $delivery->pdfUrl($d)] : null,
        ];
    }

    protected function presentPayment(CrmPayment $p): array
    {
        return [
            'id' => $p->id, 'number' => $p->number, 'amount' => (string) $p->amount, 'currency' => $p->currency,
            'unallocated' => app(CrmPaymentService::class)->unallocated($p),
            'method' => $p->method, 'reference' => $p->reference, 'paid_on' => $p->paid_on?->toDateString(),
            'status' => $p->status, 'source' => $p->source, 'gateway' => $p->gateway, 'notes' => $p->notes,
            'contact' => $p->relationLoaded('contact') && $p->contact ? ['id' => $p->contact->id, 'name' => $p->contact->name, 'phone_number' => $p->contact->phone_number] : null,
            'company' => $p->relationLoaded('company') && $p->company ? ['id' => $p->company->id, 'name' => $p->company->name] : null,
            'recorded_by' => $p->relationLoaded('recordedBy') && $p->recordedBy ? ['id' => $p->recordedBy->id, 'name' => $p->recordedBy->name] : null,
            'reversed_at' => $p->reversed_at?->toIso8601String(),
            'reversal_reason' => $p->reversal_reason,
            'allocations' => $p->allocations->map(fn ($a) => [
                'id' => $a->id, 'crm_document_id' => $a->crm_document_id, 'amount' => (string) $a->amount, 'reversed' => $a->reversed_at !== null,
                'number' => $a->relationLoaded('document') ? $a->document?->number : null,
            ])->values(),
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }

    protected function presentDelivery(CrmDocumentDelivery $x): array
    {
        return [
            'id' => $x->id, 'channel' => $x->channel, 'purpose' => $x->purpose, 'recipient' => $x->recipient, 'status' => $x->status,
            'error' => $x->error, 'sent_at' => $x->sent_at?->toIso8601String(), 'created_at' => $x->created_at?->toIso8601String(),
            'sent_by' => $x->relationLoaded('sentBy') && $x->sentBy ? ['id' => $x->sentBy->id, 'name' => $x->sentBy->name] : null,
        ];
    }

    protected function presentReminder(CrmPaymentReminder $r): array
    {
        return [
            'id' => $r->id, 'rule_key' => $r->rule_key, 'scheduled_for' => $r->scheduled_for?->toDateString(), 'status' => $r->status,
            'attempts' => $r->attempts, 'last_error' => $r->last_error, 'balance_at_send' => $r->balance_at_send === null ? null : (string) $r->balance_at_send,
            'sent_at' => $r->sent_at?->toIso8601String(),
            'document' => $r->relationLoaded('document') && $r->document ? [
                'id' => $r->document->id, 'number' => $r->document->number, 'balance_due' => (string) $r->document->balance_due,
                'customer_name' => $r->document->customer_snapshot['name'] ?? null, 'due_date' => $r->document->due_date?->toDateString(),
            ] : null,
        ];
    }
}
