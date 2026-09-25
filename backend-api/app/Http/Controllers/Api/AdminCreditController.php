<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\CreditLedgerEntry;
use App\Services\Credits\CreditException;
use App\Services\Credits\CreditOperationResult;
use App\Services\Credits\CreditService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 8 Task 1 — Super Admin manual credit operations (+ scoped reads).
 *
 * Mounted in /api/admin (permission:manage-accounts, which Super Admin and
 * Agents hold). On top of that:
 *
 *   GET  /admin/accounts/{id}/credits            Super Admin: any account;
 *                                                Agent: its own sub-clients
 *                                                only (else 404 — the same
 *                                                non-disclosure rule as
 *                                                AccountController).
 *   POST /admin/accounts/{id}/credits/grant      Super Admin ONLY (403
 *   POST /admin/accounts/{id}/credits/adjust     otherwise, checked here —
 *   POST /admin/accounts/{id}/credits/refund     permission alone is not
 *                                                enough, as for
 *                                                setCommissionRule()).
 *
 * The target account is the {id} in the path, resolved and authorized here;
 * no body/query account_id is read. Every write needs an idempotency key
 * (body `idempotency_key` or `Idempotency-Key` header), stored namespaced as
 * "admin:{operation}:{key}" so it can never collide with a system key.
 * A retry answers 200 with `replayed: true` and the ORIGINAL entry, and is
 * itself recorded in activity_logs (module Credits, action_type replay).
 */
class AdminCreditController extends Controller
{
    public function show(Request $request, int $id, CreditService $credits): JsonResponse
    {
        $account = $this->target($request, $id, writes: false);

        $entries = CreditLedgerEntry::query()->forAccount($account->id)->orderByDesc('id')
            ->paginate(min(max((int) $request->integer('per_page', 25), 1), 100));

        return response()->json(['data' => app(\App\Services\Credits\CreditAccountSummary::class)->for($account), 'ledger' => $entries]);
    }

    public function grant(Request $request, int $id, CreditService $credits): JsonResponse
    {
        $account = $this->target($request, $id, writes: true);
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:'.CreditService::MAX_AMOUNT],
            'reason' => ['required', 'string', 'max:255'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);
        $key = $this->key($request, 'grant');

        return $this->run($request, $account, 'grant', $key, fn (array $context) => $credits->grant($account, (int) $data['amount'], $key, $context));
    }

    public function adjust(Request $request, int $id, CreditService $credits): JsonResponse
    {
        $account = $this->target($request, $id, writes: true);
        $data = $request->validate([
            'amount' => ['required', 'integer', 'not_in:0', 'min:-'.CreditService::MAX_AMOUNT, 'max:'.CreditService::MAX_AMOUNT],
            'reason' => ['required', 'string', 'max:255'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);
        $key = $this->key($request, 'adjust');

        return $this->run($request, $account, 'adjust', $key, fn (array $context) => $credits->adjust($account, (int) $data['amount'], $key, $context));
    }

    public function refund(Request $request, int $id, CreditService $credits): JsonResponse
    {
        $account = $this->target($request, $id, writes: true);
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:'.CreditService::MAX_AMOUNT],
            'consumption_entry_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:255'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);
        $key = $this->key($request, 'refund');

        return $this->run($request, $account, 'refund', $key, fn (array $context) => $credits->refund($account, (int) $data['amount'], $key, (int) $data['consumption_entry_id'], $context));
    }

    // ------------------------------------------------------------------

    /**
     * The path's account, if this caller may act on it. Writes: Super Admin
     * only. Reads: Super Admin any; an Agent only its own sub-clients (404
     * otherwise, never revealing that the account exists); anyone else 403.
     */
    private function target(Request $request, int $id, bool $writes): Account
    {
        $user = $request->user();
        $isSuperAdmin = (bool) $user?->isSuperAdmin();
        $isAgent = ! $isSuperAdmin && $user?->account?->account_type === 'agent';

        if ($writes) {
            abort_unless($isSuperAdmin, 403, 'Only the Super Admin can grant, adjust or refund credits.');
        } else {
            abort_unless($isSuperAdmin || $isAgent, 403);
        }

        $account = Account::find($id);
        abort_if(! $account, 404);
        abort_if($isAgent && (int) $account->agent_id !== (int) $user->account_id, 404);

        return $account;
    }

    private function key(Request $request, string $operation): string
    {
        $raw = $request->input('idempotency_key', $request->header('Idempotency-Key'));

        $request->merge(['idempotency_key' => $raw]);
        $request->validate(['idempotency_key' => ['required', 'string', 'min:8', 'max:150', 'regex:/^[A-Za-z0-9._:\-]+$/']]);

        return "admin:{$operation}:{$raw}";
    }

    /** @param Closure(array<string, mixed>): CreditOperationResult $operation */
    private function run(Request $request, Account $account, string $name, string $key, Closure $operation): JsonResponse
    {
        $context = [
            'reason' => $request->input('reason'),
            'reference_type' => $request->filled('reference') ? 'admin_reference' : null,
            'reference_id' => $request->input('reference'),
            'actor_user_id' => $request->user()->id,
            'source' => CreditService::SOURCE_ADMIN,
        ];

        try {
            $result = $operation($context);
        } catch (CreditException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => strtoupper($e->reason),
            ], $e->reason === CreditException::IDEMPOTENCY_CONFLICT ? 409 : 422);
        }

        if ($result->replayed) {
            // "Was it retried?" — the retry itself is on record (no new ledger effect).
            ActivityLog::create([
                'user_id' => $request->user()->id,
                'account_id' => $account->id,
                'agent_id' => $account->agent_id,
                'module_name' => 'Credits',
                'action_type' => 'replay',
                'route_path' => $request->path(),
                'ip_address' => $request->ip(),
                'new_values' => ['operation' => $name, 'idempotency_key' => $key, 'ledger_entry_id' => $result->entry->id],
            ]);
        }

        return response()->json([
            'message' => $result->replayed ? 'Already applied — the original result is returned.' : 'Credits updated.',
            'replayed' => $result->replayed,
            'data' => [
                'entry' => $result->entry,
                'balance' => app(CreditService::class)->balance($account),
            ],
        ], $result->replayed ? 200 : 201);
    }
}
