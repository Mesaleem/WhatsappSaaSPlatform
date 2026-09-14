<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\LoginAuditLog;
use App\Services\Pdf\SimplePdfWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Role-Based Login Audit Logging Architecture — read side. Rows are
 * written exclusively by AuthController::logAttempt(); this controller
 * only lists/exports them, scoped per-role via scopedQuery():
 *   - super_admin: every tenant, or one via ?account_id= (ResolvesTenantAccount,
 *     same convention as every other Module 7-style controller).
 *   - admin: forced to their own account's rows (TenantIsolationMiddleware
 *     already resolves account_id to their own regardless of any query
 *     param — see ResolvesTenantAccount's docblock).
 *   - agent (3-Tier Hierarchy & Agent-Client Scope Engine, Phase 4):
 *     forced to their own Agent account's rows PLUS every one of their
 *     Sub-Clients' rows (account_id IN [own, ...owned-by-agent]) — an
 *     Agent needs visibility across the whole reseller tree they're
 *     accountable for, not just their own organization's logins. Treated
 *     the same as 'admin' for the per-user narrowing below (i.e. NOT
 *     narrowed to their own single user_id) for the same reason.
 *   - user (plain staff): further narrowed to ONLY their own login rows —
 *     a staff member should not see their coworkers' login history.
 */
class AuditLogController extends Controller
{
    use ResolvesTenantAccount;

    private const VALID_STATUSES = ['success', 'failed'];

    public function index(Request $request): JsonResponse
    {
        $filters = $this->validateFilters($request);
        $perPage = min((int) $request->integer('per_page', 15), 100);

        $logs = $this->scopedQuery($request, $filters)
            ->with(['user:id,name,email', 'account:id,company_name'])
            ->orderByDesc('logged_in_at')
            ->paginate($perPage);

        return response()->json(array_merge($logs->toArray(), [
            'scope' => $this->resolveAccount($request) ? 'account' : 'global',
        ]));
    }

    /**
     * GET /api/audit-logs/csv — same streamed/cursor() pattern as
     * ExportController::csv(): flat memory use regardless of row count.
     */
    public function csv(Request $request): StreamedResponse
    {
        $filters = $this->validateFilters($request);
        $account = $this->resolveAccount($request);
        $query = $this->scopedQuery($request, $filters)->with(['user:id,name,email', 'account:id,company_name']);

        $filename = $account
            ? sprintf('login-audit-%d-%s.csv', $account->id, now()->format('Ymd_His'))
            : sprintf('login-audit-all-clients-%s.csv', now()->format('Ymd_His'));

        return response()->streamDownload(function () use ($query, $account) {
            $handle = fopen('php://output', 'w');

            $header = ['ID', 'User', 'Email', 'Role', 'Status', 'IP Address', 'Logged In At'];
            if (! $account) {
                $header[] = 'Client';
            }
            fputcsv($handle, $header);

            foreach ($query->cursor() as $log) {
                $row = [
                    $log->id,
                    $log->user?->name ?? '—',
                    $log->email,
                    $log->role ?? '—',
                    $log->status,
                    $log->ip_address,
                    $log->logged_in_at?->toDateTimeString(),
                ];
                if (! $account) {
                    $row[] = $log->account?->company_name ?? '—';
                }
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * GET /api/audit-logs/pdf — one-page summary (counts + the most recent
     * rows that fit), same rationale/writer as ExportController::pdf():
     * no PDF library is installed or installable in this environment.
     */
    public function pdf(Request $request): Response
    {
        $filters = $this->validateFilters($request);
        $account = $this->resolveAccount($request);
        $baseQuery = $this->scopedQuery($request, $filters);

        $totalSuccess = (clone $baseQuery)->where('status', 'success')->count();
        $totalFailed = (clone $baseQuery)->where('status', 'failed')->count();

        $recent = (clone $baseQuery)
            ->with(['user:id,name,email', 'account:id,company_name'])
            ->orderByDesc('logged_in_at')
            ->limit(25)
            ->get();

        $lines = [
            'WhatsApp SaaS Platform - Login Audit Summary Report',
            $account ? 'Account: '.$account->company_name.' (ID '.$account->id.')' : 'Account: All Clients (Platform-Wide)',
            ($filters['from'] ?? null) ? 'From: '.$filters['from'] : null,
            ($filters['to'] ?? null) ? 'To: '.$filters['to'] : null,
            ($filters['status'] ?? null) ? 'Status filter: '.$filters['status'] : null,
            ($filters['role'] ?? null) ? 'Role filter: '.$filters['role'] : null,
            '',
            'Total Successful Logins: '.$totalSuccess,
            'Total Failed Attempts: '.$totalFailed,
            '',
            'Most Recent Attempts (up to 25):',
        ];

        foreach ($recent as $log) {
            $who = $log->user?->email ?? $log->email ?? 'unknown';
            $lines[] = sprintf(
                '%s | %s | %s | %s',
                $log->logged_in_at?->toDateTimeString(),
                $who,
                $log->status,
                $account ? ($log->role ?? '—') : ($log->account?->company_name ?? 'platform').' / '.($log->role ?? '—')
            );
        }

        $lines[] = '';
        $lines[] = 'Generated: '.now()->toDateTimeString();

        $pdf = SimplePdfWriter::render($lines);
        $filename = $account
            ? sprintf('login-audit-summary-%d-%s.pdf', $account->id, now()->format('Ymd_His'))
            : sprintf('login-audit-summary-all-clients-%s.pdf', now()->format('Ymd_His'));

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($pdf),
        ]);
    }

    /**
     * @return array{search?: string, status?: string, role?: string, from?: string, to?: string}
     */
    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(self::VALID_STATUSES)],
            'role' => ['sometimes', 'string', 'max:255'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);
    }

    private function scopedQuery(Request $request, array $filters)
    {
        $user = $request->user();
        $account = $this->resolveAccount($request);

        $query = LoginAuditLog::query();

        // 3-Tier Hierarchy & Agent-Client Scope Engine (Phase 4) —
        // agent_scope_id is set by TenantIsolationMiddleware (this
        // route group is wrapped in it, unlike /admin/accounts) only
        // when the caller's own account is an Agent; non-null here means
        // $account above already resolved to that same Agent's own
        // account (ResolvesTenantAccount reads the same 'account_id'
        // attribute TenantIsolationMiddleware sets to the caller's own
        // account_id for any non-Super-Admin). Expands the single-account
        // filter below into "this Agent's own account OR any of their
        // Sub-Clients' accounts" instead.
        $agentScopeId = $request->attributes->get('agent_scope_id');

        if ($agentScopeId) {
            $query->where(function ($q) use ($agentScopeId) {
                $q->where('account_id', $agentScopeId)
                    ->orWhereIn('account_id', Account::query()->where('agent_id', $agentScopeId)->pluck('id'));
            });
        } elseif ($account) {
            $query->where('account_id', $account->id);
        }

        // A plain 'user' role never sees anyone else's login rows, even
        // within their own account. 'agent' is treated like 'admin' here
        // (Phase 4) — see this class's docblock.
        if (! $user->isSuperAdmin() && ! $user->hasRole('admin') && ! $user->hasRole('agent')) {
            $query->where('user_id', $user->id);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['role'])) {
            $query->where('role', $filters['role']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($qq) => $qq->where('name', 'like', "%{$search}%"));
            });
        }

        if (! empty($filters['from'])) {
            $query->where('logged_in_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }

        if (! empty($filters['to'])) {
            $query->where('logged_in_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        return $query;
    }
}
