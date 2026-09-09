<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\PaymentAlert;
use App\Services\Pdf\SimplePdfWriter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    use ResolvesTenantAccount;

    private const VALID_STATUSES = ['pending', 'queued', 'sent', 'failed'];

    /**
     * GET /api/exports/csv — streamed CSV of message logs, filtered
     * identically to MessageLogController::index() (search/status/date
     * range), scoped to the caller's account.
     *
     * Memory efficiency: response()->streamDownload() writes directly to
     * php://output as each row is produced, and the query is consumed via
     * Eloquent's cursor() (a generator backed by a single unbuffered PDO
     * statement) rather than get()/paginate() — so memory use stays flat
     * whether the export is 50 rows or 500,000; nothing loads the full
     * result set into an array at once.
     */
    public function csv(Request $request): StreamedResponse
    {
        $account = $this->resolveAccount($request);
        $filters = $this->validateFilters($request);

        $query = $this->filteredQuery($account?->id, $filters);
        if (! $account) {
            $query->with('account:id,company_name');
        }
        $filename = $account
            ? sprintf('payment-alerts-%d-%s.csv', $account->id, now()->format('Ymd_His'))
            : sprintf('payment-alerts-all-clients-%s.csv', now()->format('Ymd_His'));

        return response()->streamDownload(function () use ($query, $account) {
            $handle = fopen('php://output', 'w');

            $header = [
                'ID', 'Recipient Phone', 'Customer Name', 'Amount', 'Payment Ref',
                'Status', 'Cost Deducted', 'Error Reason', 'Sent At', 'Created At',
            ];
            if (! $account) {
                $header[] = 'Client';
            }
            fputcsv($handle, $header);

            foreach ($query->cursor() as $alert) {
                $row = [
                    $alert->id,
                    $alert->recipient_phone,
                    $alert->customer_name,
                    $alert->amount,
                    $alert->payment_ref,
                    $alert->status,
                    $alert->cost_deducted,
                    $alert->error_reason,
                    $alert->sent_at?->toDateTimeString(),
                    $alert->created_at->toDateTimeString(),
                ];
                if (! $account) {
                    $row[] = $alert->account?->company_name ?? '—';
                }
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * GET /api/exports/pdf — one-page billing/compliance SUMMARY (not a
     * row-by-row dump — that's what the CSV export is for).
     *
     * NO PDF LIBRARY IS INSTALLED (composer.json has none — no dompdf,
     * mpdf, tcpdf, snappy) AND NONE CAN BE INSTALLED FROM THIS SESSION:
     * there is no `php`/`composer` binary reachable in this environment
     * (confirmed in the Module 5 report), so any code assuming a package
     * that isn't already vendored would fail immediately for you with a
     * "class not found" error. Per the standing dependency-minimization
     * instruction, SimplePdfWriter (app/Services/Pdf) hand-writes a
     * minimal but valid PDF 1.4 file (plain text only, no styling/tables)
     * directly against the PDF file format spec — no dependency, works
     * the moment this ships. The byte-offset/xref-table algorithm was
     * verified before being written: prototyped in Python, and the
     * output validated with `qpdf --check` (0 errors) and `pdftotext`
     * (correct text extraction) in this session. Module 8 reuses this
     * same writer for invoice PDFs (BillingController), which is why it
     * now lives in its own service class instead of as a private method
     * here.
     *
     * If you want a richer, styled report later, `composer require
     * barryvdh/laravel-dompdf` (HTML-to-PDF, well-maintained) is the
     * natural upgrade — that's a call for you to make, not one to make
     * silently in this response.
     */
    public function pdf(Request $request): Response
    {
        $account = $this->resolveAccount($request);
        $filters = $this->validateFilters($request);
        $subscription = $account?->currentSubscription;

        $agg = $this->filteredQuery($account?->id, $filters)
            ->selectRaw(
                "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as total_sent, ".
                "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as total_failed, ".
                "COALESCE(SUM(CASE WHEN status = 'sent' THEN cost_deducted ELSE 0 END), 0) as total_cost"
            )
            ->first();

        $totalSent = (int) $agg->total_sent;
        $totalFailed = (int) $agg->total_failed;
        $attempted = $totalSent + $totalFailed;
        $deliveredRate = $attempted > 0 ? round(($totalSent / $attempted) * 100, 2) : 0.0;

        $from = isset($filters['from']) ? Carbon::parse($filters['from'])->toDateString() : 'account inception';
        $to = isset($filters['to']) ? Carbon::parse($filters['to'])->toDateString() : now()->toDateString();

        $lines = [
            'WhatsApp SaaS Platform - Payment Alerts Summary Report',
            $account ? 'Account: '.$account->company_name.' (ID '.$account->id.')' : 'Account: All Clients (Platform-Wide)',
            'Period: '.$from.' to '.$to,
            ($filters['status'] ?? null) ? 'Status filter: '.$filters['status'] : null,
            ($filters['search'] ?? null) ? 'Search filter: "'.$filters['search'].'"' : null,
            '',
            'Total Alerts Attempted: '.$attempted,
            'Total Sent: '.$totalSent,
            'Total Failed: '.$totalFailed,
            'Delivered Rate: '.number_format($deliveredRate, 2).'%',
            'Total Cost Incurred: Rs. '.number_format((float) $agg->total_cost, 4),
        ];

        if ($subscription) {
            $lines[] = '';
            $lines[] = 'Current Subscription';
            $lines[] = 'Engine: '.$subscription->engine_type.'   Billing model: '.$subscription->billing_model;
            $lines[] = 'Allocated: '.($subscription->total_allocated_messages ?? 'Unlimited')
                .'   Used: '.$subscription->used_messages
                .'   Status: '.$subscription->status;
        }

        $lines[] = '';
        $lines[] = 'Generated: '.now()->toDateTimeString();

        $pdf = SimplePdfWriter::render($lines);
        $filename = $account
            ? sprintf('payment-alerts-summary-%d-%s.pdf', $account->id, now()->format('Ymd_His'))
            : sprintf('payment-alerts-summary-all-clients-%s.pdf', now()->format('Ymd_His'));

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($pdf),
        ]);
    }

    /**
     * @return array{search?: string, status?: string, from?: string, to?: string}
     */
    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(self::VALID_STATUSES)],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);
    }

    /** Null $accountId = every tenant (Super Admin, no client selected). */
    private function filteredQuery(?int $accountId, array $filters)
    {
        $query = $accountId ? PaymentAlert::forAccount($accountId) : PaymentAlert::query();

        if (! empty($filters['status'])) {
            $query->withStatus($filters['status']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('customer_name', 'like', "%{$search}%")
                    ->orWhere('recipient_phone', 'like', "%{$search}%")
                    ->orWhere('payment_ref', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        return $query;
    }
}
