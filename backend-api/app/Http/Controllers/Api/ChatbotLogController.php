<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ChatbotLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Module 10, requirement 2 — read-only execution log history. Rows are
 * written exclusively by ChatbotEngineService::log(); this controller
 * only ever reads them, mirroring MessageLogController's
 * index-with-filters convention from Module 7 so the two "logs" screens
 * in the frontend behave identically. Account resolution goes through
 * ResolvesTenantAccount, same fix/rationale as ChatbotRuleController.
 */
class ChatbotLogController extends Controller
{
    use ResolvesTenantAccount;

    private const VALID_STATUSES = ['replied', 'ignored', 'failed'];

    /** GET /api/chatbot/logs — paginated, newest first. */
    public function index(Request $request): JsonResponse
    {
        $account = $this->account($request);
        $filters = $this->validateFilters($request);

        $perPage = min((int) $request->integer('per_page', 15), 100);

        $logs = $this->filteredQuery($account->id, $filters)
            ->with('chatbotRule:id,name')
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json($logs);
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

    private function filteredQuery(int $accountId, array $filters)
    {
        $query = ChatbotLog::forAccount($accountId);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('sender_phone', 'like', "%{$search}%")
                    ->orWhere('incoming_message', 'like', "%{$search}%");
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

    private function account(Request $request): Account
    {
        return $this->requireAccount(
            $request,
            'Select a client from the header to view their chatbot logs.'
        );
    }
}
