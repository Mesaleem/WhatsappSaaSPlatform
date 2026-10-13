<?php

namespace App\Services\Crm\Sales;

use App\Models\CrmDocument;
use App\Services\Pdf\SimplePdfWriter;

/**
 * CRM Sales Phase 2 — a document's PDF, laid out by the existing SimplePdfWriter::
 * renderInvoice() (no new PDF dependency). Built only from the document's frozen
 * snapshots and lines, so an issued document always renders the same, whatever
 * changes later in products, companies or the business profile. Rendered on demand,
 * never stored, so there is no file to leak or to go stale.
 */
class CrmDocumentPdf
{
    private const TITLES = [
        CrmDocument::TYPE_PROPOSAL => 'PROPOSAL',
        CrmDocument::TYPE_QUOTATION => 'QUOTATION',
        CrmDocument::TYPE_CUSTOMER_PO => 'PURCHASE ORDER (RECEIVED)',
        CrmDocument::TYPE_SALES_ORDER => 'SALES ORDER',
        CrmDocument::TYPE_INVOICE => 'TAX INVOICE',
        CrmDocument::TYPE_CREDIT_NOTE => 'CREDIT NOTE',
    ];

    public function render(CrmDocument $doc): string
    {
        return SimplePdfWriter::renderInvoice($this->layout($doc));
    }

    public function filename(CrmDocument $doc): string
    {
        return strtolower(str_replace('_', '-', $doc->type)).'-'.preg_replace('/[^A-Za-z0-9-]/', '-', (string) ($doc->number ?? 'draft-'.$doc->id)).'.pdf';
    }

    /** @return array<string, mixed> */
    public function layout(CrmDocument $doc): array
    {
        $seller = $doc->seller_snapshot ?? [];
        $customer = $doc->customer_snapshot ?? [];
        $date = static fn ($d): string => $d ? $d->format('d M Y') : '-';
        $money = static fn ($v): string => number_format((float) $v, 2);

        $status = match ($doc->status) {
            'paid', 'accepted', 'fulfilled' => [strtoupper($doc->status), '#059669'],
            'partially_paid' => ['PARTIALLY PAID', '#D97706'],
            'draft' => ['DRAFT', '#6B7280'],
            'void', 'cancelled', 'rejected', 'expired' => [strtoupper($doc->status), '#DC2626'],
            default => [strtoupper(str_replace('_', ' ', $doc->status)), '#0F766E'],
        };

        $details = array_values(array_filter([
            ['Date', $date($doc->issue_date)],
            $doc->valid_until ? ['Valid until', $date($doc->valid_until)] : null,
            $doc->due_date ? ['Due date', $date($doc->due_date)] : null,
            $doc->external_reference ? ['Customer PO', $doc->external_reference] : null,
            $customer['gstin'] ?? null ? ['Customer GSTIN', $customer['gstin']] : null,
            $doc->place_of_supply ? ['Place of supply', $doc->place_of_supply] : null,
        ]));

        $items = $doc->items->map(fn ($i) => [
            trim($i->description.($i->hsn_sac ? " (HSN/SAC {$i->hsn_sac})" : '')),
            rtrim(rtrim((string) $i->quantity, '0'), '.').' '.$i->unit,
            $money($i->unit_price),
            $money($i->line_total),
        ])->values()->all();
        if ($items === [] && $doc->body) {
            $items = [[mb_substr((string) $doc->body, 0, 200), '-', '-', '-']];
        }

        $taxNote = match ($doc->tax_mode) {
            'intra' => 'CGST INR '.$money($doc->cgst_total).' + SGST INR '.$money($doc->sgst_total),
            'inter' => 'IGST INR '.$money($doc->igst_total),
            default => null,
        };

        $payment = null;
        if ($doc->type === CrmDocument::TYPE_INVOICE && $doc->status !== 'draft') {
            $payment = [
                ['Amount paid', 'INR '.$money($doc->amount_paid)],
                ['Credited', 'INR '.$money($doc->amount_credited)],
                ['Balance due', 'INR '.$money($doc->balance_due)],
            ];
        }

        $footer = array_values(array_filter([
            ($seller['bank_details'] ?? null) ? 'Bank: '.str_replace("\n", ', ', (string) $seller['bank_details']) : null,
            ($seller['upi_id'] ?? null) ? 'UPI: '.$seller['upi_id'] : null,
            $doc->terms ? 'Terms: '.str_replace("\n", ' ', mb_substr((string) $doc->terms, 0, 300)) : null,
            $doc->type === CrmDocument::TYPE_INVOICE && $doc->tax_mode === 'none' ? 'GST not charged.' : null,
        ]));

        return [
            'company' => (string) ($seller['name'] ?? 'Business'),
            'subtitle' => trim(implode(', ', array_filter([implode(', ', $seller['address'] ?? []), ($seller['gstin'] ?? null) ? 'GSTIN '.$seller['gstin'] : null]))),
            'accent' => '#0F766E',
            'title' => self::TITLES[$doc->type] ?? strtoupper($doc->type),
            'number' => (string) ($doc->number ?? 'DRAFT'),
            'issued_on' => $date($doc->issue_date),
            'status' => $status[0],
            'status_color' => $status[1],
            'billed_to' => array_values(array_filter([
                $customer['name'] ?? null,
                ($customer['contact_name'] ?? null) && ($customer['contact_name'] !== ($customer['name'] ?? null)) ? 'Attn: '.$customer['contact_name'] : null,
                ...($customer['address'] ?? []),
                $customer['phone'] ?? null,
                $customer['email'] ?? null,
            ])),
            'details' => $details,
            'items' => $items,
            'subtotal' => 'INR '.$money($doc->taxable_total),
            'tax' => 'INR '.$money($doc->tax_total),
            'tax_note' => $taxNote,
            'total' => 'INR '.$money($doc->grand_total),
            'payment' => $payment,
            'footer' => $footer,
        ];
    }
}
