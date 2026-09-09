<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\MailLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Mail Log — read-only track record of every individual broadcast email
 * SEND ATTEMPT (see MailLog's migration docblock for why this exists
 * alongside notification_broadcasts' aggregate counts). Scoped the same
 * way AuditLogController/NotificationBroadcastController are: a tenant
 * Admin sees only their own account's rows, Super Admin sees every
 * account's rows in Global View or one client's via ?account_id=.
 */
class MailLogController extends Controller
{
    use ResolvesTenantAccount;

    private const VALID_STATUSES = ['sent', 'failed'];

    public function index(Request $request): JsonResponse
    {
        $account = $this->resolveAccount($request);
        $filters = $this->validateFilters($request);

        $perPage = min((int) $request->integer('per_page', 15), 100);

        $logs = MailLog::query()
            ->with('broadcast:id,subject')
            ->when($account, fn ($q) => $q->where('account_id', $account->id))
            ->when(
                ! empty($filters['search']),
                fn ($q) => $q->where(function ($qq) use ($filters) {
                    $qq->where('recipient_email', 'like', '%'.$filters['search'].'%')
                        ->orWhere('recipient_name', 'like', '%'.$filters['search'].'%');
                })
            )
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(
                ! empty($filters['from']),
                fn ($q) => $q->where('sent_at', '>=', Carbon::parse($filters['from'])->startOfDay())
            )
            ->when(
                ! empty($filters['to']),
                fn ($q) => $q->where('sent_at', '<=', Carbon::parse($filters['to'])->endOfDay())
            )
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json(array_merge($logs->toArray(), [
            'scope' => $account ? 'account' : 'global',
        ]));
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
}
