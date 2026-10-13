<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\CrmDeal;
use App\Models\CrmDocument;
use App\Models\CrmPayment;
use App\Services\Crm\Sales\CrmDocumentCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRM Sales Phase 6 — GET /api/crm/reports/sales: the sales & receivables dashboard for
 * the resolved account. Read-only; every figure is computed from the stored rows on
 * each call (nothing cached that could drift). Money sums are done in integer paise.
 */
class CrmSalesReportController extends Controller
{
    use ResolvesTenantAccount;

    public function __invoke(Request $request, CrmDocumentCalculator $calc): JsonResponse
    {
        $account = $this->requireAccount($request);
        $today = now()->startOfDay();
        $monthStart = now()->startOfMonth()->toDateString();

        $open = CrmDocument::query()->forAccount($account->id)->where('type', CrmDocument::TYPE_INVOICE)->whereIn('status', ['issued', 'partially_paid'])
            ->get(['id', 'number', 'due_date', 'balance_due', 'customer_snapshot']);

        $aging = ['current' => 0, '1_30' => 0, '31_60' => 0, '61_90' => 0, '90_plus' => 0];
        $outstanding = 0;
        $overdue = 0;
        $debtors = [];
        foreach ($open as $invoice) {
            $minor = $calc->toMinor((string) $invoice->balance_due);
            $outstanding += $minor;
            $days = $invoice->due_date ? (int) $invoice->due_date->diffInDays($today, false) : 0;
            $bucket = match (true) {
                $days <= 0 => 'current',
                $days <= 30 => '1_30',
                $days <= 60 => '31_60',
                $days <= 90 => '61_90',
                default => '90_plus',
            };
            $aging[$bucket] += $minor;
            if ($days > 0) {
                $overdue += $minor;
            }
            $name = (string) ($invoice->customer_snapshot['name'] ?? 'Unknown');
            $debtors[$name] = ($debtors[$name] ?? 0) + $minor;
        }
        arsort($debtors);

        $sum = fn ($query, string $column = 'amount') => $calc->format($calc->toMinor((string) ($query->sum($column) ?: '0')));

        $deals = CrmDeal::query()->forAccount($account->id);

        return response()->json(['data' => [
            'receivables' => [
                'outstanding' => $calc->format($outstanding),
                'overdue' => $calc->format($overdue),
                'open_invoices' => $open->count(),
                'aging' => array_map(fn (int $m) => $calc->format($m), $aging),
                'top_debtors' => collect($debtors)->take(5)->map(fn ($m, $name) => ['name' => $name, 'balance' => $calc->format($m)])->values(),
            ],
            'this_month' => [
                'invoiced' => $sum(CrmDocument::query()->forAccount($account->id)->where('type', CrmDocument::TYPE_INVOICE)->whereNotIn('status', ['draft', 'void'])->whereDate('issue_date', '>=', $monthStart), 'grand_total'),
                'collected' => $sum(CrmPayment::query()->forAccount($account->id)->whereNull('reversed_at')->whereDate('paid_on', '>=', $monthStart)),
                'quotations_sent' => CrmDocument::query()->forAccount($account->id)->where('type', CrmDocument::TYPE_QUOTATION)->whereNotNull('issued_at')->whereDate('issue_date', '>=', $monthStart)->count(),
                'quotations_accepted' => CrmDocument::query()->forAccount($account->id)->where('type', CrmDocument::TYPE_QUOTATION)->where('status', 'accepted')->where('decided_at', '>=', $monthStart)->count(),
                'deals_won' => (clone $deals)->where('status', CrmDeal::STATUS_WON)->where('won_at', '>=', $monthStart)->count(),
                'won_value' => $sum((clone $deals)->where('status', CrmDeal::STATUS_WON)->where('won_at', '>=', $monthStart), 'value'),
            ],
            'pipeline' => [
                'open_deals' => (clone $deals)->where('status', CrmDeal::STATUS_OPEN)->count(),
                'open_value' => $sum((clone $deals)->where('status', CrmDeal::STATUS_OPEN), 'value'),
            ],
        ]]);
    }
}
