<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\PaymentAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class MessageLogController extends Controller
{
    use ResolvesTenantAccount;

    private const VALID_STATUSES = ['pending', 'queued', 'sent', 'failed'];

    /**
     * GET /api/alerts/logs — paginated data grid.
     *
     * The list view intentionally omits `raw_response` (can be an
     * arbitrarily large JSON blob per row); show() below returns it for
     * the detail modal only, one row at a time.
     */
    public function index(Request $request): JsonResponse
    {
        $account = $this->resolveAccount($request);
        $filters = $this->validateFilters($request);

        $perPage = min((int) $request->integer('per_page', 15), 100);

        $query = $this->filteredQuery($account?->id, $filters)
            ->select([
                'id', 'account_id', 'recipient_phone', 'customer_name', 'amount',
                'payment_ref', 'status', 'cost_deducted', 'error_reason', 'sent_at',
                'created_at', 'updated_at',
            ]);

        if (! $account) {
            // Super Admin, no client selected: label every row with its
            // tenant instead of erroring — mirrors TeamController::index().
            $query->with('account:id,company_name');
        }

        $logs = $query->latest('id')->paginate($perPage)->withQueryString();

        return response()->json($logs->toArray() + ['scope' => $account ? 'account' : 'global']);
    }

    /**
     * GET /api/alerts/logs/{id} — detail modal: raw delivery status, cost
     * breakdown, error reason and the full driver payload (raw_response).
     * Not explicitly named as a separate endpoint in the module spec, but
     * required to serve "full payload metadata" without bloating index()'s
     * response for every row in a paginated list.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $account = $this->resolveAccount($request);

        $alert = $account
            ? PaymentAlert::forAccount($account->id)->findOrFail($id)
            : PaymentAlert::with('account:id,company_name')->findOrFail($id);

        return response()->json($alert);
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

    /**
     * Shared filter application — ExportController mirrors this logic so
     * CSV/PDF exports match exactly what the data grid shows for the same
     * filter set. Kept as a small duplicated helper per-controller rather
     * than a shared trait, consistent with this codebase's existing
     * per-controller account()-resolution pattern.
     */
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
