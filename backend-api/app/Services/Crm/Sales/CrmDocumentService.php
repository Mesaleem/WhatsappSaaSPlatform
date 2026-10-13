<?php

namespace App\Services\Crm\Sales;

use App\Models\Account;
use App\Models\Contact;
use App\Models\CrmBusinessProfile;
use App\Models\CrmCompany;
use App\Models\CrmDocument;
use App\Models\CrmProduct;
use App\Services\Crm\CrmActivityService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CRM Sales Phase 2 — the ONE place sales documents change.
 *
 *   create / update   only while editable (a draft, or a received customer PO); line
 *                     items are replaced and totals recomputed (CrmDocumentCalculator).
 *                     A product line copies the product's name, price, tax and HSN/SAC
 *                     at that moment — later product changes never reach the document.
 *   issue             takes the number (CrmDocumentNumbering), freezes the customer and
 *                     seller snapshots, recomputes with the tax mode of that moment and
 *                     fills default dates. After this the document is read-only.
 *   transition        the user moves in CrmDocument::USER_TRANSITIONS (accept, reject,
 *                     cancel, fulfil, void...). Voiding an invoice is refused once any
 *                     payment or credit is applied (reverse the payment / void the credit
 *                     note first). Accepting a quotation does NOT win the deal.
 *   convert           proposal -> quotation, quotation / customer PO -> sales order or
 *                     invoice, sales order -> invoice: a new DRAFT with the same lines.
 *   credit note       created from an issued invoice; issuing it credits the invoice
 *                     (amount_credited) and can never credit more than the balance.
 *
 * Every multi-row change is one transaction with the document row locked.
 */
class CrmDocumentService
{
    public function __construct(
        private readonly CrmDocumentCalculator $calculator,
        private readonly CrmDocumentNumbering $numbering,
        private readonly CrmActivityService $activities,
    ) {
    }

    public function profileFor(Account $account): CrmBusinessProfile
    {
        return CrmBusinessProfile::query()->firstOrNew(['account_id' => $account->id], ['round_off' => true, 'default_due_days' => 15]);
    }

    /** @param array<string, mixed> $data */
    public function create(Account $account, string $type, array $data, ?int $actorUserId): CrmDocument
    {
        if (! in_array($type, CrmDocument::TYPES, true)) {
            throw ValidationException::withMessages(['type' => ['The selected document type is invalid.']]);
        }
        if ($type === CrmDocument::TYPE_CREDIT_NOTE) {
            throw ValidationException::withMessages(['type' => ['Create a credit note from the invoice it corrects.']]);
        }

        return DB::transaction(function () use ($account, $type, $data, $actorUserId): CrmDocument {
            $doc = new CrmDocument([
                'account_id' => $account->id,
                'type' => $type,
                'status' => CrmDocument::INITIAL_STATUS[$type],
                'created_by_user_id' => $actorUserId,
                'updated_by_user_id' => $actorUserId,
            ]);
            $this->fillAndPrice($doc, $data, $account);

            return $doc->fresh('items');
        });
    }

    /** @param array<string, mixed> $data */
    public function update(CrmDocument $doc, array $data, ?int $actorUserId): CrmDocument
    {
        return DB::transaction(function () use ($doc, $data, $actorUserId): CrmDocument {
            $doc = $this->lock($doc);
            if (! $doc->isEditable()) {
                throw ValidationException::withMessages(['status' => ['Only a draft can be edited. Issued documents are final; create a new one or a credit note.']]);
            }
            $doc->updated_by_user_id = $actorUserId;
            $this->fillAndPrice($doc, $data, Account::query()->findOrFail($doc->account_id));

            return $doc->fresh('items');
        });
    }

    public function delete(CrmDocument $doc): void
    {
        DB::transaction(function () use ($doc): void {
            $doc = $this->lock($doc);
            if ($doc->number !== null || ! $doc->isEditable()) {
                throw ValidationException::withMessages(['status' => ['Only an unissued draft can be deleted. Cancel or void it instead.']]);
            }
            $doc->delete();
        });
    }

    public function issue(CrmDocument $doc, ?int $actorUserId): CrmDocument
    {
        return DB::transaction(function () use ($doc, $actorUserId): CrmDocument {
            $doc = $this->lock($doc);
            $account = Account::query()->findOrFail($doc->account_id);

            if ($doc->type === CrmDocument::TYPE_CUSTOMER_PO) {
                throw ValidationException::withMessages(['status' => ['A customer PO is received, not issued. Approve it instead.']]);
            }
            if (! $doc->isEditable()) {
                throw ValidationException::withMessages(['status' => ['This document has already been issued.']]);
            }
            if ($doc->type !== CrmDocument::TYPE_PROPOSAL && ! $doc->items()->exists()) {
                throw ValidationException::withMessages(['items' => ['Add at least one line before issuing.']]);
            }
            if ($doc->contact_id === null && $doc->crm_company_id === null) {
                throw ValidationException::withMessages(['contact_id' => ['Choose the customer (a contact or a company) before issuing.']]);
            }

            $profile = $this->profileFor($account);
            $issueDate = $doc->issue_date ? Carbon::parse($doc->issue_date) : now()->startOfDay();
            $numbered = $this->numbering->next($account->id, $doc->type, CrmDocument::PREFIXES[$doc->type], $issueDate);

            $doc->forceFill([
                'number' => $numbered['number'],
                'financial_year' => $numbered['financial_year'],
                'issue_date' => $issueDate->toDateString(),
                'seller_snapshot' => $this->sellerSnapshot($account, $profile),
                'customer_snapshot' => $this->customerSnapshot($doc),
                'terms' => $doc->terms ?? $profile->default_terms,
                'status' => CrmDocument::ISSUED_STATUS[$doc->type],
                'issued_at' => now(),
                'issued_by_user_id' => $actorUserId,
            ]);
            if (in_array($doc->type, [CrmDocument::TYPE_QUOTATION, CrmDocument::TYPE_PROPOSAL], true) && $doc->valid_until === null) {
                $doc->valid_until = $issueDate->copy()->addDays(30)->toDateString();
            }
            if ($doc->type === CrmDocument::TYPE_INVOICE && $doc->due_date === null) {
                $doc->due_date = $issueDate->copy()->addDays((int) ($profile->default_due_days ?? 15))->toDateString();
            }

            // Re-price with the tax mode of this moment, from the lines already stored.
            $this->price($doc, $this->storedLines($doc), $profile);

            if ($doc->type === CrmDocument::TYPE_CREDIT_NOTE) {
                $this->applyCredit($doc);
            }

            $this->activities->logEvent($doc->account_id, $this->label($doc)." {$doc->number} issued", $this->links($doc), $actorUserId);

            return $doc->fresh('items');
        }, 3);
    }

    public function transition(CrmDocument $doc, string $to, ?string $reason, ?int $actorUserId): CrmDocument
    {
        return DB::transaction(function () use ($doc, $to, $reason, $actorUserId): CrmDocument {
            $doc = $this->lock($doc);
            $allowed = CrmDocument::USER_TRANSITIONS[$doc->type][$doc->status] ?? [];
            if (! in_array($to, $allowed, true)) {
                throw ValidationException::withMessages(['status' => ["A {$this->label($doc)} cannot move from {$doc->status} to {$to}."]]);
            }

            if ($to === 'void' && $doc->type === CrmDocument::TYPE_INVOICE
                && (bccomp((string) $doc->amount_paid, '0', 2) > 0 || bccomp((string) $doc->amount_credited, '0', 2) > 0)) {
                throw ValidationException::withMessages(['status' => ['This invoice has payments or credits. Reverse the payments or void the credit notes first.']]);
            }
            if ($to === 'void' && $doc->type === CrmDocument::TYPE_CREDIT_NOTE && $doc->status === 'issued') {
                $this->releaseCredit($doc);
            }

            $doc->status = $to;
            // Who made the decision (accept, reject, approve, fulfil, cancel, void) and when.
            $doc->decided_at = now();
            $doc->decided_by_user_id = $actorUserId;
            if (in_array($to, ['cancelled', 'void'], true)) {
                $doc->cancelled_at = now();
                $doc->cancel_reason = $reason;
                if ($doc->type === CrmDocument::TYPE_INVOICE) {
                    $doc->balance_due = '0.00';
                }
            }
            $doc->updated_by_user_id = $actorUserId;
            $doc->save();

            $this->activities->logEvent($doc->account_id, $this->label($doc).' '.($doc->number ?? "#{$doc->id}")." {$to}".($reason ? ": {$reason}" : ''), $this->links($doc), $actorUserId);

            return $doc->fresh('items');
        });
    }

    public function convert(CrmDocument $source, string $targetType, ?int $actorUserId): CrmDocument
    {
        $allowedTargets = CrmDocument::CONVERSIONS[$source->type] ?? [];
        if (! in_array($targetType, $allowedTargets, true)) {
            throw ValidationException::withMessages(['type' => ['This document cannot be turned into that type.']]);
        }
        $readyStatuses = ['proposal' => ['sent', 'accepted'], 'quotation' => ['sent', 'accepted'], 'customer_po' => ['received', 'approved'], 'sales_order' => ['confirmed', 'fulfilled']];
        if (! in_array($source->status, $readyStatuses[$source->type] ?? [], true)) {
            throw ValidationException::withMessages(['status' => ['Issue (or approve) this document before converting it.']]);
        }

        return DB::transaction(function () use ($source, $targetType, $actorUserId): CrmDocument {
            $copy = new CrmDocument([
                'account_id' => $source->account_id,
                'type' => $targetType,
                'status' => CrmDocument::INITIAL_STATUS[$targetType],
                'parent_id' => $source->id,
                'crm_deal_id' => $source->crm_deal_id,
                'contact_id' => $source->contact_id,
                'crm_company_id' => $source->crm_company_id,
                'title' => $source->title,
                'place_of_supply' => $source->place_of_supply,
                'terms' => $source->terms,
                'notes' => $source->notes,
                'external_reference' => $source->type === CrmDocument::TYPE_CUSTOMER_PO ? $source->external_reference : null,
                'created_by_user_id' => $actorUserId,
                'updated_by_user_id' => $actorUserId,
            ]);
            $copy->save();
            $this->price($copy, $this->storedLines($source), $this->profileFor(Account::query()->findOrFail($source->account_id)));

            $this->activities->logEvent($source->account_id, $this->label($copy).' created from '.$this->label($source).' '.($source->number ?? "#{$source->id}"), $this->links($copy), $actorUserId);

            return $copy->fresh('items');
        });
    }

    /**
     * A draft credit note against an issued invoice: the given lines, or the whole
     * invoice when none are given.
     *
     * @param list<array<string, mixed>>|null $items
     */
    public function createCreditNote(CrmDocument $invoice, ?array $items, ?string $reason, ?int $actorUserId): CrmDocument
    {
        if (! $invoice->isInvoice() || ! in_array($invoice->status, ['issued', 'partially_paid', 'paid'], true)) {
            throw ValidationException::withMessages(['type' => ['A credit note corrects an issued invoice.']]);
        }

        return DB::transaction(function () use ($invoice, $items, $reason, $actorUserId): CrmDocument {
            $note = new CrmDocument([
                'account_id' => $invoice->account_id,
                'type' => CrmDocument::TYPE_CREDIT_NOTE,
                'status' => 'draft',
                'parent_id' => $invoice->id,
                'crm_deal_id' => $invoice->crm_deal_id,
                'contact_id' => $invoice->contact_id,
                'crm_company_id' => $invoice->crm_company_id,
                'title' => 'Credit note for '.$invoice->number,
                'place_of_supply' => $invoice->place_of_supply,
                'notes' => $reason,
                'created_by_user_id' => $actorUserId,
                'updated_by_user_id' => $actorUserId,
            ]);
            $note->save();
            $this->price($note, $items ?? $this->storedLines($invoice), $this->profileFor(Account::query()->findOrFail($invoice->account_id)));

            return $note->fresh('items');
        });
    }

    /** Quotations / proposals past valid_until become expired. Returns how many. */
    public function expireDue(): int
    {
        $count = 0;
        CrmDocument::query()
            ->whereIn('type', [CrmDocument::TYPE_QUOTATION, CrmDocument::TYPE_PROPOSAL])
            ->where('status', 'sent')
            ->whereDate('valid_until', '<', now()->toDateString())
            ->each(function (CrmDocument $doc) use (&$count): void {
                $doc->forceFill(['status' => 'expired'])->save();
                $count++;
            });

        return $count;
    }

    /**
     * An invoice's paid / balance / status from its ACTIVE allocations and credits.
     * Callers hold the invoice row lock (CrmPaymentService, credit notes).
     */
    public function recomputeInvoice(CrmDocument $invoice): CrmDocument
    {
        if (! $invoice->isInvoice() || in_array($invoice->status, ['draft', 'void'], true)) {
            return $invoice;
        }

        $paid = $this->calculator->toMinor((string) ($invoice->allocations()->active()->sum('amount') ?: '0'));
        $credited = $this->calculator->toMinor((string) $invoice->amount_credited);
        $grand = $this->calculator->toMinor((string) $invoice->grand_total);
        $balance = $grand - $paid - $credited;
        if ($balance < 0) {
            throw ValidationException::withMessages(['amount' => ['This would pay or credit the invoice beyond its total.']]);
        }

        $invoice->forceFill([
            'amount_paid' => $this->calculator->format($paid),
            'balance_due' => $this->calculator->format($balance),
            'status' => $balance === 0 ? 'paid' : ($paid + $credited > 0 ? 'partially_paid' : 'issued'),
        ])->save();

        return $invoice;
    }

    // ------------------------------------------------------------------ internals

    private function lock(CrmDocument $doc): CrmDocument
    {
        return CrmDocument::query()->whereKey($doc->id)->lockForUpdate()->firstOrFail();
    }

    /** @param array<string, mixed> $data */
    private function fillAndPrice(CrmDocument $doc, array $data, Account $account): void
    {
        $doc->fill(array_intersect_key($data, array_flip([
            'title', 'contact_id', 'crm_company_id', 'crm_deal_id', 'issue_date', 'valid_until', 'due_date',
            'place_of_supply', 'body', 'terms', 'notes', 'external_reference',
        ])));
        $doc->save();

        if (array_key_exists('items', $data)) {
            $this->price($doc, $this->resolveProducts($account, (array) $data['items']), $this->profileFor($account));
        } elseif (! $doc->items()->exists()) {
            $this->price($doc, [], $this->profileFor($account));
        }
    }

    /**
     * Replace the lines and totals of $doc.
     *
     * @param list<array<string, mixed>> $lines
     */
    private function price(CrmDocument $doc, array $lines, CrmBusinessProfile $profile): void
    {
        $taxMode = $this->taxMode($doc, $profile);
        $result = $this->calculator->calculate($lines, $taxMode, (bool) ($profile->round_off ?? true));

        $doc->items()->delete();
        foreach ($result['items'] as $i => $line) {
            $source = $lines[$i];
            $doc->items()->create($line + [
                'account_id' => $doc->account_id,
                'crm_product_id' => $source['crm_product_id'] ?? null,
                'hsn_sac' => $source['hsn_sac'] ?? null,
                'unit' => $source['unit'] ?? 'nos',
            ]);
        }

        $doc->forceFill($result['totals'] + ['tax_mode' => $taxMode]);
        if ($doc->type === CrmDocument::TYPE_INVOICE && $doc->status === 'draft') {
            $doc->balance_due = $result['totals']['grand_total'];
        }
        $doc->save();
    }

    /**
     * Fill product lines from the tenant's own catalogue (a foreign product id is the
     * same "not available" as a missing one).
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function resolveProducts(Account $account, array $items): array
    {
        $out = [];
        foreach (array_values($items) as $i => $item) {
            if (! empty($item['crm_product_id'])) {
                $product = CrmProduct::query()->forAccount($account->id)->find((int) $item['crm_product_id']);
                if (! $product) {
                    throw ValidationException::withMessages(["items.{$i}.crm_product_id" => ['The selected product is not available.']]);
                }
                $item += [
                    'description' => $product->name,
                    'unit_price' => (string) $product->unit_price,
                    'tax_rate' => (string) $product->tax_rate,
                    'hsn_sac' => $product->hsn_sac,
                    'unit' => $product->unit,
                ];
            }
            $description = trim((string) ($item['description'] ?? ''));
            if ($description === '') {
                throw ValidationException::withMessages(["items.{$i}.description" => ['Each line needs a description.']]);
            }
            $out[] = ['description' => mb_substr($description, 0, 500)] + $item;
        }

        return $out;
    }

    /** @return list<array<string, mixed>> the document's stored lines, as calculator input */
    private function storedLines(CrmDocument $doc): array
    {
        return $doc->items()->get()->map(fn ($item) => [
            'description' => $item->description,
            'quantity' => (string) $item->quantity,
            'unit_price' => (string) $item->unit_price,
            'discount_amount' => (string) $item->discount_amount,
            'tax_rate' => (string) $item->tax_rate,
            'hsn_sac' => $item->hsn_sac,
            'unit' => $item->unit,
            'crm_product_id' => $item->crm_product_id,
        ])->values()->all();
    }

    /**
     * none   the tenant has no GSTIN (no GST charged)
     * intra  place of supply = the tenant's state (CGST + SGST)
     * inter  another state (IGST)
     * Place of supply: the document's own, else the customer company's GSTIN state, else
     * the tenant's own state (a local sale).
     */
    private function taxMode(CrmDocument $doc, CrmBusinessProfile $profile): string
    {
        $seller = $profile->stateCode();
        if (! $profile->gstin || ! $seller) {
            return 'none';
        }

        $place = $doc->place_of_supply;
        if (! $place && $doc->crm_company_id) {
            $gstin = CrmCompany::query()->whereKey($doc->crm_company_id)->value('gstin');
            $place = $gstin ? substr($gstin, 0, 2) : null;
        }

        return ($place ?? $seller) === $seller ? 'intra' : 'inter';
    }

    /** @return array<string, mixed> */
    private function sellerSnapshot(Account $account, CrmBusinessProfile $profile): array
    {
        return [
            'name' => $profile->legal_name ?: $account->company_name,
            'gstin' => $profile->gstin,
            'state' => $profile->state,
            'state_code' => $profile->stateCode(),
            'address' => array_values(array_filter([$profile->address_line1, $profile->address_line2, trim(($profile->city ?? '').' '.($profile->pincode ?? ''))])),
            'phone' => $profile->phone,
            'email' => $profile->email,
            'bank_details' => $profile->bank_details,
            'upi_id' => $profile->upi_id,
        ];
    }

    /** @return array<string, mixed> */
    private function customerSnapshot(CrmDocument $doc): array
    {
        $company = $doc->crm_company_id ? CrmCompany::query()->find($doc->crm_company_id) : null;
        $contact = $doc->contact_id ? Contact::query()->find($doc->contact_id) : null;

        return [
            'name' => $company?->name ?? $contact?->name ?? $contact?->phone_number,
            'contact_name' => $contact?->name,
            'phone' => $contact?->phone_number ?? $company?->phone,
            'email' => $contact?->email ?? $company?->email,
            'gstin' => $company?->gstin,
            'address' => array_values(array_filter([$company?->address_line1, $company?->address_line2, trim(($company?->city ?? '').' '.($company?->pincode ?? ''))])),
            'state' => $company?->state,
        ];
    }

    private function applyCredit(CrmDocument $note): void
    {
        $invoice = CrmDocument::query()->whereKey($note->parent_id)->lockForUpdate()->firstOrFail();
        $credit = $this->calculator->toMinor((string) $note->grand_total);
        $balance = $this->calculator->toMinor((string) $invoice->balance_due);
        if ($credit > $balance) {
            throw ValidationException::withMessages(['items' => ['A credit note cannot be more than the invoice balance ('.$invoice->balance_due.'). Reverse payments first to credit more.']]);
        }
        $invoice->amount_credited = $this->calculator->format($this->calculator->toMinor((string) $invoice->amount_credited) + $credit);
        $this->recomputeInvoice($invoice);
    }

    private function releaseCredit(CrmDocument $note): void
    {
        $invoice = CrmDocument::query()->whereKey($note->parent_id)->lockForUpdate()->firstOrFail();
        $credited = $this->calculator->toMinor((string) $invoice->amount_credited) - $this->calculator->toMinor((string) $note->grand_total);
        $invoice->amount_credited = $this->calculator->format(max(0, $credited));
        $this->recomputeInvoice($invoice);
    }

    private function label(CrmDocument $doc): string
    {
        return match ($doc->type) {
            CrmDocument::TYPE_CUSTOMER_PO => 'Customer PO',
            CrmDocument::TYPE_SALES_ORDER => 'Sales order',
            CrmDocument::TYPE_CREDIT_NOTE => 'Credit note',
            default => ucfirst($doc->type),
        };
    }

    /** @return array<string, int|null> */
    private function links(CrmDocument $doc): array
    {
        return ['crm_deal_id' => $doc->crm_deal_id, 'contact_id' => $doc->contact_id, 'crm_company_id' => $doc->crm_company_id];
    }
}
