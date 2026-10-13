<?php

namespace App\Services\Crm\Sales;

use App\Services\Collections\BillingCollectionService;
use Illuminate\Validation\ValidationException;

/**
 * CRM Sales Phase 2 — line and document totals, in INTEGER minor units (paise) only,
 * never floats. Amount parsing / formatting reuses BillingCollectionService::toMinor()
 * / format() (the codebase's existing money helpers).
 *
 * Per line:  gross    = round(qty × rate)                 (qty in thousandths)
 *            taxable  = gross − discount                  (discount ≤ gross)
 *            intra    CGST = SGST = round(taxable × rate/2 %)   (state of supply = seller's)
 *            inter    IGST = round(taxable × rate %)
 *            none     no tax (the tenant has no GSTIN)
 *            total    = taxable + tax
 * Document: the sums; round-off (when the tenant wants it) to the nearest rupee;
 *           grand total = taxable + tax + round-off. Rounding is half-up at each step.
 */
class CrmDocumentCalculator
{
    public function __construct(private readonly BillingCollectionService $money)
    {
    }

    /**
     * @param list<array{description: string, quantity: mixed, unit_price: mixed, discount_amount?: mixed, tax_rate?: mixed}> $items
     * @return array{items: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function calculate(array $items, string $taxMode, bool $roundOff): array
    {
        $lines = [];
        $sum = ['gross' => 0, 'discount' => 0, 'taxable' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0];

        foreach (array_values($items) as $i => $item) {
            $key = "items.{$i}";
            $qtyMilli = $this->quantityMilli($item['quantity'] ?? '1', "{$key}.quantity");
            $price = $this->money->toMinor((string) ($item['unit_price'] ?? '0'), "{$key}.unit_price");
            $discount = $this->money->toMinor((string) ($item['discount_amount'] ?? '0'), "{$key}.discount_amount");
            $rateBp = $this->rateBasisPoints($item['tax_rate'] ?? '0', "{$key}.tax_rate");

            $gross = $this->roundDiv($qtyMilli * $price, 1000);
            if ($discount > $gross) {
                throw ValidationException::withMessages(["{$key}.discount_amount" => ['The discount cannot be more than the line amount.']]);
            }
            $taxable = $gross - $discount;

            [$cgst, $sgst, $igst] = match ($taxMode) {
                'intra' => [$half = $this->roundDiv($taxable * $rateBp, 20000), $half, 0],
                'inter' => [0, 0, $this->roundDiv($taxable * $rateBp, 10000)],
                default => [0, 0, 0],
            };

            $lines[] = [
                'position' => $i,
                'description' => $item['description'],
                'quantity' => $this->formatMilli($qtyMilli),
                'unit_price' => $this->money->format($price),
                'discount_amount' => $this->money->format($discount),
                'tax_rate' => $this->formatBasisPoints($taxMode === 'none' ? 0 : $rateBp),
                'taxable_amount' => $this->money->format($taxable),
                'cgst_amount' => $this->money->format($cgst),
                'sgst_amount' => $this->money->format($sgst),
                'igst_amount' => $this->money->format($igst),
                'line_total' => $this->money->format($taxable + $cgst + $sgst + $igst),
            ];

            $sum['gross'] += $gross;
            $sum['discount'] += $discount;
            $sum['taxable'] += $taxable;
            $sum['cgst'] += $cgst;
            $sum['sgst'] += $sgst;
            $sum['igst'] += $igst;
        }

        $tax = $sum['cgst'] + $sum['sgst'] + $sum['igst'];
        $beforeRound = $sum['taxable'] + $tax;
        $round = $roundOff ? $this->roundDiv($beforeRound, 100) * 100 - $beforeRound : 0;

        return [
            'items' => $lines,
            'totals' => [
                'subtotal' => $this->money->format($sum['gross']),
                'discount_total' => $this->money->format($sum['discount']),
                'taxable_total' => $this->money->format($sum['taxable']),
                'cgst_total' => $this->money->format($sum['cgst']),
                'sgst_total' => $this->money->format($sum['sgst']),
                'igst_total' => $this->money->format($sum['igst']),
                'tax_total' => $this->money->format($tax),
                'round_off' => $this->signed($round),
                'grand_total' => $this->money->format($beforeRound + $round),
            ],
        ];
    }

    /** "1500.00" -> 150000 (reuses the collections parser). */
    public function toMinor(string $value, string $key = 'amount'): int
    {
        return $this->money->toMinor($value, $key);
    }

    public function format(int $minor): string
    {
        return $this->money->format($minor);
    }

    /** Half-up integer division for non-negative numerators. */
    private function roundDiv(int $numerator, int $denominator): int
    {
        return intdiv($numerator * 2 + $denominator, $denominator * 2);
    }

    private function quantityMilli(mixed $value, string $key): int
    {
        $s = is_int($value) ? (string) $value : (is_string($value) ? trim($value) : (is_float($value) ? rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') : ''));
        if (! preg_match('/^(\d{1,9})(?:\.(\d{1,3}))?$/', $s, $m) || ((int) $m[1] === 0 && (int) ($m[2] ?? 0) === 0)) {
            throw ValidationException::withMessages([$key => ['Enter a quantity above zero with at most 3 decimals.']]);
        }

        return (int) $m[1] * 1000 + (int) str_pad($m[2] ?? '', 3, '0');
    }

    private function rateBasisPoints(mixed $value, string $key): int
    {
        $s = is_int($value) ? (string) $value : (is_string($value) ? trim($value) : (is_float($value) ? number_format($value, 2, '.', '') : ''));
        if (! preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $s, $m)) {
            throw ValidationException::withMessages([$key => ['Enter a tax rate like 18 or 12.5.']]);
        }
        $bp = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
        if ($bp > 10000) {
            throw ValidationException::withMessages([$key => ['The tax rate cannot be more than 100%.']]);
        }

        return $bp;
    }

    private function formatMilli(int $milli): string
    {
        return intdiv($milli, 1000).'.'.str_pad((string) ($milli % 1000), 3, '0', STR_PAD_LEFT);
    }

    private function formatBasisPoints(int $bp): string
    {
        return intdiv($bp, 100).'.'.str_pad((string) ($bp % 100), 2, '0', STR_PAD_LEFT);
    }

    private function signed(int $minor): string
    {
        return ($minor < 0 ? '-' : '').$this->money->format(abs($minor));
    }
}
