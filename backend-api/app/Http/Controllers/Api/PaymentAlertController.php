<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessPaymentAlertJob;
use App\Models\PaymentAlert;
use App\Services\PaymentAlerts\PaymentAlertDispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentAlertController extends Controller
{
    use ResolvesTenantAccount;

    /**
     * Columns the bulk CSV must contain, in any order. Values are matched
     * case-insensitively against the header row.
     */
    private const REQUIRED_CSV_COLUMNS = ['phone', 'customer_name', 'amount', 'payment_ref'];

    /**
     * POST /api/alerts/send — single alert dispatch.
     *
     * Deduplication: the payment_ref uniqueness check happens BEFORE the
     * row is created and BEFORE any job is dispatched, so a duplicate
     * costs nothing — no quota deduction, no queue entry. See the
     * migration's unique(['account_id','payment_ref']) index for the
     * concurrency backstop this relies on (caught below as a QueryException).
     */
    public function send(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client/tenant account to send alerts from (pass ?account_id=).');

        $data = $request->validate([
            'recipient_phone' => ['required', 'string', 'max:20'],
            'customer_name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_ref' => ['required', 'string', 'max:255'],
        ]);

        // Module 9: dedup-check/create/queue logic moved into
        // PaymentAlertDispatcher so the new external Api\V1\
        // ExternalAlertController shares this exact code path — response
        // shape and status codes below are unchanged from before the
        // extraction.
        $result = PaymentAlertDispatcher::dispatch($account->id, $data);

        if ($result['status'] === 'disconnected') {
            return $this->disconnectedResponse();
        }

        if ($result['status'] === 'duplicate') {
            return $this->duplicateResponse($result['payment_ref']);
        }

        return response()->json([
            'message' => 'Payment alert queued.',
            'alert' => $result['alert'],
        ], 202);
    }

    /**
     * POST /api/alerts/bulk-upload — CSV batch dispatch.
     *
     * Every valid, non-duplicate row is created and queued individually
     * (same dedup guard as send(), applied per-row, plus an in-file check
     * so the same payment_ref appearing twice in one CSV only queues
     * once). The request returns a per-row outcome summary rather than a
     * single pass/fail, since a large CSV will usually be a mix.
     */
    public function bulkUpload(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client/tenant account to send alerts from (pass ?account_id=).');
        $account->loadMissing(['currentSubscription', 'whatsAppSession']);

        if (PaymentAlertDispatcher::isWhatsAppDisconnected($account)) {
            return $this->disconnectedResponse();
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        if ($handle === false) {
            return response()->json(['message' => 'Could not read the uploaded file.'], 422);
        }

        $header = fgetcsv($handle);
        if ($header === false || $header === [null]) {
            fclose($handle);

            return response()->json(['message' => 'The CSV file is empty.'], 422);
        }

        $normalizedHeader = array_map(static fn ($col) => strtolower(trim((string) $col)), $header);
        $missingColumns = array_diff(self::REQUIRED_CSV_COLUMNS, $normalizedHeader);

        if (! empty($missingColumns)) {
            fclose($handle);

            return response()->json([
                'message' => 'CSV is missing required column(s): '.implode(', ', $missingColumns),
                'required_columns' => self::REQUIRED_CSV_COLUMNS,
            ], 422);
        }

        $columnIndex = array_flip($normalizedHeader);

        $queued = [];
        $skippedDuplicates = [];
        $invalidRows = [];
        $seenRefsInThisFile = [];
        $rowNumber = 1; // header consumed the first row

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            $isBlankLine = count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0;
            if ($isBlankLine) {
                continue;
            }

            $phone = trim((string) ($row[$columnIndex['phone']] ?? ''));
            $customerName = trim((string) ($row[$columnIndex['customer_name']] ?? ''));
            $amountRaw = trim((string) ($row[$columnIndex['amount']] ?? ''));
            $paymentRef = trim((string) ($row[$columnIndex['payment_ref']] ?? ''));

            $rowErrors = [];
            if ($phone === '') {
                $rowErrors[] = 'phone is required';
            }
            if ($customerName === '') {
                $rowErrors[] = 'customer_name is required';
            }
            if (! is_numeric($amountRaw) || (float) $amountRaw <= 0) {
                $rowErrors[] = 'amount must be a positive number';
            }
            if ($paymentRef === '') {
                $rowErrors[] = 'payment_ref is required';
            }

            if (! empty($rowErrors)) {
                $invalidRows[] = ['row' => $rowNumber, 'errors' => $rowErrors];

                continue;
            }

            $isDuplicate = isset($seenRefsInThisFile[$paymentRef])
                || PaymentAlert::isDuplicate($account->id, $paymentRef);

            if ($isDuplicate) {
                $skippedDuplicates[] = ['row' => $rowNumber, 'payment_ref' => $paymentRef];

                continue;
            }
            $seenRefsInThisFile[$paymentRef] = true;

            try {
                $alert = PaymentAlert::create([
                    'account_id' => $account->id,
                    'recipient_phone' => $phone,
                    'customer_name' => $customerName,
                    'amount' => $amountRaw,
                    'payment_ref' => $paymentRef,
                    'status' => 'queued',
                ]);
            } catch (QueryException $e) {
                if ($this->isUniqueConstraintViolation($e)) {
                    $skippedDuplicates[] = ['row' => $rowNumber, 'payment_ref' => $paymentRef];

                    continue;
                }

                throw $e;
            }

            ProcessPaymentAlertJob::dispatch($alert->id);
            $queued[] = ['row' => $rowNumber, 'payment_ref' => $paymentRef, 'id' => $alert->id];
        }

        fclose($handle);

        return response()->json([
            'message' => sprintf(
                '%d alert(s) queued, %d duplicate(s) skipped, %d invalid row(s) skipped.',
                count($queued),
                count($skippedDuplicates),
                count($invalidRows),
            ),
            'queued_count' => count($queued),
            'queued' => $queued,
            'skipped_duplicates' => $skippedDuplicates,
            'invalid_rows' => $invalidRows,
        ]);
    }

    private function duplicateResponse(string $paymentRef): JsonResponse
    {
        return response()->json([
            'message' => "An alert for payment_ref '{$paymentRef}' has already been sent for this account.",
        ], 409);
    }

    private function disconnectedResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'WhatsApp account is disconnected. Please connect your device first.',
            'error_code' => 'WHATSAPP_DISCONNECTED',
        ], 422);
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        // SQLSTATE 23000 = integrity constraint violation, consistent
        // across SQLite (this project's local driver) and MySQL.
        return $e->getCode() === '23000';
    }
}
